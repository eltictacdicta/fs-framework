# Proposal: Consolidate Session & CSRF Handling

## Intent

Eliminate dual session cookies (`PHPSESSID` from StealthMode vs `FSSESS_xxx` from SessionManager) causing "Token de seguridad inválido" when accessing admin via stealth mode from a normal browser. StealthMode starts session without `session_name()`, creating a competing cookie. When `PhpBridgeSessionStorage` wraps the wrong session, `migrate(true)` may lose CSRF tokens.

## Scope

### In Scope
- StealthMode `ensurePhpSessionStarted()` sets `session_name()` matching `SessionManager::resolveSessionName()` before `session_start()`
- CSRF token survives `session->migrate(true)` in unified session path
- Bootstrap audit: all `session_start()` call sites set session name first
- Legacy `PHPSESSID` detection and migration to `FSSESS_xxx`

### Out of Scope
- Full auth redesign, legacy cookie refactor (`user`/`logkey`/`auth_sig`)
- Standalone script sessions (covered by `standalone-csrf-hardening`)

## Capabilities

### New Capabilities
- `session-consolidation`: Single session name across StealthMode, SessionManager, and standalone scripts; `PhpBridgeSessionStorage` paths verified for CSRF integrity

### Modified Capabilities
- `session-integrity`: SI-01 updated with explicit `PhpBridgeSessionStorage` sync contract after `migrate(true)`
- `login-csrf`: LC-02 verified under unified session path

## Approach

1. **StealthMode**: Call `session_name(SessionManager::resolveSessionName())` before `session_start()` in `ensurePhpSessionStarted()`
2. **CsrfManager**: After `ensureSession()` wraps via `PhpBridgeSessionStorage`, verify token exists in bag; refresh if absent
3. **SessionManager**: Gate `initialize()` to detect correctly-named active session (skip re-init)
4. **Transition**: Detect legacy `PHPSESSID` → copy `$_SESSION` to `FSSESS_xxx` → expire old cookie with `Set-Cookie: PHPSESSID=deleted; Max-Age=0`

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `src/Core/StealthMode.php:470-475` | Modified | Add `session_name()` before `session_start()` |
| `src/Security/SessionManager.php:82-131,133-143` | Modified | Make `resolveSessionName()` public; gate `initialize()` for active named sessions |
| `src/Security/CsrfManager.php:129-145` | Modified | Token presence guard after bridge wrap |
| `base/fs_login.php:512-536` | Verify | Confirm CSRF refresh post-migration works |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Legacy session data lost on transition | Medium | Copy `$_SESSION` contents before expiring `PHPSESSID` |
| `session_name()` call order breaks other flows | Low | Audit all `session_start()` sites; guard in `SessionManager` |
| Token refresh on every `getManager()` call | Low | Cache check result per request |

## Rollback Plan

Revert `StealthMode` change, revert `CsrfManager` guard. Restore `FS_CSRF_SOFT=true` during rollback window. Each change atomic — no cascade on revert.

## Dependencies

- `symfony/security-csrf` ^7.4 (already in use)
- `standalone-csrf-hardening` (fix-system-updater-backup) — must not break direct `$_SESSION` CSRF reads

## Success Criteria

- [ ] `ensurePhpSessionStarted()` sets session name matching `SessionManager::resolveSessionName()` before `session_start()`
- [ ] Stealth admin access (`?page=login&adminpanel=HASH`) from normal browser — no CSRF error
- [ ] Only `FSSESS_xxx` cookie set on responses; `PHPSESSID` absent after transition
- [ ] `ddev exec php vendor/bin/phpunit` — 436 tests pass
- [ ] Playwright E2E tests in `scripts-onlogin/` pass
