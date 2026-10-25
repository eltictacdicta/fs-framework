# session-consolidation Specification

## Purpose

Unified session cookie name (`FSSESS_xxx`) across StealthMode, SessionManager, and standalone scripts. Eliminates dual `PHPSESSID`/`FSSESS_xxx` competition that breaks CSRF token reads in `PhpBridgeSessionStorage` flows.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| SC-01 | Core bootstrap paths that open the application session **MUST** set a session name derived from the shared session-identity resolver before `session_start()`; the installer is the sole exception, using its own `FSINSTALL_` prefix by design | MUST |
| SC-02 | StealthMode `ensurePhpSessionStarted()` **MUST** set `session_name()` before `session_start()` | MUST |
| SC-03 | `SessionManager::initialize()` **MUST** detect an already-active session with the correct name and skip re-init | MUST |
| SC-04 | A request carrying a legacy `PHPSESSID` cookie **MUST NOT** read, open or copy that foreign session's data; the framework **MUST** discard the cookie and run on its own `FSSESS_xxx` session | MUST |
| SC-05 | Responses **MUST NOT** set a live legacy `PHPSESSID` cookie for this installation; when a legacy cookie was present, the response **MUST** expire it on the paths that can belong to this install | MUST |

### Known deviation: residual bare `session_start()` fallbacks

SC-01 states the invariant that actually holds. Two pre-existing bare `session_start()` call sites remain and are **not** covered by it; they are a separate hardening follow-up, not a behaviour this change introduced:

- `controller/admin_agentes.php` — the duplicated-petition guard starts a session with no name when `session_status() !== PHP_SESSION_ACTIVE`. In practice `SessionManager` has normally already started the application session, so the branch is rarely reachable.
- `src/Security/CsrfManager.php` — the `ensureSession()` branch used only when `SessionManager` is unavailable starts a session with no name.

The installer (`base/install_security.php`) is a deliberate exception: it applies its own `FSINSTALL_` prefix on top of the same identity seed so the installer session never shares the application session.

### Scenario: StealthMode uses correct session name

- GIVEN admin accesses `?page=login&adminpanel=HASH` from normal browser
- WHEN `StealthMode::ensurePhpSessionStarted()` runs
- THEN `session_name()` is `FSSESS_xxx` (matching `SessionManager::resolveSessionName()`)
- AND `session_start()` opens a session with that name

### Scenario: SessionManager skips re-init for named session

- GIVEN a session is active with `session_name() === SessionManager::resolveSessionName()`
- WHEN `SessionManager::initialize()` is called
- THEN it does NOT call `session_start()` or `session_name()` again
- AND wraps existing session via `PhpBridgeSessionStorage`

### Scenario: Legacy PHPSESSID cookie is discarded, never merged

- GIVEN a request arrives with a `PHPSESSID` cookie belonging to another application on the same host
- WHEN the framework initializes the session
- THEN the foreign session is NOT opened and NONE of its keys are copied into `FSSESS_xxx`
- AND `PHPSESSID` is unset from `$_COOKIE` and expired in the response
- AND the request continues on the installation's own `FSSESS_xxx` session

**Security rationale (do not revert):** `SessionManager::isValid()` validates only `user_nick` + `login_time` + `last_activity`. Copying a foreign session's keys therefore allowed login injection by any other application on the same host sharing `session.save_path`. Continuity after the cookie rename is provided by the signed `user`/`logkey`/`auth_sig` cookies, which are validated against the database before any session is reconstructed. The previous data-copying migration was removed for this reason and **MUST NOT** be reintroduced.

### Scenario: CSRF token is unaffected by a stale legacy cookie

- GIVEN a login POST arrives carrying a stale `PHPSESSID` cookie (stealth flow)
- WHEN `validateCsrf()` reads the token
- THEN the token is read from the installation's own `FSSESS_xxx` session, where `StealthMode` wrote it
- AND the stale `PHPSESSID` is discarded without being read
- AND `validateCsrf()` returns true for the submitted token

The guarantee comes from token write and token read landing on the same `FSSESS_xxx` session, not from any migration of session data.

### Edge Case: Concurrent requests carrying a stale legacy cookie

- GIVEN two simultaneous requests both carry the same stale `PHPSESSID`
- WHEN each request initializes the session
- THEN neither request opens the foreign session and both run on the installation's own `FSSESS_xxx` session
- AND the legacy cookie is expired independently in each response
- AND no session data is adopted from the foreign session by either request

### Edge Case: Expired own session is recreated without touching legacy data

- GIVEN a `FSSESS_xxx` cookie exists but the session store expired it
- WHEN a request arrives with only the cookie (no live session data)
- THEN `session_start()` creates a fresh session with the same name
- AND no attempt is made to read or recover `PHPSESSID` data
