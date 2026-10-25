# Design: Consolidate Session & CSRF Handling

## Technical Approach

Three-phase change: (1) StealthMode sets `session_name()` matching `SessionManager::resolveSessionName()` before `session_start()`, ending dual-cookie competition. (2) `SessionManager::initialize()` gates active-session detection on name match and handles legacy `PHPSESSID` migration. (3) `CsrfManager::ensureSession()` guards token presence after `PhpBridgeSessionStorage` wraps — refreshing if `migrate(true)` cleared it.

## Architecture Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| session_name set location | `StealthMode::ensurePhpSessionStarted()` directly | StealthMode runs first in bootstrap (index.php:100-108). No new bootstrap layer needed. Dependency on `SessionManager::resolveSessionName()` is one method call. |
| resolveSessionName visibility | `public static` | Currently `private static`. Must be callable from `StealthMode`. This is the only visibility change needed. |
| Named-session gate in SessionManager | `session_status() === PHP_SESSION_ACTIVE && session_name() === self::resolveSessionName()` | Direct check avoids double-init. Session with mismatched name (legacy PHPSESSID) enters migration path. |
| PHPSESSID migration location | `SessionManager::initialize()` pre-step | Centralizes all session bootstrap. Runs before CsrfManager reads tokens. StealthMode no longer opens legacy sessions. |
| Token presence guard | `CsrfManager::ensureSession()` after session acquisition | Single enforcement point. Per-request cache via `static $tokenVerified` prevents redundant checks. |
| PhpBridge sync contract | CsrfManager guard serves as the contract | No hook needed in `fs_login::save_session_data()`. Guard fires on next `getManager()` after any `migrate(true)`. |

## Bootstrap Flow

```
Request arrives
  │
  ▼
PublicAccessGate::intercept()
  ├── hasAuthenticatedSession()
  │     └── ensurePhpSessionStarted()    ◄── session_name('FSSESS_xxx') NOW SET
  │           └── session_start()         ◄── opens FSSESS_xxx, NOT PHPSESSID
  │
  ▼
fs_controller::pre_private_core()
  └── SessionManager::getInstance()
        └── initialize()
              ├── [PHPSESSID cookie?] migrateLegacyPhpSession()
              ├── [session active + name match?] wrap with PhpBridgeSessionStorage
              └── [else] NativeSessionStorage with FSSESS_xxx options
```

## PhpBridgeSessionStorage Sync Contract

| Property | Guarantee |
|----------|-----------|
| After `migrate(true)` | `CsrfManager::getManager()` verifies `_csrf/fs_form` exists in session bag |
| Token absent? | `refreshToken()` called, new token persisted to `$_SESSION` |
| Per-request | Check cached; subsequent `getManager()` calls return same instance |
| Both backends | Same guard runs whether session was NativeSessionStorage or PhpBridgeSessionStorage |

## Migration Path (Legacy PHPSESSID → FSSESS_xxx)

1. Detect `$_COOKIE['PHPSESSID']` exists AND session not yet active
2. Open legacy session under `PHPSESSID` name, save `$_SESSION` snapshot
3. Close legacy session (`session_write_close()`)
4. Open unified session under `FSSESS_xxx`
5. Merge saved data into `$_SESSION`
6. Issue `setcookie('PHPSESSID', '', time()-3600, '/')` — expires client-side
7. `$_SESSION` flagged `_migrated_from_phpsessid = true` for logging

**Concurrent requests**: second request either finds `FSSESS_xxx` already set (uses it) or migrates independently. No data loss — legacy session read is read-only.

**Expired session after migration**: `session_start()` creates fresh `FSSESS_xxx`. No PHPSESSID recovery attempted — data was already copied.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/Core/StealthMode.php:470-475` | Modify | `ensurePhpSessionStarted()` calls `session_name(SessionManager::resolveSessionName())` before `session_start()` |
| `src/Security/SessionManager.php:82-131` | Modify | Make `resolveSessionName()` public; add name-match gate in `initialize()`; add `migrateLegacyPhpSession()` method |
| `src/Security/CsrfManager.php:129-145` | Modify | Add token-presence guard in `ensureSession()`; add `$tokenVerified` static cache |
| `tests/Components/StealthModeTest.php` | Modify | Add test verifying `session_name()` matches after `ensurePhpSessionStarted()` |
| `tests/Security/CsrfManagerTest.php` | Modify | Add test for token-presence guard with PhpBridgeSessionStorage |
| `tests/Security/SessionManagerTest.php` | Modify | Add test for named-session gate and PHPSESSID migration |

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `StealthMode::ensurePhpSessionStarted()` sets session_name | Mock `SessionManager::resolveSessionName()`, assert `session_name()` after call |
| Unit | `SessionManager::initialize()` skips re-init on named session | Set up active `FSSESS_xxx` session, call `initialize()`, assert no second `session_start()` |
| Unit | `SessionManager::migrateLegacyPhpSession()` copies data | Simulate PHPSESSID cookie, assert `$_SESSION` data transferred to `FSSESS_xxx` |
| Unit | `CsrfManager::ensureSession()` refreshes token when absent | Simulate `$_SESSION` without `_csrf/fs_form`, assert token generated |
| Unit | `CsrfManager::getManager()` caches per-request | Call twice, assert `$tokenVerified` skips second check |
| Integration | StealthMode + SessionManager bootstrap | Full request simulation: stealth access + login flow, assert no CSRF error |
| Integration | CSRF token survives migrate(true) | Simulate login→migrate→new page, assert form token valid |
| E2E | Stealth admin panel access from normal browser | Playwright: `OidcProvider` flow, assert no "Token de seguridad inválido" |

## Rollback Plan

Per-component, atomic revert:

1. **StealthMode**: Remove `session_name()` call from `ensurePhpSessionStarted()` — restores PHPSESSID
2. **CsrfManager**: Remove token-presence guard block — restores pre-guard behavior
3. **SessionManager**: Restore `private` visibility on `resolveSessionName()`; remove name-match gate and migration method
4. **Transition**: Set `FS_CSRF_SOFT=true` during rollback window to prevent user-facing errors

## Open Questions

- None.
