# Proposal: Fix System Updater Backup

## Intent

Two bugs prevent `process_backup.php` from completing backups:
1. **CSRF token corruption**: Symfony `SessionManager` initialization via `CsrfManager::isValid()` overwrites `$_SESSION['_sf2_attributes']`, dropping the CSRF token stored by the main framework. Affects AJAX-based backup start requests.
2. **Fatal error (FIXED, deployment gap)**: `process_bootstrap.php` loaded `session_auth.php` only inside `system_updater_process_init()`, but `process_backup.php` never calls that function — it uses `system_updater_start_authenticated_session()` from `session_auth.php` directly. Commit `ef4fafd` (v2.4.18) already fixed this at source. Verify DDEV deployment.

## Scope

### In Scope
- Harden `csrf_guard.php` to prevent `SessionManager` from corrupting session tokens (Bug 1)
- Verify DDEV is running the patched `process_bootstrap.php` with `session_auth.php` at top-level (Bug 2)
- Ensure `process_backup.php` `/action=start` completes without CSRF errors

### Out of Scope
- Changes to core OidcProvider framework or `process_core_update.php` / `process_restore.php`
- Database or backup_manager.php logic
- General session security hardening beyond the two bugs

## Capabilities

### New Capabilities
- `standalone-csrf-hardening`: Standalone scripts validate CSRF tokens via direct `$_SESSION` reads, avoiding Symfony `SessionManager` bootstrap that can corrupt session state.

### Modified Capabilities
- None

## Approach

### Bug 2 — Deployment verification
The fix (`ef4fafd`) moved `require_once 'session_auth.php'` to file top-level in `process_bootstrap.php`. Verify DDEV container syncs the updated file:
```bash
ddev restart && ddev exec ls -la plugins/system_updater/lib/process_bootstrap.php
```
No code changes needed.

### Bug 1 — CSRF hardening
`csrf_guard.php` already implements direct `$_SESSION` token read (lines 81–90) as primary path, falling back to `CsrfManager::isValid()` (lines 92–101) only if the direct path fails. The fallback is the corruption vector.

Hardening steps:
1. **Eliminate the fallback path** — if direct `$_SESSION` read fails, reject immediately (no `CsrfManager::isValid()` call)
2. **Read-and-close session** — call `session_write_close()` before any code that might bootstrap the Symfony container, preventing `PhpBridgeSessionStorage` writeback
3. **Guard `ensure_session_ready()`** — in `process_backup.php`, close the session (`session_write_close()`) immediately after authentication and CSRF check, before `respond_and_continue()` triggers background work

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `lib/csrf_guard.php` | Modified | Remove CsrfManager fallback; add session_write_close guard |
| `process_backup.php` | Modified | Add session_write_close() after ensure_session_ready() in `/action=start` handler |
| `lib/process_bootstrap.php` | None (verify) | Top-level session_auth.php require is already committed |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Direct `$_SESSION` read fails for valid tokens | Low | The direct read path is already battle-tested in production; it reads the same `_csrf/fs_form` key that the main framework writes |
| Removing `CsrfManager` fallback breaks edge cases | Low | The fallback only triggers when direct read fails — which means the token is genuinely absent or corrupted |
| DDEV cache serves old `process_bootstrap.php` | Medium | `ddev restart` clears opcache; verify file content on next `/action=start` |

## Rollback Plan

- Revert the `csrf_guard.php` change to restore `CsrfManager::isValid()` fallback if edge cases emerge
- The `session_write_close()` additions are idempotent — no-op if session already closed
- Bug 2 fix is already reversible via git revert of `ef4fafd`

## Dependencies

- None. Plugin is standalone — no core framework or other plugin changes required.

## Success Criteria

- [ ] `process_backup.php?action=start` returns 200 with valid CSRF token from admin dashboard
- [ ] Backup job completes without "sesión no válida" or "CSRF inválido" errors
- [ ] DDEV container loads patched `process_bootstrap.php` (line 19: `require_once __DIR__ . '/session_auth.php';` is outside any function)
- [ ] Existing PHPUnit tests pass (`ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`)
