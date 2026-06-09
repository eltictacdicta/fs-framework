# Design: Standalone CSRF Hardening

## Technical Approach

Remove the `CsrfManager::isValid()` fallback from `csrf_guard.php`, making direct `$_SESSION` read the sole CSRF validation path. Add `session_write_close()` after successful validation to prevent any subsequent Symfony bootstrap from corrupting `$_SESSION['_sf2_attributes']` via `PhpBridgeSessionStorage` writeback.

## Architecture Decisions

### Decision: Remove CsrfManager fallback entirely

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Keep fallback, add flag to skip Symfony path | Complexity; still risks accidental bootstrap | Rejected |
| Remove fallback, reject on direct-read failure | Simpler; matches spec "reject immediately" | **Chosen** |
| Remove fallback, add secondary session-read strategy | Over-engineering; direct read covers valid case | Rejected |

**Rationale**: The fallback exists only because the direct read wasn't proven. It's now battle-tested. The spec explicitly requires rejection without touching Symfony. No middle ground — remove lines 92-101 and let code flow to existing diagnostic logging at line 103.

### Decision: session_write_close() inside ensure_request_csrf()

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Only in process_backup.php (ensure_session_ready already has it) | SSE scripts unprotected from Symfony writeback during long ops | Rejected |
| In csrf_guard.php after successful direct read | Closes session for ALL callers; SSE scripts safe because auth is done before CSRF guard | **Chosen** |
| In process_bootstrap.php after ensure_request_csrf() | Duplicates logic; csrf_guard.php is the natural boundary | Rejected |

**Rationale**: Closing the session immediately after CSRF validation in `csrf_guard.php` protects ALL three process scripts. For SSE scripts (`process_core_update.php`, `process_restore.php`), authentication already completed before CSRF guard runs; no further session access needed. For `process_backup.php`, `ensure_session_ready()` reopens and closes again — second open/close cycle is idempotent via `system_updater_native_session_start()`.

### Decision: Diagnostic logging format

**Choice**: Remove `$diagInfo` from log format (was only populated by catch block of removed fallback). Keep `session=`, `sid=` (8-char prefix), `cookies=`, `sf2_attrs=`, `stored_token=`, `token_len=` as already implemented.

**Rationale**: Existing logging at lines 103-118 already satisfies spec requirement. After fallback removal, `$diagInfo` is always empty — remove noise. No sensitive data logged (session ID truncated, no raw tokens).

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `lib/csrf_guard.php` | Modify | Remove fallback block (lines 92-101). Add `session_write_close()` after line 88. Clean `$diagInfo` from log. |
| `process_backup.php` | None | `ensure_session_ready()` already closes session at line 404. No changes needed. |
| `process_core_update.php` | None | Protected by csrf_guard.php change. No direct changes needed. |
| `process_restore.php` | None | Protected by csrf_guard.php change. No direct changes needed. |

## Data Flow

```
Browser request (AJAX/SSE with CSRF token in GET/POST/header)
        │
        ▼
ensure_request_csrf()
        │
        ├─ Token absent → 403 "Token CSRF ausente" ✓ (unchanged)
        │
        ├─ Token present:
        │       │
        │       ▼
        │   Direct $_SESSION['_sf2_attributes']['_csrf/fs_form'] read
        │   (system_updater_csrf_read_stored_token + system_updater_csrf_verify_token)
        │       │
        │       ├─ Match → session_write_close() [NEW] → return ✓
        │       │
        │       └─ No match → error_log (diagnostics) → 403 "Token CSRF inválido"
        │                                              ~~~ NO CsrfManager fallback [NEW] ~~~
        │
        └─ Session inactive / empty → error_log → 403 "Token CSRF inválido"
                                       ~~~ NO CsrfManager fallback [NEW] ~~~
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `system_updater_csrf_read_stored_token()` with/without `_sf2_attributes` | PHPUnit test in `tests/CsrfGuardTest.php` |
| Unit | `system_updater_csrf_verify_token()` — valid token, mismatch, malformed | PHPUnit test |
| Unit | `ensure_request_csrf()` rejects when fallback removed, session inactive | PHPUnit test with `@runInSeparateProcess` |
| Integration | `process_backup.php?action=start` returns 200 with valid token | DDEV integration test (requires running app) |
| Regression | Existing `SessionAuthTest` and `ProcessBackupTest` pass | `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` |

## Migration / Rollout

No migration required. Changes are additive (`session_write_close` is idempotent) and subtractive (removing fallback). Rollback: restore lines 92-101 and remove `session_write_close()` from csrf_guard.php.
