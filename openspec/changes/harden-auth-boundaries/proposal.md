# Proposal: Harden authentication boundaries

## Intent

Close the six CORE findings of the unauthenticated penetration test of
2026-10-24 (`penetration_test_report.md`, 8 validated findings, 7 MEDIUM and 1
LOW, no CRITICAL or HIGH). The two remaining findings belong to the
`system_updater` plugin and are a separate change (see "Out of Scope").

The assessment's own diagnosis is the intent of this change: **the framework's
controls are sound but they live in the main `index.php` request path.**
Everything that reaches the application through a different door — a login-shaped
POST that is not the login page, a second credential evaluation, the legacy
cookie path, the anonymous password-reset handler, an unapplied reverse-proxy
deny list — re-implements authentication or omits it. This change moves each of
those decisions onto the fact it should depend on (the requested page, the
authenticated session, the owning feature) instead of on the shape of the
request.

No business-data path was breached during the assessment, because every business
controller independently re-checks the session. That is defense in depth working
as designed; it is not a substitute for the boundary this change restores.

## Scope

### In Scope

| # | Finding | Severity | Boundary that fails | Primary surfaces |
|---|---|---|---|---|
| VULN-0002 | PublicAccessGate bypass | MEDIUM | Global anonymous-access gate | `src/Core/PublicAccessGate.php`, `src/Core/StealthMode.php`, `base/fs_maintenance_mode.php` |
| VULN-0003 | Login CSRF guard bypassed | MEDIUM | Login CSRF + single credential evaluation | `controller/login.php`, `base/fs_controller.php` |
| VULN-0005 | Credential material in the served tree + unapplied edge deny rules | MEDIUM | Served surface / edge deny-by-default | `openspec/**/verify-report.md`, `nginx-sample.conf`, `scripts/hooks/pre-commit` |
| VULN-0006 | Shared-throttle login DoS | MEDIUM | Authentication lockout state ownership | `base/fs_ip_filter.php`, `controller/password_reset.php` |
| VULN-0007 | Session cookie without `Secure`, no HSTS, duplicate `Set-Cookie` | LOW | Session transport | `base/fs_session_manager.php`, `src/Security/SessionManager.php`, `src/Security/SecurityHeaders.php`, `src/Core/StealthMode.php` |
| VULN-0008 | Unauthenticated credential rotation + data disclosure | MEDIUM | Remember-me revocation authority | `src/Security/LegacyAuthBridge.php`, `base/fs_login.php`, `themes/AdminLTE/view/login/default.twig` |

Recommended apply order (from `FIX-PLAN.md`, cheapest-and-highest-impact
first): 0002 → 0003 → 0008 → 0006 → 0005 → 0007. The breakdown below is presented
in numeric order.

### Out of Scope

- **VULN-0001 and VULN-0004** — both live entirely in `plugins/system_updater/`
  (`recovery.php`; `lib/session_auth.php::system_updater_is_logged_in()`).
  `/plugins/*` is gitignored (`.gitignore:47`); each plugin is its own
  repository. Their SDD belongs in
  `plugins/system_updater/openspec/changes/{name}/`, committed in that
  repository, **not** in this change and not in this repository's core tree.
- **Post-authentication object-level authorization** across customer, sales,
  catalogue and company controllers. This is the report's highest-value untested
  area and it is untested because no credentials were available — not because it
  passed. It is a proof gap, not a clean result. It needs a follow-up
  authenticated scan with one low-privilege and one administrator account.
- **Other proof gaps from the same report**: whether the session identifier
  rotates on a *successful* login, whether logout invalidates the session
  server-side, and user/timing enumeration through the login and reset endpoints
  (blocked by the shared rate limiter). VULN-0007 and VULN-0003 touch these
  surfaces, but neither finding is closed by evidence, so neither is claimed.
- **Deployment-side operations** required by VULN-0005: applying the nginx deny
  rules on the live server and rotating the exposed `admin` log key. This change
  ships the artifacts and the runbook; the operator performs the actions. See
  VULN-0005 for the explicit split.
- **General authentication redesign** (centralised middleware for every entry
  point, standalone plugin script auth, CSP `unsafe-inline` reduction). These are
  the report's medium-term recommendations and are deliberately not attempted
  here.
- **Non-core plugin trees.** No file under `plugins/` is touched.

## Per-finding breakdown

### VULN-0002 — PublicAccessGate bypass (MEDIUM, CWE-863, CVSS 6.5)

**Symptom.** The global anonymous gate exempts any request that merely *looks*
like a login submission, because the second half of the exemption condition
inspects only the HTTP method and the request body. An anonymous
`POST /index.php?page=admin_info&nlogin=1` — or the same POST carrying
`user`/`password` — returns `HTTP 200` with the controller's anonymous body
instead of `302` to login. Reachability of the `password_reset` POST handler was
demonstrated this way; the request stopped at the per-IP limiter, not at the
gate.

**Verified surface.**

| File | Line | What |
|---|---|---|
| `src/Core/PublicAccessGate.php` | 59 | `isLegacyLoginRequest() \|\| isLegacyLoginSubmission()` — the loose exemption |
| `src/Core/StealthMode.php` | 199–207 | `isLegacyLoginRequest()` / `isLegacyLoginSubmission()` public wrappers |
| `src/Core/StealthMode.php` | 433–449 | `isHiddenLoginPageRequest()` (`page === 'login'`) vs `isHiddenLoginSubmission()` (method + `nlogin` + body) |
| `base/fs_maintenance_mode.php` | 555 (called at 348) | `isStealthLoginSubmission()` — a second, independent copy of the same predicate |
| `tests/Components/PublicAccessGateTest.php` | ~137 | `testInterceptAllowsLegacyLoginSubmissionWhenStealthDisabled()` — the shipped test **encodes the insecure request shape** and must be corrected, not deleted |

**Fix direction.** Make the anonymous exemption depend on the *requested page*,
not on the request shape. A login form can only legitimately be submitted to
`index.php?page=login` — `fs_controller::loginActionUrl()` and
`StealthMode::getHiddenLoginUrl()` both always emit `page=login` — so requiring
the page costs nothing functionally and is exactly the missing check.
`isLegacyLoginSubmission()` stays in use where the stealth secret has already
been verified with `hash_equals()`, so it does not become dead code.
Consolidate the duplicated "login submission" predicate between
`src/Core/StealthMode.php` and `base/fs_maintenance_mode.php` so the two cannot
drift apart again.

**Sibling defect folded into this workstream.** The report documents a second,
independent gate bypass: the gate authorises on
`Plugins::isPublicPath($request->getPathInfo())` (`PublicAccessGate.php:38`,
`src/Core/Plugins.php:100` with `FRAMEWORK_EXACT_PUBLIC_PATHS` = `/favicon.ico`,
`/apple-touch-icon*.png`) while `index.php` dispatches on `$_GET['page']`. So
`POST /index.php/favicon.ico?page=password_reset` presents an allowlisted path
to the gate and still dispatches to a private controller (GET/HEAD on that path
short-circuit to `204`; POST is the method that reaches the handler). It is the
same gate and the same root cause — an allowlist evaluated against one
interpretation of the request while the application uses another — and VULN-0006
names it as the reachability enabler for its own PoC. It is folded here rather
than filed separately; the spec phase must confirm that folding or spin it out
(see Risks & Unknowns).

**Regression tests.** Anonymous POST with `?nlogin` to a non-login page redirects
to login; anonymous POST with a `user`/`password` body to a non-login page
redirects to login; a legitimate login submission to `page=login` still passes;
a public path carrying a private `page=` parameter is still gated.

### VULN-0003 — Login CSRF guard bypassed (MEDIUM, CWE-352, CVSS 5.4)

**Symptom.** `fs_controller::log_in()` rejects a credential POST with a missing
or invalid CSRF token, then hands control to `login::public_core()`, which
immediately re-runs the same credential flow through `process_login_logic()`
with no token check. The credential path therefore executes twice and the second
execution is unvalidated: a cross-site form POST with no token can authenticate
a victim's browser (login CSRF). The live differential is decisive — with an
invalid token the credential-evaluation message appears **once** (only the
fall-through path runs); with a valid token it appears **twice** in one response.
The shipped requirement
`openspec/specs/login-csrf/spec.md` LC-02 and its scenario ("POST al formulario
de login sin token CSRF o con token inválido … el login es rechazado") state the
opposite guarantee, so this is a spec violation, not an interpretation gap.

**Verified surface.**

| File | Line | What |
|---|---|---|
| `controller/login.php` | 197–206 | `public_core()` — renders the template, then unconditionally calls `process_login_logic()` |
| `controller/login.php` | 58 | `process_login_logic()` definition |
| `controller/login.php` | 47, 205 | The two call sites (`private_core()` and `public_core()`) |
| `base/fs_controller.php` | 958–981 | `log_in()` with the CSRF guard at 966 (`isMethod('POST') && requestHasCredentials() && !validateCsrf()`) |
| `base/fs_controller.php` | 257–261 | The anonymous branch: `!$this->log_in()` → `template = 'login/default'` → `$this->public_core()` |

**Fix direction.** Make the CSRF verdict authoritative. Early-return from
`login::public_core()` when the request is a POST that already failed CSRF
validation, leaving GET handling, `?autologin=`, plugin public-login redirects
and the wrong-password path untouched. `private_core()` is not affected: it is
only reached after `log_in()` succeeds, i.e. after `validateCsrf()` passed.

**Also in scope: a credential POST must be processed exactly once.** Today a
failed login with a *valid* token is handled by both `log_in()` and
`process_login_logic()`, which double-counts throttle failures and doubles the
user-visible error message. The throttle must reflect real submissions.

**Regression tests.** Invalid token never reaches authentication; no token field
at all never reaches authentication; a valid token still reaches the normal flow;
GET still reaches the normal flow; one credential submission produces exactly one
throttle attempt and one error message.

### VULN-0005 — Credential material in the served tree + unapplied edge deny rules (MEDIUM, CWE-538, CVSS 5.3)

**Symptom.** Two independent exposures with one root cause — the document root is
the project root, and the deny-by-default layer that is supposed to compensate is
shipped but unapplied.

1. `openspec/changes/ventas-clientes-controller-dedup/verify-report.md` is served
   statically and publishes a real deployment credential: the `admin` account's
   `log_key` (read from `fs_users.log_key`), its matching HMAC `auth_sig`, the
   session identifier, and a copy-pasteable recipe for forging an authenticated
   session. Replay was attempted twice and rejected, so this is **disclosure, not
   compromise** — but a server-side per-account secret must be treated as burned.
2. `nginx-sample.conf` ships 30 deny/`location` rules and **none are in force on
   the deployment**: `GET /config.php` returns `200` where the sample specifies
   `404`, and operator scripts execute where the sample specifies `403`. Even if
   applied, the sample covers only `base/ model/ src/ controller/ scripts/
   tests/ vendor/ tmp/ backups/ .git/` and has no rule for the planning tree.

**Verified surface.**

| Surface | State on the working tree (verified) |
|---|---|
| `openspec/changes/ventas-clientes-controller-dedup/verify-report.md:62` | The live credential is **still present** and **committed** (last touched by `1b4ec1ae`). Redacting the working tree does not remove it from history. |
| `openspec/` — 15 `verify-report.md` files, 158 `.md` files | Served; the credential-bearing one is the only file in the tree matching the 64-hex credential pattern. |
| `nginx-sample.conf:22–27` | Exact-file denies (`config.php`, `cron.php`, `debug_loader.php`, `visual_flow_demo.php`, `emergency_admin_reset.php`, `reset_admin_password.php`) |
| `nginx-sample.conf:38–48` | Prefix denies: `base/ model/ src/ controller/ scripts/ tests/ vendor/ tmp/ backups/ .git/` |
| `nginx-sample.conf:55–73` | Plugin PHP denies, ordered before the generic `location ~ \.php$` handler |
| **Not covered at all** | `openspec/`, `docs/`, `tools/`, `extras/`, `odd/`, `dev-tools/`, `managed_context/`, `test_suite_analysis/` — all eight **exist in the web root** (verified) and hold no runtime assets |
| `scripts/hooks/pre-commit` (installed at `.git/hooks/pre-commit`) | Already exists; currently only regenerates Composer autoload. Natural home for the secret scan. |

**Fix direction.**

1. Redact credential material in every `verify-report.md` and related planning
   document — described, never reproduced. Replace `logkey`, `auth_sig`, session
   identifiers and CSRF tokens with `<redacted>`. The narrative keeps its
   evidentiary value; `openspec/changes/ventas-clientes-dispatch-regression-test/verify-report.md`
   already uses this style and is the reference.
2. Extend `nginx-sample.conf` with deny locations for the eight uncovered
   non-runtime directories above. `^~` prefix locations take precedence over the
   `location ~ \.php$` handler and are matched on the normalised, decoded URI, so
   this closes the class rather than the single document. `themes/` is
   deliberately **not** added — it holds browser-fetched CSS/JS.
3. Add a pre-commit secret scan over the planning tree: a rule for 40/64-char hex
   blobs adjacent to `logkey` / `auth_sig` / `password` / `token` catches this
   case before it is committed.

**Deployment actions this change does not perform** (operator handoff; they are
success criteria, not code):
- Apply the deny rules on the live server and reload nginx; verify
  `GET /openspec/changes/ventas-clientes-controller-dedup/verify-report.md` → `403`
  and `GET /config.php` → `404`.
- **Rotate the `admin` log key.** The published `auth_sig` must be treated as
  burned. This is the only step that actually closes the finding; redaction and
  deny rules are hygiene, because the value is already in git history and in the
  public upstream repository.
- Longer term (not this change): keep the document root limited to runtime assets
  so a misconfigured server cannot expose the development tree.

**Regression tests.** A repository scan asserting no 64-hex blob adjacent to
`logkey=` / `auth_sig=` / `password=` / `token=` exists under the served tree;
`nginx-sample.conf` contains a deny location for each of the eight directories;
the pre-commit hook rejects a fixture containing a pasted credential.

### VULN-0006 — Shared-throttle login DoS (MEDIUM, CWE-799, CVSS 4.3)

**Symptom.** `controller/password_reset.php` increments the **same** per-IP
counter that gates authentication, before any input validation and with no CSRF
token required, and is reachable cross-site. Six empty POSTs lock an entire
network behind one address out of the ERP for 600 seconds, renewable, and also
refuse the password-recovery form — removing the victim's own way back in. The
demonstration is direct: after the reset bursts, the same credential POST with a
freshly minted valid token returns the ban message instead of
`Usuario o contraseña incorrectos.`, proving the password comparison is never
reached.

**Verified surface.**

| File | Line | What |
|---|---|---|
| `base/fs_ip_filter.php` | 45–47 | `__construct()` — single state file `tmp/<FS_TMP_NAME>ip.log` |
| `base/fs_ip_filter.php` | 30–31, 101–110 | `BAN_SECONDS = 600`, `MAX_ATTEMPTS = 5`, `is_banned()` |
| `controller/password_reset.php` | 74 | `new fs_ip_filter()` — the shared bucket |
| `controller/password_reset.php` | 118–134 | `process_request()`; `set_attempt()` at 128 **above** the empty-`nick_or_email` check at 131 |
| `controller/password_reset.php` | 191–217 | `process_reset()` also writes the shared counter at 217 |
| `base/fs_login.php` | 75 | `new fs_ip_filter()` — authentication's own bucket (must stay byte-identical) |
| `base/fs_login.php` | 116–141 | `log_in()` consults `is_banned()` before any password comparison |

`set_attempt()` has exactly three call sites (login credential submission,
`process_request()`, `process_reset()`), so the counter cannot be driven by
anything else.

**Fix direction.**

1. Give `fs_ip_filter` an optional **namespace** that suffixes its state file, and
   construct the reset controller's filter with a dedicated namespace, leaving the
   login bucket byte-identical. Sanitise the namespace (`preg_replace('/[^a-z0-9_]/', '', …)`)
   so even a future dynamic value cannot traverse out of `tmp/`. The parameter is
   optional, so the login path and the existing test doubles are unaffected.
2. Move `set_attempt()` **below** the empty-`nick_or_email` check so a submission
   that is not an attempt is not counted at all.
3. Require a CSRF token on the reset request.
4. State the invariant the fix encodes: **lockout state is a security control;
   only credential-submission handlers may write to the authentication bucket.**
   Any future feature that throttles itself uses its own namespace by default.

**Regression tests.** Repeated anonymous reset submissions do not lock out
authentication; an empty submission increments no counter; the login bucket path
is unchanged for the empty namespace; a hostile namespace still resolves inside
`tmp/`; the reset flow keeps its own 5-per-600 s throttle and identical messages.

### VULN-0007 — Session cookie without `Secure`, no HSTS, duplicate `Set-Cookie` (LOW, CWE-614, CVSS 3.1)

**Symptom.** Every response sets the session cookie **twice** — one bare
`path=/` header and one with `HttpOnly; SameSite=Lax` — and **neither carries
`Secure`**, on an HTTPS-only deployment. No `Strict-Transport-Security` header is
returned by any tested response. Exploitability today is bounded (TCP/80 and
TCP/8080 are closed, the certificate is a development one), which is why the
finding is LOW; it is a genuine transport-hardening defect, not a live
session-theft route.

**Verified surface.**

| File | Line | What |
|---|---|---|
| `base/fs_session_manager.php` | 99–125 | `initializeLegacySession()`: `$secure = SecureRequestDetector::isSecure()` (112), `session_set_cookie_params()` (122) |
| `src/Security/SessionManager.php` | 137–146 | Symfony path: `cookie_secure` from `SecureRequestDetector::isSecure()` |
| `src/Security/SecureRequestDetector.php` | — | `isSecure()` via `Request::create(..., $_SERVER)->isSecure()` after `TrustedProxyConfigurator::configure()` |
| `src/Core/StealthMode.php` | 470–500 | `ensurePhpSessionStarted()`: `session_name(...)` + `@session_start()`, then a defensive `setcookie()` at ~494 that sets `httponly`/`samesite` but **not** `secure` |
| `src/Security/SecurityHeaders.php` | 101–114 | `applyDefaultHeaders()` — emits `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, CSP. **No HSTS.** Applied at `index.php:134` |
| `tests/Security/SecurityHeadersTest.php` | — | Covers CSP only; no HSTS assertion exists |

**Diagnosis (to confirm, not assumed).** A `Secure`-aware detector already exists
and is already wired, so the live absence of `Secure` is most plausibly a
detection failure: behind the TLS-terminating proxy `$_SERVER['HTTPS']` is unset
and `FS_TRUSTED_PROXIES` is unset on this deployment (the report confirms
forwarding headers are not trusted here), so `isSecure()` returns `false`. The
duplicate `Set-Cookie` indicates a session is started **before** the cookie
parameters are applied — the same dual-bootstrap family as
`consolidate-session-csrf` (see "Relationship to existing work"). Both points are
hypotheses to pin with a test, not conclusions to patch around.

**Fix direction.**

1. Set the session cookie parameters **before** the session starts so exactly one
   `Set-Cookie` is emitted, carrying `Secure` (when the request is secure),
   `HttpOnly` and `SameSite=Lax`. Remove or reconcile the later duplicate
   emission so the attribute set is constant for the session lifetime.
2. Send `Strict-Transport-Security` with a long `max-age` (and
   `includeSubDomains` where appropriate) from `SecurityHeaders::applyDefaultHeaders()`,
   which is already the single place the front controller emits hardening
   headers — and therefore covers `api.php`/`cron.php` if they route through it.
3. Make the secure-cookie decision testable: the detector must be exercised for
   the proxy-terminated case, so a deployment behind a TLS proxy does not silently
   drop `Secure`.

**Regression tests.** Exactly one `Set-Cookie` header, with the full attribute
set, for a request that is secure; `Secure` present when the request is detected
as secure and absent otherwise; `Strict-Transport-Security` present on secure
responses; the `SecureRequestDetector` proxy-terminated case pinned.

### VULN-0008 — Unauthenticated credential rotation + data disclosure (MEDIUM, CWE-306, CVSS 6.5)

**Symptom.** A single anonymous `GET /index.php?page=login` carrying the
*unsigned* cookies `user`, `logkey`, `auth_sig` runs the legacy remember-me
handler. When the key does not match, the handler resolves the target account
from the **unsigned `user` cookie** and rotates that account's `fs_users.log_key`,
invalidating the victim's persistent login; it writes an audit record
`El usuario ha cerrado la sesión.` attributed to the victim; and it renders that
account's last recorded IP into the anonymous login page. Knowing a nickname is
sufficient — no password, session, CSRF token or rate limit is involved, and it
is repeatable indefinitely. Existing, disabled and absent nicknames all reach the
revocation path. Active Symfony sessions are *not* terminated
(`SessionManager::isValid()` checks only nickname and expiry, never `log_key`),
so the effect is persistent-credential invalidation rather than a forced logout —
which is why integrity is rated limited and there is no availability impact.

**Verified surface.**

| File | Line | What |
|---|---|---|
| `src/Security/LegacyAuthBridge.php` | 250–263 | `resolveLegacyLogoutNick()` — session nickname, **else the unsigned `user` cookie** (257–260) |
| `src/Security/LegacyAuthBridge.php` | 163–172 | `$user->rotate_logkey(); $user->save();` — the mutation |
| `base/fs_login.php` | 191–211 | `revokeCurrentRememberMeToken()` — a **second copy** of the same fallback at 199 (`$this->session->get('user_nick') ?: filter_input(INPUT_COOKIE, 'user')`) |
| `base/fs_login.php` | 155–189 | `log_out()`; `revokeCurrentRememberMeToken()` first (191), audit write at 187–188 attributed to `$user` |
| `base/fs_login.php` | 229–254 | `log_in_cookie()`; the `!$user \|\| !$user->enabled` branch calls `log_out(TRUE)` at ~236 |
| `base/fs_login.php` | 288–308 | `handleInvalidCookieSession()` — `$user->last_ip` interpolated into the message at 300–306 |
| `themes/AdminLTE/view/login/default.twig` | 55–66 | `get_errors()` / `get_messages()` rendered with the `raw` filter — the nickname is interpolated into both, so this is also a markup-injection class |

**Blast radius.** `base/fs_login.php` is referenced by **19 files** in the tree: 7
production files (`base/fs_controller.php`, `base/fs_auth.php`,
`base/fs_autoload.php`, `base/fs_login.php`, `base/fs_chunked_upload.php`,
`src/Core/Base/Controller.php`, `model/core/fs_user.php`) and 12 test files, with
**8 instantiation sites** outside the class itself (`base/fs_controller.php:227`,
`base/fs_chunked_upload.php:112`, `src/Core/Base/Controller.php:115`, plus five
test doubles). This is the widest-blast-radius item in the change and is why it
is scheduled after the one-line gate fixes.

**Fix direction.**

1. Remove the unsigned-cookie fallback from **both** copies.
   `resolveLegacyLogoutNick()` returns only the nickname held in the
   authenticated session; `revokeCurrentRememberMeToken()` drops its
   `$_COOKIE['user']` fallback. Fixing only one leaves the hole reachable if the
   bridge ever becomes optional. An authenticated logout still rotates the key,
   because an authenticated request always carries the session nickname; an
   anonymous request carries none and can no longer name a victim.
2. Treat "the remember-me key did not match" as a fact about the **request**, not
   about the account: clear the caller's own cookies and invalidate the caller's
   own session (which `log_out(TRUE)` already does once revocation stops following
   the cookie) and do not mutate the named account. The anti-takeover notice is
   redundant — a successful login from a new device already rotates the key, so a
   stale cookie is invalid by design.
3. Stop interpolating the stored `last_ip` into a page rendered for an anonymous
   requester. Use a neutral "session no longer valid, please sign in again"
   notice. If the warning is retained at all, it belongs in the authenticated
   user's own session or an out-of-band notification.
4. Escape the blocks that render `get_errors()` / `get_messages()`. The nickname
   is interpolated into both the "user does not exist" and ban messages, so
   escaping closes the accompanying markup-injection class rather than this one
   sink.

**Regression tests.** An anonymous request with a named cookie does not rotate
that account's key; an authenticated logout still rotates it; no per-account IP
is rendered to an anonymous requester; a bad-key request still clears the
caller's own cookies and still emits its (escaped) notice; the 19 reference sites
stay green.

## Systemic root cause

The assessment names five root causes; the six findings in this change are the
two that belong to the core:

1. **Controls are bound to one request path, not to the action.** The global
   access gate, login throttle, IP ban and uniform error messages all live in the
   main controller path. Every entry point that reaches the application another
   way re-implements authentication or omits it. This is VULN-0002 (a request
   *shape* standing in for the requested page), VULN-0006 (a second feature
   writing the authentication lockout) and VULN-0008 (a legacy handler resolving
   identity from an unverified cookie). The fixes are the same shape: bind the
   decision to the fact it should depend on.
2. **CSRF and authentication are conflated.** A token proving "this request came
   from my page" is accepted as proof of "this user is logged in", or as a reason
   to skip a boundary. VULN-0003 is the core instance: the CSRF verdict is
   computed and then discarded by a second call site, so the control does not
   gate the action it was written to gate. (The plugin instance of the same root
   cause is VULN-0004, out of scope.)

Two further root causes from the report are partially in scope:
**deny-by-default is not applied at the edge** (VULN-0005: the shipped rules are
unapplied and do not cover the planning tree) and **shared mutable state across
unrelated features** (VULN-0006: the reset feature and authentication write the
same counter). **Real runtime values shipped alongside public developer
artifacts** is the VULN-0005 disclosure half.

## Relationship to existing work

### `openspec/changes/consolidate-session-csrf` (complete, NOT archived)

That change is about **one session, one cookie, one CSRF token store**: it
eliminates the competing `PHPSESSID` cookie, makes `StealthMode` set
`session_name()` before `session_start()`, and guarantees the CSRF token survives
`session->migrate(true)`. Its success criterion is that stealth admin access
stops producing "Token de seguridad inválido".

This change does not duplicate it, and the overlap is deliberate and narrow:

| | `consolidate-session-csrf` | `harden-auth-boundaries` |
|---|---|---|
| Problem | Two session cookies break CSRF token continuity | The CSRF verdict is discarded; the anonymous gate trusts request shape |
| `login-csrf` LC-02 | Token survives the unified session (**transport** of the token) | The verdict is authoritative and a credential POST runs **once** (**enforcement** of the verdict) |
| Session cookie | Which name/backend the session uses | Whether the emitted cookie carries `Secure` and whether one or two `Set-Cookie` headers are emitted |
| Files | `src/Security/SessionManager.php`, `src/Core/StealthMode.php` (session name), `src/Security/CsrfManager.php` | `controller/login.php`, `base/fs_controller.php`, `src/Security/SecurityHeaders.php`, `base/fs_session_manager.php` |

LC-02 is the shared requirement, so the spec phase must **edit the delta, not
redefine the requirement**: LC-02 gains an explicit single-execution and
authoritative-verdict clause on top of the session-storage clause
`consolidate-session-csrf` already added. Because that change is complete but not
archived, its delta is not yet merged into `openspec/specs/login-csrf/spec.md`;
this change must be specced against the **merged** requirement and must not
create a competing delta that would lose its clause on archive. Sequencing is a
prerequisite, not a preference.

`consolidate-session-csrf` also touches `src/Core/StealthMode.php`
(`ensurePhpSessionStarted()`), which VULN-0007 must revisit for the missing
`secure` attribute and the duplicate emission. Same file, different concern —
coordinate so neither change re-opens the other.

### `openspec/changes/fix-system-updater-backup` (present in the core tree)

That change concerns the `system_updater` plugin and lives under the core
`openspec/` tree. **This change does not copy that pattern.** VULN-0001 and
VULN-0004 are plugin-only: their SDD belongs in
`plugins/system_updater/openspec/changes/{name}/`, committed in the plugin's own
repository, because `/plugins/*` is gitignored and the core must not carry plugin
code or plugin SDD artifacts. Referencing the plugin findings here as an explicit
boundary is the correct relationship; relocating or duplicating their SDD into
the core is not.

## Capabilities

### New Capabilities

| Capability | Covers |
|---|---|
| `anonymous-access-gate` | The global gate's anonymous exemption depends on the requested page, not the request shape; one shared login-submission predicate; gate and dispatch agree on the request interpretation (VULN-0002) |
| `login-throttle-isolation` | Namespaced `fs_ip_filter` state; authentication lockout is writable only by credential-submission handlers; validation precedes counting (VULN-0006) |
| `deployment-surface-hygiene` | Credential material described, never reproduced; edge deny rules cover every non-runtime directory; a pre-commit secret scan rejects pasted credentials (VULN-0005) |
| `session-transport-hardening` | Single `Set-Cookie` with `Secure`/`HttpOnly`/`SameSite=Lax`; HSTS on secure responses; secure-cookie detection verified behind a TLS proxy (VULN-0007) |
| `remember-me-revocation-authority` | Only an authenticated session names the account whose remember-me token is revoked; no per-account data is rendered to an anonymous requester (VULN-0008) |

### Modified Capabilities

| Capability | Change |
|---|---|
| `login-csrf` (LC-02) | Add to the existing requirement: the CSRF verdict is authoritative and a credential POST is processed exactly once. Must be layered onto the clause `consolidate-session-csrf` added, not replace it. |
| `session-integrity` (SI-02, SI-03) | Make the rotation-target contract explicit: `log_key` rotation is initiated by the account owner's authenticated session; a failed remember-me verification is a fact about the request and must not mutate a named account. |

## Approach

1. **Correct the tests that encode the insecure shapes first.** The shipped
   `testInterceptAllowsLegacyLoginSubmissionWhenStealthDisabled()` asserts that a
   credential POST with no `page` parameter must be exempt — that is the
   vulnerability written down as a contract. Fixing the test before the code keeps
   TDD honest and prevents the patch from being "made green" by the old
   assertion.
2. **Close the two one-line boundaries (VULN-0002, VULN-0003).** Both are
   deletions/early-returns with clear negative controls already recorded in the
   finding reports; they are the real security boundary and they unblock the
   VULN-0006 PoC's reachability.
3. **Isolate the lockout state (VULN-0006).** Additive optional constructor
   parameter, one controller call site, plus the CSRF token and the ordering fix.
   The login bucket must remain byte-identical.
4. **Fix the revocation authority (VULN-0008).** Widest blast radius; both copies
   of the fallback must go in the same change; characterisation tests around the
   legacy cookie path are the safety net.
5. **Harden the served surface (VULN-0005) and session transport (VULN-0007).**
   Both are configuration/artifact-level; they carry the operator handoff (apply
   nginx rules, rotate the key) and must not be merged as "done" without it.
6. **One change, reviewed in the FIX-PLAN order, with the per-finding regression
   tests as the delivery unit.** Each finding is independently revertible.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `src/Core/PublicAccessGate.php` | Modified | Exemption depends on the requested page (VULN-0002) |
| `src/Core/StealthMode.php` | Modified | One shared login-submission predicate; `secure` on the session-cookie sync (VULN-0002, VULN-0007) |
| `base/fs_maintenance_mode.php` | Modified | Drop the duplicated predicate, delegate to the single source (VULN-0002) |
| `src/Core/Plugins.php` | Verify | `isPublicPath()` / `FRAMEWORK_EXACT_PUBLIC_PATHS` vs `page=` dispatch (VULN-0002 sibling) |
| `controller/login.php` | Modified | Authoritative CSRF verdict; single credential evaluation (VULN-0003) |
| `base/fs_controller.php` | Verify | `log_in()` guard and the anonymous branch remain the only entry into `public_core()` (VULN-0003) |
| `base/fs_ip_filter.php` | Modified | Optional namespace suffixing the state file (VULN-0006) |
| `controller/password_reset.php` | Modified | Namespaced filter; `set_attempt()` after validation; CSRF token (VULN-0006) |
| `nginx-sample.conf` | Modified | Eight new `^~` deny locations (VULN-0005) |
| `openspec/**/verify-report.md` + planning docs | Modified | Redact credential material (VULN-0005) |
| `scripts/hooks/pre-commit` | Modified | Secret scan over the planning tree (VULN-0005) |
| `base/fs_session_manager.php` | Modified | Cookie parameters before session start; single emission (VULN-0007) |
| `src/Security/SessionManager.php` | Verify | Symfony path keeps `cookie_secure` from the detector (VULN-0007) |
| `src/Security/SecurityHeaders.php` | Modified | Emit HSTS on secure responses (VULN-0007) |
| `src/Security/SecureRequestDetector.php` | Verify | Proxy-terminated detection pinned by a test (VULN-0007) |
| `src/Security/LegacyAuthBridge.php` | Modified | `resolveLegacyLogoutNick()` returns only the session nickname (VULN-0008) |
| `base/fs_login.php` | Modified | Second fallback removed; neutral notice; no `last_ip` to anonymous requesters (VULN-0008) |
| `themes/AdminLTE/view/login/default.twig` | Modified | Escape `get_errors()` / `get_messages()` (VULN-0008) |
| `tests/**` | Modified/Added | Correct the gate test; per-finding regression tests |

## Non-Goals

- **Not a general authentication rewrite.** No centralised middleware, no
  re-architecture of the legacy cookie path, no removal of the remember-me
  mechanism. The remember-me feature is kept; only *who may name the account* and
  *what is disclosed to whom* change.
- **Not a plugin change.** VULN-0001 and VULN-0004 are not touched, not
  mitigated from the core, and not tracked in this repository.
- **Not a purge of git history.** Redacting the credential-bearing document does
  not remove it from history or from the public upstream repository. Rotation is
  the closure; redaction is hygiene. Rewriting published history is not proposed.
- **Not a document-root relocation.** Restricting the deployment to runtime
  assets is the correct long-term fix and is explicitly deferred; this change
  ships the deny rules that make the current layout safe.
- **Not an authenticated-authorization review.** Post-login object-level
  authorization is untested and remains untested here.
- **No new baseline, no `ignoreErrors` growth.** Findings are closed by correcting
  the contract or the code.
- **No CSP `unsafe-inline` reduction.** Recommended by the report, deliberately
  out of scope (it would touch rendering paths this change must not destabilise).

## Risks & Unknowns

| # | Risk / Unknown | Severity | Handling |
|---|---|---|---|
| 1 | **`consolidate-session-csrf` is complete but not archived.** Its LC-02 delta is not merged into `openspec/specs/login-csrf/spec.md`. A competing delta here could lose its clause on archive. | Medium | Sequence: archive (or merge) that change first, then spec LC-02 against the merged requirement. Flag as a prerequisite in the spec phase. |
| 2 | **Same-file overlap with `consolidate-session-csrf`** on `src/Core/StealthMode.php::ensurePhpSessionStarted()`. VULN-0007 revisits the session-cookie emission that change just stabilised. | Medium | Coordinate; the VULN-0007 test must assert the single-`Set-Cookie` invariant, so a regression is caught rather than argued. |
| 3 | **The `Secure` diagnosis is a hypothesis.** A `Secure`-aware detector already exists and is wired; the live absence may be a detection failure behind the proxy, or a second code path. Patching `SecurityHeaders` alone would not fix it. | Medium | Pin the detector's proxy-terminated behaviour with a test before changing the emission path. If the detector is wrong, fix the detector. |
| 4 | **The duplicate `Set-Cookie` cause is not established.** It may be a pre-parameter `session_start()` (dual bootstrap) rather than the defensive `setcookie()`. | Medium | Establish it with a test, not by removing the defensive call and hoping. |
| 5 | **VULN-0008 blast radius.** 19 files reference `base/fs_login.php`; 8 instantiation sites. The legacy cookie path has characterisation tests, but the revocation path's behaviour change (no rotation for an anonymous request) is argued from control flow, not measured against a database-backed instance. | Medium | Change both fallbacks in one commit; rely on `tests/Security/FsLogin*`, `LegacyAuthBridgeTest`, `RememberMeSignatureRequiredTest`; add the anonymous-request regression test. |
| 6 | **VULN-0005 closure is not achievable in code.** The credential is committed and public upstream; only rotation closes it, and that is an operator action. Merging the redaction without rotation leaves the finding open while looking fixed. | High | Report the change as *incomplete until rotation and nginx reload are confirmed*; state the retest explicitly in Success Criteria. |
| 7 | **Folding the path-info/`page=` dispatch mismatch into VULN-0002.** It is the same gate and the same root cause, but the report files it as a second, independent bypass. | Low | Spec phase confirms the folding or spins it out; either way it is closed in this change, not silently dropped. |
| 8 | **`fs_ip_filter` namespace default must be inert.** Any change to the empty-namespace path would move the authentication lockout file and reset every live ban. | Medium | Assert the legacy path byte-for-byte (`tmp/<FS_TMP_NAME>ip.log`) in a test before and after. |
| 9 | **Gate exemption narrowing could break a legitimate flow.** `?autologin=`, plugin public-login redirects, OIDC `isPublicPath` prefixes and favicon probes all pass through the same gate. | Medium | Existing coverage (`LegacyLoginRedirectTest`, `StealthModeTest`, `PublicAccessGateTest`) must stay green; the narrowing is on the exemption only, never on `Plugins::isPublicPath()`. |
| 10 | **The nginx rules are an inactive sample.** Nothing in the repository executes them, and they cannot be verified here (`nginx -t` plus a live request is owed by the operator). | Medium | Ship the rules and the retest; record it as an unverified gate rather than a passed one. |
| 11 | **Pre-commit hooks are not enforced for every contributor** (local `.git/hooks`, not a server-side check). | Low | Treat the scan as defense in depth; the redaction sweep is the primary control. |
| 12 | **Correcting `testInterceptAllowsLegacyLoginSubmissionWhenStealthDisabled()` is a deliberate test change.** | Low | It asserts an insecure contract; the replacement must cover the legitimate `page=login` submission *and* both bypass inputs. |

## Rollback Plan

Each finding is independently revertible; there is no cascade.

- **VULN-0002 / VULN-0003**: revert the exemption narrowing and the `public_core()`
  early-return. Both are additive guards; reverting restores today's behaviour
  without touching data or schema.
- **VULN-0006**: revert to the shared bucket. No data migration — the namespaced
  file is simply no longer read, and the login bucket was never moved.
- **VULN-0008**: revert both fallback removals together. Reverting only one
  re-opens the hole; that is the one place where a partial rollback is not safe
  and must be treated as all-or-nothing.
- **VULN-0005 / VULN-0007**: revert the config, header and document changes. The
  operator handoff (nginx rules, key rotation) is not reverted by a code revert —
  a rotated key stays rotated, which is the desired direction.
- **FS_CSRF_SOFT** is not touched by this change, so no CSRF soft-mode window is
  needed.

## Dependencies

- `symfony/http-foundation` ^7.4 — `Request::isSecure()`, `Request::getPathInfo()`
  (already in use).
- `symfony/security-csrf` ^7.4 — `validateCsrf()` / `isCsrfValid()` (already in
  use).
- `openspec/changes/consolidate-session-csrf` — its LC-02 delta must be merged or
  archived before this change is specced (risk 1).
- `plugins/system_updater` — independent; no dependency in either direction. Its
  two findings are tracked in the plugin's own repository.
- No new Composer dependency. No schema change. No plugin change.

## Success Criteria

- [ ] Anonymous POST with `?nlogin`, and anonymous POST with a `user`/`password`
      body, to **every** non-login controller page returns `302` to login.
- [ ] A legitimate login submission to `page=login` is unaffected; the plugin
      public-path allowlist, OIDC redirects and favicon probes are unaffected.
- [ ] A credential POST with an invalid token, and with no token at all, performs
      no authentication.
- [ ] One credential submission produces exactly one throttle attempt and one
      error message.
- [ ] An anonymous request carrying a named `user` cookie does not rotate that
      account's `log_key`; an authenticated logout still rotates it.
- [ ] No per-account IP is rendered to an anonymous requester; the login page's
      error/message blocks are escaped.
- [ ] Repeated anonymous password-reset submissions do not lock out
      authentication; an empty submission increments no counter; the login bucket
      path is unchanged.
- [ ] Exactly one `Set-Cookie` header per response, with `Secure`, `HttpOnly` and
      `SameSite=Lax`; `Strict-Transport-Security` present on secure responses.
- [ ] `nginx-sample.conf` denies all eight previously uncovered non-runtime
      directories; a pre-commit scan rejects a fixture containing a pasted
      credential.
- [ ] No credential material matching the 40/64-hex pattern adjacent to
      `logkey` / `auth_sig` / `password` / `token` remains under the served tree.
- [ ] **Operator handoff confirmed** (not verifiable in this repository):
      nginx reloaded and `GET /openspec/changes/ventas-clientes-controller-dedup/verify-report.md`
      → `403`, `GET /config.php` → `404`; the `admin` log key rotated.
- [ ] `ddev exec php vendor/bin/phpunit` green, including the corrected
      `PublicAccessGateTest` and the new per-finding regressions.
- [ ] `ddev exec composer phpstan` and `phpstan:plugins` unchanged (no new
      findings, no `ignoreErrors` growth).
- [ ] Re-running the strix scan recipe (FIX-PLAN §6) reports the six findings
      closed and does not report a new one in the touched surfaces.

## Estimated Size

| Bucket | Files | Est. lines |
|---|---|---|
| VULN-0002 gate + predicate consolidation | 3 source, 1 test corrected | ~50 source, ~60 test |
| VULN-0003 login CSRF + single execution | 1 source, 1 new test | ~20 source, ~70 test |
| VULN-0008 revocation authority + disclosure | 3 source (1 template), 3 tests | ~40 source, ~90 test |
| VULN-0006 throttle isolation | 2 source, 1 test | ~30 source, ~60 test |
| VULN-0005 redaction + nginx + hook | ~5 docs, 2 config, 1 test | ~40 + the redaction sweep |
| VULN-0007 cookie flags + HSTS | 3 source, 2 tests | ~35 source, ~60 test |

Roughly **15 source files, ~6 config/doc files, ~10 test files, ~500-600 lines**
excluding the `verify-report.md` redaction sweep. That exceeds the 400-line
review budget as a single unit, so the tasks phase must plan **chained PRs by
finding** in the FIX-PLAN order (0002 → 0003 → 0008 → 0006 → 0005 → 0007), each
with its own regression test as the delivery unit. Findings 0002, 0003 and 0006
are independently revertible one-line-to-small guards and are the natural first
slice; 0008 carries the blast radius and 0005 carries the operator handoff.
