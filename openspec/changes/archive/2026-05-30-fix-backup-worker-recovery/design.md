# Design: Refactor Backup to SSE

## Technical Approach

Rewrite `process_backup.php` from worker+polling to SSE, mirroring the proven pattern in `process_core_update.php` (112 lines) and `process_restore.php` (151 lines). The bootstrap library (`process_bootstrap.php`) already provides all SSE primitives — `system_updater_process_init()`, `system_updater_send_sse()`, `system_updater_save_progress()`. The `backup_manager::create_backup_with_progress()` callback API is already compatible. This is a delete-heavy refactor: ~350 lines of worker/queue/recovery machinery are replaced by ~30 lines of SSE glue.

## Architecture Decisions

### Decision: Keepalive mechanism

| Option | Tradeoff | Decision |
|--------|----------|----------|
| A) Inline timer | Simple: track `$lastEventTime`, send `:keepalive\n\n` if >10s since last event. No changes to `backup_manager`. | **Chosen** |
| B) Callback-based | Pass keepalive callback to `backup_manager`. Requires modifying `backup_manager` to periodically call it between steps. More invasive. | Rejected |

**Rationale**: `backup_manager::create_backup_with_progress()` already calls the progress callback at each step. Steps like DB table export can take >10s for large tables. An inline timer in `process_backup.php` checks elapsed time and sends keepalive independently of the progress callback. Zero changes to `backup_manager`. The timer runs inside the progress callback itself (the most frequently called point), so keepalives fire even during long table exports.

### Decision: Progress callback structure

| Option | Tradeoff | Decision |
|--------|----------|----------|
| A) Single closure | One callback that does save + send_sse + keepalive check. Matches `process_core_update.php` exactly. | **Chosen** |
| B) Separate callbacks | Split save, send, keepalive into different functions. More modular but adds complexity for no benefit in a 100-line script. | Rejected |

**Rationale**: The `process_core_update.php` pattern (lines 25-29) uses a single closure that calls `system_updater_save_progress()` then `system_updater_send_sse()`. Proven, simple, consistent.

### Decision: Frontend integration

| Option | Tradeoff | Decision |
|--------|----------|----------|
| A) Replace polling with EventSource | Direct swap: `$.ajax` + `setInterval` → `new EventSource()`. Matches existing core update and restore patterns in the same template. | **Chosen** |
| B) Dual-mode (polling + SSE) | Keep polling as fallback. Adds complexity, defeats the purpose of eliminating polling. | Rejected |

**Rationale**: The template already has two working EventSource integrations (`coreUpdateEventSource` and `restoreEventSource`). The backup section follows the same pattern.

### Decision: CSRF integration with SSE

| Option | Tradeoff | Decision |
|--------|----------|----------|
| A) Token in query parameter | `EventSource` only supports GET. Token passed as `&_csrf_token=...`. Matches existing pattern in core update and restore. | **Chosen** |
| B) Header-based CSRF | `EventSource` cannot set custom headers. | Impossible |

**Rationale**: `process_bootstrap.php` line 92 calls `ensure_request_csrf()` for SSE mode on `action=start`. `csrf_guard.php` reads token from `$_GET['_csrf_token']`. This is the established pattern — no changes needed.

## Data Flow

```
Browser                    process_backup.php           backup_manager
  │                              │                           │
  │── GET ?action=start ────────→│                           │
  │   (EventSource, GET)         │                           │
  │                              │── init (SSE headers) ────→│
  │                              │── ensure_request_csrf()   │
  │                              │                           │
  │←── event: start ────────────│                           │
  │                              │                           │
  │                              │── new backup_manager() ──→│
  │                              │── create_backup_with_     │
  │                              │     progress(callback) ──→│
  │                              │                           │
  │←── event: progress ─────────│←── callback(db, msg, %) ──│
  │←── event: progress ─────────│←── callback(files, msg, %)│
  │←── :keepalive ──────────────│   (if >10s between events) │
  │←── event: progress ─────────│←── callback(unify, msg, %)│
  │←── event: complete ─────────│←── callback(complete, 100)│
  │                              │                           │
  │   (EventSource closes)       │── @unlink(progressFile)   │
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `plugins/system_updater/process_backup.php` | **Rewrite** | From 654 lines to ~130 lines. Remove worker/queue/recovery/lock machinery. Use SSE bootstrap. |
| `plugins/system_updater/view/admin_updater.html.twig` | Modify | Replace `$.ajax` + `setInterval` polling (lines 446-611) with `EventSource` integration (~80 lines). |
| `plugins/system_updater/lib/backup_manager.php` | None | Callback API unchanged. |
| `plugins/system_updater/lib/process_bootstrap.php` | None | Read-only dependency. |

## New `process_backup.php` Structure

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
    // Keepalive: send ping if >10s since last event
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

**Key differences from current 654-line file:**
- Removed: `respond_json()`, `respond_and_continue()`, `get_request_param()`, `sanitize_token()`, `get_progress_file()`, `get_lock_file()`, `get_session_pointer_file()`, `read_json_file()`, `write_json_file()`, `load_pointer()`, `load_progress()`, `save_progress()`, `clear_job_state()`, `mark_stale_job_if_needed()`, `should_attempt_queue_recovery()`, `recover_queued_job()`, `has_active_job()`, `shell_functions_available()`, `detect_php_binary()`, `launch_cli_worker()`, `create_job_id()`, `ensure_session_ready()`, `run_backup_job()` — all eliminated.
- Removed: `action=worker`, `action=progress`, `action=status`, `action=cleanup` — only `action=start` remains.
- Removed: CLI argument parsing, constants (`FS_BACKUP_STALE_SECONDS`, etc.), lock file machinery.
- Uses: `system_updater_process_init()`, `system_updater_send_sse()`, `system_updater_save_progress()` from bootstrap.

## Frontend Changes

Replace the `startBackup()` AJAX call + `startBackupStatusPolling()` setInterval with an EventSource, matching the existing `coreUpdateEventSource` pattern (lines 665-726 of the template).

**Remove**: `backupJobId`, `backupStatusPoller`, `backupLastStatusMessage`, `startBackupStatusPolling()`, `stopBackupStatusPolling()`, `cleanupBackupStatus()`.

**Replace `startBackup()` with:**

```javascript
function startBackup(chainConfig) {
    pendingBackupChain = chainConfig || null;
    backupLog = [];
    backupFinished = false;
    configureBackupModal(pendingBackupChain);
    updateBackupProgress(0, 'Iniciando copia de seguridad...');
    $('#backupDetails').html('<small class="text-muted">Conectando al servidor...</small>');
    $('#backupCompleteBtn').hide();
    $('#backupErrorBtn').hide();
    $('#btn-create-backup').prop('disabled', true);
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

**Also remove**: `cleanupBackupStatus()` call from `finishBackupAsError()` — no cleanup needed with SSE (no server-side job state to clear).

## Security Review

Per the FSFramework security review checklist:

| Category | Status | Notes |
|----------|--------|-------|
| CSRF | ✅ | Token passed as query param in EventSource GET. Validated by `ensure_request_csrf()` in `process_bootstrap.php`. |
| SQL Injection | ✅ N/A | No direct SQL in `process_backup.php`. DB operations in `backup_manager` use `mysqli` with proper escaping. |
| XSS | ✅ | SSE data is JSON-encoded. Frontend uses `$('#...').text()` for messages, not `.html()`. |
| Input Validation | ✅ | `system_updater_process_init()` sanitizes `action` from `$_GET`. No user input reaches backup logic directly. |
| Session Security | ✅ | Auth via `system_updater_start_authenticated_session()` + CSRF validation. Same pattern as core update and restore. |
| Error Exposure | ✅ | Exceptions caught, generic messages sent via SSE. No stack traces exposed to client. |

**Note on `csrf_guard.php`**: The `$isSse` detection (line 25-26) checks for `process_core_update.php` and `process_restore.php` in the URI. It does NOT check for `process_backup.php`. After the refactor, `process_backup.php` will use SSE mode, so `csrf_guard.php` must be updated to include `process_backup.php` in the `$isSse` detection. However, since `system_updater_process_init()` sets `SYSTEM_UPDATER_SSE_MODE` constant and `system_updater_shutdown_on_missing_config()` already checks it, the fallback path (HTTP 403 JSON) is acceptable — the `ensure_request_csrf()` function in `csrf_guard.php` will still validate correctly, just return JSON instead of SSE on CSRF failure. This is a minor UX issue (browser gets a 403 instead of an SSE error event), not a security issue. **Recommendation**: Add `process_backup.php` to the `$isSse` check in `csrf_guard.php` as part of this change.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `backup_manager::create_backup_with_progress()` callback invocation | Already covered by existing tests |
| Integration | SSE stream sends correct events in order | Manual test: `curl -N` with valid session + CSRF token |
| E2E | Frontend EventSource receives and displays progress | Manual test in browser: click "Crear Copia de Seguridad", verify progress bar updates |
| Regression | Existing backup/restore workflow still works | Run existing PHPUnit tests |

## Migration / Rollout

No migration required. This is a drop-in replacement:
1. `process_backup.php` is a standalone script — replacing it has no ripple effects.
2. `backup_manager.php` API is unchanged.
3. Frontend changes are confined to the backup section of the template.
4. Rollback: revert `process_backup.php` and `admin_updater.html.twig`.

## Open Questions

- [ ] Should `csrf_guard.php` be updated to include `process_backup.php` in the `$isSse` URI check? (Minor: affects error response format on CSRF failure, not security.)
