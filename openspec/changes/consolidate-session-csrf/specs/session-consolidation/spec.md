# session-consolidation Specification

## Purpose

Unified session cookie name (`FSSESS_xxx`) across StealthMode, SessionManager, and standalone scripts. Eliminates dual `PHPSESSID`/`FSSESS_xxx` competition that breaks CSRF token reads in `PhpBridgeSessionStorage` flows.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| SC-01 | All `session_start()` sites **MUST** call `session_name(SessionManager::resolveSessionName())` first | MUST |
| SC-02 | StealthMode `ensurePhpSessionStarted()` **MUST** set `session_name()` before `session_start()` | MUST |
| SC-03 | `SessionManager::initialize()` **MUST** detect an already-active session with the correct name and skip re-init | MUST |
| SC-04 | Request with legacy `PHPSESSID` cookie **MUST** copy `$_SESSION` to `FSSESS_xxx` and expire the old cookie | MUST |
| SC-05 | Responses **MUST** only set `FSSESS_xxx` cookie; `PHPSESSID` absent after transition | MUST |

### Scenario: StealthMode uses correct session name

- GIVEN admin accesses `?page=login&adminpanel=HASH` from normal browser
- WHEN `StealthMode::ensurePhpSessionStarted()` runs
- THEN `session_name()` is `FSSESS_xxxx` (matching `SessionManager::resolveSessionName()`)
- AND `session_start()` opens a session with that name

### Scenario: SessionManager skips re-init for named session

- GIVEN a session is active with `session_name() === SessionManager::resolveSessionName()`
- WHEN `SessionManager::initialize()` is called
- THEN it does NOT call `session_start()` or `session_name()` again
- AND wraps existing session via `PhpBridgeSessionStorage`

### Scenario: Legacy PHPSESSID cookie migration

- GIVEN a request arrives with `PHPSESSID` cookie and active session data
- WHEN the cookie does NOT match `FSSESS_xxx`
- THEN `$_SESSION` contents are copied to a new `FSSESS_xxx` session
- AND response includes `Set-Cookie: PHPSESSID=deleted; Max-Age=0; path=/`

### Scenario: CSRF token survives session migration

- GIVEN a login POST arrives with `PHPSESSID` cookie (stealth flow)
- WHEN session is migrated to `FSSESS_xxx` per SC-04
- THEN CSRF token stored in session bag persists through migration
- AND `validateCsrf()` returns true for the submitted token

### Edge Case: Concurrent requests during migration

- GIVEN two simultaneous requests with the same `PHPSESSID`
- WHEN the first request migrates to `FSSESS_xxx`
- THEN the second request either uses the new cookie (if set) or starts fresh migration
- AND no `$_SESSION` data is lost in the race

### Edge Case: Expired session after migration

- GIVEN a `FSSESS_xxx` cookie exists but the session store expired it
- WHEN a request arrives with only the cookie (no live session data)
- THEN `session_start()` creates a fresh session with the same name
- AND no attempt is made to recover `PHPSESSID` data
