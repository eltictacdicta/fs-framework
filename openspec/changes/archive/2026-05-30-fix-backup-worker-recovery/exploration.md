# Exploration: Refactor process_backup.php to SSE

## Current State

`process_backup.php` uses a **worker + polling** architecture:
1. Frontend sends `action=start` via AJAX → receives `job_id`
2. Server launches a CLI worker (`nohup php process_backup.php action=worker ...`)
3. Worker writes progress to a JSON file in `/tmp/`
4. Frontend polls `action=status` every 2 seconds via `setInterval`
5. Recovery logic: if worker dies, `recover_queued_job()` re-launches from the polling request

The other scripts (`process_core_update.php`, `process_restore.php`) use **SSE (Server-Sent Events)**:
1. Frontend opens `EventSource` connection to `?action=start`
2. Server runs the operation inline, sending `event: progress\n` streams
3. No job files, no workers, no polling, no recovery logic needed

## SSE Pattern (from process_core_update.php and process_restore.php)

### Code Template

```php
<?php
require_once __DIR__ . '/lib/process_bootstrap.php';
$ctx = system_updater_process_init(['mode' => 'sse', 'progress_prefix' => 'fs_backup']);
$sessionId = $ctx['session_id'];
$action = $ctx['action'];
$progressFile = $ctx['progress_file'];

require_once __DIR__ . '/lib/backup_manager.php';

$progressCallback = function ($step, $message, $percent) use ($progressFile) {
    $data = system_updater_save_progress($progressFile, $step, $message, $percent);
    system_updater_send_sse('progress', $data);
    usleep(10000);
};

switch ($action) {
    case 'start':
        system_updater_send_sse('start', ['message' => 'Iniciando backup...', 'percent' => 0]);
        system_updater_save_progress($progressFile, 'init', 'Inicializando...', 0);

        try {
            $backupManager = new backup_manager(FS_FOLDER);
            system_updater_send_sse('init', ['message' => 'Verificando entorno...', 'percent' => 2]);

            $result = $backupManager->create_backup_with_progress('', true, $progressCallback);

            if (isset($result['complete']) && !empty($result['complete']['success'])) {
                system_updater_save_progress($progressFile, 'complete', '¡Backup completado!', 100);
                system_updater_send_sse('complete', [
                    'message' => '¡Copia de seguridad creada con éxito!',
                    'percent' => 100,
                    'backup_name' => $result['complete']['backup_name'] ?? '',
                    'redirect' => 'index.php?page=admin_updater&success=backup',
                ]);
            } else {
                $errors = $backupManager->get_errors();
                $errorMsg = !empty($errors) ? implode('; ', $errors) : 'Error desconocido';
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

    case 'progress':
        if (file_exists($progressFile)) {
            $data = json_decode((string) file_get_contents($progressFile), true);
            system_updater_send_sse('progress', $data);
        } else {
            system_updater_send_sse('progress', ['step' => 'waiting', 'message' => 'Esperando inicio...', 'percent' => 0]);
        }
        break;

    case 'status':
        if (file_exists($progressFile)) {
            $data = json_decode((string) file_get_contents($progressFile), true);
            $isAlive = (time() - ($data['timestamp'] ?? 0)) < 120;
            system_updater_send_sse('status', ['active' => $isAlive, 'data' => $data]);
        } else {
            system_updater_send_sse('status', ['active' => false, 'data' => null]);
        }
        break;

    default:
        system_updater_send_sse('error', ['message' => 'Acción no válida: ' . $action, 'percent' => 0]);
        break;
}
exit;
```

### Key differences from restore/core_update
- Backup does NOT use `system_updater_begin_maintenance()` / `system_updater_end_maintenance()` (no maintenance mode needed for backup)
- Backup returns a nested `$result` structure with `['complete']['success']` instead of flat `$result['success']`

## Frontend Handling

### Current backup frontend (polling-based)
- `startBackup()` → AJAX `GET process_backup.php?action=start` → gets `job_id`
- `startBackupStatusPolling(jobId)` → `setInterval` every 2s → AJAX `GET process_backup.php?action=status`
- Listens for `data.status`: `queued`, `running`, `complete`, `error`
- On complete: `cleanupBackupStatus()` calls `action=cleanup`

### Core update / restore frontend (SSE-based)
- Opens `new EventSource(url)` with `action=start`
- Listens for named events: `start`, `init`, `progress`, `complete`, `error`
- Each event handler: `JSON.parse(e.data)`, update UI
- On `complete`/`error`: `eventSource.close()`

### Migration required in frontend
Replace the polling functions with EventSource pattern matching the restore/core_update template.

## What backup_manager Needs

`backup_manager::create_backup_with_progress()` signature:
```php
public function create_backup_with_progress(
    string $customName = '',
    bool $includePlugins = true,
    callable $progressCallback = null
): array
```

The callback signature: `function($step, $message, $percent)`

Progress steps reported: `init`, `database_start`, `db_connect`, `db_header`, `db_tables`, `db_table`, `db_footer`, `db_complete`, `files_start`, `files_scan`, `files_count`, `files_progress`, `files_close`, `files_complete`, `unify`, `cleanup`, `complete`

This is fully compatible with the SSE callback pattern — no changes needed to `backup_manager.php`.

## What Can Be Eliminated from process_backup.php

| Component | Reason |
|-----------|--------|
| `FS_BACKUP_STALE_SECONDS`, `FS_BACKUP_QUEUE_RECOVERY_SECONDS`, `FS_BACKUP_MAX_RECOVERY_ATTEMPTS` | Worker recovery constants — no workers in SSE |
| `respond_json()`, `respond_and_continue()`, `respond_json_encode()` | JSON response helpers — SSE uses `system_updater_send_sse()` |
| `get_progress_file()`, `get_lock_file()`, `get_session_pointer_file()` | Job file management — SSE uses session-bound temp file |
| `read_json_file()`, `write_json_file()` | Job persistence — SSE progress file is transient |
| `load_pointer()`, `load_progress()`, `save_progress()` (custom) | Custom progress with job status — SSE uses `system_updater_save_progress()` |
| `clear_job_state()` | Cleanup of job files — SSE deletes progress file on exit |
| `mark_stale_job_if_needed()` | Stale worker detection — no workers |
| `should_attempt_queue_recovery()`, `recover_queued_job()` | Queue recovery — no queue |
| `has_active_job()` | Concurrent job detection — SSE is inherently single-connection |
| `shell_functions_available()`, `detect_php_binary()` | CLI worker launch helpers |
| `launch_cli_worker()` | Worker process spawning |
| `create_job_id()` | Job ID generation |
| `ensure_session_ready()` | Session management — SSE uses `system_updater_process_init()` |
| `run_backup_job()` | The entire worker execution function |

**Estimated lines to remove: ~350 of 654 lines (54%)**

## Migration Plan

### What to keep
- `FS_FOLDER` definition
- `require_once __DIR__ . '/lib/process_bootstrap.php'`
- `require_once __DIR__ . '/lib/backup_manager.php'` (lazy load)
- The `action=start` case: CSRF validation, session auth, backup execution, error handling
- The `action=progress` and `action=status` cases (for polling fallback)

### What to remove
- All worker/CLI/nohup machinery
- All custom JSON file helpers (use `system_updater_save_progress()` instead)
- All job queue/recovery logic
- All custom session management (use `system_updater_process_init()`)
- All custom response helpers (use `system_updater_send_sse()`)

### What to rewrite
1. **Bootstrap**: Replace manual FS_FOLDER/config.php/session with `system_updater_process_init(['mode' => 'sse', 'progress_prefix' => 'fs_backup'])`
2. **action=start**: Run backup inline (not via worker), use `$progressCallback` that calls `system_updater_save_progress()` + `system_updater_send_sse('progress', ...)`
3. **action=progress/status**: Use `system_updater_send_sse()` instead of `respond_json()`
4. **Frontend**: Replace polling functions with EventSource pattern

### What to keep for backward compatibility
- `action=cleanup` endpoint (frontend calls it on complete)
- The `SYSTEM_UPDATER_PROCESS_BACKUP_BOOTSTRAP_ONLY` constant (used by other code that includes this file)

## Risks

1. **Timeout on large backups**: SSE connection stays open during the entire backup. If the backup takes >300s, the web server or reverse proxy may timeout. The worker approach avoids this by decoupling from HTTP.
   - Mitigation: `set_time_limit(0)` and `ignore_user_abort(true)` are already set by `system_updater_process_init()`. Need to verify nginx/fastcgi timeout settings.

2. **Browser disconnect**: If the user closes the tab, `ignore_user_abort(true)` keeps the script running, but the progress events go nowhere. The user loses visibility.
   - Mitigation: The `action=progress` polling endpoint can serve as fallback for reconnection.

3. **Concurrent backups**: SSE approach doesn't have the job lock mechanism. Two SSE connections could start two backups simultaneously.
   - Mitigation: Add a lockfile check at the start of `action=start`, similar to `has_active_job()` but simpler.

## Recommendation

**Proceed with SSE migration.** The worker+polling approach adds ~350 lines of complexity for a problem (HTTP timeout) that `system_updater_process_init()` already solves with `set_time_limit(0)` and `ignore_user_abort(true)`. The other two scripts prove the SSE pattern works reliably for long-running operations.

Keep a polling fallback via `action=progress` for resilience, but eliminate the worker/job/queue machinery.

## Ready for Proposal
Yes — the exploration is complete. The SSE pattern is well-understood, the frontend needs a focused rewrite, and `backup_manager` needs zero changes.
