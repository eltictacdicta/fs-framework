# Tasks: Refactor Backup to SSE

## Change: fix-backup-worker-recovery

---

## Task 1: Update csrf_guard.php SSE detection ✅

**Files:**
- `plugins/system_updater/lib/csrf_guard.php` — line 25-26

**Action:** Add `process_backup.php` to the `$isSse` URI check in `system_updater_csrf_failure_response()`.

**Current (line 25-26):**
```php
$isSse = strpos($uri, 'process_core_update.php') !== false
    || strpos($uri, 'process_restore.php') !== false;
```

**Target:**
```php
$isSse = strpos($uri, 'process_core_update.php') !== false
    || strpos($uri, 'process_restore.php') !== false
    || strpos($uri, 'process_backup.php') !== false;
```

**Rationale:** Without this, a CSRF failure on the backup endpoint returns a JSON 403 instead of an SSE error event. Not a security issue (CSRF still validates), but a UX inconsistency — the browser gets a 403 HTTP response instead of an SSE `error` event that the EventSource `onerror` handler can display.

**Verification:** Manual: trigger CSRF failure on `process_backup.php?action=start` (invalid token) — should return SSE error event, not JSON 403.

---

## Task 2: Rewrite process_backup.php to SSE ✅

**Files:**
- `plugins/system_updater/process_backup.php` — full rewrite

**Action:** Replace the 654-line worker+polling script with a ~130-line SSE script. This is a delete-heavy refactor: ~524 lines of worker/queue/recovery/lock/polling machinery are eliminated.

**What to remove (entire functions + constants + CLI parsing):**
- Constants: `FS_BACKUP_STALE_SECONDS`, `FS_BACKUP_QUEUE_RECOVERY_SECONDS`, `FS_BACKUP_MAX_RECOVERY_ATTEMPTS`
- CLI argument parsing block (lines 16-26)
- `backup_json_encode()`
- `respond_json()`, `respond_and_continue()`
- `get_request_param()`, `sanitize_token()`
- `get_progress_file()`, `get_lock_file()`, `get_session_pointer_file()`
- `read_json_file()`, `write_json_file()`
- `load_pointer()`, `load_progress()`, `save_progress()`
- `clear_job_state()`, `mark_stale_job_if_needed()`
- `should_attempt_queue_recovery()`, `recover_queued_job()`
- `has_active_job()`, `shell_functions_available()`, `detect_php_binary()`
- `launch_cli_worker()`, `create_job_id()`, `ensure_session_ready()`, `run_backup_job()`
- Actions: `worker`, `progress`, `status`, `cleanup` — only `start` remains
- `SYSTEM_UPDATER_PROCESS_BACKUP_BOOTSTRAP_ONLY` early-return gate

**What to add (following `process_core_update.php` pattern):**

```php
<?php
require_once __DIR__ . '/lib/process_bootstrap.php';
$ctx = system_updater_process_init(['mode' => 'sse', 'progress_prefix' => 'fs_backup']);
$sessionId = $ctx['session_id'];
$action = $ctx['action'];
$progressFile = $ctx['progress_file'];

if (!file_exists(__DIR__ . '/lib/backup_manager.php')) {
    system_updater_send_sse('error', ['message' => 'Error: No se encuentra el plugin system_updater.', 'percent' => 0]);
    exit;
}
require_once __DIR__ . '/lib/backup_manager.php';

$lastEventTime = time();

$progressCallback = function ($step, $message, $percent) use ($progressFile, &$lastEventTime) {
    if (time() - $lastEventTime > 10) {
        echo ":keepalive\n\n";
        @flush();
    }
    $data = system_updater_save_progress($progressFile, $step, $message, $percent);
    system_updater_send_sse('progress', $data);
    $lastEventTime = time();
    usleep(10000);
};

switch ($action) {
    case 'start':
        system_updater_send_sse('start', ['message' => 'Iniciando copia de seguridad...', 'percent' => 0]);
        system_updater_save_progress($progressFile, 'init', 'Preparando copia de seguridad...', 0);

        try {
            $backupManager = new backup_manager(FS_FOLDER);
            system_updater_send_sse('init', ['message' => 'Verificando entorno...', 'percent' => 2]);

            $result = $backupManager->create_backup_with_progress('', true, $progressCallback);

            if (isset($result['complete']) && !empty($result['complete']['success'])) {
                system_updater_save_progress($progressFile, 'complete', '¡Copia de seguridad creada con éxito!', 100);
                system_updater_send_sse('complete', [
                    'message' => '¡Copia de seguridad creada con éxito!',
                    'percent' => 100,
                    'backup_name' => $result['complete']['backup_name'] ?? '',
                    'redirect' => 'index.php?page=admin_updater&success=backup',
                ]);
            } else {
                $errors = $backupManager->get_errors();
                $errorMsg = !empty($errors) ? implode('; ', $errors) : 'Error desconocido durante el backup';
                system_updater_save_progress($progressFile, 'error', $errorMsg, 0, $errorMsg);
                system_updater_send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
            }
        } catch (\Throwable $e) {
            $errorMsg = 'Excepción: ' . $e->getMessage();
            system_updater_save_progress($progressFile, 'error', $errorMsg, 0, $errorMsg);
            system_updater_send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
        }

        @unlink($progressFile);
        break;

    default:
        system_updater_send_sse('error', ['message' => 'Acción no válida: ' . $action, 'percent' => 0]);
        break;
}
exit;
```

**Key design decisions (from design.md):**
- **Keepalive**: Inline timer in progress callback. `$lastEventTime` tracks last event; sends `:keepalive\n\n` if >10s elapsed. Zero changes to `backup_manager`.
- **Progress callback**: Single closure matching `process_core_update.php` pattern — save + send_sse + keepalive check.
- **Only `action=start`**: No worker/progress/status/cleanup actions. EventSource replaces all polling.
- **No FS_FOLDER definition or autoload**: `system_updater_process_init()` handles bootstrap.

**Verification:**
- File loads without syntax errors: `ddev exec php -l plugins/system_updater/process_backup.php`
- File structure matches `process_core_update.php` (bootstrap → init → callback → switch)
- No references to removed functions remain

---

## Task 3: Replace frontend polling with EventSource ✅

**Files:**
- `plugins/system_updater/view/admin_updater.html.twig` — lines 350-611 (backup JS section)

**Action:** Replace `$.ajax` + `setInterval` polling with `EventSource`, matching the existing `coreUpdateEventSource` pattern (lines 665-726).

**Remove (variables + functions):**
- Variables: `backupJobId`, `backupStatusPoller`
- Functions: `startBackupStatusPolling()`, `stopBackupStatusPolling()`, `cleanupBackupStatus()`
- `cleanupBackupStatus()` call from `finishBackupAsError()` — no server-side job state to clear with SSE

**Replace `startBackup()` function (lines 446-497):**

```javascript
function startBackup(chainConfig) {
    pendingBackupChain = chainConfig || null;
    backupLog = [];
    backupFinished = false;
    configureBackupModal(pendingBackupChain);
    updateBackupProgress(0, pendingBackupChain ? 'Iniciando copia previa...' : 'Iniciando copia de seguridad...');
    $('#backupDetails').html('<small class="text-muted">Conectando al servidor...</small>');
    $('#backupCompleteBtn').hide();
    $('#backupErrorBtn').hide();
    $('#btn-create-backup').prop('disabled', true);
    if (pendingBackupChain) {
        setCoreActionButtonsEnabled(false);
    }
    $('#backupProgressModal').modal('show');

    var sseUrl = 'plugins/system_updater/process_backup.php?action=start&_csrf_token=' + encodeURIComponent(csrfToken);
    var backupEventSource = new EventSource(sseUrl);

    backupEventSource.addEventListener('start', function(e) {
        var data = JSON.parse(e.data);
        addBackupLogEntry(data.message || 'Proceso iniciado');
    });

    backupEventSource.addEventListener('init', function(e) {
        var data = JSON.parse(e.data);
        addBackupLogEntry(data.message || 'Verificando...');
    });

    backupEventSource.addEventListener('progress', function(e) {
        var data = JSON.parse(e.data);
        updateBackupProgress(data.percent || 0, data.message || 'Procesando...');
        if (data.message && data.message !== backupLastStatusMessage) {
            backupLastStatusMessage = data.message;
            addBackupLogEntry(data.message);
        }
    });

    backupEventSource.addEventListener('complete', function(e) {
        var data = JSON.parse(e.data);
        backupFinished = true;
        updateBackupProgress(100, data.message || '¡Completado!', 'success');
        addBackupLogEntry('✓ ' + (data.message || 'Completado'));
        if (data.backup_name) addBackupLogEntry('Backup: ' + data.backup_name);
        $('#btn-create-backup').prop('disabled', false);
        hideLoading();
        backupEventSource.close();

        if (pendingBackupChain) {
            addBackupLogEntry('Iniciando automáticamente el siguiente paso...');
            var nextAction = pendingBackupChain;
            pendingBackupChain = null;
            setTimeout(function() {
                $('#backupProgressModal').modal('hide');
                startCoreUpdate(false, nextAction.isReinstall);
            }, 500);
            return;
        }
        $('#backupCompleteBtn').show();
    });

    backupEventSource.addEventListener('error', function(e) {
        var data = {};
        try { data = JSON.parse(e.data); } catch(ex) {}
        if (data.message) {
            finishBackupAsError(data.message);
            backupEventSource.close();
        }
    });

    backupEventSource.onerror = function() {
        if (!backupFinished) {
            finishBackupAsError('Error de conexión con el servidor');
        }
        backupEventSource.close();
    };
}
```

**Update `finishBackupAsError()`:**
- Remove `stopBackupStatusPolling()` call (function no longer exists)
- Remove `cleanupBackupStatus()` call (no server-side job state to clear)

**Keep unchanged:**
- `backupLastStatusMessage` — still used for dedup log entries
- `backupLog`, `backupFinished` — still used
- `pendingBackupChain` — still used for backup→core-update chaining
- `configureBackupModal()`, `updateBackupProgress()`, `addBackupLogEntry()`, `finishBackupAsError()` — same logic
- `$('#btn-create-backup').click()` handler — same

**Verification:**
- `startBackup()` creates `EventSource`, not `$.ajax`
- No references to `backupJobId`, `backupStatusPoller`, `startBackupStatusPolling`, `stopBackupStatusPolling`, `cleanupBackupStatus`
- `finishBackupAsError()` no longer calls `stopBackupStatusPolling()` or `cleanupBackupStatus()`
- `backupLastStatusMessage` variable declaration still present (used in progress handler)

---

## Task 4: Manual verification ✅

**Approach:** Integration test via browser + curl since there are no automated tests for the SSE endpoints.

**Checks:**
1. **Syntax check:** `ddev exec php -l plugins/system_updater/process_backup.php`
2. **CSRF guard check:** Verify `csrf_guard.php` includes `process_backup.php` in `$isSse`
3. **SSE stream test:** `curl -N` with valid session + CSRF token against `process_backup.php?action=start` — verify SSE events: `start` → `init` → `progress` (multiple) → `complete`
4. **Keepalive test:** For large backups, verify `:keepalive` comments appear between progress events when steps take >10s
5. **Error handling:** Trigger with invalid CSRF — verify SSE error event (not JSON 403)
6. **Frontend E2E:** Click "Crear Copia de Seguridad" in admin UI — verify progress bar updates in real time, completion shows success button
7. **Backup→core-update chain:** Test "Copia de seguridad + Actualizar" wizard path — verify backup completes then core update starts automatically
8. **Connection loss:** Close browser tab mid-backup — verify no orphan processes (no lock files, no worker PIDs)

---

## Task 5: Version bump ✅

**Files:**
- `plugins/system_updater/fsframework.ini` — bump version
- `plugins/system_updater/facturascripts.ini` — bump version (if exists)

**Action:** Increment patch version.

**Verification:** Version field is updated in both ini files.

---

## Commit Strategy (work-unit-commits)

This is a **delete-heavy refactor** — the diff will show ~524 lines removed and ~130 added for the backend, plus ~80 net lines changed in the frontend. The total net change is well under 400 lines, so a single PR is appropriate.

| Commit | Description | Files | Est. Lines Changed |
|--------|-------------|-------|--------------------|
| 1 | `fix(csrf): add process_backup.php to SSE URI detection` | `csrf_guard.php` | +1 |
| 2 | `refactor(backup): rewrite process_backup.php from worker+polling to SSE` | `process_backup.php` | ~654 (full rewrite) |
| 3 | `refactor(backup): replace frontend polling with EventSource` | `admin_updater.html.twig` | ~160 |
| 4 | `chore(system_updater): version bump` | `fsframework.ini`, `facturascripts.ini` | +2 |

**PR strategy:** Single PR. The diff is delete-heavy (~700 lines removed, ~200 added). Net change ~200 lines — well under the 400-line review budget.

**Commit ordering rationale:**
- Commit 1 is independent and safe to land alone (minor UX fix).
- Commit 2 is the core backend change. After this commit, the backup endpoint returns SSE but the frontend still uses polling — the UI will break. This is acceptable because commits 2+3 should be reviewed together.
- Commit 3 restores frontend functionality by consuming the SSE stream.
- Commit 4 is housekeeping.

**Alternative:** Squash commits 1-3 into a single commit if the reviewer prefers a single atomic change. The split is for review clarity, not for independent deployability.

---

## Review Workload Forecast

| File | Action | Lines |
|------|--------|-------|
| `csrf_guard.php` | Edit | ~3 lines changed |
| `process_backup.php` | Rewrite | ~130 lines (from 654) |
| `admin_updater.html.twig` | Edit | ~80 lines changed (from ~160 lines of polling code) |
| `fsframework.ini` | Edit | ~1 line |
| **Total** | | **~214 lines in final form** |

**Diff character:** ~700 deletions, ~200 additions. Heavily net-negative. Most of the review is confirming that deleted code is truly replaced by the bootstrap library.

**Risk level:** Low-medium. The pattern is proven (core update + restore already use it). The main risk is the frontend `startBackup()` rewrite — the backup→core-update chaining logic must be preserved exactly.

---

## Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Frontend backup→core-update chain breaks | Low | High | Test the "Copia de seguridad + Actualizar" wizard path end-to-end |
| Keepalive not frequent enough for large tables | Low | Medium | Inline timer checks on every progress callback invocation; DB export calls callback per table |
| Orphan processes on connection close | Low | Low | SSE mode runs inline — if browser closes, PHP detects connection abort. No background worker to orphan |
| CSRF guard misses process_backup.php | None | Low | Task 1 explicitly adds it |
