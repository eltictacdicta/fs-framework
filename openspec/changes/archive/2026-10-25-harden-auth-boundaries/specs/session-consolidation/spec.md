# Delta for session-consolidation

> **Merge note (shape):** the canonical `openspec/specs/session-consolidation/spec.md`
> states its requirements as a table row per ID followed by standalone scenario blocks, and
> it documents SC-01's residual gap in a `### Known deviation: residual bare
> session_start() fallbacks` paragraph. This delta therefore supplies **two** replacement
> blocks: the new text for the **SC-01** requirement row, and the replacement for that
> **Known deviation** paragraph. Nothing else in the canonical file changes.
>
> **Merge note (scope):** **SC-02…SC-05 are NOT modified.** The W-4 residual
> (`src/Core/StealthMode.php` ~494-499: a same-named session cookie with a hardcoded
> `path => '/'` and no `secure`) is closed by the new `session-transport-hardening`
> capability, which makes the stealth emission consume the shared cookie parameters and
> **removes** the duplicate emission instead of reconciling it. This delta records the
> consequence for SC-01's deviation paragraph; it does not restate SC-05.
>
> **Language:** this delta is in English to match the canonical file (created in English
> by the archived change). The canonical file is not translated.

## MODIFIED Requirements

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

(Previously: SC-01 stated the invariant that actually held and carried an explicit
`### Known deviation` list of the two bare `session_start()` sites, which were left as a
separate hardening follow-up.)

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

### Replaces the `### Known deviation: residual bare session_start() fallbacks` paragraph

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

## ADDED Requirements

(None)

## REMOVED Requirements

(None)
