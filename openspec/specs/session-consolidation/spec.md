# session-consolidation Specification

## Purpose

Unified session cookie name (`FSSESS_xxx`) across StealthMode, SessionManager, and standalone scripts. Eliminates dual `PHPSESSID`/`FSSESS_xxx` competition that breaks CSRF token reads in `PhpBridgeSessionStorage` flows.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| SC-01 | Core bootstrap paths that open the application session **MUST** set a session name derived from the shared session-identity resolver before `session_start()`; the installer is the sole exception, using its own `FSINSTALL_` prefix by design. The invariant **MUST** hold with no residual deviation: outside the installer exception, no core path may start an unnamed session | MUST |
| SC-02 | StealthMode `ensurePhpSessionStarted()` **MUST** set `session_name()` before `session_start()` | MUST |
| SC-03 | `SessionManager::initialize()` **MUST** detect an already-active session with the correct name and skip re-init | MUST |
| SC-04 | A request carrying a legacy `PHPSESSID` cookie **MUST NOT** read, open or copy that foreign session's data; the framework **MUST** discard the cookie and run on its own `FSSESS_xxx` session | MUST |
| SC-05 | Responses **MUST NOT** set a live legacy `PHPSESSID` cookie for this installation; when a legacy cookie was present, the response **MUST** expire it on the paths that can belong to this install | MUST |

### Requirement: SC-01 — Shared session name before session_start()

Core bootstrap paths that open the application session **MUST** set a session name derived
from the shared session-identity resolver before `session_start()`; the installer is the
sole exception, using its own `FSINSTALL_` prefix by design. This invariant **MUST** hold
with no residual deviation: outside the installer exception, no core path may start an
unnamed session.

The two call sites that were previously recorded as a deviation — the duplicated-petition
guard in `controller/admin_agentes.php` and the `ensureSession()` fallback branch in
`src/Security/CsrfManager.php` — **MUST** set the shared session name before
`session_start()`. Both started a session with the default name, which emits a `PHPSESSID`
cookie and re-opens the dual-cookie competition SC-04/SC-05 close. Because they may open
the session before any other component does, they are also required to apply the shared
session cookie parameters before the start; that obligation is owned by
`session-transport-hardening` (STH-02, STH-05) and is not restated here.

The file's other `session_start()` — the instance method
`NativeSessionCsrfStorage::ensureSession()` — is named here so "no residual deviation" is
not ambiguous about which `ensureSession()` is meant. It is a storage fallback, not a
bootstrap path, and it is not a third deviating site: whenever `SessionManager` is
available it applies `SessionManager::resolveSessionName()` before starting, and with
`SessionManager` absent its only construction site (`CsrfManager::getManager()`) has
already opened a named session through `CsrfManager::ensureSession()` /
`startSharedNativeSession()`. The one shape that would start unnamed — a token operation
after the session was closed mid-request, while `SessionManager` is absent — is not
exercised by any shipped flow. It is recorded here rather than claimed impossible, and it
**MUST** be fixed (a one-line delegation to `CsrfManager::startSharedNativeSession()`)
before any change makes that shape reachable, instead of being documented as an exception.

> *(Added during verify.)* The earlier text named "the `ensureSession()` branch" without
> disambiguating, while `src/Security/CsrfManager.php` has two methods with that name: the
> fixed static `CsrfManager::ensureSession()` and this storage residual. The paragraph
> above states which one the requirement fixes and why the other is out of scope.

#### Scenario: No core path starts an unnamed session

- GIVEN every core `session_start()` call site
- WHEN each is inspected for a preceding `session_name(...)` derived from the shared resolver
- THEN every reachable site complies, and the installer (`base/install_security.php`) is the only exception
- AND the storage residual is recorded with the exact shape that would not comply (a token operation after a mid-request session close with `SessionManager` absent), not claimed to comply

#### Scenario: The duplicate-petition guard uses the shared session name

- GIVEN a separate process with no active session
- WHEN the duplicated-petition guard in `controller/admin_agentes.php` starts the session
- THEN `session_name()` is `SessionManager::resolveSessionName()`
- AND the name was set before `session_start()`

#### Scenario: The CsrfManager fallback uses the shared session name

- GIVEN a separate process with no active session and `SessionManager` unavailable
- WHEN the `src/Security/CsrfManager.php` `ensureSession()` fallback starts the session
- THEN `session_name()` is the shared resolver value
- AND the name was set before `session_start()`

#### Scenario: The installer exception is unchanged

- GIVEN `base/install_security.php`
- WHEN it starts its session
- THEN it uses its own `FSINSTALL_` prefix on the same identity seed
- AND it is not required to use the application session name

#### Scenario: No `PHPSESSID` cookie is emitted by either path

- GIVEN either of the two previously deviating paths
- WHEN it opens the session
- THEN the session in force carries the shared session name
- AND no default-named session cookie is created for this installation

### Closed deviation: residual bare `session_start()` fallbacks

**Closed deviation.** The two bare `session_start()` call sites that this section used to
list as an open deviation (`controller/admin_agentes.php` and
`src/Security/CsrfManager.php`) now set the shared session name before starting the
session, so SC-01 holds with no residual deviation and this section no longer records one.
The change is recorded here rather than silently dropped, because the canonical file
previously documented the deviation as real.

The `NativeSessionCsrfStorage::ensureSession()` residual described in SC-01 is not an
exception to this closing: it is a storage fallback rather than a bootstrap path, it names
the session whenever `SessionManager` is available, and the only shape that would not name
it requires a mid-request session close that no shipped flow performs. It is stated there
so the claim is checkable rather than assumed.

The installer (`base/install_security.php`) remains the deliberate exception: it applies
its own `FSINSTALL_` prefix on top of the same identity seed so the installer session never
shares the application session. That exception is by design and is **not** a deviation.

If a future core path needs to open the session without the shared name, it **MUST** be
documented here as an explicit exception with its reason; it **MUST NOT** be added
silently.

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
