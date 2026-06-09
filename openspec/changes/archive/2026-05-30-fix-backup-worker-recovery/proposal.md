# Proposal: Refactor Backup to SSE

## Intent

Refactor `process_backup.php` from worker+polling to SSE (Server-Sent Events), matching the proven pattern used by `process_core_update.php` and `process_restore.php`. This eliminates ~350 lines of complex worker/recovery/queue machinery.

## Motivation

The current worker-based approach has two interrelated bugs:
1. **Race condition**: Worker CLI bootstrap takes >8s but `FS_BACKUP_QUEUE_RECOVERY_SECONDS = 8`. Recovery triggers before worker sets status to `"running"`.
2. **Synchronous recovery blocks HTTP**: `recover_queued_job()` runs backup synchronously in the polling request, browser times out.

Both bugs stem from the worker+polling architecture. Instead of patching, refactor to SSE — the same pattern already proven in two other process scripts.

## Scope

### In Scope
- Rewrite `process_backup.php` to use `system_updater_process_init(['mode' => 'sse'])` 
- Update frontend (`admin_updater.html.twig`) to use EventSource instead of polling
- Add keepalive pings to prevent nginx/fastcgi timeout
- Remove worker, queue, recovery, lock, and polling machinery

### Out of Scope
- Changes to `backup_manager.php` (callback API is already compatible)
- Changes to `process_bootstrap.php` or `session_auth.php`
- Changes to `process_core_update.php` or `process_restore.php`
- Database schema changes

## Capabilities

### Removed Capabilities
- Worker CLI background execution (replaced by inline SSE)
- Job queue with file-based state in `/tmp/`
- Recovery mechanism for dead workers
- Lock file concurrency control

### Modified Capabilities
- Backup progress reporting: now via SSE instead of JSON polling

## Approach

### Backend (`process_backup.php`)
1. Remove all worker/queue/recovery/lock machinery (~350 lines)
2. Use `system_updater_process_init(['mode' => 'sse', 'progress_prefix' => 'fs_backup'])` for bootstrap
3. Use `system_updater_send_sse()` for progress events
4. Use `system_updater_save_progress()` for progress file (optional, for status endpoint)
5. Add keepalive ping every ~10 seconds during long operations
6. Keep `action=start` as the main entry, remove `action=worker`, `action=progress`, `action=cleanup`

### Frontend (`admin_updater.html.twig`)
1. Replace `setInterval` polling with `EventSource` 
2. Listen for `start`, `progress`, `complete`, `error` events
3. Update progress bar from event data
4. Handle connection close/error gracefully

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `process_backup.php` | **Rewritten** | From ~654 lines to ~300 lines |
| `view/admin_updater.html.twig` | Modified | Polling → EventSource |
| `lib/backup_manager.php` | None | Callback API unchanged |
| `lib/process_bootstrap.php` | None | Read-only dependency |
| `lib/csrf_guard.php` | None | Already fixed |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| nginx/fastcgi timeout kills long backup | Medium | Keepalive pings every 10s; `set_time_limit(0)` already set by `system_updater_process_init()` |
| Browser closes during backup → backup dies | Low | Acceptable tradeoff; user can re-run. Backup takes 10-60s typically |
| Frontend JS breaks on older browsers | Very Low | EventSource is supported in all modern browsers |

## Rollback Plan

Revert `process_backup.php` and `admin_updater.html.twig` to previous versions. No schema or external state changes.

## Dependencies

None. Standalone plugin.

## Success Criteria

- [ ] `process_backup.php?action=start` streams progress via SSE
- [ ] Frontend shows real-time progress bar via EventSource
- [ ] Backup completes without "Error de conexión con el servidor"
- [ ] No files created in `/tmp/` during backup (except temp backup output)
- [ ] Existing PHPUnit tests pass
- [ ] File reduced from ~654 to ~300 lines
