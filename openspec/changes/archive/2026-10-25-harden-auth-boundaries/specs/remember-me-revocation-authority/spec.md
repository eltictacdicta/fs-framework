# remember-me-revocation-authority Specification

Change: `harden-auth-boundaries` — NEW capability. This spec becomes the source of
truth at archive time (no existing canonical spec to modify).

Finding: **VULN-0008** (CWE-306, CVSS 6.5) — a single anonymous
`GET /index.php?page=login` carrying the *unsigned* cookies `user`, `logkey` and
`auth_sig` runs the legacy remember-me handler; when the key does not match, the handler
resolves the target account from the **unsigned `user` cookie** and rotates that account's
`fs_users.log_key`, writes an audit record attributed to the victim, and renders that
account's last recorded IP into the anonymous login page. Knowing a nickname is
sufficient.

Design refs: D13, D14, D15, D16, D20; W3 of `design.md`; proposal "VULN-0008".

## Purpose

Rotation of a per-account server-side secret is an authority-bearing action. Today the
authority to perform it is derived from an unverified cookie, so the *rejection* path of
the remember-me check performs an unauthenticated write against a named account, and the
same request discloses per-account data to an anonymous requester.

This capability binds the decision to the fact it should depend on: the account whose
remember-me token is revoked is named by an **authenticated session**, never by a
request-supplied cookie. A failed remember-me verification is a fact about the **request**,
not about the account: it clears the caller's own cookies and invalidates the caller's own
session, and it mutates nothing else.

The remember-me feature itself is kept, and no public signature changes: 20 files
reference `base/fs_login.php`, and all of them must keep compiling.

Design decision D20 applies: the rotation-target invariant lives **here**, not in
`session-integrity`. `SI-02` (`set_password()` MUST rotate `log_key`) must keep admitting
the password-reset flow, which legitimately rotates the key with no authenticated session
(the emailed token is the authority). Restating `SI-02` as "rotation is initiated by the
account owner's authenticated session" would contradict shipped, intended behaviour, so
this change does not touch `SI-02`/`SI-03`.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| RMR-01 | Remember-me revocation **MUST** act only on the account named by an authenticated session | MUST |
| RMR-02 | A failed remember-me verification **MUST** be treated as a fact about the request and **MUST NOT** mutate the named account | MUST |
| RMR-03 | Logout audit attribution **MUST** come from the authenticated session, never from a request-supplied value | MUST |
| RMR-04 | No per-account value **MUST** be rendered to an anonymous requester | MUST |
| RMR-05 | The login page's error and message blocks **MUST** be escaped | MUST |
| RMR-06 | The revocation-authority change **MUST NOT** alter any public signature | MUST |

### Requirement: RMR-01 — Only an authenticated session names the account to revoke

`log_key` rotation triggered by logout **MUST** resolve its target exclusively from the
nickname held in the authenticated session. The unsigned `user` cookie **MUST NOT** be a
source of identity for this decision.

Both implementations of the rule **MUST** be corrected together, in the same change:

1. `LegacyAuthBridge::resolveLegacyLogoutNick()` — the `$_COOKIE['user']` fallback
   **MUST** be deleted; the resolver returns the session nickname or `null`.
2. `base/fs_login::revokeCurrentRememberMeToken()` — the duplicate fallback
   (`session nickname ?: $_COOKIE['user']`) **MUST** be removed by **deleting the
   duplicate revocation implementation** so the method delegates unconditionally to the
   bridge. Patching only the `?:` expression would leave two copies of the same rule to
   drift again, which is the defect being closed. Recorded trade-off: in a hypothetical
   tree without `SessionManager`, an authenticated logout would no longer rotate
   `log_key` — a loss of a hardening action, never a security hole.

An authenticated logout **MUST** still rotate the key, because an authenticated request
always carries the session nickname. Rollback of the two removals is **all-or-nothing**,
and the asymmetry was measured rather than assumed: reverting **only the bridge**
re-opens the hole immediately, because `fs_login` delegates to it; reverting **only the
`fs_login` deletion** does not re-open the hole while `SessionManager` exists — that copy
is dead code there — but it restores the second copy of the rule, which is the exact
drift hazard this finding closes and which is live in any tree without `SessionManager`.
Revert both or neither.

*(Corrected during PR 3.)* The original sentence claimed reverting either one re-opens
the hole "through the other", which is false in the second direction. The implementation
and its tests follow the normative requirement — correct both copies in the same change —
and this note now states the real behaviour instead of an approximation.

#### Scenario: An anonymous request cannot rotate a named account's key

- GIVEN an anonymous `GET /index.php?page=login` carrying `user=<real nick>` and a bogus `logkey`/`auth_sig`
- WHEN the legacy remember-me handler processes the request
- THEN the named account's `fs_users.log_key` is unchanged
- AND `rotate_logkey()` and `save()` were never called for that account

#### Scenario: An authenticated logout still rotates the key

- GIVEN an authenticated session for a user and a `?logout=1` request
- WHEN the logout completes
- THEN that user's `log_key` is rotated

#### Scenario: The resolver returns null with only the cookie present

- GIVEN `resolveLegacyLogoutNick()` evaluated with no session nickname and `$_COOKIE['user']` set
- WHEN it resolves the nickname
- THEN it returns `null`

#### Scenario: The resolver prefers the session nickname

- GIVEN a session nickname and a different `$_COOKIE['user']` value
- WHEN `resolveLegacyLogoutNick()` resolves the nickname
- THEN it returns the session nickname

#### Scenario: There is no second copy of the rule

- GIVEN `base/fs_login::revokeCurrentRememberMeToken()`
- WHEN it resolves the account to revoke
- THEN it delegates to the bridge
- AND it contains no independent cookie-based resolution

### Requirement: RMR-02 — A failed verification mutates nothing but the caller

A failed remember-me verification **MUST** be treated as a fact about the request. The
handler **MUST** clear the caller's own `user`/`logkey`/`auth_sig` cookies and invalidate
the caller's own session, and **MUST NOT** mutate the account named by any request value.

Both branches of `handleInvalidCookieSession()` **MUST** perform the caller-scoped cleanup
(`log_out(TRUE)`). Today the else-branch calls `log_out()` without the remember-me flag, so
the caller's own stale cookie survives and the next request repeats the flow.

The anti-takeover notice is **not** required: a successful login from a new device already
rotates the key, so a stale cookie is invalid by design.

**Testing seam (normative for the scenarios below).** The cookie deletions are emitted by
`log_out(TRUE)`, so the CLI-observable assertion is that `log_out(TRUE)` was invoked (via
a `fs_login` double) and that the account-mutation calls were not. The header-level half of
the contract (`Set-Cookie` deleting the caller's own `user`/`logkey`/`auth_sig`) is a
**live HTTP observation owed by the operator**, recorded as an unverified gate: the CLI
SAPI cannot observe `Set-Cookie`. A scenario in this requirement **MUST NOT** claim a
header assertion the CLI harness cannot make.

The remember-me mechanism is retained — only *who may name the account* and *what is
disclosed to whom* change. No password check, session or CSRF token is added to this path.

#### Scenario: A bad-key request clears only the caller's own state

- GIVEN an anonymous request carrying a named `user` cookie and a key that does not match
- WHEN the handler processes the failure
- THEN the caller-scoped cleanup is invoked with the remember-me flag set (`log_out(TRUE)`), which clears the caller's own cookies and invalidates the caller's own session
- AND no row of the named account is written
- AND the header-level cookie deletions are verified by a live HTTP probe, because the CLI SAPI cannot observe `Set-Cookie`

#### Scenario: The caller-scoped cleanup runs on both branches

- GIVEN a failure where the requester's IP equals the stored `last_ip`, and a failure where it differs
- WHEN each is handled
- THEN both clear the caller's own cookies and invalidate the caller's own session
- AND neither leaves the caller's stale cookie in place

#### Scenario: A non-existent nickname behaves the same for the caller

- GIVEN an anonymous request naming a nickname that does not exist
- WHEN the handler processes it
- THEN the caller's own cookies are cleared and its own error text is rendered
- AND no row is touched

#### Scenario: The remember-me feature is retained

- GIVEN a valid `user` + `logkey` + `auth_sig` triple for an enabled account
- WHEN the login page is requested with it
- THEN the session is restored exactly as today

### Requirement: RMR-03 — Logout audit attribution comes from the session

The audit record written by `log_out()` (`El usuario ha cerrado la sesión.`) **MUST** be
attributed to the authenticated session nickname. A request-supplied cookie value **MUST
NOT** determine the recorded `fs_logs.usuario`.

#### Scenario: An authenticated logout names the authenticated user

- GIVEN an authenticated session for a user and a logout request
- WHEN the audit record is written
- THEN `fs_logs.usuario` is the authenticated nickname

#### Scenario: An anonymous request cannot forge an audit record

- GIVEN an anonymous request carrying `user=<other nick>`
- WHEN the request is processed
- THEN no audit record attributed to `<other nick>` is written

### Requirement: RMR-04 — No per-account value is rendered to an anonymous requester

`handleInvalidCookieSession()` **MUST NOT** interpolate the account's stored `last_ip`
(or any other stored per-account value) into a response rendered for an unauthenticated
requester. It **MUST** emit a neutral notice stating that the session is no longer valid
and the user should sign in again.

A "someone accessed this account from another location" warning, if retained at all,
belongs in the authenticated owner's own session or in an out-of-band notification, both
outside this change.

#### Scenario: The anonymous failure page discloses no account data

- GIVEN an anonymous request carrying a named `user` cookie and a key that does not match
- WHEN the response is rendered
- THEN the body contains no IP address
- AND it contains the neutral session-invalid notice

#### Scenario: The stored IP is not read on this path

- GIVEN `handleInvalidCookieSession()`
- WHEN it builds its message
- THEN it does not read the account's `last_ip`

### Requirement: RMR-05 — The login page escapes its error and message blocks

The login template **MUST** escape the blocks that render `get_errors()` and
`get_messages()`. The `raw` filter **MUST** be removed from both loops in
`themes/AdminLTE/view/login/default.html.twig` (the file named by the proposal as
`login/default.twig` is actually `login/default.html.twig`).

This is required because the nickname is interpolated into the "user does not exist" and
ban messages, so both blocks are a markup-injection sink reachable by the same anonymous
request. Escaping is applied at the template sink (Twig `{{ }}` auto-escapes); escaping in
PHP before `new_error_msg()` would cover only one of many consumers of the same message.

#### Scenario: A script payload in a login message renders escaped

- GIVEN a login-page error or message whose text contains `<script>alert(1)</script>`
- WHEN the login page renders the block
- THEN the payload is rendered escaped
- AND no executable script element is produced

#### Scenario: Neither block uses the raw filter

- GIVEN `themes/AdminLTE/view/login/default.html.twig`
- WHEN the `get_errors()` and `get_messages()` loops are inspected
- THEN neither applies the `raw` filter
- AND the surrounding markup is unchanged

### Requirement: RMR-06 — No public signature changes

The revocation-authority change **MUST NOT** alter the public signature or visibility
contract of `fs_login::log_out()`, `fs_login::revokeCurrentRememberMeToken()` or
`LegacyAuthBridge`'s public API. All files that reference `base/fs_login.php` **MUST**
keep compiling: the verified count is 20 files (7 production-path files plus
`phpstan-bootstrap.php` and 12 test files) with 6 production instantiation sites in 4
files.

A test seam **MUST** be available for the revocation rule without a database or an HTTP
request: the resolver's visibility may be widened from `private` to `protected` and
annotated as internal-for-tests (precedent:
`SessionManager::legacyPhpSessionCookiePaths()`).

#### Scenario: Every referencing site still compiles and passes

- GIVEN the 20 files that reference `base/fs_login.php`
- WHEN the suite runs
- THEN all of them compile and their tests pass with no signature change

#### Scenario: The revocation rule is unit-testable without a database

- GIVEN the revocation-rule seam
- WHEN it is exercised from a test
- THEN the session-nickname and cookie-only cases are distinguishable without a database connection or an HTTP request

#### Scenario: The widest-blast-radius regression set stays green

- GIVEN the legacy cookie path's characterisation tests
- WHEN the suite runs
- THEN `LegacyAuthBridgeTest`, `FsLoginCookieAuthTest`, `FsLoginCharacterizationTest`, `RememberMeSignatureRequiredTest`, `CsrfSessionSyncTest` and `TrustedSessionRestampTest` pass

## Out of scope (explicit)

- **Amending `session-integrity`'s `SI-02`/`SI-03`.** Rejected by design D20: `SI-02` must
  keep admitting the password-reset flow, which legitimately rotates `log_key` with no
  authenticated session. The invariant here is about *remember-me revocation*, not about
  every `log_key` rotation.
- **Patching only the second fallback.** Rejected by design D13: it leaves two copies of
  the rule to drift.
- **Rotating the named account only when `enabled === TRUE`.** Rejected: the report
  verified that the disabled and non-existent branches reach revocation through
  `log_out(TRUE)` too, so account state does not gate it; the correct rule is "the
  requester must prove they own the identity", not "the identity must look plausible".
- **Keeping the `last_ip` warning for an authenticated owner.** Rejected:
  `handleInvalidCookieSession()` is reached by definition on an unauthenticated request.
- **A forced logout of active sessions.** Not claimed: `SessionManager::isValid()` checks
  only nickname and expiry, never `log_key`, so the effect is persistent-credential
  invalidation, not session termination.
