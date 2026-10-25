# Design: harden-auth-boundaries

- **Change**: `harden-auth-boundaries` (CORE FSFramework change)
- **Artifact store**: openspec
- **Phase**: design
- **Inputs**:
  - `openspec/changes/harden-auth-boundaries/proposal.md` (approved)
  - `penetration_test_report.md`, `FIX-PLAN.md`, `vulnerabilities/vuln-000{2,3,5,6,7,8}.md`
  - Canonical specs (post-archive, read as the source of truth): `openspec/specs/login-csrf/spec.md` (amended **LC-02**), `openspec/specs/session-consolidation/spec.md` (**SC-01**…**SC-05**, including the `### Known deviation` block and **SC-04**), `openspec/specs/session-integrity/spec.md` (amended **SI-01**)
  - Predecessor change, archived 2026-10-25: `openspec/changes/archive/2026-10-25-consolidate-session-csrf/` (`design.md`, `verify-report.md`)
- **Predecessor status**: `consolidate-session-csrf` is **archived and merged**. The sequencing prerequisite recorded as risk 1 in the proposal is **satisfied**; this design is written against the merged canonical requirements, not against a pending delta.

## Technical Approach

Six workstreams, one per core finding, sharing one shape: **bind each decision to the fact it should depend on** instead of to the shape of the request. The requested page (not the request body) decides the anonymous exemption; the CSRF verdict (not a second call site) decides whether credentials are evaluated; the authenticated session (not an unsigned cookie) names the account whose remember-me token is revoked; the owning feature (not the shared login counter) owns its throttle state; the emitted cookie parameters (not the php.ini defaults in force at `session_start()` time) decide the session transport attributes; and the served surface is deny-by-default rather than "the shipped rules, if applied".

Two small shared artifacts carry most of the design:

1. `base/fs_auth_request.php` — `fs_auth_request`, a dependency-free, static-only class that is the **single definition of the request facts the authentication boundary depends on**: the canonical requested page name (mirroring `index.php`'s own rule), whether the request is a stealth login submission, and whether the request carries a credential submission. Today that knowledge exists in four places that can drift (W1).
2. `SessionManager::sessionCookieParams()` + a promoted `resolveCookiePath()` — the **single definition of the session cookie attribute set**, consumed by the Symfony path, the legacy path and `StealthMode` (W6). `consolidate-session-csrf` already set the precedent by promoting `resolveSessionName()` from `private` to `public` for exactly this reason.

## Architecture Decisions

| # | Decision | Choice | Alternative rejected | Rationale |
|---|---|---|---|---|
| D1 | Where the single "login submission" predicate lives | New dependency-free `base/fs_auth_request.php` (`final class fs_auth_request`, all-static), `require_once`d by both callers | Put it on `StealthMode` (namespaced, `src/Core`) or on `fs_maintenance_mode` (`base/`, plugin-loaded) | `StealthMode` is not guaranteed loaded when the `system_updater` plugin loads `base/fs_maintenance_mode.php` directly, and vice versa. A `class_exists` guard on either side degrades to a silent local copy — i.e. drift, the exact defect being fixed. A dependency-free `base/` file has no load-order hazard. |
| D2 | The canonical requested-page rule | `fs_auth_request::requestedPage(array $query): string` — `is_string`, `trim`, then `/^[a-z][a-z0-9_]*$/`, else `''` | `($_GET['page'] ?? '') === 'login'` (the raw comparison in the pentest's patch) | `index.php:216-229` (`fs_get_requested_page_name()`) already applies exactly this rule. A raw `===` makes the gate and the dispatcher disagree again for `page=login%20` (dispatcher: login; raw gate: not login → spurious 302). Mirroring the dispatcher's rule is the point of VULN-0002, not an extra. |
| D3 | The public-path / `page=` mismatch (proposal's folded sibling) | **Fold it in.** `PublicAccessGate` exempts a framework exact public path **only when the request does not name a controller page**; plugin-registered prefixes stay exempt unconditionally | Reorder the gate so the `page=` check runs first (would change plugin-prefix semantics); or fix it only in `index.php` (leaves the gate's decision wrong) | Same gate, same root cause, same file, same test file, and it is the reachability enabler VULN-0006 names for its own PoC. Plugin prefixes are opt-in by the plugin that owns the route, so narrowing them buys nothing and risks `/oauth/*` regressions. |
| D4 | The `Secure` diagnosis (VULN-0007, proposal risk 3) | **Pinned to a source-level cause, not a hypothesis.** `StealthMode::ensurePhpSessionStarted()` (`src/Core/StealthMode.php:472-477`) calls `session_name()` + `session_start()` **without** applying any session cookie parameters, and it runs from `PublicAccessGate::intercept()` → `hasAuthenticatedSession()`, i.e. **before** `SessionManager::initialize()` | Patch `SecurityHeaders`/add `Secure` to the defensive `setcookie()` only | The two live `Set-Cookie` headers are explained exactly by this: PHP's own cookie from the parameterless `session_start()` (bare `path=/`) plus `StealthMode`'s defensive `setcookie()` (`HttpOnly; SameSite=Lax`, no `secure`). `SecureRequestDetector::isSecure()` is already correct and already pinned for both proxy cases (`tests/Security/SecureRequestDetectorTest.php:60,73`); the *detector* is not the defect, the *ordering* is. |
| D5 | The duplicate `Set-Cookie` cause (proposal risk 4) | Same root cause as D4. `ensurePhpSessionStarted()` applies `session_set_cookie_params(SessionManager::sessionCookieParams())` + `ini_set('session.use_strict_mode','1')` **before** `session_start()`, and the defensive `setcookie()` at `:494-499` is **deleted** | Keep the defensive `setcookie()` and make it attribute-identical (two headers, same attributes) | The proposal's success criterion is *exactly one* `Set-Cookie`. With cookie parameters applied before `session_start()` and `use_strict_mode=1`, PHP's own emission is complete: it emits on a new/regenerated id and stays silent when the client already holds the valid active id — which is precisely the case the defensive call was compensating for. Attribute-identical duplicates would satisfy RFC 6265 but not the stated criterion. This is also recommendation 8 of the pentest report verbatim ("enable strict session mode at the outermost entry point … a client-chosen session identifier is currently adopted before that happens"). |
| D6 | HSTS emission point | `SecurityHeaders::applyDefaultHeaders()` emits `Strict-Transport-Security: max-age=31536000` when `SecureRequestDetector::isSecure()`, `max-age` overridable via `FS_HSTS_MAX_AGE` (0 disables) | Hardcode `includeSubDomains`; or emit from a new middleware | The report's `applyDefaultHeaders()` parenthetical is **false on this tree**: it is called only from `index.php:134` — `api.php` and `cron.php` call it nowhere. `includeSubDomains` on a shared parent (`*.ddev.site`) pins HTTPS for a whole zone and is not cheaply reversible, so it is **off by default** and opt-in. |
| D7 | VULN-0003 guard placement | Inside `login::process_login_logic()`, immediately before `handleCredentialLogin()`, expressed as one predicate `shouldSkipCredentialLogin()` | At the top of `login::public_core()` (the pentest patch) | A top-of-`public_core()` guard also blocks the **plugin public-login redirect** path (`process_login_logic():60-63` → `redirectToPublicLogin()` → `exit`), which the proposal explicitly requires to stay untouched. Placing it after the redirect and before the credential branch preserves GET handling, `?autologin=`, plugin redirects (including their POST shape) and the wrong-password path. |
| D8 | The CSRF verdict read in D7 | `$this->isCsrfValid()` (already `public`), **not** `requireCsrf()` | `requireCsrf()` | `requireCsrf()` validates when nobody has yet (`csrf_checked === false`) and would therefore reject a POST to `page=login` that legitimately carries no token and no credentials. `csrf_valid === false` is only ever set by a `validateCsrf()` that already ran, so `isCsrfValid()` is not fail-open **for the authenticating shape** (`log_in()` validates whenever `requestHasCredentials()` is true, and that is the only shape that can authenticate). |
| D9 | "Exactly once" mechanism | `fs_controller::log_in()` records `credentialLoginEvaluated` when it actually hands a credential POST to `fs_login::log_in()`; `shouldSkipCredentialLogin()` returns true when that flag is set | Compare `$_POST` before/after, or remove the credential branch from `process_login_logic()` entirely | The flag is the only observable that distinguishes "`log_in()` evaluated it" from "the CSRF guard returned before evaluating it". Removing the branch would also remove `handleAutoLogin()` and the plugin redirect from the public path. |
| D10 | Modern-controller credential CSRF (**design-phase discovery**, see "Discoveries") | **In scope.** `src/Core/Base/Controller::initializeConnectedSession()` gains the same credential-POST CSRF guard as `fs_controller::log_in()` | Defer to a follow-up | It is the same control on a second door — literally the systemic root cause this change exists to close. An authenticated victim POSTing the attacker's `user`/`password` to any FS2025 route switches the victim's session to the attacker's account, with no token. It was invisible to the pentest because the anonymous shape is gated (so no unauthenticated test could reach it). Cost is ~10 lines + 2 tests; independently revertible. |
| D11 | `fs_ip_filter` namespace shape | Optional constructor parameter `string $namespace = ''`; suffix = `preg_replace('/[^a-z0-9_]/','',strtolower($namespace)) . '_'` | A separate filter class, or a static factory | Optional parameter keeps the login path and every existing test double byte-identical (proposal risk 8). A separate class duplicates the read/write/prune logic. |
| D12 | Where the reset flow's CSRF is enforced | `controller/password_reset.php` calls `requireCsrf()` in `process_request()` and `process_reset()` | Add a token to the template | The template **already** emits `{{ csrf_field() }}` in both forms (`themes/AdminLTE/view/password_reset.html.twig:80,116`). The token was always sent and never validated — a controller-side change only, no template diff. |
| D13 | Second copy of the unsigned-cookie fallback (`base/fs_login.php:199-210`) | **Delete the duplicate revocation implementation** rather than patch it | Patch it to drop only the `?: filter_input(INPUT_COOKIE,'user')` part | The branch is unreachable in this tree (`getLegacyAuthBridge()` returns non-null whenever `SessionManager` exists, and it always does), so patching leaves two copies of the same rule to drift again — the exact complaint in the proposal. Deleting removes the drift surface. Trade-off recorded: in a hypothetical tree without `SessionManager`, an authenticated logout would no longer rotate `log_key` — a loss of a hardening action, never a security hole. |
| D14 | Audit attribution on logout | `log_out()` attributes the audit record to the **authenticated session nickname**, not `filter_input(INPUT_COOKIE,'user')` (`base/fs_login.php:179,187`) | Leave it; it is not in the proposal's fix direction | The report lists "access-log forgery (integrity)" as an impact of VULN-0008 and the same anonymous request reaches it. Leaving it would half-close the finding while the acceptance criteria claim it is closed. |
| D15 | `last_ip` disclosure | `handleInvalidCookieSession()` uses a neutral notice and calls `log_out(TRUE)` on **both** branches | Keep the two-branch message, drop only the IP text | The current else-branch calls `log_out()` **without** `rmuser`, so the caller's own stale cookie survives and the next request repeats the flow. `log_out(TRUE)` on both branches is what the proposal's point 2 describes ("clear the caller's own cookies and invalidate the caller's own session"), and it terminates the repeat loop. |
| D16 | Template escaping | Remove `|raw` from `themes/AdminLTE/view/login/default.html.twig:55,63` | Escape in PHP before `new_error_msg()` | Twig `{{ }}` auto-escapes; the PHP side interpolates the nickname into messages used elsewhere too, so escaping at the sink would be one of many. Note: the proposal names `.../login/default.twig`; the real file is `.../login/default.html.twig`. |
| D17 | The two bare `session_start()` sites (`controller/admin_agentes.php:271`, `src/Security/CsrfManager.php:133`) | **In scope**, folded into W6 | Leave them as the canonical spec's `### Known deviation` describes | `admin_agentes.php:271` starts a session with the **default name** when none is active — that emits a `PHPSESSID` cookie and re-opens the exact dual-cookie regression `consolidate-session-csrf` just closed. `CsrfManager:133` is the same shape in a file W6 already touches. Both are one line. The spec phase must also amend the `session-consolidation` Known-deviation paragraph, or the canonical spec will keep documenting a deviation that no longer exists. |
| D18 | `$tokenVerified` intra-request invalidation + non-discriminating `CsrfTokenSurvivesMigrationTest` | **Out of scope**, deferred with a named owner | Fold into W6 | No finding maps to either. `$tokenVerified` correctness depends on `NativeSessionCsrfStorage`'s raw-`$_SESSION` semantics; changing it is a CSRF-continuity change that belongs to `session-integrity` (SI-01), and it would put an unrelated CSRF behaviour change inside an auth-boundary change. The archived verify run's own verdict is "latent; does not bite today". Deferral is recorded in Non-goals, not silently dropped. |
| D19 | The third page-canonicalisation copy in `fs_maintenance_mode::isBackendPageRequest()` (`base/fs_maintenance_mode.php:542-553`, `strtolower(trim())` + allowlist) | **Deferred**, documented residual | Adopt `fs_auth_request::requestedPage()` there too | No finding attaches to it, and adopting the strict rule changes which pages skip maintenance — an unrelated behaviour change inside a security-boundary change. Note the real divergence: `page=ADMIN_HOME` is a backend page to maintenance mode and an invalid page to `index.php`. Recorded for a follow-up, not fixed here. |
| D20 | `session-integrity` delta scope | **Do not touch SI-02/SI-03.** The rotation-target invariant lives in the new `remember-me-revocation-authority` capability | Amend SI-02 as the proposal's Affected-Capabilities table suggests | SI-02 ("`set_password()` MUST rotate `log_key`") must keep admitting the password-reset flow, which legitimately rotates the key with **no authenticated session** (the emailed token is the authority). Restating SI-02 as "rotation is initiated by the account owner's authenticated session" would contradict shipped, intended behaviour. The invariant is about *remember-me revocation*, not about every `log_key` rotation. |
| D21 | `login-csrf` delta shape | **New requirement ID** (`LC-06`) added alongside LC-02 | Amend LC-02 a second time | LC-02 is a *transport/storage* clause (which session the token is read from, and that it survives the cookie rename) added by the archived change; the new clause is an *enforcement* clause (what the verdict does). A second amendment to the same ID is exactly the competing-delta hazard the proposal flags; separate IDs keep both independently testable and cannot lose each other on archive. |
| D22 | Pre-commit hook placement | The secret scan runs **above** the `composer.json` early-exit at `scripts/hooks/pre-commit:9-13` | Append after the Composer block | The current hook exits `0` whenever `composer.json` is not staged, so anything appended after it never runs for a normal commit. |

## 1. W1 — VULN-0002: anonymous access gate (AAG)

### Approach

1. `base/fs_auth_request.php` (new): the single definition of the three request facts. Pure, static, dependency-free, array-in/boolean-out so it is unit-testable without superglobals and without a booted kernel.
   - `requestedPage(array $query): string` — canonical page name (D2).
   - `isStealthSubmission(string $method, array $query, array $post): bool` — the consolidated login-submission predicate, moved verbatim in semantics from `base/fs_maintenance_mode.php:555-566` (which already has the pure signature).
   - `hasCredentialSubmission(string $method, array $post): bool` — POST + `password` + (`user` | `nick`).
2. `src/Core/StealthMode.php`
   - `isHiddenLoginPageRequest()` → `fs_auth_request::requestedPage($_GET) === 'login'`.
   - `isHiddenLoginSubmission()` → delegates to `fs_auth_request::isStealthSubmission(...)`.
   - `isLegacyLoginRequest()` / `isLegacyLoginSubmission()` public wrappers stay (external contract).
3. `src/Core/PublicAccessGate.php`
   - The exemption becomes page-based: `isLegacyLoginRequest()` alone.
   - `isLegacyLoginSubmission()` stays in use behind `hash_equals()` in `StealthMode::hasAccess()` / `isHiddenLoginAllowed()`, so it does not become dead code.
   - The public-path exemption is narrowed: a framework exact public path is exempt only when the request does not name a controller page (D3).
4. `src/Core/Plugins.php`
   - Extract `isFrameworkExactPublicPath(string $path): bool` from the duplicated `in_array(..., FRAMEWORK_EXACT_PUBLIC_PATHS, true)` at `:107` and `:157`.
   - `shouldRespondNoContentForBrowserProbe()` (`:145-158`) additionally requires that the request names no controller page — the same interpretation mismatch in a second place. Today `GET /index.php/favicon.ico?page=password_reset` returns `204` instead of the `302` a private page owes; that is not a bypass (nothing dispatches) but it makes the retest ambiguous and it is the same root cause.
5. `base/fs_maintenance_mode.php` — `isStealthLoginSubmission()` delegates to `fs_auth_request`; the local copy is deleted. `hasStealthAdminAccess()` keeps its public signature (the `system_updater` plugin calls it through `lib/maintenance_mode_compat.php`).
6. `phpstan.neon` — add `base/fs_auth_request.php` to `scanFiles` (required so `src/Core/StealthMode.php` resolves it) and to `paths` (recommended, so the new file is actually analysed).

### Alternatives considered and rejected

- **Keep `isLegacyLoginSubmission()` in the gate and add a page check only for it.** Rejected: leaves two exemption paths with different semantics and leaves `?nlogin` on a non-login page exempt whenever the body is empty.
- **Require `page=login` AND a POST method in the gate.** Rejected: `isLegacyLoginRequest()` must keep admitting the `GET ?page=login` page render; the gate's exemption is about the page, and the method is already implied by the form's action.
- **Canonicalise the page inside `index.php` only** (make the dispatcher read the same rule the gate uses). Rejected: `index.php` is not the only dispatcher (`api.php`, `cron.php`, modern routes), and the gate must make a correct decision before any of them run.
- **Widen `FRAMEWORK_EXACT_PUBLIC_PATHS` handling to plugin prefixes too.** Rejected (D3).

### Blast radius

`src/Core/PublicAccessGate.php` is constructed once per request in `index.php:140`, before Symfony routing, plugin dispatch and every controller. It is also constructed by `tests/Components/PublicAccessGateTest.php` and `tests/Security/LegacyLoginRedirectTest.php`. `StealthMode` is constructed by the gate, by `controller/login.php::createStealthMode()`, and by `tests/Components/StealthModeTest.php`. `base/fs_maintenance_mode.php` is loaded only by the `system_updater` plugin (via `lib/maintenance_mode_compat.php`) and by four test files — **it is not on the core request path**, which is why the consolidation is a low-risk refactor there. `Plugins::shouldRespondNoContentForBrowserProbe()` runs at `index.php:163` on the probe path only.

New failure mode to guard: `?page[]=login` makes `$_GET['page']` an array. A `trim()` on it is a `TypeError` in PHP 8. `fs_auth_request::requestedPage()` must `is_string()`-guard and return `''` (→ gated), never fatal.

### Rollback

Revert `PublicAccessGate` to the two-predicate exemption and `StealthMode::isHiddenLoginPageRequest()` to the raw comparison. `fs_auth_request` may be left in place unused (it is additive and inert) or reverted with the same commit. `fs_maintenance_mode` reverts to its local copy. No data, no schema, no session state.

### Acceptance criteria (observable)

- Anonymous `POST /index.php?page=admin_info&nlogin=1` → `302` to `index.php?page=login`.
- Anonymous `POST /index.php?page=admin_info` with `user`/`password` in the body → `302` to `index.php?page=login`.
- The same two shapes against **every** non-login controller page → `302`. No `200` with a controller body.
- Anonymous `POST /index.php?page=password_reset&nlogin=1` → `302`, and `password_reset::process_request()` does **not** execute (no reset message in the body).
- Anonymous `POST /index.php?page=login` with `nlogin` and/or `user`/`password` → not intercepted by the gate (reaches the login controller; behaviour unchanged from today).
- `GET /index.php?page=login` (stealth disabled) → not intercepted.
- Anonymous `POST /index.php/favicon.ico?page=password_reset` → `302` (was `200`).
- Anonymous `GET /index.php/favicon.ico?page=password_reset` → `302` (was `204`).
- `GET /index.php/favicon.ico` (no `page=`) → `204`, unchanged; `GET /index.php?page=login` → `200`.
- Plugin-registered public prefixes (`/oauth`, `/.well-known`) → not intercepted, unchanged, with or without a `page=` parameter.
- `?page[]=login` (array) → gated with `302`; no `TypeError`, no 500.
- `?page=LOGIN` → gated with `302`: the dispatcher's `preg_match('/^[a-z][a-z0-9_]*$/')` rejects uppercase, so no controller page is dispatched and the gate agrees (fail-closed).
- `?page=login%20` → **exempt**: the dispatcher's `fs_get_requested_page_name()` (`index.php:218-227`) trims the value to `login` and dispatches the login controller, so the gate MUST resolve it identically — AAG-02 ("MUST NOT compare the raw `$_GET['page']` value") and AAG-05 ("the gate and the dispatcher MUST agree on which page a request names"). A raw comparison would redirect a legitimate login submission away, which is the very defect AAG-02 removes.
  - *Corrected after U1.6.* The original text asserted both values were gated, which contradicted AAG-02, AAG-05 and D2's chosen rule, and was internally inconsistent with its own parenthetical. The implementation and `testInterceptGatesInvalidPageValuesButAgreesWithTheDispatcher` follow the normative requirements; this line now matches them.
- `StealthModeTest`, `PublicAccessGateTest`, `LegacyLoginRedirectTest`, `StealthSessionBootstrapTest`, `FsMaintenanceModeTest` stay green.
- The corrected `testInterceptAllowsLegacyLoginSubmissionWhenStealthDisabled()` asserts the **secure** shape (a credential POST to `page=login` is exempt; a credential POST to another page is not).

## 2. W2 — VULN-0003: login CSRF verdict is authoritative, credentials evaluated once (LC-06)

### Approach

1. `base/fs_auth_request.php::hasCredentialSubmission()` is the single definition of "this POST is the login form's credential submission".
2. `base/fs_controller.php`
   - `requestHasCredentials()` delegates to `fs_auth_request::hasCredentialSubmission($this->request->getMethod(), $this->request->request->all())` and becomes `protected` (was `private`; no behaviour change, needed by subclasses only if a future one shares the guard — kept for symmetry with the modern base).
   - `log_in()` sets `$this->credentialLoginEvaluated = true` when the credential POST passed CSRF and is about to be handed to `fs_login`.
   - New `protected function credentialLoginEvaluated(): bool`.
3. `controller/login.php`
   - `process_login_logic()` gains one guard immediately before `handleCredentialLogin()`:
     ```php
     if ($this->shouldSkipCredentialLogin()) {
         return;
     }
     ```
   - `protected function shouldSkipCredentialLogin(): bool` returns `true` when the request is a POST **and** either (a) `!$this->isCsrfValid()` (the verdict from `fs_controller::log_in()` is final — VULN-0003), or (b) `$this->credentialLoginEvaluated()` (this POST was already evaluated — one evaluation per submission).
   - The plugin public-login redirect, `restoreBufferedVariables()`, `switchDatabaseIfRequested()`, the `?logout` handling, the `logged_on` redirect and `handleAutoLogin()` all keep running before the guard.
4. `src/Core/Base/Controller.php` (D10) — `initializeConnectedSession()` gains the equivalent guard before `$this->login_tools->log_in($this->user)`: reject a credential POST whose CSRF token is not valid, using the same shared predicate and the controller's own CSRF helper. No `public_core()` exists on this base, so there is no double-execution half here — only the authoritative-verdict half.

### Alternatives considered and rejected

- **Guard at the top of `public_core()`** (the pentest patch, verified `OK (3 tests, 4 assertions)`). Rejected: it also blocks `process_login_logic():60-63`'s plugin public-login redirect, which the proposal lists as a preserved path. The pentest's own preserved-behaviour run did not exercise a plugin redirect on a **POST**, so the test suite could not have caught it.
- **`requireCsrf()` instead of `isCsrfValid()`.** Rejected (D8).
- **Make `fs_login::log_in()` the only credential entry point** and delete the branch from `process_login_logic()`. Rejected: `handleAutoLogin()` and the plugin redirect share the method; splitting it is a larger refactor with a wider diff for the same observable outcome.
- **Suppress only the duplicate error message, not the duplicate evaluation.** Rejected: the throttle attempt is the security-relevant half.
- **Skip W2b (modern controller) to a follow-up.** Rejected (D10).

### Blast radius

`fs_controller::log_in()` runs in the constructor of **every** legacy page controller (`base/fs_controller.php:258`), i.e. every request. The change is additive: the only new observable is that a credential POST is evaluated once instead of twice. `controller/login.php` is reached only for `page=login`. `src/Core/Base/Controller.php` is the base of every FS2025 controller — its guard fires only when a POST carries `password` **and** (`user`|`nick`) **and** the token is invalid; a legitimate modern form that posts those field names with a valid token is unaffected.

**Intended, user-visible side effect to document in the PR body:** the IP ban counter (`fs_ip_filter::MAX_ATTEMPTS = 5`, `is_banned()` is `count > 5`) and `LoginThrottle` (`MAX_ATTEMPTS = 6`) are currently incremented **twice** per failed submission. After this change they are incremented once, so the lockout now needs 6 real failed submissions instead of 3. This is the proposal's stated intent ("the throttle must reflect real submissions"), not a regression, and it must not be mistaken for one during review.

### Rollback

Delete the `shouldSkipCredentialLogin()` guard from `process_login_logic()`, the `credentialLoginEvaluated` flag and the modern-controller guard. All three are additive; reverting restores today's double evaluation exactly. No data or session state is involved.

### Acceptance criteria (observable)

- A credential POST with an **invalid** token → no authentication; the response contains the CSRF error and **no** credential-evaluation message (`Usuario o contraseña incorrectos.`, `baneada`, `desactivado`). Exactly one `fs_ip_filter::set_attempt()` per submission.
- A credential POST with **no token field at all** → identical to the invalid-token case.
- A credential POST with a **valid** token and wrong credentials → the credential-evaluation message appears **exactly once** in the response (was twice), and exactly one throttle attempt is recorded.
- A credential POST with a valid token and correct credentials → login succeeds and redirects exactly as today.
- `GET /index.php?page=login` → unchanged (`?autologin=` still works; the login page renders).
- `GET /index.php?page=login&logout=1` → unchanged.
- A plugin-registered public-login redirect is honoured for an anonymous **POST** to `page=login` (unchanged).
- A POST to a modern (FS2025) route carrying `user`/`password` with an invalid token → the request does **not** switch the session; with a valid token it behaves as today.
- `FsControllerCsrfBlockingTest`, `FsLoginPasswordVerificationTest`, `LoginThrottle*`, `LegacyLoginRedirectTest`, `LoginActionUrlTest` stay green.

## 3. W3 — VULN-0008: remember-me revocation authority (RMR)

### Approach

1. `src/Security/LegacyAuthBridge.php`
   - `resolveLegacyLogoutNick()` returns **only** the authenticated session nickname; the `$_COOKIE['user']` fallback (`:257-260`) is deleted.
   - Visibility `private` → `protected`, annotated `@internal Expuesto para las pruebas` (precedent: `SessionManager::legacyPhpSessionCookiePaths()`), so the rule can be unit-tested without a database or an HTTP request.
2. `base/fs_login.php`
   - The duplicate revocation implementation in `revokeCurrentRememberMeToken()` (`:199-210`) is **deleted** (D13); the method delegates unconditionally to the bridge.
   - `handleInvalidCookieSession()`: both branches call `log_out(TRUE)`; the message becomes a neutral "session no longer valid, please sign in again" and no longer interpolates `$user->last_ip` (D15). `fs_is_local_ip()` keeps its other callers in `base/fs_ip_filter.php`.
   - `log_out()` attributes the audit record to the authenticated session nickname, not the cookie (D14).
3. `themes/AdminLTE/view/login/default.html.twig` — `|raw` removed from the `get_errors()` and `get_messages()` loops (`:55`, `:63`) (D16).

### Alternatives considered and rejected

- **Patch the second fallback to drop only its `?: filter_input(...)` part.** Rejected (D13): leaves two copies of the rule.
- **Keep the `last_ip` warning but render it only to an authenticated owner.** Rejected: `handleInvalidCookieSession()` is reached by definition on an unauthenticated request; the "someone accessed from another location" warning belongs in an out-of-band notification, which the proposal also names as out of scope.
- **Rotate the named account but only when `enabled === TRUE`.** Rejected: the report verified that the disabled/nonexistent branch reaches revocation through `log_out(TRUE)` too, so account state does not gate it; and the correct rule is "the requester must prove they own the identity", not "the identity must look plausible".
- **Escape in PHP (`no_html()`) before `new_error_msg()`.** Rejected (D16).

### Blast radius

Verified on the working tree (the proposal's counts are slightly stale — recorded here for the tasks phase):

| Measure | Proposal | Verified |
|---|---|---|
| Files referencing `fs_login` | 19 | **20** (`base/fs_auth.php`, `base/fs_autoload.php`, `base/fs_chunked_upload.php`, `base/fs_controller.php`, `base/fs_login.php`, `model/core/fs_user.php`, `src/Core/Base/Controller.php`, `phpstan-bootstrap.php`, plus 12 test files) |
| Production instantiation sites | 7 files | **6 sites in 4 files**: `base/fs_controller.php:227`, `base/fs_chunked_upload.php:112`, `src/Core/Base/Controller.php:115`, `model/core/fs_user.php:500,511,527` |
| Test doubles | 5 | **5** (`RandomStringHardeningTest:65`, `FsLoginSessionInitializationTest:42`, `CsrfSessionSyncTest:54`, `FsLoginPasswordVerificationTest:314`, `FsLoginCookieAuthTest:41`) |

No signature changes, so all 20 reference sites keep compiling. The behaviour change is confined to the *anonymous* request: today it rotates a named account and writes an audit row for it; after the change it clears its own cookies, invalidates its own session and writes a neutral notice. An authenticated logout is unchanged (the session nickname is present, so the key still rotates).

Rollback is **all-or-nothing for the two fallback removals** (proposal's explicit warning): reverting only the bridge re-opens the hole through the `fs_login` copy and vice versa. The template escaping, the neutral notice and the audit attribution are independently revertible.

### Acceptance criteria (observable)

- Anonymous `GET /index.php?page=login` carrying `user=<real nick>` + bogus `logkey`/`auth_sig` → the named account's `fs_users.log_key` is **unchanged** (`rotate_logkey()`/`save()` never called).
- The same request → no audit row `El usuario ha cerrado la sesión.` attributed to the named account.
- The same request → the response body contains **no** IP address; it contains the neutral notice; `Set-Cookie` deletes the caller's own `user`, `logkey` and `auth_sig`.
- Anonymous `GET /index.php?page=login` with a nickname that does not exist → unchanged behaviour for the caller (its own cookies are deleted, its own error text is rendered), and no row is touched.
- Authenticated `?logout=1` → `log_key` **is** rotated, and the audit row names the authenticated nickname.
- A login-page error or message containing `<script>alert(1)</script>` renders escaped.
- `LegacyAuthBridgeTest`, `FsLoginCookieAuthTest`, `FsLoginCharacterizationTest`, `RememberMeSignatureRequiredTest`, `CsrfSessionSyncTest`, `TrustedSessionRestampTest` stay green.

## 4. W4 — VULN-0006: throttle isolation (LTI)

### Approach

1. `base/fs_ip_filter.php::__construct(string $namespace = '')` — D11. Empty namespace → `FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'ip.log'` **byte-identical**. Non-empty → `.../<sanitised>_ip.log`.
2. `controller/password_reset.php`
   - `public_core()` constructs `new fs_ip_filter('password_reset')`.
   - `process_request()`: `set_attempt()` moves **below** the empty-`nick_or_email` check, so a submission that is not an attempt is not counted at all.
   - `process_request()` and `process_reset()` call `requireCsrf()` before doing work (D12). `process_reset()` keeps its `set_attempt()` where it is: it counts a genuinely failed token validation, and it now writes the reset bucket.
3. Invariant to state in the spec: **lockout state is a security control; only credential-submission handlers may write to the authentication bucket.** Any future feature that throttles itself gets its own namespace by default.

### Alternatives considered and rejected

- **A dedicated `fs_password_reset_filter` class.** Rejected (D11).
- **Namespacing by a static registry rather than a constructor parameter.** Rejected: hidden global state; the constructor parameter is visible at every call site and defaulted safely.
- **Move the reset flow to a Symfony rate limiter.** Rejected: new dependency surface, and it would not fix the ordering defect.
- **Fix only the ordering and keep the shared bucket.** Rejected: an attacker can still drive the shared counter with *valid* empty-`nick_or_email`-avoiding submissions, and the ban still disables the recovery form.

### Blast radius

`new fs_ip_filter()` has exactly two call sites in production: `base/fs_login.php:75` (unchanged, default namespace) and `controller/password_reset.php:74` (namespaced). `set_attempt()` has three call sites, all enumerated. Test doubles construct it with no argument, so the default path is what they exercise. `tmp/` gains one file per namespace; nothing enumerates `tmp/`.

Risk: the reset flow's own throttle is now independent, so an attacker can exhaust their own reset quota **and** still not affect authentication — which is the intent. The reset flow keeps 5-per-600 s with identical messages.

### Rollback

Revert the namespace argument and the two controller call sites: the namespaced file is simply no longer read and the login bucket was never moved — no data migration. Revert the `set_attempt()` move and the `requireCsrf()` calls independently (the CSRF requirement is the user-visible one; a soft window is not needed because the template already ships the token).

### Acceptance criteria (observable)

- 6 anonymous empty POSTs to `page=password_reset` → the next credential POST with a fresh valid token still reaches the password comparison (`Usuario o contraseña incorrectos.`), i.e. authentication is **not** locked out.
- After those 6 requests, a 7th reset request is refused with the reset throttle message (its own bucket still works).
- One empty reset submission increments **no** counter file (neither bucket).
- `new fs_ip_filter()` resolves to `tmp/<FS_TMP_NAME>ip.log`; `new fs_ip_filter('password_reset')` resolves to `tmp/<FS_TMP_NAME>password_reset_ip.log`; a hostile namespace (`'Password-Reset!!'`, `'../../etc/passwd'`) resolves inside `tmp/` and contains no path separator.
- A reset POST without a valid CSRF token is refused and performs no lookup, no token mint and no mail.
- A reset POST with a valid token behaves as today.
- `FsIpFilterTest`, `FsLoginCharacterizationTest` stay green.

## 5. W5 — VULN-0005: served surface hygiene (DSH)

### Approach

Order inside the workstream matters: **hook first, then redaction**, so the sweep cannot re-introduce a secret.

1. `scripts/hooks/pre-commit` — a secret scan inserted **above** the `composer.json` early-exit (D22), over staged files only. Rule: a 40/64-char hex blob adjacent to `logkey` / `auth_sig` / `password` / `token` (i.e. `logkey=<hex>`, `logkey: <hex>`, `"logkey": "<hex>"`). Failure → `exit 1` with the file and line. An inline `# nosecret` marker opts a line out.
2. Redaction sweep over the served planning tree. Verified: the credential-bearing line is **exactly one** — `openspec/changes/ventas-clientes-controller-dedup/verify-report.md:62` (a repository-wide search for 40/64-hex adjacent to `logkey=`/`auth_sig=` returns that line and nothing else). Replace `logkey`, `auth_sig`, the session identifier and the CSRF-token prefix with `<redacted>`. Reference style: `openspec/changes/ventas-clientes-dispatch-regression-test/verify-report.md:64` already redacts the key but **still prints a live-looking session identifier** (`FSSESS_a8512aa711f7=vfya2a1f4634c8e26f1`); it is swept too, because the proposal's rule is "session identifiers and CSRF tokens" as well. `FSSESS_` occurs 75 times across 11 planning files — most are prose/spec text (`FSSESS_xxx`) and must be left alone; only literal values are redacted.
3. `nginx-sample.conf` — eight new `^~` deny locations, `openspec/ docs/ tools/ extras/ odd/ dev-tools/ managed_context/ test_suite_analysis/`. Verified: all eight exist in the web root. `themes/` is deliberately **not** added (browser-fetched CSS/JS). Placement is unconstrained (`^~` prefix locations beat the regex `location ~ \.php$` handler), so the block goes with the existing sensitive-directory group at `:38-48`.
4. A pre-commit-hook companion test: a repository scan asserting no credential-shaped blob remains under the served tree.

### Alternatives considered and rejected

- **Delete the planning tree from the deployment.** Rejected by the proposal (document-root relocation is the long-term fix, explicitly deferred).
- **Rewrite git history to purge the credential.** Rejected (proposal non-goal): the value is in the public upstream repository, so rotation is the closure and history rewriting buys nothing.
- **Deny `openspec/` with a regex `location ~ ^/openspec/`.** Rejected: `^~` is matched on the normalised, decoded URI and takes precedence over the PHP handler, which is what the existing rules rely on.
- **A server-side secret scan (CI/git hook on the remote).** Rejected as out of reach in this repository; the local hook is defense in depth and the redaction sweep is the primary control.

### Blast radius

No PHP runtime path changes. `nginx-sample.conf` is an **inactive sample** — nothing in the repository executes it and it cannot be verified here (`nginx -t` plus a live request is owed by the operator). The pre-commit hook is a local `.git/hooks` file, not a server-side check, so it does not constrain every contributor. The redaction sweep touches Markdown only, so the PHPUnit/PHPStan gates do not cover it.

**Operator handoff — the change is incomplete until confirmed.** Redaction and deny rules are hygiene; the published `auth_sig` must be treated as burned. The finding is closed only by (a) applying the deny rules on the live server and reloading nginx, and (b) rotating the `admin` account's `log_key`. Neither is achievable in this repository and neither is reverted by a code revert.

### Rollback

Revert the hook, the config and the document edits independently. A rotated key stays rotated (the desired direction). No interaction with the other workstreams.

### Acceptance criteria (observable)

- A repository scan over the served tree finds no 40/64-hex blob adjacent to `logkey` / `auth_sig` / `password` / `token`. (Note: `tests/bootstrap.php:62` defines a deterministic 64-hex `FS_SECRET_KEY`; it is **not** adjacent to any of the four tokens, so an adjacency-based rule does not match it. A rule based on "any 64-hex blob" would produce a false positive and must not be used.)
- `nginx-sample.conf` contains a `^~` deny location for each of the eight directories, and contains **no** deny location for `themes/`.
- The pre-commit hook rejects a staged fixture containing `logkey=<64 hex>` and exits non-zero, and does **not** fire on a commit that stages only `composer.json` changes (the scan runs before the early exit).
- **Operator handoff confirmed (not verifiable here):** `GET /openspec/changes/ventas-clientes-controller-dedup/verify-report.md` → `403`; `GET /config.php` → `404`; the `admin` `log_key` rotated.

## 6. W6 — VULN-0007: session transport hardening (STH)

### Approach

Order is load-bearing: **pin the cause first, then change the emission path.**

1. **Pin (test first).**
   - `tests/Security/SecureRequestDetectorTest.php` already covers `isSecure()` for `HTTPS=on`, `HTTPS=off`, `X-Forwarded-Proto` **with** a trusted proxy, and **without** one (`:44,52,60,73`). The detector is therefore not the hypothesis; the ordering is (D4). Extend only if a gap appears.
   - New `tests/Security/SessionCookieParamsTest.php` pins the **inputs** to cookie emission: `SessionManager::sessionCookieParams()` returns `secure => true` under a trusted-proxy-terminated request and `secure => false` otherwise, with `path` from `resolveCookiePath()`, `httponly => true`, `samesite => 'Lax'`. This is the discriminating assertion the CLI harness *can* make — see the Testing Strategy note on `Set-Cookie`.
2. `src/Security/SessionManager.php`
   - `resolveCookiePath()` promoted `private static` → `public static` (precedent: `resolveSessionName()`).
   - New `public static function sessionCookieParams(): array` — `['lifetime' => SessionPolicy::getIdleTimeout(), 'path' => self::resolveCookiePath(), 'domain' => '', 'secure' => SecureRequestDetector::isSecure(), 'httponly' => true, 'samesite' => 'Lax']`. One definition, three consumers.
   - `initialize()` builds its `NativeSessionStorage` `$options` from it (`name`, `gc_maxlifetime`, `use_strict_mode` stay local).
3. `base/fs_session_manager.php::initializeLegacySession()` — `session_set_cookie_params(SessionManager::sessionCookieParams())` (behaviour identical today; the two copies become one).
4. `src/Core/StealthMode.php::ensurePhpSessionStarted()` — **before** `session_start()`: `ini_set('session.use_strict_mode', '1')` and `session_set_cookie_params(SessionManager::sessionCookieParams())`. The defensive `setcookie()` at `:494-499` is **deleted** (D5). The `session_name(SessionManager::resolveSessionName())` call added by the archived change stays untouched.
5. `src/Security/SecurityHeaders.php::applyDefaultHeaders()` — HSTS (D6).
6. D17: `controller/admin_agentes.php:271` and `src/Security/CsrfManager.php:133` set the shared session name before `session_start()` (or delegate to the shared session accessor).
7. **Coordination with the archived change — explicit statement.** `consolidate-session-csrf` closed the *dual-cookie* hazard by making one session name win (SC-01…SC-05) and left W-4 open: `StealthMode`'s cookie sync emitted the same-named cookie with a hardcoded `path => '/'` and no `secure`. This design **closes W-4**, it does not re-open SC-05: the emission is made attribute-identical to `SessionManager`'s by consuming `resolveCookiePath()` + `SecureRequestDetector::isSecure()` through `sessionCookieParams()`, and the duplicate emission is removed rather than reconciled. `SC-01`'s `### Known deviation` paragraph is the one canonical text that must change (D17) — the spec phase owns that delta. The `resolveSessionName()` name-match gate in `SessionManager::initialize()` (`:109`) is **not** touched.

### Alternatives considered and rejected

- **Add `secure` to the defensive `setcookie()` only.** Rejected: leaves two `Set-Cookie` headers, contradicting the stated criterion, and leaves the parameterless `session_start()` in place (so `use_strict_mode` stays off at the outermost entry point, which the report explicitly asks to fix).
- **Apply the parameters in `index.php` before the gate.** Rejected: `index.php` is not the only entry point, and `StealthMode` is also driven from `controller/login.php`.
- **Emit HSTS unconditionally, including on plain HTTP.** Rejected: HSTS on an HTTP response is ignored by browsers, and it would break a local HTTP-only install in confusing ways.
- **Hardcode `includeSubDomains`.** Rejected (D6).
- **Call the full `applyDefaultHeaders()` from `api.php` and `cron.php`.** Rejected: it would add CSP/nosniff/X-Frame-Options to the API surface — an unrelated behaviour change with its own blast radius. Recommended instead (see Risks): a narrow `SecurityHeaders::applyTransportHeaders()` (HSTS only) called from all three entry points, marked as an addition beyond the proposal's Affected Areas so the tasks phase can slice it out.

### Blast radius

`StealthMode::ensurePhpSessionStarted()` runs on the stealth entry path and from `PublicAccessGate::intercept()` → `hasAuthenticatedSession()`, i.e. potentially on **every** request before `SessionManager`. Setting `use_strict_mode` there changes how a client-supplied session id is treated: an id that was never initialized server-side is now **discarded** and a fresh one issued, instead of being adopted. That is recommendation 8 of the pentest report and the fail-safe direction, but it is a real behaviour change for a client holding an uninitialized id (it is logged out instead of being adopted). Deleting the defensive `setcookie()` relies on PHP emitting the cookie on a new/regenerated id — which it does — and on staying silent when the client already holds the valid active id — which is exactly when no re-sync is needed.

`SessionManager::initialize()`'s `PhpBridgeSessionStorage` branch (`:109-115`) runs before the `NativeSessionStorage` branch, so the new options only take effect when `SessionManager` starts the session itself. This is why D4/D5 place the parameters in `StealthMode` as well: whichever component starts the session first must apply them.

`SecurityHeaders::applyDefaultHeaders()` is called only from `index.php:134`, **before** the gate (`:141`) — so HSTS is emitted even on a gated `302`.

`src/Core/Base/Controller.php` is unaffected by W6.

### Rollback

Revert `ensurePhpSessionStarted()` to `session_name()` + `session_start()` + the defensive `setcookie()`, revert `SecurityHeaders` to no HSTS, and revert the two bare `session_start()` sites. `sessionCookieParams()` may remain as an internal helper. No data or schema. Note: an HSTS `max-age` already served to a browser is **not** undone by a code revert — a tester's browser can pin `panel-ab.ddev.site` to HTTPS for a year. Set `FS_HSTS_MAX_AGE=0` on a dev host before deploying if that is a problem.

### Acceptance criteria (observable)

- Exactly **one** `Set-Cookie` header per response, and its value carries `Secure`, `HttpOnly` and `SameSite=Lax` — on a request the detector classifies as secure. (Live HTTP probe owed; see Testing Strategy for what the in-process suite can and cannot assert.)
- `Secure` is present when the request is detected secure and absent otherwise, for the same code path.
- `sessionCookieParams()['secure']` is `true` behind a trusted proxy terminating TLS with `X-Forwarded-Proto: https`, and `false` without a trusted proxy.
- `Strict-Transport-Security: max-age=31536000` present on secure `index.php` responses; absent when `FS_HSTS_MAX_AGE=0`; no `includeSubDomains` unless explicitly opted in.
- `StealthMode::ensurePhpSessionStarted()` applies the cookie parameters and strict mode before `session_start()`: a test that forces `PHP_SESSION_NONE` observes `session_get_cookie_params()` carrying the shared attribute set.
- `SC-01`…`SC-05` scenarios stay green: one session name, no live `PHPSESSID` for this installation, and no second same-named cookie with divergent attributes.
- `SessionManagerTest`, `SessionNamePerInstallTest`, `SessionLegacyPhpSessionMigrationTest`, `StealthModeTest`, `StealthSessionBootstrapTest`, `SecurityHeadersTest` stay green.

## Sequencing and dependency order

| Order | Workstream | Depends on | Independently shippable |
|---|---|---|---|
| 1 | W1 (VULN-0002) | — | Yes. Ships alone. |
| 2 | W2 (VULN-0003) | — | Yes. Ships alone. |
| 3 | W3 (VULN-0008) | — | Yes. Ships alone. |
| 4 | W4 (VULN-0006) | W1 for its **retest** only, not for its code | Yes. |
| 5 | W5 (VULN-0005) | — | Yes, but **not "done"** without the operator handoff. |
| 6 | W6 (VULN-0007) | — | Yes, but its two live criteria need an HTTP probe. |

Real constraints, not preferences:

- **W1 and W6 both edit `src/Core/StealthMode.php`** (W1: `isHiddenLoginPageRequest`/`isHiddenLoginSubmission`; W6: `ensurePhpSessionStarted`). Different methods, same file → sequence them, never run them in parallel branches.
- **W1 before W4's live retest.** VULN-0006's PoC reaches `password_reset` anonymously *through* the gate bypass. Without W1 the retest cannot distinguish "the counter is isolated" from "the handler is unreachable".
- **W5 internal order: hook before redaction.** Otherwise the sweep can be undone by the next commit.
- **W2b (modern-controller guard) is independently revertible inside W2** and can be sliced out of the PR if review capacity is tight.
- **W3 is the widest blast radius** and should be reviewed with the full 20-file reference list in hand.
- The recommended apply order is the proposal's/FIX-PLAN's `0002 → 0003 → 0008 → 0006 → 0005 → 0007`, unchanged. The only addition is that W4's *verification* is scheduled after W1.

## Testing Strategy

Strict TDD is active for this project: `strict_tdd: true`, runner `ddev exec php vendor/bin/phpunit`. Every acceptance criterion above is written as an observable that can go red before it goes green.

**Baseline (do not attribute to this change).** The full suite currently has **12 failures, all in `plugins/OidcProvider`** — a separate repository with pre-existing DB-fixture drift. The **Security suite is green: 269 tests, 0 failures** (the archived verify run records `Tests: 269, Assertions: 601, Skipped: 1`). The full suite is `Tests: 4311, Assertions: 16196, Failures: 12, Skipped: 27`. Every workstream's PR must be measured against these numbers, not against "green".

**The CLI harness cannot assert headers.** The suite runs in CLI, where `headers_sent()` is true and `setcookie()`/`header()` are no-ops (`headers_list()` returns `[]` under the CLI SAPI). The archived verify run records this explicitly for SC-05. Therefore W6's tests are built on the **input** seam (`sessionCookieParams()`), and the header-level assertions ("exactly one `Set-Cookie`", "`Secure` present", "HSTS present") are **live HTTP probes owed by the operator**, recorded as unverified gates rather than passed ones. This is a deliberate, documented limitation — not a test that was skipped.

| Layer | What to test | Approach |
|---|---|---|
| Unit | `fs_auth_request` (all three statics) | Pure array-in/bool-out. Table-driven: `page=login`, `LOGIN`, `login `, `page[]=login`, absent, `admin_info`, `nlogin`, credential bodies, GET vs POST. No globals, no kernel. |
| Unit | `PublicAccessGate::intercept()` | `tests/Components/PublicAccessGateTest.php`: correct the test that encodes the insecure shape, add the two bypass regressions, the public-path-with-`page=` regression and the `page[]` type-safety case. |
| Unit | `StealthMode` predicates | `tests/Components/StealthModeTest.php`: `isLegacyLoginRequest()` true only for the canonical login page; `isLegacyLoginSubmission()` still used behind `hash_equals()`. |
| Unit | `fs_maintenance_mode::hasStealthAdminAccess()` | `tests/Base/FsMaintenanceModeTest.php`: unchanged public behaviour after delegating the predicate (`testLoginSubmissionShowsMaintenance`, `testStealthLoginRequestSkipsMaintenance` must stay green). |
| Unit | `login::public_core()` / `process_login_logic()` guard | New `tests/Controller/LoginPublicCoreCsrfTest.php` (the name the pentest verified): invalid token → credential flow not reached; no token → not reached; valid token → reached once; GET → reached. |
| Unit | Throttle attempt count per submission | Extend `tests/Security/FsControllerCsrfBlockingTest.php` + a spy on `fs_ip_filter::set_attempt()` / `LoginThrottle::recordFailure()`: exactly one per submission. |
| Unit | Modern controller credential guard | New `tests/Security/ModernControllerCredentialCsrfTest.php`. |
| Unit | `LegacyAuthBridge::resolveLegacyLogoutNick()` | Extend `tests/Security/LegacyAuthBridgeTest.php` (the `protected` visibility is the test seam): session nick wins; `$_COOKIE['user']` alone → `null`. |
| Unit | Anonymous revocation does not mutate an account | New `tests/Security/AnonymousLogoutRevocationTest.php`: an overridden `fs_user` double records `rotate_logkey()`/`save()` calls; anonymous request → zero calls; authenticated request → one. |
| Unit | Audit attribution + neutral notice | Extend `tests/Security/FsLoginCookieAuthTest.php` and `tests/Base/FsLoginCharacterizationTest.php`. |
| Unit | Twig escaping | Render the `get_errors()`/`get_messages()` blocks with a `<script>` payload (the pattern used by `tests/Core/*MacroContractTest.php`) and assert the escaped output; a source-level assertion on `|raw` is the fallback if the theme loader is not constructible. |
| Unit | `fs_ip_filter` namespace | Extend `tests/Base/FsIpFilterTest.php`: empty namespace path byte-for-byte, namespaced path, hostile namespace stays inside `tmp/`. |
| Unit | Reset ordering + CSRF | New `tests/Security/PasswordResetThrottleIsolationTest.php`: empty submission increments nothing; no token → refused; valid token → proceeds; the reset bucket is not the auth bucket. |
| Unit | `SessionManager::sessionCookieParams()` | New `tests/Security/SessionCookieParamsTest.php`: attribute set + `secure` under both proxy configurations. |
| Unit | HSTS | Extend `tests/Security/SecurityHeadersTest.php` (currently CSP-only): the HSTS decision is asserted on the **predicate** (`FS_HSTS_MAX_AGE` + `isSecure()`), since the header itself is not observable in CLI. |
| Unit | Stealth cookie ordering | Extend `tests/Components/StealthModeTest.php` with a `RunInSeparateProcess` case forcing `PHP_SESSION_NONE` and asserting `session_get_cookie_params()` after `ensurePhpSessionStarted()` (the pattern the archived change used for name-before-start). |
| Contract | `nginx-sample.conf` deny coverage | New `tests/Core/NginxDenyRulesTest.php`: parse the file, assert a `^~` deny location per directory, assert `themes/` is absent. Text-level only; `nginx -t` is owed by the operator. |
| Scan | Served-tree secret scan | New `tests/Security/ServedTreeSecretScanTest.php`: adjacency rule over tracked non-vendor paths, with the `tests/bootstrap.php` near-miss documented in the test. |
| Integration | Gate + login + reset end to end | Extend `tests/Integration/StealthSessionBootstrapTest.php` and add a gate→login→reset flow test using `Request::create`, asserting status codes and `Location` only (no headers). |
| Regression | SC-01…SC-05 / SI-01 / LC-01…LC-05 | `tests/Security/Session*`, `tests/Security/Csrf*`, `tests/Integration/CsrfTokenSurvivesMigrationTest` must stay green, byte-identical. |

## Discoveries from design-phase source reading

Two findings that are **not** in the pentest report, recorded here so the spec and tasks phases can act on them deliberately rather than by accident.

1. **Modern-controller credential CSRF (`src/Core/Base/Controller.php:125-135`).** `initializeConnectedSession()` calls `$this->login_tools->log_in($this->user)` with no CSRF guard and no `requestHasCredentials()` condition. The anonymous shape is gated (the gate redirects anonymous requests to a modern route), so no unauthenticated test could reach it — which is why the report missed it. But an **authenticated** victim POSTing the attacker's `user`/`password` to any FS2025 route reaches `fs_login::log_in()` → `log_in_user()` and switches the victim's session to the attacker's account with no token: login CSRF on a second door. Decision **D10: in scope** for W2.
2. **`applyDefaultHeaders()` is not called by `api.php` or `cron.php`.** The proposal's VULN-0007 fix direction assumes it "covers `api.php`/`cron.php` if they route through it"; verified, it does not — `index.php:134` is the only call site. Decision **D6**: HSTS lands in `applyDefaultHeaders()` as the required scope, with a recommended narrow `applyTransportHeaders()` addition for the other two entry points, flagged as beyond the proposal's Affected Areas.

Three further verified facts that change the proposal's stated numbers or file names:

3. **The template is `themes/AdminLTE/view/login/default.html.twig`**, not `.../default.twig` (proposal line 525). The `|raw` filters are at lines 55 and 63.
4. **`themes/AdminLTE/view/password_reset.html.twig` already emits `{{ csrf_field() }}`** in both forms (lines 80, 116). VULN-0006's "require a CSRF token" is a controller-only change.
5. **`base/fs_login.php` reference counts** are 20 files / 6 production instantiation sites (proposal says 19 and 8). Recorded in W3's blast-radius table.

## Size forecast

| Workstream | Source/config lines | Test lines | Files touched |
|---|---|---|---|
| W1 (VULN-0002) | ~55 (incl. new `base/fs_auth_request.php`) | ~130 | 6 source + 3 test + `phpstan.neon` |
| W2 (VULN-0003) | ~45 (incl. W2b) | ~170 | 3 source + 3 test |
| W3 (VULN-0008) | ~45 | ~170 | 3 source/template + 4 test |
| W4 (VULN-0006) | ~25 | ~120 | 2 source + 2 test |
| W5 (VULN-0005) | ~55 config/hook + ~5 doc | ~130 | 1 config + 1 hook + 2 docs + 2 test |
| W6 (VULN-0007) | ~65 | ~165 | 7 source + 5 test |
| **Total** | **~290 source/config + ~5 doc** | **~885** | **~35 files** |

**This exceeds the 400-line review budget** — roughly 1,180 changed lines, about three times the budget — and the delivery strategy for this session is `auto-chain`, so the tasks phase **must** slice it. The natural seams are exactly the six workstreams, in the apply order above; each is independently revertible and each carries its own regression tests as the delivery unit:

| PR | Content | Est. lines | Notes |
|---|---|---|---|
| 1 | W1 — gate + predicate consolidation + `fs_auth_request` | ~185 | Under budget. Includes the corrected test that encoded the insecure shape. |
| 2 | W2 — authoritative verdict + single execution (+ W2b) | ~215 | Under budget. W2b can be split out if needed. |
| 3 | W3 — revocation authority + disclosure + escaping | ~215 | Under budget. Widest blast radius; review with the reference list. |
| 4 | W4 — throttle isolation + ordering + reset CSRF | ~145 | Under budget. Verify after PR 1. |
| 5 | W5 — hook + redaction + nginx + scans | ~190 | Under budget. **Carries the operator handoff.** |
| 6 | W6 — cookie params + strict mode + HSTS + bare `session_start()` sites | ~230 | Under budget. Touches `StealthMode`, so it must not run in parallel with PR 1. |

If the tasks phase prefers a two-PR start, PR 1 and PR 2 are the cheapest-and-highest-impact pair (the real security boundary) and are the natural first slice, matching the FIX-PLAN.

## Non-goals

- **The two plugin findings (VULN-0001, VULN-0004).** Both live in `plugins/system_updater/` (`recovery.php`; `lib/session_auth.php::system_updater_is_logged_in()`). `/plugins/*` is gitignored; each plugin is its own repository. Their SDD belongs in `plugins/system_updater/openspec/changes/{name}/`, committed there. No file under `plugins/` is touched by this change, and the core carries no plugin code or plugin SDD.
- **Post-authentication object-level authorization** across customer, sales, catalogue and company controllers. Untested because no credentials were available — a proof gap, not a clean result. Needs a follow-up authenticated scan with one low-privilege and one administrator account.
- **Other proof gaps from the same report**: whether the session id rotates on a successful login, whether logout invalidates the session server-side, and user/timing enumeration. W2 and W6 touch these surfaces; neither finding is closed by evidence, so neither is claimed.
- **No document-root relocation.** Restricting the deployment to runtime assets is the correct long-term fix and is explicitly deferred; W5 ships the deny rules that make the current layout safe.
- **No git-history rewrite.** Redaction is hygiene; rotation is the closure.
- **No general authentication redesign**: no centralised middleware for every entry point, no standalone plugin-script auth, no CSP `unsafe-inline` reduction.
- **No new PHPStan baseline, no `ignoreErrors` growth.** Findings are closed by correcting the contract or the code. Note for the verification phase: `composer phpstan` analyses `src` + `tests` (+ `scanFiles`), so `base/fs_controller.php`, `base/fs_ip_filter.php`, `base/fs_login.php`, `controller/login.php` and `controller/password_reset.php` are **not** covered by that gate; only `src/` changes are. `phpstan:plugins` is unaffected (no plugin file changes).
- **Explicit deferrals with named owners** (recorded so they are not silently dropped):
  - `CsrfManager::$tokenVerified` intra-request invalidation and the non-discriminating `tests/Integration/CsrfTokenSurvivesMigrationTest` (W-6/W-7 of the archived verify run) → owner: a `session-integrity` follow-up change (SI-01). Decision D18.
  - The third page-canonicalisation copy in `fs_maintenance_mode::isBackendPageRequest()` → owner: a maintenance-mode follow-up. Decision D19.

## Operator handoff — the change is not complete without it

| # | Action | Verification | Owner |
|---|---|---|---|
| 1 | Apply the extended `nginx-sample.conf` deny rules on the live server and reload nginx | `GET /openspec/changes/ventas-clientes-controller-dedup/verify-report.md` → `403`; `GET /config.php` → `404` | Operator |
| 2 | Rotate the `admin` account's `log_key` (the published `auth_sig` is burned) | The published triple no longer appears in any served document; the account's key differs from the published value | Operator |
| 3 | Probe a live secure response for exactly one `Set-Cookie` carrying `Secure`/`HttpOnly`/`SameSite=Lax`, and for `Strict-Transport-Security` | Two `curl -D -` captures | Operator |
| 4 | `nginx -t` on the extended config | Exit `0` | Operator |

Until 1 and 2 are confirmed, VULN-0005 must be reported as **open**, not closed. Merging the redaction and the deny rules without them leaves the finding open while looking fixed — the proposal's risk 6, severity High. Items 3 and 4 are unverified gates, not passed ones.

## Risks (design-level)

| # | Risk | Severity | Handling |
|---|---|---|---|
| 1 | `?page[]=login` (array) makes `$_GET['page']` an array; a `trim()` would be a `TypeError` in PHP 8 and turn the gate into a 500 | High (availability) | `fs_auth_request::requestedPage()` must `is_string()`-guard and return `''` → gated. Pinned by a dedicated test. This is the single most likely new bug in W1. |
| 2 | The canonical page rule changes the exemption for non-canonical `page` values (`login%20`, `LOGIN`) | Low | Both are fail-closed: the gate returns a `302` to the real login page. Covered by a test matrix, not by argument. |
| 3 | W2's "exactly once" halves the effective lockout sensitivity (3 → 6 real failures) and will read as a regression in review | Medium | Intended and documented in the PR body; asserted by an "exactly one attempt per submission" test. |
| 4 | W2b's modern-controller guard could reject a legitimate modern POST that carries `nick`/`password` with no token | Medium | Today such a POST already switches the session (the bug), so rejecting it is correct; a valid token passes. Pinned by a two-direction test. |
| 5 | W6's `use_strict_mode=1` in `ensurePhpSessionStarted()` discards an uninitialized client-supplied session id (previously adopted) | Medium | Fail-safe direction and explicitly requested by the report (recommendation 8). `SessionFixationPreventionTest` and `SessionNamePerInstallTest` must stay green; the behaviour is asserted, not argued. |
| 6 | W6 deleting the defensive `setcookie()` relies on PHP's own emission; a regression would show as a stale session id in the stealth flow | Medium | The params-before-start test plus the name-match gate in `SessionManager::initialize()` (`:109`) which is untouched. `StealthSessionBootstrapTest` must stay green. |
| 7 | An HSTS `max-age` already served is not undone by a code revert and can pin a DDEV host to HTTPS for a year | Medium | `FS_HSTS_MAX_AGE=0` disables it; `includeSubDomains` is opt-in; documented in W6's rollback. Set the escape on dev hosts before deploying. |
| 8 | The W5 repository scan can produce false positives on legitimate 64-hex values | Low | The rule requires **adjacency** to `logkey`/`auth_sig`/`password`/`token`. Verified near-miss: `tests/bootstrap.php:62`'s deterministic `FS_SECRET_KEY` is not adjacent to any of them. An inline `# nosecret` marker is available. |
| 9 | `base/fs_maintenance_mode.php` is consumed by the `system_updater` plugin through `lib/maintenance_mode_compat.php`, outside this repository's gates | Low | `hasStealthAdminAccess()`'s public signature is unchanged; only the private predicate's home moves. `FsMaintenanceModeTest` and `AuthorizationFreshnessTest` cover it. |
| 10 | W1 and W6 both edit `src/Core/StealthMode.php` | Medium | Sequence them (PR 1 then PR 6); never two parallel branches. |
| 11 | The proposal's counts and file names are stale in three places (template name, `fs_login` reference counts, `applyDefaultHeaders()` coverage) | Low | Corrected in this design and in the Discoveries section; the spec and tasks phases should use the verified values. |
| 12 | The canonical `login-csrf`/`session-integrity` specs are Spanish; the archived deltas were English (the archived verify run's S-1) | Low | D21/D20: add `LC-06` in Spanish to match the canonical file, and do not touch SI-02/SI-03. Decide deliberately in the spec phase. |

## Open Questions

1. **W2b confirmation.** The spec phase should confirm the modern-controller credential guard as in-scope (design recommends in, D10) or spin it out — the same treatment the proposal gives the path-info/`page=` sibling bypass. Either way it is not silently dropped.
2. **`applyTransportHeaders()` for `api.php`/`cron.php`.** Design recommends a narrow HSTS-only helper called from all three entry points; the tasks phase may slice it out. If sliced out, HSTS is `index.php`-only and that must be stated in the spec, not implied.
3. **`FS_HSTS_MAX_AGE` default.** Design proposes `31536000` (matching the proposal's success criterion) with `0` as the documented dev escape. If the reviewer prefers a conservative default on a development deployment, this is the one knob to change.
4. **`LC-06` language.** The canonical `login-csrf/spec.md` is Spanish; the delta should match it (archived S-1).
5. **Whether the `session-consolidation` `### Known deviation` paragraph is amended in this change or in a follow-up.** D17 puts the two bare `session_start()` sites in scope, which makes the paragraph stale; the spec phase must decide whether the delta ships here (recommended) or the paragraph is left deliberately inaccurate.
