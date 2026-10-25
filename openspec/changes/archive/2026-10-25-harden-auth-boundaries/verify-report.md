# Verify Report: `harden-auth-boundaries`

- **Change**: `harden-auth-boundaries` (CORE FSFramework change)
- **Artifact store**: openspec
- **Phase**: verify (independent)
- **Verifier**: SDD executor — verification performed by reading the implementation and running the
  commands directly. `tasks.md` checkmarks, `apply-progress.md` and the six PR bodies were treated as
  **claims, not evidence**. No implementation file, test, delta, canonical spec, `tasks.md`,
  `proposal.md` or `design.md` was modified.
- **Revision verified**: `master` at `de9f91a9` (working tree clean), with all six workstreams merged:
  `d1b66e53` (W1/AAG), `f26ac8ec` (W2/LC), `d576e014` (W3/RMR), `39449170` (W4/LTI + AAG-06),
  `63c11119` (W5/DSH), `90e9c313` (W6/STH).
- **Date**: 2026-10-25

---

## Verdict

**NOT ARCHIVE-READY AS-IS. Implementation complete and green; two delta statements are false
against the shipped code and MUST be corrected before the deltas are merged into
`openspec/specs/`.**

The code is in good shape: every workstream's control is present, reachable and covered by a
discriminating test; the suites are exactly at the documented baseline; not one of the 12 full-suite
failures belongs to this change. The blocker is **documentation truth**, not behaviour. Two
statements inside `specs/anonymous-access-gate/spec.md` contradict both the code and other
requirements in the same delta, and one more (`DSH-01`'s definition) is violated by three lines the
delta never authorises an exception for. Merging them verbatim would write false MUSTs into the
canonical specification — the same defect class the archived `consolidate-session-csrf` verify
flagged as CRITICAL.

**Issue counts: CRITICAL 2 · WARNING 4 · SUGGESTION 5.**

| Finding | Class | Blocks archive? |
|---|---|---|
| **C-1** — AAG-01's normative body ("only when … `login`") contradicts the code and AAG-06 | CRITICAL | **Yes** |
| **C-2** — AAG-02's scenario "Non-canonical page values are gated" contradicts the code, AAG-02's own table and AAG-05 | CRITICAL | **Yes** |
| **W-1** — SC-01 "no residual deviation" leaves one unnamed-start path in the file it claims to have fixed | WARNING | No (residual) |
| **W-2** — DSH-01's definition is over-broad; three live matches survive on an opt-out the requirement never authorises | WARNING | No, but amend before merge |
| **W-3** — STH-02's header half is unverifiable here; the defensive cookie sync removal is a live-only regression risk | WARNING | No (operator probe O.3) |
| **W-4** — Six `tasks.md` PR-level obligations remain unchecked, one of them a live retest the apply phase says is not observable | WARNING | No (record hygiene) |
| **S-1…S-5** | SUGGESTION | No |

---

## Commands run (via `ddev exec` only)

| # | Command | Exact measured result | Exit |
|---|---|---|---|
| 1 | `ddev exec php vendor/bin/phpunit` | `Tests: 4497, Assertions: 16796, Failures: 12, Skipped: 27` — all 12 failures enumerated below | `1` |
| 2 | `ddev exec php vendor/bin/phpunit --testsuite Security` | `OK, but some tests were skipped! Tests: 334, Assertions: 857, Skipped: 1` | `0` |
| 3 | `ddev exec composer phpstan` | `[ERROR] Found 38 errors` — 4 pre-existing untouched test files (`RememberMeSignatureRequiredTest`, plus the three already-known ones) | `1` |
| 4 | 15 change-scoped test files (see below) | `OK (213 tests, 656 assertions)` | `0` |

Command 4 was:

```
ddev exec php vendor/bin/phpunit \
  tests/Base/FsAuthRequestTest.php \
  tests/Controller/LoginPublicCoreCsrfTest.php \
  tests/Security/ModernControllerCredentialCsrfTest.php \
  tests/Security/AnonymousLogoutRevocationTest.php \
  tests/Security/PasswordResetThrottleIsolationTest.php \
  tests/Security/ServedTreeSecretScanTest.php \
  tests/Core/PreCommitSecretScanTest.php \
  tests/Core/NginxDenyRulesTest.php \
  tests/Security/SessionCookieParamsTest.php \
  tests/Controller/AdminAgentesSessionBootstrapTest.php \
  tests/Components/LoginTemplateEscapingTest.php \
  tests/Components/PublicAccessGateTest.php \
  tests/Components/StealthModeTest.php \
  tests/Base/FsIpFilterTest.php \
  tests/Security/SecurityHeadersTest.php
```

### Baseline reconciliation

| Signal | Documented baseline | Measured today | Verdict |
|---|---|---|---|
| Full suite tests | ~4497 | **4497** | matches |
| Full suite failures | 12, all `plugins/OidcProvider` | **12, all `plugins/OidcProvider`** | matches |
| Security suite | ~334 green | **334 / 0 failures / 1 skipped** | matches |
| PHPStan findings | 38 pre-existing | **38** | matches |

The 12 failures, enumerated (names only, none in this change's surface):

```
1–8   OidcProvider\Controller\admin_oidc_customer_access_htmxTest::*
9     OidcProvider\Controller\admin_oidc_customer_access_validation_groupsTest::testValidSelectionCallsSyncGruposThenApprove
10–11 OidcProvider\Service\ClienteRoleResolverIntegrationTest::testMultiGrupoUnionResolvesAcrossDbRows / testOrphanGrupoMembershipIsSilentlySkipped
12    OidcProvider\Service\migration011_cliente_gruposTest::testCreatedTableCollationMatchesReferencedTables
```

**No new failure and no new PHPStan finding was introduced by this change.** The 12 are the
documented pre-existing DB-fixture drift in a separate repository (`plugins/OidcProvider/.git`),
which this change never touched (`git log -- plugins/` shows no commit from any of the six PRs).

---

## Requirement-by-requirement compliance

Legend: **SATISFIED** = code does what the requirement says, pinned by a test that can go red.
**PARTIAL** = the observable holds but the requirement's own wording is not literally true.
**NOT SATISFIED** = the code contradicts the requirement.

### Delta: `anonymous-access-gate` (NEW capability — canonical dir absent)

| ID | Status | Evidence |
|---|---|---|
| **AAG-01** Exemption depends on the requested page, never the request shape | **SATISFIED in code / delta contradicted (C-1)** | `src/Core/PublicAccessGate.php:78-97` — the exemption is `isLegacyLoginRequest()` (page only) plus `isPublicPage(requestedPage($_GET))`; `isLegacyLoginSubmission()` no longer participates (`:74-77` comment says so). Pinned by `PublicAccessGateTest` bypass regressions. **But the requirement body at `specs/anonymous-access-gate/spec.md:50-55` says "only when … is `login`"**, and the code also exempts `password_reset` (`:95`). AAG-01 and AAG-06 are mutually contradictory as written. |
| **AAG-02** One canonical requested-page rule mirroring the dispatcher | **SATISFIED / one scenario false (C-2)** | `base/fs_auth_request.php:82-95` applies `is_string` guard → `trim()` → `/^[a-z][a-z0-9_]*$/`, exactly `index.php:216-229`. `FsAuthRequestTest` 40 cases green. **Scenario "Non-canonical page values are gated, not exempted" (`spec.md:134-139`) asserts `?page=login%20` → `302`; the gate returns `null`**, and `PublicAccessGateTest::testInterceptGatesInvalidPageValuesButAgreesWithTheDispatcher` (`:444-470`) asserts exactly the opposite. |
| **AAG-03** Non-string `page` is never fatal | **SATISFIED** | `fs_auth_request.php:84-87` returns `''` for anything non-string before `trim()`. `FsAuthRequestTest` array/null cases + `PublicAccessGateTest::testInterceptGatesArrayPageInputWithoutFatalError` (`:428-442`) → `302`, no `TypeError`. |
| **AAG-04** Exactly one login-submission predicate | **SATISFIED** | `base/fs_auth_request.php:108-119` is the only definition. `src/Core/StealthMode.php:445-452` and `base/fs_maintenance_mode.php:560-569` both delegate and hold no local copy. `hasStealthAdminAccess()` keeps its public signature (`:327`). |
| **AAG-05** Gate and dispatcher agree on the named page | **SATISFIED** | `PublicAccessGate.php:52` exempts a framework exact path **only** when `requestedPage($_GET) === ''`; plugin prefixes stay unconditional (`:45-55`). `Plugins::isFrameworkExactPublicPath()` extracted at `src/Core/Plugins.php:129-135` and consumed by both `isPublicPath()` (`:105`) and `shouldRespondNoContentForBrowserProbe()` (`:179`) with the same `requestedPage(...) !== ''` guard. Verified live shapes: `POST /favicon.ico?page=admin_info` → `302` (`PublicAccessGateTest:364-380`), `GET /favicon.ico?page=admin_info` → `302`, `GET /favicon.ico` (no page) → `204`, plugin prefixes with and without `page=` → not intercepted (`:403-421`). |
| **AAG-06** `password_reset` is a public page | **SATISFIED (stealth-disabled only, documented)** | `fs_auth_request.php:53` `PUBLIC_PAGES = ['login', 'password_reset']`; consulted at `PublicAccessGate.php:95`. `GET ?page=password_reset` → `null` (`PublicAccessGateTest:284,294-297`), `POST ?page=password_reset&nlogin=1` → `null`, `POST` with credential body → `null`. `/favicon.ico?page=password_reset` → `null` (`:323-325`) and `/favicon.ico?page=admin_info` → `302` (`:364-380`): **the AAG-05 path rule still holds for a private page**, exactly as the brief required. The safety of exposure is real: `controller/password_reset.php:127` `requireCsrf()` before any work, `:147` `set_attempt()` after validation, `:78` dedicated bucket. |

### Delta: `login-csrf` (ADDED LC-06, LC-07 — LC-02 untouched)

| ID | Status | Evidence |
|---|---|---|
| **LC-06** Authoritative verdict; credentials evaluated at most once | **SATISFIED** | `base/fs_controller.php:970-993`: the credential POST is CSRF-validated; on failure `log_in()` returns `false` and sets nothing; on success it sets `$this->credentialLoginEvaluated = true` (`:988`) before handing to `fs_login`. `controller/login.php:85-87` returns when `shouldSkipCredentialLogin()`; `:107-114` is `POST && (!isCsrfValid() \|\| credentialLoginEvaluated())` — **`isCsrfValid()`, never `requireCsrf()`** (`:102-106` documents why). Guard placement is exactly as required: after the plugin public-login redirect (`:60-63`) and before the credential branch (`:89`). Discriminating tests: `LoginPublicCoreCsrfTest` asserts `0` `logInCalls` / `0` `user->loginCalls` for invalid and missing tokens (`:128-129,150-151`), and `1` for the second-evaluation case (`:185`); `FsControllerCsrfBlockingTest::testValidTokenWrongPasswordSubmissionRecordsExactlyOneThrottleAttempt` (`:245-268`) asserts **exactly 1** `logInCalls`, **1** `set_attempt()` and **1** `LoginThrottle` attempt (was 2 each). |
| **LC-07** Modern FS2025 door rejects a credential POST without a valid token | **SATISFIED** | `src/Core/Base/Controller.php:179-186` `deliverSessionLogin()` consults `shouldSkipCredentialLogin()` **before** `$this->login_tools->log_in()`; `:197-208` uses the shared predicate `fs_auth_request::hasCredentialSubmission()` and the controller's own `validateFormToken()` (`:524-536`, fail-closed on empty token and on `CsrfManager::isValid()` false). Required at `:15`. `ModernControllerCredentialCsrfTest` green. |

### Delta: `remember-me-revocation-authority` (NEW capability)

| ID | Status | Evidence |
|---|---|---|
| **RMR-01** Only an authenticated session names the account | **SATISFIED — both fallbacks gone** | `src/Security/LegacyAuthBridge.php:260-268` returns the session nick or `null`; the `$_COOKIE['user']` fallback is deleted (`git diff f26ac8ec d576e014`). `base/fs_login.php:210-213` is now a one-line delegation to the bridge — the duplicate implementation (including its `?: filter_input(INPUT_COOKIE,'user')`) is **deleted**, not patched. `revokeCurrentLegacyLogin()` returns early on `null` nick (`:153-156`). Grep confirms no revocation path reads `$_COOKIE['user']`; the remaining cookie reads (`fs_login.php:144,185,230`, `LegacyAuthBridge.php:114`) are the login/cleanup paths, not the revocation target. |
| **RMR-02** A failed verification mutates nothing but the caller | **SATISFIED (header half = operator probe O.5)** | `base/fs_login.php:300-308`: single neutral notice, `log_out(TRUE)` on the only branch (the old `last_ip`-equality early return is gone). `log_out()` (`:155-196`) clears the caller's own `logkey`/`auth_sig` (and `user` under `TRUE`), invalidates the caller's own session, and never touches a named account. `AnonymousLogoutRevocationTest` (586 lines) uses an `fs_user` recording double and asserts **zero** `rotate_logkey()`/`save()` calls for the anonymous shape and **one** for the authenticated logout. The `Set-Cookie` deletion itself is unreachable in CLI — correctly recorded as O.5, not claimed. |
| **RMR-03** Audit attribution from the session | **SATISFIED** | `base/fs_login.php:157-162` reads `$this->session->get('user_nick')` **before** `invalidate()`, and `:192-194` passes that (`null` when anonymous) to `set_user_nick()`. The cookie value is never consulted. `FsLoginCookieAuthTest` asserts the recorded `usuario` is the session nick, never a cookie value. |
| **RMR-04** No per-account value to an anonymous requester | **SATISFIED** | `base/fs_login.php:300-308` no longer reads `$user->last_ip` at all (the whole two-branch IP message is deleted). `FsLoginCharacterizationTest` + `FsLoginCookieAuthTest` additions assert the neutral notice and the absence of an IP. |
| **RMR-05** Login error/message blocks escaped | **SATISFIED** | `themes/AdminLTE/view/login/default.html.twig:55,63` — `{{ value }}`, no `|raw`. `LoginTemplateEscapingTest` renders the real template through a `FilesystemLoader` **and** keeps the source-level `|raw` assertion so the contract cannot be lost by a render detail. |
| **RMR-06** No public signature changes | **SATISFIED** | `git diff f26ac8ec d576e014` changes no public signature: `log_out()` and `revokeCurrentRememberMeToken()` keep theirs; the only visibility change is `resolveLegacyLogoutNick()` `private` → `protected` (explicitly permitted, `@internal`). 20 referencing sites compile and the full suite is at baseline. See S-4 on the inert `protected` (class is `final`). |

### Delta: `login-throttle-isolation` (NEW capability)

| ID | Status | Evidence |
|---|---|---|
| **LTI-01** One owner per lockout bucket; **auth bucket byte-identical** | **SATISFIED — the highest-consequence invariant holds** | `base/fs_ip_filter.php:58` builds `FS_FOLDER . '/tmp/' . FS_TMP_NAME . $this->namespaceSuffix($namespace) . 'ip.log'`; `namespaceSuffix()` (`:80-88`) returns `''` **only** for the empty namespace, so the resolved path is character-for-character the pre-change expression. `FsIpFilterTest::testTheAuthenticationBucketPathIsUnchanged` (`:153-160`) asserts the exact string. `base/fs_login.php:75` still passes **no** namespace; `controller/password_reset.php:78` passes `'password_reset'` → `tmp/<FS_TMP_NAME>password_reset_ip.log` (`FsIpFilterTest:171`). `set_attempt()` still has exactly 3 production call sites. **No live ban is reset.** |
| **LTI-02** Namespace sanitised, cannot escape `tmp/` | **SATISFIED** | `[^a-z0-9_]` stripped + lower-cased (`:86`); hostile inputs `'Password-Reset!!'`, `'../../etc/passwd'`, `'!!!'`, `'..%2F..%2Fetc'` all resolve inside `tmp/` with no separator (`FsIpFilterTest` data provider). Deterministic across two computations. |
| **LTI-03** Validation precedes counting | **SATISFIED** | `controller/password_reset.php:139-147` — the empty-`nick_or_email` check and its message come **before** `set_attempt()`. `PasswordResetThrottleIsolationTest` asserts an empty submission increments neither bucket. |
| **LTI-04** Reset request and completion require a valid CSRF token | **SATISFIED** | `:127` (`process_request`) and `:198` (`process_reset`) call `requireCsrf()` as the **first** statement, before lookup/mint/mail and before any counter write. `requireCsrf()` (`base/fs_controller.php:476-504`) is fail-closed (a prior `csrf_valid === false` cannot be turned into success; an unvalidated POST is validated). Both template forms already emitted `{{ csrf_field() }}` — controller-only change, no template diff. |
| **LTI-05** Reset flow keeps its own throttle, same thresholds and messages | **SATISFIED** | `BAN_SECONDS = 600`, `MAX_ATTEMPTS = 5` unchanged (`base/fs_ip_filter.php:33-34`); messages still `password-reset-too-many-attempts` / `password-reset-enter-nick-or-email`; no new translation key, template or public API. Note the deliberate interaction with LC-06 (effective lockout now needs 6 real failures instead of 3) is documented in LC-06 itself, so the two requirements do not contradict. |

### Delta: `deployment-surface-hygiene` (NEW capability)

| ID | Status | Evidence |
|---|---|---|
| **DSH-01** Credential material described, never reproduced | **SATISFIED for the published credential / definition over-broad (W-2)** | `openspec/changes/ventas-clientes-controller-dedup/verify-report.md:57-70` now carries `logkey=<redacted>`, `auth_sig=<redacted>`, `FSSESS_<redacted>=<redacted>`, `_token="<redacted>"` while keeping the request shape, the code path and the PASS/302 conclusion (scenario 4 holds). `ventas-clientes-dispatch-regression-test/verify-report.md` likewise. A tree-wide adjacency scan returns **no** credential; the only three matches are the SHA-1-of-the-empty-string fixtures in `tests/Security/CookieSecureAndPasswordMigrationTest.php:73,96,115`, each carrying `# nosecret`. `tests/bootstrap.php`'s 64-hex `FS_SECRET_KEY` is a documented non-match. |
| **DSH-02** Deny-by-default covers every non-runtime directory; themes not denied | **SATISFIED (text-level only)** | `nginx-sample.conf:54-61` has all eight `^~` denies (`openspec/ docs/ tools/ extras/ odd/ dev-tools/ managed_context/ test_suite_analysis/`); `themes/` is absent and explicitly explained at `:53`; the pre-existing exact-file denies (`:22-27`) and prefix denies (`:38-47`) are unchanged. `NginxDenyRulesTest` green (7 cases). **Live behaviour is O.1.** |
| **DSH-03** Pre-commit secret scan rejects a staged credential before the early exit | **SATISFIED** | `scripts/hooks/pre-commit:21-27` defines the adjacency rule with an explicit "a bare 64-hex rule MUST NOT be used" rationale; the scan block (`:29-76`) runs **above** the `composer.json` early exit (`:81-89`), reads the **staged blob** (`git show ":$file"`) rather than the worktree, excludes `vendor/|plugins/`, scans `--diff-filter=ACMR` (renames included), honours `# nosecret`, and prints only `file:line` + token name (never the value). The hook is installed and identical to the tracked file (`.git/hooks/pre-commit` symlinked). `PreCommitSecretScanTest` drives it against a temporary `GIT_INDEX_FILE` (4 cases). |
| **DSH-04** Redaction invariant enforced by a suite scan | **SATISFIED** | `tests/Security/ServedTreeSecretScanTest.php` applies the adjacency rule tree-wide, goes red on a fixture and green once removed, and pins the `tests/bootstrap.php` near-miss inline. Note the test is **scoped to the two `ventas-clientes-*` reports** for the literal session-identifier/CSRF shapes; the change's own artifacts were checked separately by this verifier and carry no hex literal and no live cookie value. |
| **DSH-05** Operator handoff recorded as an unverified gate; finding stays open | **SATISFIED as a record** | O.1/O.2/O.6 are recorded in `tasks.md:448-461` and `design.md:427-436` with exact retest recipes, marked **OWED**, and `apply-progress.md:71-73` states VULN-0005 MUST be reported open. This report does so (below). **VULN-0005 is OPEN.** |

### Delta: `session-transport-hardening` (NEW capability)

| ID | Status | Evidence |
|---|---|---|
| **STH-01** One definition of the cookie attribute set | **SATISFIED** | `src/Security/SessionManager.php:456-467` returns exactly the six keys with the specified values; `resolveCookiePath()` promoted to `public` (`:473`). Three consumers consume it: `initialize()` builds `cookie_*` options from it (`:145-147`), `base/fs_session_manager.php:121` calls `session_set_cookie_params(SessionManager::sessionCookieParams())`, `src/Core/StealthMode.php:493` likewise. `SessionCookieParamsTest:137-142` asserts the Symfony path carries **no** hardcoded attribute name and **no** `resolveCookiePath()` call. The detector was not changed. |
| **STH-02** Parameters before start; duplicate emission removed | **PARTIAL — input seam SATISFIED, header half operator-only (W-3 / O.3)** | `src/Core/StealthMode.php:491-496` applies `session_name()` → `session_set_cookie_params()` → `ini_set('session.use_strict_mode','1')` → `@session_start()`, in that order. The defensive `setcookie()` **and its debug `error_log` spam** are deleted (`git diff 63c11119 90e9c313`). `StealthModeTest`'s `#[RunInSeparateProcess]` case asserts `session_get_cookie_params()` carries the shared set after the call. **"Exactly one `Set-Cookie`" cannot be asserted here** — CLI SAPI; O.3. |
| **STH-03** Strict session mode at the outermost entry point | **SATISFIED (input seam)** | `StealthMode.php:494` before `:495`; `StealthModeTest` asserts `ini_get('session.use_strict_mode') === '1'` after `ensurePhpSessionStarted()`. `SessionFixationPreventionTest` and `SessionNamePerInstallTest` green. |
| **STH-04** HSTS on secure responses, documented disable, no `includeSubDomains` by default | **SATISFIED (decision asserted; header emission = O.4)** | `src/Security/SecurityHeaders.php:144-176` `hstsHeaderValue()` returns `''` when insecure or when `FS_HSTS_MAX_AGE <= 0`, otherwise `max-age=<n>` with `includeSubDomains` only under `FS_HSTS_INCLUDE_SUBDOMAINS`. Default `31536000` (`:22`). `applyDefaultHeaders()` calls it first (`:114`). **U6.7 shipped**: `applyTransportHeaders()` (`:127-138`) is called from `api.php:79` and `cron.php:68`, so coverage is **all three entry points**, not `index.php`-only — STH-04's coverage-boundary scenario is satisfied and stated here. |
| **STH-05** No bare session start outside the installer | **SATISFIED in practice / one unreachable residual (W-1)** | `controller/admin_agentes.php:268-277` now delegates to `SessionManager::getInstance()` instead of calling `session_start()`. `src/Security/CsrfManager.php:124-136` delegates to the new `startSharedNativeSession()` (`:161-172`), which sets `session_name(fs_session_identity_name())` before starting. `base/fs_session_manager.php:126-128` and `base/fs_maintenance_mode.php:609-612` are named. `base/install_security.php:28-37` keeps its `FSINSTALL_` prefix — the sole exception, unchanged. A tree-wide grep of non-vendor, non-plugin `session_start()` sites returns only these. **One site still starts unnamed in its fallback branch**: `NativeSessionCsrfStorage::ensureSession()` (`src/Security/CsrfManager.php:434-438`) — see W-1. |
| **STH-06** W-4 closed without re-opening the dual-cookie regression | **SATISFIED** | The W-4 residual is closed by *consuming* the shared params, not by reconciling a duplicate: the acronym `Path=/`-hardcoded, `secure`-less `setcookie()` is gone. The `resolveSessionName()` name-match gate at `SessionManager::initialize():109-115` is **absent from the W6 diff** — untouched, as the requirement mandates. `SessionManagerTest`, `SessionNamePerInstallTest`, `SessionLegacyPhpSessionMigrationTest`, `StealthSessionBootstrapTest` stay green, so SC-01…SC-05 are not re-opened. The only remaining `PHPSESSID` emission is the deliberate expiry of a foreign cookie (`:255-271` scoped by `legacyPhpSessionCookiePaths()`), which is SC-05's own requirement. |

### Delta: `session-consolidation` (MODIFIED SC-01 + Known-deviation paragraph)

| ID | Status | Evidence |
|---|---|---|
| **SC-01** Shared session name before `session_start()`, no residual deviation | **PARTIAL — one residual (W-1)** | Both previously-deviating sites are fixed: `admin_agentes` delegates to `SessionManager` (name + cookie params + strict mode before start); `CsrfManager::ensureSession()` sets the name via the dependency-free `fs_session_identity` resolver before starting (`CsrfManager.php:161-172`, pinned by `CsrfManagerTest:185-231` and `AdminAgentesSessionBootstrapTest`). The installer exception is preserved. **However**, `NativeSessionCsrfStorage::ensureSession()` in the *same file* still has `if (class_exists(SessionManager)) session_name(...); @session_start();` — an unnamed start when `SessionManager` is absent. It is unreachable by construction today (its only caller, `getManager():96-101`, always runs `CsrfManager::ensureSession()` first, which leaves the session active), but the delta's "no core path may start an unnamed session" is therefore not literally true. |

---

## Findings

### CRITICAL

**C-1 — `AAG-01`'s normative body contradicts the code and `AAG-06`.**

`specs/anonymous-access-gate/spec.md:50-55` states:

> `PublicAccessGate::intercept()` **MUST** exempt an anonymous request from the gate **only when**
> the request's canonical requested page is `login`.

The shipped gate also exempts `password_reset` (`src/Core/PublicAccessGate.php:95`, driven by
`fs_auth_request::PUBLIC_PAGES = ['login', 'password_reset']` at `base/fs_auth_request.php:53`), and
`AAG-06` at `spec.md:267-285` **requires** it to. Only AAG-01's *scenario* was annotated
("Superseded by AAG-06", `:82-89`); the normative sentence was never amended. Merging both deltas
verbatim writes two contradictory `MUST`s into a brand-new canonical capability, and a reader
implementing AAG-01 literally would delete the exemption that makes password recovery reachable —
the exact regression PR 4 was created to undo.

*Required action*: amend AAG-01's body to "only when the requested page is in the anonymously served
set (AAG-06)" before merging. The code needs no change.

**C-2 — `AAG-02`'s scenario "Non-canonical page values are gated, not exempted" is false.**

`specs/anonymous-access-gate/spec.md:134-139`:

> GIVEN an anonymous request naming `?page=LOGIN` and another naming `?page=login%20`
> THEN **both** return a `302` whose `Location` is the real login page
> AND neither is treated as a login page by the gate

`?page=login%20` decodes to `login `, which `fs_auth_request::requestedPage()` trims to `login` and
accepts, so `StealthMode::isLegacyLoginPageRequest()` is `true` and `intercept()` returns `null`. The
shipped test asserts precisely that (`tests/Components/PublicAccessGateTest.php:460-469`,
`assertNull(...)`) and names the conflict inline. The scenario also contradicts **AAG-02's own
resolution table** two blocks earlier (`spec.md:127`, `['page' => 'login ']` → `'login'`), AAG-05,
`design.md` D2 and `index.php:216-229`. The apply phase flagged the correction as **owed**
(`tasks.md:137`); `design.md:101-103` was corrected, **the delta was not**.

*Required action*: split the scenario — `?page=LOGIN` → `302` (fail-closed), `?page=login%20` → exempt
and resolves to `login` for both gate and dispatcher. The code needs no change.

### WARNING

**W-1 — SC-01's "no residual deviation" leaves one unnamed-start path in the file it claims to have
fixed.**

`src/Security/CsrfManager.php` now has **two** methods named `ensureSession()`: the static one on
`CsrfManager` (`:124`, fixed — delegates to `startSharedNativeSession()`), and the instance one on
`NativeSessionCsrfStorage` (`:432-446`), which still reads:

```php
if (session_status() === PHP_SESSION_NONE) {
    if (class_exists(SessionManager::class)) {
        session_name(SessionManager::resolveSessionName());
    }
    @session_start();
```

When `SessionManager` is unavailable that starts a default-named (`PHPSESSID`) session — the exact
shape SC-01/STH-05 forbid. It is **unreachable by construction today**: its only instantiation is
`getManager():101`, which always calls `CsrfManager::ensureSession()` first, leaving the session
active. Pre-existing (blame `0da69c83`, 2026-06-09), untouched by this change. The delta names "the
`ensureSession()` branch" without disambiguating, and the canonical `### Known deviation` paragraph
it replaces describes this branch; a merged spec would be ambiguous about which one is meant.

*Suggested action*: have that branch call `CsrfManager::startSharedNativeSession()` too (one line), or
delete it as dead code. Not a blocker: no path can currently reach it with no session open.

**W-2 — `DSH-01`'s definition of a credential is over-broad relative to the shipped control, so the
requirement is literally violated by the tree.**

`specs/deployment-surface-hygiene/spec.md:40-43` defines a credential as *"a 40- or 64-character
hexadecimal blob adjacent to one of the tokens `logkey`, `auth_sig`, `password` or `token`"*, with no
exemption mechanism. Three lines match that definition exactly:
`tests/Security/CookieSecureAndPasswordMigrationTest.php:73,96,115` (`password =
'da39a3ee…afd80709'`, the SHA-1 of the empty string, a legacy fixture). They survive only because the
**hook and the test** honour `# nosecret` — a marker `DSH-03` authorises but `DSH-01` never mentions.
Merging DSH-01 verbatim creates a canonical requirement the repository violates on three lines.

*Suggested action*: add to DSH-01 "a line carrying the documented `# nosecret` opt-out is not a
finding (see DSH-03)", which is what the implementation actually enforces.

**W-3 — STH-02's header half is unverifiable here, and removing the defensive cookie sync is the one
change whose regression could only appear live.**

STH-02's observable contract is *"exactly one `Set-Cookie` header per response … attribute set
constant for the session lifetime"*. Under the CLI SAPI `headers_list()` is `[]` and
`setcookie()`/`header()` are no-ops, so **no test can assert it** — and none claims to (each of the
five relevant test files says so explicitly; verified by grep). The risk this leaves: `StealthMode`
no longer re-syncs the cookie when *another* component already started the session (the deleted code's
own justification), and D5 argues PHP covers that case because params are applied before the start and
`use_strict_mode=1` discards uninitialised ids. That argument is sound but **unmeasured**. If a
third-party component ever starts the session before both `StealthMode` and `SessionManager`, a stale
id could survive. **O.3 is the probe that would catch it and it has not been run.**

**W-4 — six `tasks.md` PR-level obligations are still unchecked, one of them a live retest the apply
phase itself says is unobservable.**

`tasks.md:202,256,321,383,443,444`. Five are PR-body/handoff statements owed by an author. The sixth
(`:321-322`) is the PR 4 live retest: the apply note records that all six anonymous reset POSTs now
return `302 → login` (proving *reachability* only), that the authentication bucket file is
byte-identical before and after, and that **counter isolation is evidenced in-process only**. That is
an honest record, but it means VULN-0006's end-to-end claim rests on unit/integration evidence, and
the checkbox must stay open rather than be ticked at archive time.

### SUGGESTION

**S-1 — AAG-02 "mirrors exactly" has one divergence.** `fs_auth_request::requestedPage()` returns
`''` where `index.php::fs_get_requested_page_name()` returns `null` for a non-string `page`; the
dispatcher then falls back to `FS_HOMEPAGE` (`index.php:233-237`) while the gate short-circuits with
`302`. Behaviour is safe (the gate decides first, fail-closed) and AAG-03 mandates it, but "exactly
the rule" is one word too strong.

**S-2 — STH-01 "a second copy of the attribute set MUST NOT remain" is not true of the installer.**
`base/install_security.php:29-35` builds its own `session_set_cookie_params([...])` array (lifetime
`0`, its own path resolver). That is the deliberate installer exception for the *name*, but STH-01
carves out nothing for the *attributes*. Either scope the sentence to "the three consumers" (as the
scenario does) or name the installer.

**S-3 — `apply-progress.md:78-79` is stale.** It still instructs that `tasks.md:359` and
`design.md:246` "must be rewritten to `FSSESS_<redacted>=<redacted>`". Both were already rewritten
(commit `7af9e4ff`); this verifier re-checked the whole change directory and found **no unredacted hex
literal and no live cookie value** in any of its artifacts — including the deltas. Harmless, but the
archive record would carry an instruction that is already satisfied.

**S-4 — the `resolveLegacyLogoutNick()` visibility widening is inert.** `LegacyAuthBridge` is
`final class` (`:28`), so `protected` creates no subclass seam, and `LegacyAuthBridgeTest:182-188`
reaches the method through `ReflectionMethod`. RMR-06 permits the widening, but it loosens
encapsulation without delivering the test seam it describes.

**S-5 — `LC-06` and `LTI-05` read as contradictory in isolation.** LC-06 documents that the lockout
sensitivity deliberately drops from 3 to 6 real failures; LTI-05 says the authentication lockout
"MUST keep its full strength". Both are true (config constants vs. per-submission arithmetic) and
LC-06 explains it, but a reader landing on LTI-05 alone would not know. One cross-reference in LTI-05
would close it.

---

## Per-delta merge verdict

| Delta | Ready to merge into `openspec/specs/`? | Reason |
|---|---|---|
| `anonymous-access-gate` (AAG-01…AAG-06) | **NO — as written** | **C-1** writes a `MUST` that contradicts `AAG-06` in the same file; **C-2** writes a scenario the code refutes and that contradicts AAG-02's own table. Both are bounded text fixes; after them the delta is fully mergeable. Canonical dir does not exist → archive must **create** `openspec/specs/anonymous-access-gate/spec.md`; the delta already has full-spec shape (`# … Specification` / `## Purpose` / `## Requirements`), matching the `htmx-core-support` precedent. |
| `login-csrf` (ADDED LC-06, LC-07) | **YES** | Spanish, matching the canonical file's language (the merge note at `:3-13` makes that a deliberate decision, resolving the archived change's S-1). LC-02 correctly **not** redefined — the enforcement clause gets its own ID instead of a competing second amendment, which is exactly what the proposal's risk 1 required. Both requirements and all 12 scenarios are literally true against the code, *including* the amended "0 `set_attempt()` on the rejection path" clause (`:65-66`) and the reason it is unreachable. Merge = two table rows + two requirement sections + scenarios. |
| `login-throttle-isolation` (LTI-01…LTI-05) | **YES** | New capability, full-spec shape. Every requirement and scenario verified literally, including the byte-identical auth bucket (the highest-consequence invariant) and the four hostile-namespace cases. Merge = create `openspec/specs/login-throttle-isolation/`. |
| `deployment-surface-hygiene` (DSH-01…DSH-05) | **YES, with the W-2 amendment** | New capability, full-spec shape. DSH-02/03/04 are literally true and text-level verifiable; DSH-05 is the honest operator gate. DSH-01 needs one clarifying sentence for the documented `# nosecret` opt-out, otherwise the merged requirement is violated by three lines of the tree. |
| `session-transport-hardening` (STH-01…STH-06) | **YES, with S-2 as an optional clarification** | New capability, full-spec shape. Every requirement is true at source level; the delta is scrupulous about the CLI limitation (its own normative testing constraint at `:32-40` forbids claiming a header assertion the harness cannot make, and no test violates that). STH-02's header half stays an operator gate — which the delta states, so it is not a false claim. |
| `remember-me-revocation-authority` (RMR-01…RMR-06) | **YES** | New capability, full-spec shape. All six requirements true; the PR 3 self-corrections (`:77-80`, `:127-133`) put the real revert asymmetry and the real testing seam in writing instead of an approximation. That is the standard this repo should keep. |
| `session-consolidation` (MODIFIED SC-01 + Known-deviation) | **YES, with a W-1 note** | Shape matches the canonical file (SC-01 row replacement + Known-deviation paragraph replacement), language matches (English). The claim "no residual deviation" is *effectively* true but not literally, because of `NativeSessionCsrfStorage::ensureSession()`. Merge with either the one-line code fix or a sentence naming that residual. |

No delta carries an unredacted secret literal. Verified by scanning every file under
`openspec/changes/harden-auth-boundaries/` for `[0-9a-fA-F]{32,}` — **zero matches** — and for
`FSSESS_` values other than the `FSSESS_xxx` prose placeholder — **zero matches**.

---

## VERIFIED vs OPERATOR-OWED

### VERIFIED by this run (code read + a test that can go red)

| Item | Where |
|---|---|
| AAG-01 page-based exemption, not shape-based | `PublicAccessGate.php:78-97`; `PublicAccessGateTest` bypass regressions |
| AAG-02 canonical rule (in code) | `fs_auth_request.php:82-95`; `FsAuthRequestTest` |
| AAG-03 non-string `page` is not fatal | `fs_auth_request.php:84-87`; `PhpUnit` array/null + `page[]=login` gate case |
| AAG-05 public path + private page is gated; `password_reset` on that path is exempt; plugin prefixes unaffected; `204` probe preserved | `PublicAccessGateTest:323-325,364-380,395-400,403-421` |
| AAG-06 `password_reset` exempt for GET and POST, page-based not shape-based | `fs_auth_request.php:53`; `PublicAccessGate.php:95`; `PublicAccessGateTest:284-317` |
| LC-06 authoritative verdict + single evaluation + exactly one throttle attempt | `fs_controller.php:970-993`; `login.php:85-114`; `LoginPublicCoreCsrfTest`; `FsControllerCsrfBlockingTest:245-268` |
| LC-07 modern-door credential guard with the shared predicate | `Base/Controller.php:179-208`; `ModernControllerCredentialCsrfTest` |
| RMR-01 both unsigned-cookie revocation fallbacks deleted | `LegacyAuthBridge.php:260-268`; `fs_login.php:210-213` |
| RMR-02 failure mutates nothing but the caller | `fs_login.php:300-308`; `AnonymousLogoutRevocationTest` (recording `fs_user` double, 0 mutations anonymous / 1 authenticated) |
| RMR-03 audit attribution from the session | `fs_login.php:157-162,192-194` |
| RMR-04 no `last_ip` on that path | `fs_login.php:300-308` |
| RMR-05 both login blocks escaped | `login/default.html.twig:55,63`; `LoginTemplateEscapingTest` |
| RMR-06 no public signature change | `git diff f26ac8ec d576e014` |
| LTI-01 **auth bucket byte-identical**; reset bucket separate; no namespace at `fs_login.php:75` | `fs_ip_filter.php:58,80-88`; `FsIpFilterTest:153-173`; construct sites grepped |
| LTI-02 hostile namespaces cannot escape `tmp/` | `fs_ip_filter.php:86`; `FsIpFilterTest` provider |
| LTI-03 validation precedes counting | `password_reset.php:139-147`; `PasswordResetThrottleIsolationTest` |
| LTI-04 CSRF first in both reset entry points | `password_reset.php:127,198` |
| LTI-05 thresholds/messages unchanged, no new key | `fs_ip_filter.php:33-34`; controller strings |
| DSH-01 published credential no longer reproduced; narrative preserved | `ventas-clientes-controller-dedup/verify-report.md:57-70`; tree scan clean |
| DSH-02 eight `^~` denies, no `themes/` deny, old denies intact | `nginx-sample.conf:22-61`; `NginxDenyRulesTest` |
| DSH-03 hook above the early exit, staged-blob scan, `# nosecret`, renames | `scripts/hooks/pre-commit:21-89`; `PreCommitSecretScanTest` |
| DSH-04 red/goes-green scan + `bootstrap.php` near-miss pinned | `ServedTreeSecretScanTest` |
| STH-01 single attribute-set definition, three consumers, no hardcoded copy in the Symfony path | `SessionManager.php:145-147,456-467,473`; `SessionCookieParamsTest:137-142` |
| STH-02 params applied before the start; defensive `setcookie()` deleted | `StealthMode.php:491-496`; W6 diff |
| STH-03 `use_strict_mode=1` before the start | `StealthMode.php:494`; `StealthModeTest` separate-process case |
| STH-04 HSTS decision, `FS_HSTS_MAX_AGE=0` escape, `includeSubDomains` opt-in, **all three entry points** | `SecurityHeaders.php:107-205`; `api.php:79`; `cron.php:68`; `SecurityHeadersTest` |
| STH-05 both named deviating sites fixed in code; installer exception preserved | `admin_agentes.php:268-277`; `CsrfManager.php:124-136,161-172`; `install_security.php:28-37` |
| STH-06 W-4 closed by removal, name-match gate untouched, SC-01…SC-05 green | W6 diff shows no change at `SessionManager.php:109-115` |
| SC-01 both deviating sites fixed (see W-1 for the residual) | `CsrfManagerTest:185-231`; `AdminAgentesSessionBootstrapTest` |
| Baseline: full suite / Security suite / PHPStan unchanged | Commands 1–3 above |

### OPERATOR-OWED — **do not record these as verified**

| # | Probe | Closes | Recorded in |
|---|---|---|---|
| **O.1** | Apply the extended deny rules on the live server, `nginx -t` → `0`, `GET /openspec/changes/ventas-clientes-controller-dedup/verify-report.md` → `403`, `GET /config.php` → `404` | VULN-0005 (half) | `tasks.md:454` |
| **O.2** | Rotate the `admin` `log_key` (the published `auth_sig` is burned) | **VULN-0005 (the only step that actually closes it)** | `tasks.md:455` |
| **O.3** | Live `Set-Cookie` probe on a secure response: **exactly one** session cookie, carrying `Secure`, `HttpOnly`, `SameSite=Lax` | STH-02 / VULN-0007 header criteria | `tasks.md:456` |
| **O.4** | Live HSTS probe: `max-age=31536000`, no `includeSubDomains` by default; absent with `FS_HSTS_MAX_AGE=0` | STH-04 / VULN-0007 header criteria | `tasks.md:457` |
| **O.5** | Live remember-me failure probe: anonymous `GET ?page=login` with `user=<real nick>` + bogus `logkey`/`auth_sig` → the caller's own `user`/`logkey`/`auth_sig` are deleted, and the named account's `fs_users.log_key` is unchanged in the DB | RMR-02 header half / VULN-0008 | `tasks.md:458` |
| **O.6** | Re-run the strix scan recipe (FIX-PLAN §6): six core findings closed, no new finding | Whole change | `tasks.md:459` |

**This report records none of O.1–O.6 as verified.** The PHPUnit CLI SAPI cannot assert headers
(`headers_list()` returns `[]`), and nothing in this repository executes `nginx-sample.conf`. The
consequence: **VULN-0007's header criteria are unproven, and VULN-0005 is OPEN.**

Also still open, by explicit scope decision, and **not to be reported as closed**:

- **VULN-0005 stays OPEN.** The redaction and the deny rules shipped; the credential lives in git
  history and in the public upstream repository. Only O.1 + O.2 close it.
- **VULN-0007's header criteria** are operator probes (O.3, O.4).
- **Post-authentication object-level authorization** was never tested — no credentials were available.
  Out of scope, unclaimed.
- **AAG-06 is a stealth-disabled-only fix.** With `stealth_enabled = 1` the gate answers with the
  public homepage or the secret-entry redirect before reaching the exemption, so the recovery page is
  reachable only with the secret — exactly like `login`. Documented at `tasks.md:310-313`; not a
  defect, but not a fix under stealth either.
- **The 12 `OidcProvider` failures** are another repository's pre-existing baseline.

---

## Could not verify

- **Any live HTTP header** — `Set-Cookie` count and attributes, `Strict-Transport-Security`, and the
  cookie deletions on the remember-me failure path. CLI SAPI. → O.3, O.4, O.5.
- **Whether the removed defensive `setcookie()` causes a live regression** when another component
  starts the session before `StealthMode` (W-3). The design argument is sound; only O.3 measures it.
- **`nginx -t` against a real server block and any live `403`/`404`.** → O.1.
- **Whether the `admin` `log_key` has been rotated.** → O.2. Until then the finding is open.
- **VULN-0006 end to end on a live site.** The gate now makes the handler genuinely reachable
  (`GET/POST ?page=password_reset` → not intercepted, verified in-process), but the live retest the
  apply phase attempted could not distinguish isolation from unreachability, and the in-process suite
  is the only evidence for counter isolation. The `tasks.md:321` checkbox stays open by design.
- **A mutation test** (removing a guard to confirm its test goes red). The verify phase forbids
  modifying implementation files; the discriminating quality of each test was assessed by reading it.
  The LC-06 and RMR tests were readable enough to confirm they can go red (explicit call counters and a
  recording double), which is why those requirements are marked SATISFIED rather than PARTIAL.

---

## Conclusion

**The change may NOT be archived as-is.** The implementation is done, correct and green — every one
of the 30 requirements is satisfied by the code, the `Security` suite is 334/334 with 0 failures, the
full suite sits exactly at its documented baseline (4497 tests / 12 pre-existing plugin failures), and
PHPStan reports its 38 pre-existing findings with no growth and no `ignoreErrors`.

What blocks archiving is the change's own record. **C-1** and **C-2** are false statements inside
`specs/anonymous-access-gate/spec.md` that contradict the shipped code and, in C-1's case, another
requirement in the same delta. Merging them verbatim would write into a brand-new canonical
capability a `MUST` that the code must violate (delete the password-recovery exemption) and a
scenario the test suite refutes. That is the same defect the archived `consolidate-session-csrf`
verify classified as its single CRITICAL blocker, and the same remedy applies: **fix the delta, do
not touch the code.**

**To unblock**, in this order:

1. Amend AAG-01's requirement body so the exemption is "the anonymously served page set (AAG-06)",
   not "`login`" (C-1).
2. Split AAG-02's "Non-canonical page values are gated" scenario: `?page=LOGIN` → `302`,
   `?page=login%20` → exempt (C-2).
3. Add the `# nosecret` opt-out to DSH-01's definition (W-2).
4. One line or one sentence for the `NativeSessionCsrfStorage::ensureSession()` residual (W-1), so
   SC-01's "no residual deviation" is literally true.
5. Then merge the deltas per the per-delta table and archive — creating the five new canonical
   capabilities and replacing SC-01 + its Known-deviation paragraph.

**Archiving the SDD does not close any finding.** VULN-0005 remains **OPEN** until O.1 and O.2 are
confirmed, and VULN-0007's header criteria remain unproven until O.3 and O.4 are run. The
`archive-report.md` must say so explicitly, and the six unchecked `tasks.md` obligations (W-4) should
be carried into it rather than ticked.

---

## Verify follow-up — corrections made after this report (appended at archive time)

**Appended**: 2026-10-25, by the archive phase. The verdict and findings above are **not** rewritten,
edited or deleted — they are the historical record of the independent verify run and stay legible as
such. This section records only what changed after that run was written, and on what evidence the
archive proceeded.

The four requirements the report set as pre-merge conditions were corrected in the deltas and
committed: `08471e79` — `docs(openspec): correct four delta statements found by the verify phase`
(touching the four delta files and adding this report). Re-checked at archive time against that
committed tree:

| Finding | Required correction | Evidence checked at archive time | Status |
|---|---|---|---|
| **C-1** — AAG-01's body said "only when … is `login`" | Name the anonymously served page set | `specs/anonymous-access-gate/spec.md` AAG-01 now binds the exemption to "the publicly served page set defined by AAG-06 (`login` and `password_reset`, enumerated once in `fs_auth_request::PUBLIC_PAGES`)", keeps the page-not-shape rule, and carries an inline `*(Amended during verify.)*` note explaining why the noun was widened | **RESOLVED** |
| **C-2** — AAG-02's scenario asserted `?page=login%20` → `302` | Split into `LOGIN` gated / `login%20` exempt | Same file now carries two scenarios: "A non-canonical page value is gated, not exempted" (`?page=LOGIN` → `302`, not treated as a login page) and "A value the dispatcher canonicalises is exempt" (`?page=login%20` → gate and dispatcher both resolve `login`; gate returns `null`), plus the correction note stating the old pair would have ratified a raw-comparison defect | **RESOLVED** |
| **W-2** — DSH-01's definition had no `# nosecret` opt-out | Add the inline opt-out | `specs/deployment-surface-hygiene/spec.md` DSH-01 now states that a line carrying `# nosecret` / `// nosecret` (the marker DSH-03 authorises) is not a finding, names the three SHA-1-of-the-empty-string fixtures, confines the opt-out to a reviewed per-line exception and keeps the adjacency rule unrelaxed | **RESOLVED** |
| **W-1** — SC-01 named "the `ensureSession()` branch" without disambiguating | Name `NativeSessionCsrfStorage::ensureSession()` and its condition | `specs/session-consolidation/spec.md` SC-01 and its Known-deviation replacement name the instance method, state that it applies `SessionManager::resolveSessionName()` whenever `SessionManager` is available, give the one shape that would start unnamed (a token operation after a mid-request session close with `SessionManager` absent), and require a one-line fix before that shape becomes reachable. `specs/session-transport-hardening/spec.md` STH-05 states the same condition | **RESOLVED** |

**The archive proceeded on that basis.** The corrected deltas are what was merged into
`openspec/specs/`; no implementation file and no test was touched, which is the remedy this report
prescribed. Nothing this report recorded as OPEN is closed by the archive:

- **VULN-0005 stays OPEN** until O.1 and O.2 are confirmed on the live server.
- **VULN-0007's header criteria** remain unproven until O.3 and O.4 are run.
- **AAG-06 remains a stealth-disabled-only fix.**
- **Post-authentication object-level authorization** was never tested and is out of scope.
- **The PR 4 live retest** proved reachability, not counter isolation.
- The six unchecked `tasks.md` obligations (W-4) were carried into `archive-report.md`, not ticked.
