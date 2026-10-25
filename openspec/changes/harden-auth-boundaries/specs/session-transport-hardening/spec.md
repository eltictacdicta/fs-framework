# session-transport-hardening Specification

Change: `harden-auth-boundaries` — NEW capability. This spec becomes the source of
truth at archive time (no existing canonical spec to modify).

Finding: **VULN-0007** (CWE-614, CVSS 3.1) — every response sets the session cookie
**twice** (one bare `path=/` header, one with `HttpOnly; SameSite=Lax`) and **neither
carries `Secure`**, on an HTTPS-only deployment; no `Strict-Transport-Security` header is
returned by any response.

Design refs: D4, D5, D6, D17; W6 of `design.md`; proposal "VULN-0007".

## Purpose

The session cookie's transport attributes must be decided by the application, before the
session starts, and emitted once. Today `StealthMode::ensurePhpSessionStarted()` calls
`session_name()` + `session_start()` with **no** cookie parameters applied, and then
compensates with a defensive `setcookie()` that omits `secure`. That is exactly the pair
of `Set-Cookie` headers observed live: PHP's own parameterless emission plus the
defensive sync.

This capability makes one definition of the cookie attribute set, applies it before every
`session_start()`, removes the duplicate emission, turns on strict session mode at the
outermost entry point, and emits HSTS on secure responses.

**This closes the archived change's W-4 residual** (`src/Core/StealthMode.php` ~494-499)
and **MUST NOT** re-open the dual-cookie hazard `consolidate-session-csrf` closed
(SC-01…SC-05). The stealth cookie sync is made attribute-identical by *consuming* the
shared parameters, and the duplicate emission is *removed* — not reconciled into two
identical headers.

### Testing constraint (normative for the scenarios below)

The suite runs in the CLI SAPI, where `headers_sent()` is true and
`setcookie()`/`header()` are no-ops (`headers_list()` returns `[]`). Therefore the
scenarios in this capability assert the **input seam** — the parameter set the application
supplies before `session_start()` — and say so explicitly. The header-level assertions
("exactly one `Set-Cookie`", "`Secure` present", "HSTS present") are **live HTTP probes
owed by the operator**, recorded as unverified gates. A scenario in this capability
**MUST NOT** claim a header assertion the CLI harness cannot make.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| STH-01 | There **MUST** be one definition of the session cookie attribute set, consumed by every component that starts the session | MUST |
| STH-02 | Cookie parameters **MUST** be applied before `session_start()`, and the duplicate emission **MUST** be removed so exactly one `Set-Cookie` is produced | MUST |
| STH-03 | The outermost session entry point **MUST** enable strict session mode before `session_start()` | MUST |
| STH-04 | `Strict-Transport-Security` **MUST** be emitted on secure responses, with a documented disable switch and no `includeSubDomains` by default | MUST |
| STH-05 | Every core bootstrap path that opens the application session **MUST** set the shared session name before `session_start()` | MUST |
| STH-06 | SC-01…SC-05 **MUST** stay green: this change closes W-4 and **MUST NOT** re-open the dual-cookie regression | MUST |

### Requirement: STH-01 — One definition of the cookie attribute set

`SessionManager::sessionCookieParams()` **MUST** be the single definition of the session
cookie attribute set, returning exactly:

| Key | Value |
|---|---|
| `lifetime` | `SessionPolicy::getIdleTimeout()` |
| `path` | `SessionManager::resolveCookiePath()` |
| `domain` | `''` |
| `secure` | `SecureRequestDetector::isSecure()` |
| `httponly` | `true` |
| `samesite` | `'Lax'` |

`resolveCookiePath()` **MUST** be promoted to `public` so the definition is consumable
(the precedent is `resolveSessionName()`, promoted for exactly this reason by the archived
change). The Symfony path, the legacy path (`base/fs_session_manager.php`) and
`StealthMode` **MUST** all consume it; a second copy of the attribute set **MUST NOT**
remain.

The `Secure` diagnosis is settled at source level: `SecureRequestDetector::isSecure()` is
already correct and already pinned for both proxy cases, so the defect was the ordering,
not the detector. The detector **MUST NOT** be changed to make the emission path work.

#### Scenario: The parameter set is exactly as specified

- GIVEN `SessionManager::sessionCookieParams()`
- WHEN it is called
- THEN it returns the six keys above with the specified values
- AND `secure` is a boolean derived from `SecureRequestDetector::isSecure()`

#### Scenario: Secure is true behind a TLS-terminating trusted proxy

- GIVEN a request where a trusted proxy terminates TLS and sends `X-Forwarded-Proto: https`
- WHEN `sessionCookieParams()` is called
- THEN `secure` is `true`

#### Scenario: Secure is false without a trusted proxy

- GIVEN the same request without a trusted proxy configured
- WHEN `sessionCookieParams()` is called
- THEN `secure` is `false`

#### Scenario: The path comes from the shared resolver

- GIVEN a subdirectory installation
- WHEN `sessionCookieParams()` is called
- THEN `path` equals `SessionManager::resolveCookiePath()`

#### Scenario: No second copy of the attribute set

- GIVEN the three consumers (Symfony storage options, `initializeLegacySession()`, `ensurePhpSessionStarted()`)
- WHEN each supplies cookie parameters
- THEN each obtains them from `sessionCookieParams()`
- AND no consumer hardcodes `path`, `secure`, `httponly` or `samesite` independently

### Requirement: STH-02 — Parameters before session start; exactly one emission

Every component that may start the application session **MUST** apply
`sessionCookieParams()` **before** `session_start()`. In
`StealthMode::ensurePhpSessionStarted()` the defensive `setcookie()` for the session
cookie (the W-4 residual, ~494-499) **MUST** be deleted: with the parameters applied
before the start and strict mode enabled, PHP's own emission is complete and the second
header is precisely the defect.

The observable contract is: **exactly one `Set-Cookie` header per response, carrying
`Secure` (when the request is detected secure), `HttpOnly` and `SameSite=Lax`, and the
attribute set is constant for the session lifetime.** The header count and the attribute
values are asserted by a live HTTP probe; the CLI suite asserts the input seam.

#### Scenario: Cookie parameters are in force before the session starts (input seam)

- GIVEN a separate process where `session_status()` is `PHP_SESSION_NONE`
- WHEN `StealthMode::ensurePhpSessionStarted()` runs
- THEN `session_get_cookie_params()` carries the shared attribute set (`httponly` true, `samesite` `Lax`, `path` from the resolver)
- AND `session_name()` is `SessionManager::resolveSessionName()`
- AND this assertion is made on the parameters supplied to the session, because the CLI SAPI cannot observe `Set-Cookie`

#### Scenario: The duplicate emission is removed, not reconciled

- GIVEN `StealthMode::ensurePhpSessionStarted()`
- WHEN it starts an already-started-mismatched session
- THEN it does not emit a second session cookie of its own
- AND the removed defensive call is not replaced by an attribute-identical duplicate

#### Scenario: Secure follows the detected transport for the same code path

- GIVEN the same emission path evaluated for a request detected secure and one detected insecure
- WHEN the cookie parameters are supplied
- THEN `secure` is `true` for the first and `false` for the second
- AND the remaining attributes are identical in both cases

#### Scenario: Exactly one Set-Cookie header on a live secure response (operator probe)

- GIVEN a live HTTPS request to `index.php?page=login`
- WHEN the response headers are captured
- THEN exactly one `Set-Cookie` header for the session cookie is present
- AND it carries `Secure`, `HttpOnly` and `SameSite=Lax`
- AND this is recorded as an operator-owned unverified gate, not asserted by the CLI suite

### Requirement: STH-03 — Strict session mode at the outermost entry point

`StealthMode::ensurePhpSessionStarted()` **MUST** set `session.use_strict_mode` to `1`
before `session_start()`. A client-supplied session identifier that was never initialized
server-side **MUST** be discarded and a fresh one issued instead of being adopted. This is
the report's own recommendation ("enable strict session mode at the outermost entry
point"); it is a deliberate behaviour change for a client holding an uninitialized
identifier, and it is the fail-safe direction.

#### Scenario: Strict mode is enabled before the start (input seam)

- GIVEN a separate process where `session.use_strict_mode` is off
- WHEN `StealthMode::ensurePhpSessionStarted()` runs
- THEN `ini_get('session.use_strict_mode')` is `'1'`
- AND the setting was applied before `session_start()`

#### Scenario: An uninitialized client-supplied id is not adopted

- GIVEN a request supplying a session id that was never initialized server-side
- WHEN the session starts through this path
- THEN the identifier is not adopted as the session identity

#### Scenario: Existing session-fixation coverage stays green

- GIVEN the existing session tests
- WHEN the suite runs
- THEN `SessionFixationPreventionTest` and `SessionNamePerInstallTest` pass

### Requirement: STH-04 — HSTS on secure responses

`SecurityHeaders::applyDefaultHeaders()` **MUST** emit
`Strict-Transport-Security: max-age=<value>` when `SecureRequestDetector::isSecure()` is
true. The `max-age` **MUST** default to `31536000` and **MUST** be overridable through
`FS_HSTS_MAX_AGE`; the value `0` **MUST** disable emission entirely. `includeSubDomains`
**MUST NOT** be emitted unless explicitly opted in: it pins HTTPS for a whole parent zone
and is not cheaply reversible.

HSTS **MUST NOT** be emitted on a response the detector classifies as insecure: the header
is ignored by browsers over plain HTTP and would break a local HTTP-only installation.

Scope statement (explicit, not implied): `applyDefaultHeaders()` is called only from
`index.php`, so HSTS coverage is `index.php` by default. Extending it to `api.php` and
`cron.php` **SHOULD** be done through a narrow transport-only helper that emits HSTS alone
(not the full header set), because calling the full `applyDefaultHeaders()` there would add
CSP, `nosniff` and `X-Frame-Options` to the API surface — an unrelated behaviour change.

#### Scenario: The HSTS decision is correct for a secure request (predicate assertion)

- GIVEN a request the detector classifies as secure and `FS_HSTS_MAX_AGE` at its default
- WHEN the HSTS decision is evaluated
- THEN the resulting header value is `max-age=31536000`
- AND the assertion targets the decision, because the CLI SAPI cannot observe `header()`

#### Scenario: The documented escape hatch disables HSTS

- GIVEN `FS_HSTS_MAX_AGE` set to `0`
- WHEN the HSTS decision is evaluated
- THEN no `Strict-Transport-Security` value is produced

#### Scenario: includeSubDomains is opt-in

- GIVEN the default configuration
- WHEN the HSTS decision is evaluated
- THEN the value contains no `includeSubDomains`
- AND it contains `includeSubDomains` only when the opt-in is explicitly set

#### Scenario: HSTS is absent on an insecure request

- GIVEN a request the detector classifies as insecure
- WHEN the HSTS decision is evaluated
- THEN no `Strict-Transport-Security` value is produced

#### Scenario: The HSTS coverage boundary is stated

- GIVEN the change's verification artifacts
- WHEN the HSTS coverage is reported
- THEN it states which entry points emit HSTS
- AND if the narrow helper for `api.php`/`cron.php` is not implemented, coverage is reported as `index.php`-only

### Requirement: STH-05 — No bare session start outside the installer exception

Every core bootstrap path that opens the application session **MUST** set the shared
session name before `session_start()`. The two call sites previously recorded as a known
deviation in `session-consolidation`'s SC-01 —
`controller/admin_agentes.php` (the duplicated-petition guard) and
`src/Security/CsrfManager.php` (the `ensureSession()` fallback branch) — **MUST** comply:
they start a session with the default name today, which emits a `PHPSESSID` cookie and
re-opens the exact dual-cookie regression `consolidate-session-csrf` closed.

The installer (`base/install_security.php`) **MUST** remain the sole exception, keeping
its own `FSINSTALL_` prefix on the same identity seed.

#### Scenario: The duplicate-petition guard uses the shared session name

- GIVEN a separate process with no active session
- WHEN `admin_agentes`'s duplicated-petition guard starts the session
- THEN `session_name()` is `SessionManager::resolveSessionName()`
- AND the name was set before `session_start()`

#### Scenario: The CsrfManager fallback uses the shared session name

- GIVEN a separate process with no active session and `SessionManager` unavailable
- WHEN `CsrfManager`'s `ensureSession()` branch starts the session
- THEN `session_name()` is the shared resolver value
- AND the name was set before `session_start()`

#### Scenario: The installer exception is preserved

- GIVEN `base/install_security.php`
- WHEN it starts its session
- THEN it uses its own `FSINSTALL_` prefix
- AND it is not required to use the application session name

### Requirement: STH-06 — W-4 closed without re-opening SC-05

This change **MUST NOT** re-open the dual-cookie hazard closed by
`consolidate-session-csrf`. A response **MUST NOT** carry a second cookie with the session
cookie's name and divergent attributes, and no live `PHPSESSID` cookie **MUST** be set for
this installation.

The `resolveSessionName()` name-match gate in `SessionManager::initialize()` **MUST NOT**
be modified.

#### Scenario: No same-named cookie with divergent attributes

- GIVEN the stealth cookie-sync path
- WHEN a session cookie is emitted for this installation
- THEN no second emission of the same cookie name with different `Path`/`Secure` attributes occurs

#### Scenario: SC-01…SC-05 stay green

- GIVEN the canonical `session-consolidation` scenarios
- WHEN the suite runs
- THEN one session name is in force, no live `PHPSESSID` is emitted for this installation, and the legacy cookie is discarded without being read

#### Scenario: The name-match gate is untouched

- GIVEN an already-active session whose name equals `SessionManager::resolveSessionName()`
- WHEN `SessionManager::initialize()` runs
- THEN it skips re-initialisation exactly as before

## Out of scope (explicit)

- **Adding `secure` to the defensive `setcookie()` only.** Rejected by design D5: it leaves
  two `Set-Cookie` headers, contradicting the stated criterion, and leaves the
  parameterless `session_start()` in place so strict mode stays off at the outermost entry
  point.
- **Applying the parameters in `index.php` before the gate.** Rejected: `index.php` is not
  the only entry point, and `StealthMode` is also driven from `controller/login.php`.
- **Emitting HSTS unconditionally, including on plain HTTP.** Rejected: ignored by
  browsers over HTTP and confusing on a local install.
- **Hardcoding `includeSubDomains`.** Rejected by design D6.
- **`CsrfManager::$tokenVerified` intra-request invalidation** and the
  non-discriminating `CsrfTokenSurvivesMigrationTest` (design D18). No finding maps to
  either; owner is a `session-integrity` follow-up change.
