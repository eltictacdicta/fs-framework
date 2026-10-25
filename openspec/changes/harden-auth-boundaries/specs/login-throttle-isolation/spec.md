# login-throttle-isolation Specification

Change: `harden-auth-boundaries` — NEW capability. This spec becomes the source of
truth at archive time (no existing canonical spec to modify).

Finding: **VULN-0006** (CWE-799, CVSS 4.3) — the public password-reset request handler
writes the **same** per-IP counter that gates authentication, before any input validation
and with no CSRF token, so anonymous reset bursts lock an entire network out of the ERP
(and out of the password-recovery form itself).

Design refs: D11, D12; W4 of `design.md`; proposal "VULN-0006".

## Purpose

Lockout state is a security control. Today two unrelated features share it: the
authentication lockout bucket (`tmp/<FS_TMP_NAME>ip.log`, owned by `fs_login`) is written
by the password-reset controller as well, so exhausting a reset quota denies login, and
`set_attempt()` runs *above* the input-validity check so a request with no body at all
counts as an attempt.

This capability makes each feature own its throttle state, makes "an attempt" mean an
actual attempt, and puts the reset request behind the CSRF token its own template already
sends.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| LTI-01 | The authentication lockout bucket **MUST** be writable only by credential-submission handlers; every other throttling feature **MUST** use its own bucket | MUST |
| LTI-02 | A namespace **MUST** be sanitised and **MUST** resolve inside `tmp/` | MUST |
| LTI-03 | Validation **MUST** precede counting: a submission that is not an attempt **MUST** increment no counter | MUST |
| LTI-04 | The reset request and the reset completion **MUST** require a valid CSRF token before any lookup, token mint or mail | MUST |
| LTI-05 | The reset flow **MUST** keep its own throttle with unchanged thresholds and messages | MUST |

### Requirement: LTI-01 — One owner per lockout bucket

`fs_ip_filter` **MUST** accept an optional namespace that suffixes its state file. The
password-reset controller **MUST** construct its filter with a dedicated namespace. The
authentication bucket **MUST** remain byte-identical to today for the empty namespace
(`tmp/<FS_TMP_NAME>ip.log`), so no live ban is reset and no deployment path changes.

The invariant this encodes is normative: **lockout state is a security control; only
credential-submission handlers may write to the authentication bucket.** Any future
feature that throttles itself **MUST** use its own namespace by default.

`set_attempt()` **MUST** keep exactly three call sites in production (login credential
submission, `process_request()`, `process_reset()`), all of them writing to the bucket
owned by their feature.

#### Scenario: Anonymous reset bursts no longer lock out authentication

- GIVEN six anonymous empty `POST` requests to `page=password_reset`
- WHEN the next credential `POST` to `page=login` is made with a freshly minted valid token and a wrong password
- THEN the response contains `Usuario o contraseña incorrectos.`
- AND it does not contain the ban message, i.e. the password comparison was reached

#### Scenario: The authentication bucket path is unchanged

- GIVEN `new fs_ip_filter()` constructed with no argument
- WHEN its state-file path is resolved
- THEN it is exactly `tmp/<FS_TMP_NAME>ip.log`, byte-for-byte identical to today

#### Scenario: The reset flow uses its own bucket

- GIVEN `new fs_ip_filter('password_reset')`
- WHEN its state-file path is resolved
- THEN it is `tmp/<FS_TMP_NAME>password_reset_ip.log`
- AND it is not the authentication bucket path

#### Scenario: Authentication keeps the default bucket

- GIVEN the production construction site in `base/fs_login.php`
- WHEN the filter is constructed
- THEN it passes no namespace
- AND authentication continues to read and write `tmp/<FS_TMP_NAME>ip.log`

#### Scenario: Exhausting the reset quota has no authentication consequence

- GIVEN an address whose reset bucket is exhausted
- WHEN a credential submission is made from the same address
- THEN the credential evaluation proceeds normally

### Requirement: LTI-02 — Namespace sanitisation cannot escape `tmp/`

The namespace suffix **MUST** be sanitised to `[a-z0-9_]` and lower-cased, and the
resolved path **MUST** stay inside `tmp/` and contain no path separator. This holds even
if a future call site derives the namespace from a non-literal value.

#### Scenario: A hostile namespace resolves inside tmp

- GIVEN the namespaces `'Password-Reset!!'` and `'../../etc/passwd'`
- WHEN each filter's state-file path is resolved
- THEN both paths are inside `tmp/` and contain no path separator
- AND neither path escapes the installation's temporary directory

#### Scenario: A sanitised namespace is deterministic

- GIVEN the namespace `'Password-Reset!!'`
- WHEN its suffix is computed twice
- THEN both computations yield the same sanitised suffix

### Requirement: LTI-03 — Validation precedes counting

In `password_reset::process_request()`, `set_attempt()` **MUST** run only after the
submission has been validated as an actual attempt (a non-empty `nick_or_email`). A
submission that is not an attempt **MUST** increment no counter, in either bucket.

#### Scenario: An empty submission increments no counter

- GIVEN a password-reset `POST` with an empty body
- WHEN `process_request()` handles it
- THEN neither the authentication bucket nor the reset bucket changed
- AND the validation message `password-reset-enter-nick-or-email` is rendered

#### Scenario: A real attempt increments exactly one counter, in its own bucket

- GIVEN a password-reset `POST` carrying a non-empty `nick_or_email` and a valid CSRF token
- WHEN `process_request()` handles it
- THEN exactly one attempt is recorded
- AND it is recorded in the reset bucket, not the authentication bucket

### Requirement: LTI-04 — The reset flow requires a CSRF token

`password_reset::process_request()` and `password_reset::process_reset()` **MUST**
validate the CSRF token before performing any lookup, minting any reset token or sending
any mail. This is a controller-side change only: the template
(`themes/AdminLTE/view/password_reset.html.twig`) already emits `{{ csrf_field() }}` in
both forms, so the token was always sent and never validated.

A request without a valid token **MUST** be refused, and **MUST NOT** increment any
counter, look up any account, mint any reset token or send any mail.

#### Scenario: A token-less reset request is refused without side effects

- GIVEN an anonymous `POST` to `page=password_reset` carrying a non-empty `nick_or_email` and no CSRF token
- WHEN `process_request()` handles it
- THEN the request is refused with a CSRF error
- AND no account lookup, no reset-token mint and no mail delivery occurred
- AND no counter was incremented

#### Scenario: An invalid token is refused identically

- GIVEN the same request carrying an invalid CSRF token
- WHEN `process_request()` handles it
- THEN the request is refused
- AND no side effect of the previous scenario occurred

#### Scenario: A valid token preserves today's behaviour

- GIVEN the same request carrying a valid CSRF token
- WHEN `process_request()` handles it
- THEN the request proceeds exactly as today (lookup, token mint, mail)

#### Scenario: Reset completion also validates the token

- GIVEN a `POST` to the reset-completion branch (`token` + `nick` present) with an invalid CSRF token
- WHEN `process_reset()` handles it
- THEN the request is refused and no password is changed

### Requirement: LTI-05 — The reset flow keeps its own throttle

The reset bucket **MUST** keep the same thresholds and the same user-visible messages as
today (5 attempts per 600-second sliding window, `password-reset-too-many-attempts`), and
the reset flow **MUST NOT** be able to deny authentication nor be denied by it. The
authentication lockout **MUST** keep its full strength: same file, same
`MAX_ATTEMPTS`/`BAN_SECONDS`, same sliding window.

#### Scenario: The reset throttle still fires on its own bucket

- GIVEN an address whose reset bucket has been driven to its limit by reset requests
- WHEN a further reset request arrives
- THEN it is refused with the reset throttle message
- AND authentication from the same address is unaffected

#### Scenario: The authentication lockout keeps its strength

- GIVEN the documented number of real failed credential submissions from one address
- WHEN the next credential submission arrives
- THEN authentication is refused with the ban message
- AND the bucket consulted is `tmp/<FS_TMP_NAME>ip.log`

#### Scenario: No public contract changed

- GIVEN the reset flow's translations and error strings
- WHEN the flow is exercised
- THEN the same message keys are rendered as today
- AND no new public API, template or translation key was introduced

## Out of scope (explicit)

- **A dedicated `fs_password_reset_filter` class** or a static namespace registry.
  Rejected by design D11: hidden global state, duplicated read/write/prune logic.
- **Moving the reset flow onto a Symfony rate limiter.** Rejected: new dependency surface
  and it would not fix the ordering defect.
- **Fixing only the ordering while keeping the shared bucket.** Rejected: an attacker can
  still drive the shared counter with valid non-empty submissions and the ban still
  disables the recovery form.
