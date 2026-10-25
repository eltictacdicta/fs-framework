# Tasks: Harden Authentication Boundaries

- **Change**: `harden-auth-boundaries` (CORE FSFramework change)
- **Artifact store**: openspec
- **Phase**: tasks
- **Inputs**: `proposal.md` (approved), `design.md` (22 decisions, per-finding blast radius and rollback, size forecast), the seven deltas under `specs/` (30 requirements, 110 scenarios), `openspec/config.yaml`
- **Delivery strategy**: `auto-chain`
- **Review budget**: **800 changed lines** per slice (session `review_budget_lines`). Every slice below is also under the repository's own 400-line default, so both budgets are satisfied without a `size:exception`.
- **Total estimate**: ~1,180 changed lines / ~35 files → **must be sliced**. Six slices, one per workstream, in the apply order `0002 → 0003 → 0008 → 0006 → 0005 → 0007`.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1,180 (source/config ~295, docs ~5, tests ~880) |
| 400-line budget risk | **High for the change as a whole; Low per slice** |
| Chained PRs recommended | **Yes** |
| Suggested split | 6 PRs, one per workstream (largest slice ~230 lines) |
| Delivery strategy | auto-chain |
| Chain strategy | Sequential to `main` (6 slices, declared merge order, no tracker branch) |
| Session review budget | 800 changed lines per slice |
| Largest single slice | ~230 lines (PR 6) — 29% of the 800-line budget |

Decision needed before apply: **Yes — one optional-scope decision (PR 6 only)**
Chained PRs recommended: **Yes**
Chain strategy: **Sequential to `main`**
400-line budget risk: **High if shipped as one unit (~1,180 lines, ~3× the 400-line default); Low for each of the six slices**

**Forecast in plain terms.** The change is ~1,180 changed lines across ~35 files, which is roughly three times the repository's 400-line review default and 1.5× the 800-line session budget. It cannot ship as a single PR. Six chained slices, one per workstream, each land under both budgets (largest ≈230 lines) and each is independently revertible. No slice needs a `size:exception`. The one decision owed before PR 6 is whether to include the optional narrow `applyTransportHeaders()` helper for `api.php`/`cron.php` (design Open Question 2, spec STH-04 `SHOULD`); it does not block PRs 1–5 and can be answered at PR 6 time. If it is dropped, HSTS coverage **must** be reported as `index.php`-only, per STH-04's "coverage boundary is stated" scenario.

### Suggested Work Units

| PR | Workstream | Finding | Requirement IDs | Est. lines | Hard constraints |
|----|-----------|---------|-----------------|-----------|------------------|
| 1 | W1 — anonymous access gate | VULN-0002 | AAG-01…AAG-05 | ~185 | **Must land before PR 6** (both edit `src/Core/StealthMode.php`) and **before PR 4's live retest** |
| 2 | W2 — login CSRF verdict (+ W2b modern path) | VULN-0003 | LC-06, LC-07 | ~215 | LC-07 (W2b) is independently sliceable if review capacity is tight |
| 3 | W3 — remember-me revocation authority | VULN-0008 | RMR-01…RMR-06 | ~215 | **Widest blast radius.** 20 referencing files / 6 production instantiation sites. Both fallback copies must change in the same PR; **partial rollback is unsafe** |
| 4 | W4 — throttle isolation | VULN-0006 | LTI-01…LTI-05 | ~145 | Live retest only **after PR 1 lands** |
| 5 | W5 — deployment surface hygiene | VULN-0005 | DSH-01…DSH-05 | ~190 | Internal order: **pre-commit hook before the redaction sweep**, or the next commit undoes it. Carries the **operator handoff** — cannot be closed in code |
| 6 | W6 — session transport hardening | VULN-0007 | STH-01…STH-06, SC-01 | ~230 | **Must NOT run in parallel with PR 1** (shared `src/Core/StealthMode.php`) |

### Chain Dependency Diagram

```
                    main
                     │
   PR 1  W1 gate ────┼──► merge
        (VULN-0002)  │        │
                     │        ├──► PR 4  W4 throttle isolation (VULN-0006)
                     │        │        live retest needs PR 1 merged
                     │        │
   PR 2  W2 CSRF ────┼──► merge   (independent)
        (VULN-0003)  │
                     │
   PR 3  W3 revocation ┼──► merge (independent; widest blast radius)
        (VULN-0008)  │
                     │
   PR 5  W5 surface ──┼──► merge (independent; operator handoff)
        (VULN-0005)  │
                     │
   PR 6  W6 transport ┼──► merge  ◄── MUST follow PR 1 (shared StealthMode.php)
        (VULN-0007)  │
                     ▼
                  main
```

Merge order: **1 → 2 → 3 → 4 → 5 → 6**. PRs 2, 3 and 5 are code-independent and may merge in any order after PR 1; PR 4 may be *authored* any time but its live retest requires PR 1 merged; PR 6 must be authored **after** PR 1 merges because both edit `src/Core/StealthMode.php`. No slice requires another slice's code to compile, so no tracker branch is used.

## Global Constraints

**Strict TDD is active** (`openspec/config.yaml`: `strict_tdd: true`; `apply.tdd: true`). Every unit that changes behaviour starts with the failing test. Units are marked **[TEST]** when the test is written or corrected first; a `[TEST]` unit and its implementation unit are separate commits only when the test is a corrective change to an existing contract, otherwise they may share one commit as long as the test lands first in the diff order.

**Test baseline — do not attribute these to this change:**

| Measurement | Value |
|---|---|
| Full suite | `Tests: 4311, Assertions: 16196, Failures: 12, Skipped: 27` |
| The 12 failures | **All 12 in `plugins/OidcProvider`** — separate repository, pre-existing DB-fixture drift |
| Security suite | `Tests: 269, Assertions: 601, Failures: 0, Skipped: 1` (green) |
| `phpstan` / `phpstan:plugins` | No new findings, no `ignoreErrors` growth |

**The CLI harness cannot assert response headers.** The suite runs under the CLI SAPI where `headers_sent()` is true and `header()`/`setcookie()` are no-ops (`headers_list()` returns `[]`). Every header-level acceptance criterion (exactly one `Set-Cookie`, `Secure`, `Strict-Transport-Security`, cookie deletions on the remember-me failure path) is an **operator-owned live HTTP probe** recorded in "Operator Handoff" below. Tests assert the **input seam** instead and must say so. No scenario in this change may claim a header assertion the CLI harness cannot make.

**FS_HSTS_MAX_AGE escape hatch.** Set `FS_HSTS_MAX_AGE=0` on development hosts before deploying PR 6 — an HSTS `max-age` already served to a browser is **not** undone by a code revert and can pin `panel-ab.ddev.site` to HTTPS for a year.

**Per-unit verification command.** Every unit ends with `ddev exec php vendor/bin/phpunit --filter <TestName>`; every PR ends with its PR-level verification block below. `ddev exec php vendor/bin/phpunit` (full suite) is run once per PR before merge.

---

## PR 1 — W1: Anonymous access gate (VULN-0002, AAG-01…AAG-05)

**Est. changed lines**: ~185 (source ~55 including the new `base/fs_auth_request.php`, tests ~130)
**Depends on**: nothing
**Must land before**: PR 6 (shared `src/Core/StealthMode.php`) and PR 4's live retest
**Rollback boundary**: revert the two-predicate exemption in `PublicAccessGate`, the raw comparison in `StealthMode::isHiddenLoginPageRequest()`, the `Plugins` probe guard, and the `fs_maintenance_mode` delegation. `base/fs_auth_request.php` is additive and inert and may be left in place or reverted in the same commit. No data, no schema, no session state.

### Work Units (one reviewable commit each)

| Unit | Goal | Est. lines | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| U1.1 | **[TEST]** Correct the shipped test that encodes the vulnerability | ~25 | `ddev exec php vendor/bin/phpunit --filter testInterceptAllowsLegacyLoginSubmissionWhenStealthDisabled` | N/A — unit test | Revert this commit alone; no production code in it |
| U1.2 | **[TEST]** `fs_auth_request` unit tests (RED) | ~45 | `ddev exec php vendor/bin/phpunit tests/Base/FsAuthRequestTest.php` | N/A — pure array-in/bool-out | Revert test file |
| U1.3 | `base/fs_auth_request.php` + `phpstan.neon` wiring | ~35 | same as U1.2 | N/A — pure statics | Delete the new file and the two `phpstan.neon` lines |
| U1.4 | `StealthMode` delegates both predicates to `fs_auth_request` | ~20 | `ddev exec php vendor/bin/phpunit tests/Components/StealthModeTest.php` | N/A | Revert the two methods to the local implementations |
| U1.5 | `fs_maintenance_mode` drops its duplicate predicate | ~10 | `ddev exec php vendor/bin/phpunit tests/Base/FsMaintenanceModeTest.php` | `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` | Restore the local copy in `base/fs_maintenance_mode.php` |
| U1.6 | `PublicAccessGate` page-based exemption + bypass regressions | ~30 | `ddev exec php vendor/bin/phpunit tests/Components/PublicAccessGateTest.php` | `ddev exec php vendor/bin/phpunit tests/Security/LegacyLoginRedirectTest.php` | Restore `isLegacyLoginRequest() \|\| isLegacyLoginSubmission()` |
| U1.7 | `Plugins` extracted exact-path predicate + probe guard | ~20 | `ddev exec php vendor/bin/phpunit tests/Components/PublicAccessGateTest.php` | N/A | Restore the duplicated `in_array(...)` and the unguarded `204` |

### Phase 1: Correct the test that encodes the vulnerability (do this FIRST)

- [x] 1.1 **[TEST] [RED] Correct `tests/Components/PublicAccessGateTest.php:137` `testInterceptAllowsLegacyLoginSubmissionWhenStealthDisabled()`.** The shipped test asserts that a credential POST **with no `page` parameter** must be exempt from the gate — that is VULN-0002 written down as a contract. Rewrite it so it asserts the **secure** contract: a credential POST to `page=login` is exempt; a credential POST to any other page is **not**. The replacement must cover the legitimate `page=login` submission **and both bypass inputs** (`?nlogin=1`, and a `user`/`password` body). **This commit must land before any production code in PR 1** — otherwise the gate change is "made green" by the old assertion. Do **not** delete the test; correct it.
  - Verify: the corrected test is RED against today's `PublicAccessGate` and GREEN after U1.6.

### Phase 2: The single request-facts definition (TDD)

- [x] 2.1 **[TEST] [RED] New `tests/Base/FsAuthRequestTest.php`** (design Testing Strategy row 1). Table-driven, no globals, no kernel. Required cases: `requestedPage()` for `['page' => 'login']` → `login`, `'login '` → `login`, `'admin_info'` → `admin_info`, `'LOGIN'` → `''`, `''` → `''`, `'9lives'` → `''`, absent → `''`, `['login']` → `''`, `null` → `''` (AAG-02, AAG-03); `isStealthSubmission()` for POST + `nlogin`, POST + `user`+`password`, POST + `user`+`email`, GET (all false), and the `base/fs_maintenance_mode.php` semantic parity cases (AAG-04); `hasCredentialSubmission()` for POST + `password` + (`user`|`nick`), POST + `password` alone → false, GET + credentials → false (AAG-04, and consumed by PR 2).
  - **The `['login']` / `null` cases are the point of AAG-03** (design Risk 1, severity High): a `trim()` on a non-string is a `TypeError` in PHP 8 and would turn the gate into a `500`. They must exist before the implementation.
- [x] 2.2 `base/fs_auth_request.php` — new `final class fs_auth_request`, all-static, dependency-free, `require_once`-able. Three methods exactly as designed (D1, D2): `requestedPage(array $query): string`, `isStealthSubmission(string $method, array $query, array $post): bool`, `hasCredentialSubmission(string $method, array $post): bool`. `requestedPage()` must `is_string()`-guard **before** `trim()` and return `''` for anything else. Semantics of `isStealthSubmission()` move verbatim from `base/fs_maintenance_mode.php:555-566`.
  - Verify: `ddev exec php vendor/bin/phpunit tests/Base/FsAuthRequestTest.php` green.
- [x] 2.3 `phpstan.neon` — add `base/fs_auth_request.php` to `scanFiles` (**required**, so `src/Core/StealthMode.php` resolves it) and to `paths` (recommended, so the new file is actually analysed). Do not add an `ignoreErrors` entry (proposal non-goal: no baseline growth).
  - Verify: `ddev exec composer phpstan` reports no new findings.

### Phase 3: StealthMode delegates (TDD)

- [x] 3.1 **[TEST] [RED] Extend `tests/Components/StealthModeTest.php`** (design Testing Strategy row 3): `isLegacyLoginRequest()` is true only for the canonical login page (including `login ` trailing-space and false for `LOGIN`); `isLegacyLoginSubmission()` still returns `true` for a verified-stealth-secret request carrying `nlogin` (AAG-04 scenario "the submission predicate stays reachable outside the exemption").
- [x] 3.2 `src/Core/StealthMode.php` — `isHiddenLoginPageRequest()` delegates to `fs_auth_request::requestedPage($_GET) === 'login'`; `isHiddenLoginSubmission()` delegates to `fs_auth_request::isStealthSubmission(...)`. The public wrappers `isLegacyLoginRequest()` / `isLegacyLoginSubmission()` **stay** (external contract) and must remain referenced behind `hash_equals()` so neither becomes dead code.
  - Verify: `ddev exec php vendor/bin/phpunit tests/Components/StealthModeTest.php` green.

### Phase 4: Predicate consolidation in the plugin-consumed file

- [x] 4.1 `base/fs_maintenance_mode.php` — `isStealthLoginSubmission()` delegates to `fs_auth_request`; the local copy at `:555-566` is deleted. `hasStealthAdminAccess()` keeps its public signature unchanged (the `system_updater` plugin calls it through `lib/maintenance_mode_compat.php`, outside this repository's gates).
  - Verify: `ddev exec php vendor/bin/phpunit tests/Base/FsMaintenanceModeTest.php` green, including `testLoginSubmissionShowsMaintenance` and `testStealthLoginRequestSkipsMaintenance`; **and** `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` green (this is the one isolated plugin suite that loads `fs_maintenance_mode`; `MaintenanceLockRecoveryTest` is the relevant member).

### Phase 5: The gate itself (TDD)

- [x] 5.1 **[TEST] [RED] Extend `tests/Components/PublicAccessGateTest.php`** with the AAG-01 and AAG-05 regressions: anonymous `POST ?page=admin_info&nlogin=1` → `302`; anonymous `POST ?page=admin_info` with `user`/`password` → `302`; the same two shapes over a table of non-login pages → all `302` with `Location: index.php?page=login`; anonymous `POST ?page=password_reset&nlogin=1` → `302` and no reset message in the body; anonymous `POST ?page=login` with `nlogin`/credentials → not intercepted; `GET ?page=login` → not intercepted; `POST /index.php/favicon.ico?page=password_reset` → `302` (was `200`); `GET /index.php/favicon.ico?page=password_reset` → `302` (was `204`); `GET /index.php/favicon.ico` with no `page` → `204` unchanged; a plugin public prefix (`/oauth/callback`, `/.well-known/...`) with and without `page=` → not intercepted; `?page[]=login` → `302`, no `TypeError`/`500`; `?page=LOGIN` → `302`.
  - **Applied deviation (U1.6):** `?page=login%20` (i.e. `$_GET['page'] === 'login '`) is **exempt**, not `302`. The original "both `302`" contradicts AAG-02's normative MUST NOT ("the gate MUST NOT compare the raw `$_GET['page']` value"), AAG-02's own resolution table (`['page' => 'login ']` → `'login'`), AAG-05 ("the gate and the dispatcher MUST agree on which page a request names") and design D2's chosen rule; the dispatcher resolves `login ` to `login`, so a raw comparison would redirect a legitimate login submission away — the defect AAG-02 exists to remove. `testInterceptGatesInvalidPageValuesButAgreesWithTheDispatcher` pins the canonical behaviour and names this conflict inline. A spec/design correction is owed; this executor may not edit them.
- [x] 5.2 `src/Core/PublicAccessGate.php` — the exemption becomes `isLegacyLoginRequest()` **alone**; `isLegacyLoginSubmission()` is removed from the exemption decision. The framework exact public path is exempt **only when the request does not name a controller page** (AAG-05, D3). Plugin-registered prefixes stay exempt unconditionally.
  - Verify: `ddev exec php vendor/bin/phpunit tests/Components/PublicAccessGateTest.php` green, including the corrected U1.1 test.
- [x] 5.3 `src/Core/Plugins.php` — extract `isFrameworkExactPublicPath(string $path): bool` from the duplicated `in_array(..., FRAMEWORK_EXACT_PUBLIC_PATHS, true)` at `:107` and `:157`; `shouldRespondNoContentForBrowserProbe()` (`:145-158`) additionally requires that the request names no controller page. Single definition, two consumers (AAG-05 scenario "the framework exact-path test has one definition").
  - Verify: `ddev exec php vendor/bin/phpunit tests/Components/PublicAccessGateTest.php tests/Security/LegacyLoginRedirectTest.php` green.

### PR 1 verification

- [x] `ddev exec php vendor/bin/phpunit --testsuite Base`
- [x] `ddev exec php vendor/bin/phpunit --testsuite Security`
- [x] `ddev exec php vendor/bin/phpunit --testsuite Modern`
- [x] `ddev exec php vendor/bin/phpunit --testsuite Integration`
- [x] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`
- [x] `ddev exec php vendor/bin/phpunit` — full suite, `Failures: 12`, all 12 in `plugins/OidcProvider`
- [x] `ddev exec composer phpstan` — no new findings, no `ignoreErrors` growth

---

## PR 2 — W2: The CSRF verdict is authoritative; credentials evaluated once (VULN-0003, LC-06, LC-07)

**Est. changed lines**: ~215 (source ~45 including W2b, tests ~170)
**Depends on**: `fs_auth_request::hasCredentialSubmission()` from PR 1 (U1.3). If PR 1 is delayed, this PR cannot start — the shared predicate is normative (LC-06, LC-07 "the credential predicate is the shared one").
**Rollback boundary**: delete the `shouldSkipCredentialLogin()` guard from `process_login_logic()`, the `credentialLoginEvaluated` flag/property and the modern-controller guard. All three are additive; reverting restores today's double evaluation exactly. No data or session state involved.
**PR body must document** the intended side effect: the IP ban counter and `LoginThrottle` are currently incremented **twice** per failed submission; after this change, once, so the lockout requires 6 real failed submissions instead of 3. This is the proposal's stated intent, not a regression, and will otherwise read as one during review.

### Work Units (one reviewable commit each)

| Unit | Goal | Est. lines | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| U2.1 | **[TEST]** `tests/Controller/LoginPublicCoreCsrfTest.php` (RED) | ~70 | `ddev exec php vendor/bin/phpunit tests/Controller/LoginPublicCoreCsrfTest.php` | `ddev exec php vendor/bin/phpunit tests/Security/LegacyLoginRedirectTest.php` | Revert test file |
| U2.2 | `login::shouldSkipCredentialLogin()` + `fs_controller` flag | ~35 | same as U2.1 | `ddev exec php vendor/bin/phpunit tests/Security/FsLoginPasswordVerificationTest.php` | Delete the guard, the flag and the accessor |
| U2.3 | **[TEST]** exactly-one-attempt assertions | ~45 | `ddev exec php vendor/bin/phpunit tests/Security/FsControllerCsrfBlockingTest.php` | `ddev exec php vendor/bin/phpunit --testsuite Security` | Revert test additions |
| U2.4 | **[TEST]** `tests/Security/ModernControllerCredentialCsrfTest.php` (RED, W2b) | ~35 | `ddev exec php vendor/bin/phpunit tests/Security/ModernControllerCredentialCsrfTest.php` | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | Revert test file; **this unit is independently sliceable out of the PR** |
| U2.5 | `src/Core/Base/Controller` credential CSRF guard (W2b) | ~30 | same as U2.4 | same as U2.4 | Delete the guard from `initializeConnectedSession()` |

### Phase 1: The legacy login path (TDD)

- [x] 1.1 **[TEST] [RED] New `tests/Controller/LoginPublicCoreCsrfTest.php`** — the name the pentest verified (design Testing Strategy row 5). Cases, all LC-06: invalid token → the credential flow is **not** reached and the response contains the CSRF error and **no** credential-evaluation message (`Usuario o contraseña incorrectos.`, `baneada`, `desactivado`); **no token field at all** → identical; valid token + wrong credentials → the credential-evaluation message appears **exactly once** (was twice); valid token + correct credentials → login succeeds and redirects as today; `GET ?page=login` → unchanged (page renders, `?autologin=` still works); `GET ?page=login&logout=1` → unchanged; a plugin-registered public-login redirect is honoured for an anonymous **POST** to `page=login`; a POST to `page=login` with **no credentials and no token** is **not** rejected by the guard (LC-06 scenario "el guard no usa requireCsrf").
  - This is the RED step for LC-06. The "exactly once" cases must be RED against today's double evaluation.
- [x] 1.2 `base/fs_controller.php` — `requestHasCredentials()` delegates to `fs_auth_request::hasCredentialSubmission($this->request->getMethod(), $this->request->request->all())` and becomes `protected` (was `private`; no behaviour change). `log_in()` sets `$this->credentialLoginEvaluated = true` when the credential POST passed CSRF and is about to be handed to `fs_login`. New `protected function credentialLoginEvaluated(): bool`.
- [x] 1.3 `controller/login.php` — `process_login_logic()` gains one guard immediately **before** `handleCredentialLogin()`: `if ($this->shouldSkipCredentialLogin()) { return; }`. New `protected function shouldSkipCredentialLogin(): bool` returns `true` when the request is a POST **and** either `!$this->isCsrfValid()` (the verdict from `fs_controller::log_in()` is final) **or** `$this->credentialLoginEvaluated()` (already evaluated once). The predicate reads `isCsrfValid()`, **never** `requireCsrf()` (D8). The guard sits **after** the plugin public-login redirect, `restoreBufferedVariables()`, `switchDatabaseIfRequested()`, the `?logout` handling, the `logged_on` redirect and `handleAutoLogin()` (D7) — a guard at the top of `public_core()` is explicitly rejected.
  - Verify: `ddev exec php vendor/bin/phpunit tests/Controller/LoginPublicCoreCsrfTest.php` green.

### Phase 2: Exactly one throttle attempt per submission (TDD)

- [x] 2.1 **[TEST] [RED] Extend `tests/Security/FsControllerCsrfBlockingTest.php`** with a spy on `fs_ip_filter::set_attempt()` / `LoginThrottle::recordFailure()` asserting **exactly one** call per credential submission, for both the invalid-token and the valid-token-wrong-password shapes (design Testing Strategy row 6; LC-06 scenario "token válido y credenciales incorrectas evalúan una sola vez").
  - Verify: RED before 1.2/1.3 land, GREEN after. Run together with `LoginThrottleTest`, `LoginThrottleAccountTest`, `LoginThrottleProxyTest`, `FsLoginPasswordVerificationTest`, `LoginActionUrlTest`, `LegacyLoginRedirectTest` — all must stay green.

### Phase 3: The modern door (W2b, LC-07) — independently sliceable

- [x] 3.1 **[TEST] [RED] New `tests/Security/ModernControllerCredentialCsrfTest.php`** (design Testing Strategy row 7). Two directions, all LC-07: an **authenticated** victim POSTing the attacker's `user`/`password` to an FS2025 route with an **invalid** token → the session does **not** switch account and `fs_login::log_in()` is not reached; the same POST with **no token field** → same; the same POST with a **valid** token → behaves as today; a legitimate modern form POST carrying `nick`/`password` with a valid token → processed normally; the guard uses the shared predicate and holds no local duplicate condition.
- [x] 3.2 `src/Core/Base/Controller.php` — `initializeConnectedSession()` gains the equivalent guard before `$this->login_tools->log_in($this->user)`: reject a credential POST whose CSRF token is not valid, using `fs_auth_request::hasCredentialSubmission()` and the controller's own CSRF helper. No `public_core()` exists on this base, so there is no double-execution half here — only the authoritative-verdict half.
  - Verify: `ddev exec php vendor/bin/phpunit tests/Security/ModernControllerCredentialCsrfTest.php` green **and** `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` green (the plugin suite whose tests compose the modern base controller).

**Applied deviation (U2.3):** the invalid-token scenario's closing clause ("se registra exactamente un `set_attempt()`") is unreachable with the guard this same delta mandates: `set_attempt()` lives inside `fs_login::log_in()` (`base/fs_login.php:139-141`), which the authoritative CSRF verdict now prevents the request from reaching. Reproducing it would need a new, unlisted write on the rejection path, and that write would let a cross-site form POST ban an arbitrary victim's IP. The requirement body only mandates the valid-token arithmetic ("una submission fallida con token válido MUST producir exactamente un intento de throttle"), which is asserted; the rejection path is asserted to be inert (0 `set_attempt()`, 0 `LoginThrottle` attempts). A spec correction is owed; this executor may not edit the delta.
**Applied deviation (U2.5):** the guard lives in a new `protected deliverSessionLogin()` seam, called from `initializeConnectedSession()`, instead of inline. The full `initializeConnectedSession()` path ends in `exit()` (it renders the login form for a visitor that is not logged on), which terminates the PHPUnit runner, so the delivery to `fs_login::log_in()` — the observable LC-07 names — could not be asserted behaviourally without the seam. This is the same documented limitation as `tests/Base/FsControllerLogoutRedirectTest.php`.
**Artifact inconsistency (U2.2, not acted on):** the tasks table lists `handleAutoLogin()` among the branches that "keep running before the guard", but the design's own snippet (`if ($this->shouldSkipCredentialLogin()) { return; }` immediately before `handleCredentialLogin()`) and this unit's 1.3 text place the guard *before* `handleCredentialLogin()`, i.e. also before `handleAutoLogin()`. The design snippet was followed; the consequence is that a credential POST whose token is invalid (or that was already evaluated) does not fall through to the `?autologin=` branch. The spec's normative placement clause only requires "after the plugin public-login redirect and before the credential branch", which holds either way.

### PR 2 verification

- [x] `ddev exec php vendor/bin/phpunit --testsuite Security` — 281 tests, 0 failures, 1 skipped
- [x] `ddev exec php vendor/bin/phpunit --testsuite Modern` — 133 tests, 0 failures
- [x] `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` — 1378 tests, 0 failures (2 pre-existing warnings, 1 skipped)
- [x] `ddev exec php vendor/bin/phpunit` — full suite, `Tests: 4397, Assertions: 16408, Failures: 12, Skipped: 27`; all 12 failures in `plugins/OidcProvider`
- [x] `ddev exec composer phpstan` — the 38 pre-existing findings only (4 untouched test files); no new findings, no `ignoreErrors` growth
- [ ] PR body states the intended lockout-sensitivity change (3 → 6 real failed submissions) — owed by the PR author; the statement is already in the U2.2 commit body

---

## PR 3 — W3: Remember-me revocation authority (VULN-0008, RMR-01…RMR-06)

**Est. changed lines**: ~215 (source/template ~45, tests ~170)
**Depends on**: nothing. Review it with the full 20-file reference list in hand (design W3 blast radius table: 20 files reference `base/fs_login.php`; 6 production instantiation sites in 4 files — `base/fs_controller.php:227`, `base/fs_chunked_upload.php:112`, `src/Core/Base/Controller.php:115`, `model/core/fs_user.php:500,511,527`).
**Rollback boundary**: **all-or-nothing for the two fallback removals** — reverting only the bridge re-opens the hole through the `fs_login` copy and vice versa. The template escaping, the neutral notice and the audit attribution are independently revertible. No signature changes, so all 20 reference sites keep compiling either way.

### Work Units (one reviewable commit each)

| Unit | Goal | Est. lines | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| U3.1 | **[TEST]** `LegacyAuthBridgeTest` resolver cases (RED) | ~35 | `ddev exec php vendor/bin/phpunit tests/Security/LegacyAuthBridgeTest.php` | N/A | Revert test additions |
| U3.2 | `LegacyAuthBridge::resolveLegacyLogoutNick()` drops the cookie fallback | ~15 | same as U3.1 | `ddev exec php vendor/bin/phpunit tests/Base/FsLoginCharacterizationTest.php` | **Revert together with U3.4** |
| U3.3 | **[TEST]** `tests/Security/AnonymousLogoutRevocationTest.php` (RED) | ~65 | `ddev exec php vendor/bin/phpunit tests/Security/AnonymousLogoutRevocationTest.php` | `ddev exec php vendor/bin/phpunit tests/Security/FsLoginCookieAuthTest.php` | Revert test file |
| U3.4 | `base/fs_login.php`: delete the duplicate revocation, neutral notice, `log_out(TRUE)` on both branches, audit attribution from the session | ~30 | same as U3.3 | `ddev exec php vendor/bin/phpunit tests/Security/RememberMeSignatureRequiredTest.php` | **Revert together with U3.2** |
| U3.5 | **[TEST]** extend `FsLoginCookieAuthTest` + `FsLoginCharacterizationTest` | ~35 | `ddev exec php vendor/bin/phpunit tests/Security/FsLoginCookieAuthTest.php tests/Base/FsLoginCharacterizationTest.php` | `ddev exec php vendor/bin/phpunit tests/Base/TrustedSessionRestampTest.php` | Revert test additions |
| U3.6 | **[TEST]** login-template escaping + `\|raw` removal | ~30 | `ddev exec php vendor/bin/phpunit tests/Components/LoginTemplateEscapingTest.php` | N/A | Restore the two `\|raw` filters (independently revertible) |

### Phase 1: Revocation authority (TDD)

- [ ] 1.1 **[TEST] [RED] Extend `tests/Security/LegacyAuthBridgeTest.php`** — `resolveLegacyLogoutNick()` (visibility widened `private` → `protected`, annotated `@internal Expuesto para las pruebas`, precedent `SessionManager::legacyPhpSessionCookiePaths()`) returns the **session nickname** when both are present and a different `$_COOKIE['user']` exists; returns `null` when only `$_COOKIE['user']` is set (RMR-01 scenarios 3 and 4).
- [ ] 1.2 `src/Security/LegacyAuthBridge.php` — delete the `$_COOKIE['user']` fallback at `:257-260`; widen visibility to `protected` for the test seam.
- [ ] 1.3 **[TEST] [RED] New `tests/Security/AnonymousLogoutRevocationTest.php`** — an overridden `fs_user` double records `rotate_logkey()` / `save()` calls. Cases: anonymous `GET ?page=login` with `user=<real nick>` + bogus `logkey`/`auth_sig` → **zero** mutation calls on the named account, no audit row attributed to it; authenticated `?logout=1` → **one** rotation and the audit row names the authenticated nickname; a non-existent nickname → unchanged behaviour for the caller, no row touched; a valid `user`+`logkey`+`auth_sig` triple for an enabled account → the session is restored exactly as today (RMR-02 "the remember-me feature is retained"). Assert `log_out(TRUE)` was invoked via a `fs_login` double — **not** `Set-Cookie` (the CLI SAPI cannot observe headers; see the spec's normative testing seam).
- [ ] 1.4 `base/fs_login.php` — **delete** the duplicate revocation implementation in `revokeCurrentRememberMeToken()` (`:199-210`) so the method delegates unconditionally to the bridge (D13); `handleInvalidCookieSession()` calls `log_out(TRUE)` on **both** branches and emits a neutral "session no longer valid, please sign in again" notice with no `$user->last_ip` interpolation (D15, RMR-04); `log_out()` attributes the audit record to the authenticated session nickname, not the cookie (D14, RMR-03).
  - Verify: U3.1/U3.3 tests green; `tests/Security/LegacyAuthBridgeTest.php`, `FsLoginCookieAuthTest.php`, `tests/Base/FsLoginCharacterizationTest.php`, `RememberMeSignatureRequiredTest.php`, `CsrfSessionSyncTest.php`, `TrustedSessionRestampTest.php` all green (RMR-06 "the widest-blast-radius regression set stays green").

### Phase 2: Disclosure + escaping (TDD)

- [ ] 2.1 **[TEST] [RED] Extend `tests/Security/FsLoginCookieAuthTest.php` and `tests/Base/FsLoginCharacterizationTest.php`** — the anonymous failure response body contains **no** IP address and contains the neutral notice; the message path does not read `last_ip`; an authenticated logout writes the session nickname to `fs_logs.usuario` (RMR-03, RMR-04).
- [ ] 2.2 **[TEST] [RED] New `tests/Components/LoginTemplateEscapingTest.php`** — render the `get_errors()` / `get_messages()` blocks with a `<script>alert(1)</script>` payload (the pattern used by `tests/Core/*MacroContractTest.php`) and assert the escaped output and that no executable script element is produced. **Documented fallback**: if the theme loader is not constructible in the test process, a source-level assertion that neither loop applies `|raw` satisfies RMR-05 scenario 2 and the limitation is recorded in the PR body.
- [ ] 2.3 `themes/AdminLTE/view/login/default.html.twig` — remove `|raw` from both loops (`:55`, `:63`; note the real file name is `default.html.twig`, not `default.twig` as the proposal says). The surrounding markup is unchanged.
  - Verify: `ddev exec php vendor/bin/phpunit tests/Components/LoginTemplateEscapingTest.php` green.

### PR 3 verification

- [ ] `ddev exec php vendor/bin/phpunit --testsuite Security`
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Base`
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Modern`
- [ ] `ddev exec php vendor/bin/phpunit` — full suite, `Failures: 12`, all 12 in `plugins/OidcProvider`
- [ ] `ddev exec composer phpstan` — no new findings (note: `base/fs_login.php` and `themes/` are not covered by this gate; the PHPUnit suite is the gate for them)
- [ ] PR body carries the 20-file reference list and the **all-or-nothing rollback** warning

---

## PR 4 — W4: Throttle isolation (VULN-0006, LTI-01…LTI-05)

**Est. changed lines**: ~145 (source ~25, tests ~120)
**Depends on**: nothing for its code. Its **live retest** depends on PR 1 (VULN-0006's PoC reaches `password_reset` anonymously *through* the gate bypass; without PR 1 the retest cannot distinguish "the counter is isolated" from "the handler is unreachable").
**Rollback boundary**: revert the namespace argument and the two controller call sites — the namespaced file is simply no longer read and the login bucket was never moved, so no data migration. The `set_attempt()` move and the `requireCsrf()` calls revert independently (the CSRF requirement is the user-visible one; no soft window is needed because the template already ships the token).

### Work Units (one reviewable commit each)

| Unit | Goal | Est. lines | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| U4.1 | **[TEST]** `FsIpFilterTest` namespace cases (RED) | ~45 | `ddev exec php vendor/bin/phpunit tests/Base/FsIpFilterTest.php` | N/A | Revert test additions |
| U4.2 | `fs_ip_filter` optional namespace | ~25 | same as U4.1 | `ddev exec php vendor/bin/phpunit tests/Base/FsLoginCharacterizationTest.php` | Revert to the single-argument constructor |
| U4.3 | **[TEST]** `tests/Security/PasswordResetThrottleIsolationTest.php` (RED) | ~55 | `ddev exec php vendor/bin/phpunit tests/Security/PasswordResetThrottleIsolationTest.php` | N/A | Revert test file |
| U4.4 | `password_reset` controller: namespaced filter, `set_attempt()` after validation, `requireCsrf()` | ~20 | same as U4.3 | `ddev exec php vendor/bin/phpunit --testsuite Security` | Revert the three controller edits independently |

### Phase 1: Namespaced bucket (TDD)

- [ ] 1.1 **[TEST] [RED] Extend `tests/Base/FsIpFilterTest.php`** — `new fs_ip_filter()` resolves to exactly `tmp/<FS_TMP_NAME>ip.log` **byte-for-byte** (LTI-01 "the authentication bucket path is unchanged"; this assertion must exist **before and after** the implementation — proposal risk 8: any change to the empty-namespace path would move the authentication lockout file and reset every live ban); `new fs_ip_filter('password_reset')` resolves to `tmp/<FS_TMP_NAME>password_reset_ip.log`; hostile namespaces `'Password-Reset!!'` and `'../../etc/passwd'` resolve inside `tmp/` and contain no path separator; the sanitised suffix is deterministic across two computations (LTI-02).
- [ ] 1.2 `base/fs_ip_filter.php::__construct(string $namespace = '')` — empty namespace path unchanged; non-empty → suffix `preg_replace('/[^a-z0-9_]/', '', strtolower($namespace)) . '_'` (D11). The parameter is optional so `base/fs_login.php:75` and every existing test double stay byte-identical.
  - Verify: `ddev exec php vendor/bin/phpunit tests/Base/FsIpFilterTest.php` green.

### Phase 2: Ordering + CSRF (TDD)

- [ ] 2.1 **[TEST] [RED] New `tests/Security/PasswordResetThrottleIsolationTest.php`** — six anonymous empty POSTs to `page=password_reset` then a credential POST with a fresh valid token and a wrong password → the response contains `Usuario o contraseña incorrectos.` and **not** the ban message (LTI-01 scenario 1); an empty reset submission increments **no** counter in either bucket and renders `password-reset-enter-nick-or-email` (LTI-03); a real attempt increments exactly one counter, in the reset bucket (LTI-03); a token-less request and an invalid-token request are refused with no lookup, no token mint, no mail and no counter increment (LTI-04); a valid-token request proceeds exactly as today; the reset-completion branch with an invalid token is refused and no password is changed; the reset throttle still fires on its own bucket with `password-reset-too-many-attempts` (LTI-05).
- [ ] 2.2 `controller/password_reset.php` — `public_core()` constructs `new fs_ip_filter('password_reset')`; in `process_request()` `set_attempt()` moves **below** the empty-`nick_or_email` check; `process_request()` and `process_reset()` call `requireCsrf()` before any work (D12 — controller-side only; the template already emits `{{ csrf_field() }}` at `themes/AdminLTE/view/password_reset.html.twig:80,116`). `process_reset()` keeps its `set_attempt()` where it is — it counts a genuinely failed token validation, now in the reset bucket.
  - Verify: `ddev exec php vendor/bin/phpunit tests/Security/PasswordResetThrottleIsolationTest.php` green.

### PR 4 verification

- [ ] `ddev exec php vendor/bin/phpunit --testsuite Base`
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Security`
- [ ] `ddev exec php vendor/bin/phpunit` — full suite, `Failures: 12`, all 12 in `plugins/OidcProvider`
- [ ] `ddev exec composer phpstan` — no new findings
- [ ] **Retest gate (requires PR 1 merged):** six anonymous empty POSTs to `page=password_reset` no longer lock out authentication; the seventh reset request is refused by the reset throttle. Recorded as a scripted manual retest in the PR body, because it depends on PR 1's gate.

---

## PR 5 — W5: Deployment surface hygiene (VULN-0005, DSH-01…DSH-05)

**Est. changed lines**: ~190 (config/hook ~55, docs ~5, tests ~130)
**Depends on**: nothing.
**Internal order is load-bearing: the pre-commit hook lands BEFORE the redaction sweep**, or the next commit undoes the sweep. Within this PR: U5.1 (RED scan) → U5.2 (hook) → U5.3 (redaction) → U5.4/U5.5 (nginx) → U5.6 (hook behaviour).
**Rollback boundary**: revert the hook, the config and the document edits independently. A rotated key stays rotated (the desired direction). No interaction with the other workstreams.
**This PR carries the operator handoff and is NOT "done" when it merges.** See "Operator Handoff" below. Until O.1 and O.2 are confirmed, VULN-0005 **must** be reported **open**.

### Work Units (one reviewable commit each)

| Unit | Goal | Est. lines | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| U5.1 | **[TEST]** `tests/Security/ServedTreeSecretScanTest.php` (RED on the shipped tree) | ~50 | `ddev exec php vendor/bin/phpunit tests/Security/ServedTreeSecretScanTest.php` | N/A — filesystem scan | Revert test file |
| U5.2 | `scripts/hooks/pre-commit` secret scan **above** the early exit | ~40 | see U5.6 | manual: `bash scripts/hooks/pre-commit` against a staged fixture | Revert the hook to the Composer-only version |
| U5.3 | Redaction sweep over the served planning tree | ~5 | `ddev exec php vendor/bin/phpunit tests/Security/ServedTreeSecretScanTest.php` | N/A — Markdown only | Revert the two document edits |
| U5.4 | **[TEST]** `tests/Core/NginxDenyRulesTest.php` (RED) | ~40 | `ddev exec php vendor/bin/phpunit tests/Core/NginxDenyRulesTest.php` | operator: `nginx -t` | Revert test file |
| U5.5 | `nginx-sample.conf` eight `^~` deny locations | ~20 | same as U5.4 | operator: live `403`/`404` probes | Revert the config hunk |
| U5.6 | **[TEST]** hook behaviour test (fixture rejected, `# nosecret` honoured, early-exit path) | ~35 | `ddev exec php vendor/bin/phpunit tests/Core/PreCommitSecretScanTest.php` | manual fallback command documented below | Revert test file |

### Phase 1: The scan that enforces the invariant (TDD, RED first)

- [ ] 1.1 **[TEST] [RED] New `tests/Security/ServedTreeSecretScanTest.php`** (DSH-04) — a repository scan over tracked, non-vendor paths applying the **adjacency** rule: a 40- or 64-character hex blob adjacent to `logkey=`, `logkey:`, `auth_sig=`, `auth_sig:`, `password=` or `token=`. Two cases: the scan **goes red** on a temporary fixture containing `auth_sig=<64 hex>` under the served tree (and green again once the fixture is removed), and the scan is **green on the shipped tree**.
  - **This test is RED before U5.3.** `openspec/changes/ventas-clientes-controller-dedup/verify-report.md:62` still publishes the live `admin` `log_key`, its matching `auth_sig`, a session identifier and a copy-pasteable session-forgery recipe. The RED run is the evidence that the redaction sweep was needed.
  - **The adjacency rule is normative and must not be "simplified".** `tests/bootstrap.php:62` defines a deterministic 64-hex `FS_SECRET_KEY` that is **not** adjacent to any of the four tokens. A bare "any 64-hex blob" rule would false-positive on it and force the control to be weakened. The near-miss must be documented inline in the test so it is not removed later (DSH-01 scenario 2, DSH-04).

### Phase 2: The pre-commit control (before the sweep)

- [ ] 2.1 `scripts/hooks/pre-commit` — insert the secret scan **above** the `composer.json` early exit at `:9-13` (D22; today the hook exits `0` whenever `composer.json` is not staged, so anything appended after that block never runs for a normal commit). Scan **staged files only** with the DSH-01 adjacency rule; on a match, print the offending file and line and `exit 1`. An inline `# nosecret` marker opts a line out (DSH-03).
- [ ] 2.2 **[TEST] New `tests/Core/PreCommitSecretScanTest.php`** — run the hook against a staged fixture containing `logkey=<64 hex>` and assert a non-zero exit with the file and line reported; assert the `# nosecret` marker suppresses the finding; assert the scan still executes for a commit that stages only `composer.json` (the early-exit scenario) and does not block a clean commit. Drive the hook with a temporary `GIT_INDEX_FILE` so the test does not touch the developer's real index. **Documented manual fallback** if the temp-index harness proves brittle: `printf 'logkey=%064d\n' 0 > /tmp/fixture && git add /tmp/fixture`-style staging, then `bash scripts/hooks/pre-commit; echo $?` → non-zero. The fallback and its result must be recorded in the PR body, not silently skipped.

### Phase 3: The redaction sweep

- [ ] 3.1 Redaction sweep over the served planning tree. Verified scope: the credential-bearing line is **exactly one** — `openspec/changes/ventas-clientes-controller-dedup/verify-report.md:62`. Replace `logkey`, `auth_sig`, the session identifier and the CSRF-token prefix with `<redacted>` while preserving the request shape, the code path and the conclusion (DSH-01 "the narrative keeps its evidentiary value"). Reference style: `openspec/changes/ventas-clientes-dispatch-regression-test/verify-report.md:64`.
- [ ] 3.2 Sweep the second document that still prints a live-looking session identifier: `openspec/changes/ventas-clientes-dispatch-regression-test/verify-report.md` (it redacts the key but still prints `FSSESS_a8512aa711f7=vfya2a1f4634c8e26f1`). **Leave prose placeholders alone** — `FSSESS_` occurs 75 times across 11 planning files, mostly as `FSSESS_xxx` in spec prose; only literal values are redacted (DSH-01 scenario 3).
- [ ] 3.3 `tests/bootstrap.php` and any other legitimate 64-hex value are **not** touched.
  - Verify: `ddev exec php vendor/bin/phpunit tests/Security/ServedTreeSecretScanTest.php` now green.

### Phase 4: The edge deny layer (TDD)

- [ ] 4.1 **[TEST] [RED] New `tests/Core/NginxDenyRulesTest.php`** (design Testing Strategy row 16) — parse `nginx-sample.conf`; assert a `^~` deny location exists for each of `openspec/ docs/ tools/ extras/ odd/ dev-tools/ managed_context/ test_suite_analysis/`; assert **no** deny location for `themes/`; assert the pre-existing exact-file denies (`config.php`, `cron.php`, `debug_loader.php`, `visual_flow_demo.php`, `emergency_admin_reset.php`, `reset_admin_password.php`) and the existing prefix denies are unchanged. Text-level only — `nginx -t` and a live request are owed by the operator (DSH-02, DSH-05).
- [ ] 4.2 `nginx-sample.conf` — add the eight `^~` deny locations, grouped with the existing sensitive-directory block at `:38-48`. `^~` prefix locations beat the regex `location ~ \.php$` handler and are matched on the normalised, decoded URI, which is why they close the class rather than one document. **`themes/` is deliberately NOT added** — it holds browser-fetched CSS/JS.
  - Verify: `ddev exec php vendor/bin/phpunit tests/Core/NginxDenyRulesTest.php` green.

### PR 5 verification

- [ ] `ddev exec php vendor/bin/phpunit --testsuite Security`
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Core`
- [ ] `ddev exec php vendor/bin/phpunit` — full suite, `Failures: 12`, all 12 in `plugins/OidcProvider`
- [ ] `ddev exec composer phpstan` — no new findings (Markdown/config changes are outside this gate by construction)
- [ ] PR body marks VULN-0005 **open** pending the operator handoff, with the retest recipe inline

---

## PR 6 — W6: Session transport hardening (VULN-0007, STH-01…STH-06, SC-01)

**Est. changed lines**: ~230 (source ~65, tests ~165)
**Depends on**: **PR 1 must already be merged** — both edit `src/Core/StealthMode.php` (PR 1: `isHiddenLoginPageRequest`/`isHiddenLoginSubmission`; PR 6: `ensurePhpSessionStarted`). Different methods, same file: sequence them, never open two parallel branches.
**Order inside the workstream is load-bearing: pin the cause first, then change the emission path** (design D4/D5). U6.1's test must exist before U6.3 touches `ensurePhpSessionStarted()`.
**Rollback boundary**: revert `ensurePhpSessionStarted()` to `session_name()` + `session_start()` + the defensive `setcookie()`, revert `SecurityHeaders` to no HSTS, and revert the two bare `session_start()` sites. `sessionCookieParams()` may remain as an internal helper. **Caveat**: an HSTS `max-age` already served to a browser is not undone by a code revert — set `FS_HSTS_MAX_AGE=0` on dev hosts before deploying.

### Work Units (one reviewable commit each)

| Unit | Goal | Est. lines | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| U6.1 | **[TEST]** `tests/Security/SessionCookieParamsTest.php` (RED) | ~55 | `ddev exec php vendor/bin/phpunit tests/Security/SessionCookieParamsTest.php` | N/A — input seam only | Revert test file |
| U6.2 | `SessionManager::sessionCookieParams()` + `resolveCookiePath()` public | ~30 | same as U6.1 | `ddev exec php vendor/bin/phpunit tests/Security/SessionManagerTest.php` | Revert both; no consumer yet at this commit |
| U6.3 | **[TEST]** `StealthModeTest` separate-process cookie-params/strict-mode case (RED) then `ensurePhpSessionStarted()` | ~45 | `ddev exec php vendor/bin/phpunit tests/Components/StealthModeTest.php` | `ddev exec php vendor/bin/phpunit tests/Integration/StealthSessionBootstrapTest.php` | Restore `session_start()` + the defensive `setcookie()` |
| U6.4 | `base/fs_session_manager.php` legacy path consumes the shared params | ~10 | `ddev exec php vendor/bin/phpunit tests/Security/SessionLegacyPhpSessionMigrationTest.php` | `ddev exec php vendor/bin/phpunit tests/Security/SessionNamePerInstallTest.php` | Restore the local attribute set |
| U6.5 | **[TEST]** HSTS predicate assertions (RED) then `SecurityHeaders` HSTS | ~50 | `ddev exec php vendor/bin/phpunit tests/Security/SecurityHeadersTest.php` | operator: live `curl -D -` probe | Remove the HSTS emission; `FS_HSTS_MAX_AGE=0` is the runtime escape |
| U6.6 | **[TEST]** the two bare `session_start()` sites (STH-05, SC-01) | ~40 | `ddev exec php vendor/bin/phpunit tests/Controller/AdminAgentesSessionBootstrapTest.php tests/Security/CsrfManagerTest.php` | N/A | Revert the two one-line changes |
| U6.7 | *(OPTIONAL — see the decision below)* narrow `applyTransportHeaders()` for `api.php`/`cron.php` | ~25 | `ddev exec php vendor/bin/phpunit tests/Security/SecurityHeadersTest.php` | operator: probe `api.php` | Delete the helper and its two call sites |

**Decision owed before U6.7** (design Open Question 2, spec STH-04 `SHOULD`): whether to ship the narrow HSTS-only `applyTransportHeaders()` called from `api.php` and `cron.php`. Recommended: **include it** — it is ~25 lines, independently revertible, and closing the coverage boundary is cheap. If dropped, HSTS coverage **must** be reported as `index.php`-only in the PR body and the verification artifacts, per STH-04's "coverage boundary is stated" scenario. This decision does not block PRs 1–5.

### Phase 1: Pin the cause, then the shared definition (TDD)

- [ ] 1.1 **[TEST] [RED] New `tests/Security/SessionCookieParamsTest.php`** (design Testing Strategy row 14) — the discriminating assertion the CLI harness **can** make. Cases: the parameter set is exactly the six keys (`lifetime` = `SessionPolicy::getIdleTimeout()`, `path` = `SessionManager::resolveCookiePath()`, `domain` = `''`, `secure` = `SecureRequestDetector::isSecure()`, `httponly` = `true`, `samesite` = `'Lax'`); `secure` is `true` behind a trusted proxy terminating TLS with `X-Forwarded-Proto: https` and `false` without a trusted proxy; `path` equals `resolveCookiePath()` for a subdirectory installation; the three consumers obtain their parameters from this single definition and none hardcodes `path`/`secure`/`httponly`/`samesite` (STH-01 scenarios 1–5).
  - Note: `tests/Security/SecureRequestDetectorTest.php` already pins `isSecure()` for both proxy cases (`:44,52,60,73`), so the detector is **not** the hypothesis — the ordering is (D4). Extend it only if a gap appears.
- [ ] 1.2 `src/Security/SessionManager.php` — promote `resolveCookiePath()` from `private static` to `public static` (precedent: `resolveSessionName()`); add `public static function sessionCookieParams(): array` returning the six keys; build `initialize()`'s `NativeSessionStorage` `$options` from it (`name`, `gc_maxlifetime`, `use_strict_mode` stay local). **Do not touch the `resolveSessionName()` name-match gate at `:109`** (STH-06 scenario 3).
  - Verify: `ddev exec php vendor/bin/phpunit tests/Security/SessionCookieParamsTest.php tests/Security/SessionManagerTest.php` green.

### Phase 2: Parameters before the start; duplicate emission removed (TDD)

- [ ] 2.1 **[TEST] [RED] Extend `tests/Components/StealthModeTest.php`** with a `#[RunInSeparateProcess]` case forcing `PHP_SESSION_NONE` and asserting, **after** `ensurePhpSessionStarted()`: `session_get_cookie_params()` carries the shared attribute set (`httponly` true, `samesite` `Lax`, `path` from the resolver), `session_name()` is `SessionManager::resolveSessionName()`, and `ini_get('session.use_strict_mode')` is `'1'` — the same pattern the archived change used for name-before-start (STH-02 scenario 1, STH-03 scenario 1). The assertion is on the **parameters supplied to the session**; the CLI SAPI cannot observe `Set-Cookie`.
- [ ] 2.2 `src/Core/StealthMode.php::ensurePhpSessionStarted()` — before `session_start()`: `ini_set('session.use_strict_mode', '1')` and `session_set_cookie_params(SessionManager::sessionCookieParams())`. **Delete** the defensive `setcookie()` at `:494-499` (D5) — do not replace it with an attribute-identical duplicate. The `session_name(SessionManager::resolveSessionName())` call added by the archived change stays untouched. Document the behaviour change in the PR body: a client-supplied session id that was never initialized server-side is now discarded instead of adopted (the report's own recommendation 8, fail-safe direction).
  - Verify: `ddev exec php vendor/bin/phpunit tests/Components/StealthModeTest.php` green; `SessionFixationPreventionTest`, `SessionNamePerInstallTest`, `SessionLegacyPhpSessionMigrationTest`, `SessionManagerTest`, `StealthSessionBootstrapTest` all green.
- [ ] 2.3 `base/fs_session_manager.php::initializeLegacySession()` — `session_set_cookie_params(SessionManager::sessionCookieParams())` (behaviour identical today; two copies become one).
  - Verify: `ddev exec php vendor/bin/phpunit tests/Security/SessionLegacyPhpSessionMigrationTest.php tests/Security/SessionNamePerInstallTest.php` green.

### Phase 3: HSTS (TDD)

- [ ] 3.1 **[TEST] [RED] Extend `tests/Security/SecurityHeadersTest.php`** (currently CSP-only) — assert the HSTS **decision** on the predicate (`FS_HSTS_MAX_AGE` + `SecureRequestDetector::isSecure()`), since the header itself is not observable in CLI: secure + default → `max-age=31536000`; `FS_HSTS_MAX_AGE=0` → no value produced; insecure → no value produced; no `includeSubDomains` by default, present only when explicitly opted in (STH-04 scenarios 1–4).
- [ ] 3.2 `src/Security/SecurityHeaders.php::applyDefaultHeaders()` — emit `Strict-Transport-Security: max-age=<FS_HSTS_MAX_AGE>` when `SecureRequestDetector::isSecure()`; default `31536000`; `0` disables entirely; `includeSubDomains` off unless opted in (D6). `applyDefaultHeaders()` is called only from `index.php:134`, **before** the gate (`:141`), so HSTS is emitted even on a gated `302`.
- [ ] 3.3 *(optional, U6.7)* narrow `applyTransportHeaders()` emitting HSTS alone, called from `api.php` and `cron.php`. Calling the full `applyDefaultHeaders()` there is rejected — it would add CSP, `nosniff` and `X-Frame-Options` to the API surface.
  - Verify: `ddev exec php vendor/bin/phpunit tests/Security/SecurityHeadersTest.php` green.

### Phase 4: Close the two bare session starts (STH-05, SC-01)

- [ ] 4.1 **[TEST] [RED] New `tests/Controller/AdminAgentesSessionBootstrapTest.php`** and an extension of `tests/Security/CsrfManagerTest.php`, both `#[RunInSeparateProcess]` with no active session: after the duplicated-petition guard in `controller/admin_agentes.php:271` starts the session, `session_name()` is `SessionManager::resolveSessionName()` and the name was set before `session_start()`; the same for `src/Security/CsrfManager.php:133`'s `ensureSession()` fallback branch (SC-01 scenarios 2 and 3, STH-05 scenarios 1 and 2).
- [ ] 4.2 `controller/admin_agentes.php:271` and `src/Security/CsrfManager.php:133` — set the shared session name before `session_start()` (or delegate to the shared session accessor). Both currently start a session with the **default** name, which emits a `PHPSESSID` cookie and re-opens the dual-cookie regression `consolidate-session-csrf` closed. `base/install_security.php` remains the sole deliberate exception with its own `FSINSTALL_` prefix — do not touch it (SC-01 scenario 4).
  - Verify: `ddev exec php vendor/bin/phpunit --testsuite Security --testsuite Modern` green; SC-01…SC-05 scenarios stay green (STH-06 scenario 2).

### PR 6 verification

- [ ] `ddev exec php vendor/bin/phpunit --testsuite Security`
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Modern`
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Integration`
- [ ] `ddev exec php vendor/bin/phpunit` — full suite, `Failures: 12`, all 12 in `plugins/OidcProvider`
- [ ] `ddev exec composer phpstan` — no new findings
- [ ] PR body states the HSTS coverage boundary (`index.php`-only, or all three entry points if U6.7 ships) and the `FS_HSTS_MAX_AGE=0` escape
- [ ] PR body records the two live probes as **owed by the operator** (O.3, O.4), not as passed

---

## Operator Handoff (NOT code units — do not mark as completed work)

These are success criteria, not implementation tasks. They are not achievable in this repository and **none is reverted by a code revert** (a rotated key stays rotated, which is the desired direction). Until **O.1 and O.2** are confirmed, **VULN-0005 MUST be reported OPEN**, not closed — the proposal's risk 6, severity High: merging the redaction and the deny rules without them leaves the finding open while looking fixed.

| # | Action | Owner | Retest / verification | Closes |
|---|--------|-------|-----------------------|--------|
| O.1 | Apply the extended `nginx-sample.conf` deny rules on the live server and reload nginx | Operator | `GET /openspec/changes/ventas-clientes-controller-dedup/verify-report.md` → `403`; `GET /config.php` → `404`; `nginx -t` exits `0` | VULN-0005 (half) |
| O.2 | **Rotate the `admin` account's `log_key`** — the published `auth_sig` is burned and is already in git history and the public upstream repository | Operator | The published triple no longer appears in any served document; the account's key differs from the published value | VULN-0005 (half — the only step that actually closes it) |
| O.3 | Live `Set-Cookie` probe on a secure response | Operator | `curl -D - https://<host>/index.php?page=login` → exactly **one** `Set-Cookie` for the session cookie, carrying `Secure`, `HttpOnly` and `SameSite=Lax` | STH-02 / VULN-0007 |
| O.4 | Live HSTS probe | Operator | `curl -D -` → `Strict-Transport-Security: max-age=31536000`, no `includeSubDomains`; with `FS_HSTS_MAX_AGE=0` → header absent | STH-04 / VULN-0007 |
| O.5 | Live remember-me failure probe | Operator | Anonymous `GET /index.php?page=login` with `user=<real nick>` + bogus `logkey`/`auth_sig` → `Set-Cookie` deletes the caller's own `user`, `logkey` and `auth_sig`; the named account's `fs_users.log_key` is unchanged (checked in the DB) | RMR-02 / VULN-0008 |
| O.6 | Re-run the strix scan recipe (FIX-PLAN §6) | Operator | Reports the six core findings closed and no new finding in the touched surfaces | Whole change |

**Deployment note.** Set `FS_HSTS_MAX_AGE=0` on development hosts **before** deploying PR 6. An HSTS `max-age` already served to a browser is not undone by a code revert.

---

## Verification Matrix

| Requirement | Workstream | Tests | Tasks |
|-------------|-----------|-------|-------|
| AAG-01 — exemption depends on the requested page | W1 | `PublicAccessGateTest` (bypass regressions) | 1.1, 5.1, 5.2 |
| AAG-02 — one canonical requested-page rule | W1 | `FsAuthRequestTest` | 2.1, 2.2 |
| AAG-03 — non-string `page` never fatal | W1 | `FsAuthRequestTest` (array/null cases) + `PublicAccessGateTest` `page[]` case | 2.1, 2.2, 5.1 |
| AAG-04 — one shared login-submission predicate | W1 | `FsAuthRequestTest`, `StealthModeTest`, `FsMaintenanceModeTest` | 2.1–2.2, 3.1–3.2, 4.1 |
| AAG-05 — gate and dispatcher agree on the named page | W1 | `PublicAccessGateTest` (favicon/`page=` and prefix cases) | 5.1–5.3 |
| LC-06 — authoritative verdict, single evaluation | W2 | `LoginPublicCoreCsrfTest`, `FsControllerCsrfBlockingTest` | 1.1–1.3, 2.1 |
| LC-07 — modern-controller credential CSRF guard | W2 | `ModernControllerCredentialCsrfTest` | 3.1–3.2 |
| RMR-01 — only an authenticated session names the account | W3 | `LegacyAuthBridgeTest`, `AnonymousLogoutRevocationTest` | 1.1–1.4 |
| RMR-02 — a failed verification mutates nothing but the caller | W3 | `AnonymousLogoutRevocationTest`, `FsLoginCookieAuthTest` | 1.3–1.4 |
| RMR-03 — audit attribution from the session | W3 | `AnonymousLogoutRevocationTest`, `FsLoginCharacterizationTest` | 1.3–1.4, 2.1 |
| RMR-04 — no per-account value to an anonymous requester | W3 | `AnonymousLogoutRevocationTest`, `FsLoginCharacterizationTest` | 1.4, 2.1 |
| RMR-05 — login error/message blocks escaped | W3 | `LoginTemplateEscapingTest` | 2.2–2.3 |
| RMR-06 — no public signature changes | W3 | the 20-reference regression set (full suite) | PR 3 verification |
| LTI-01 — one owner per lockout bucket | W4 | `FsIpFilterTest`, `PasswordResetThrottleIsolationTest` | 1.1–1.2, 2.1–2.2 |
| LTI-02 — namespace sanitisation cannot escape `tmp/` | W4 | `FsIpFilterTest` | 1.1–1.2 |
| LTI-03 — validation precedes counting | W4 | `PasswordResetThrottleIsolationTest` | 2.1–2.2 |
| LTI-04 — the reset flow requires a CSRF token | W4 | `PasswordResetThrottleIsolationTest` | 2.1–2.2 |
| LTI-05 — the reset flow keeps its own throttle | W4 | `PasswordResetThrottleIsolationTest` | 2.1–2.2 |
| DSH-01 — credential material described, never reproduced | W5 | `ServedTreeSecretScanTest` | 1.1, 3.1–3.3 |
| DSH-02 — deny-by-default covers the non-runtime surface | W5 | `NginxDenyRulesTest` | 4.1–4.2 |
| DSH-03 — pre-commit scan rejects a staged credential | W5 | `PreCommitSecretScanTest` (+ documented manual fallback) | 2.1–2.2 |
| DSH-04 — redaction invariant enforced by a suite scan | W5 | `ServedTreeSecretScanTest` | 1.1 |
| DSH-05 — operator handoff is an unverified gate | W5 | none (recorded, not asserted) | O.1, O.2, O.6 |
| STH-01 — one definition of the cookie attribute set | W6 | `SessionCookieParamsTest` | 1.1–1.2 |
| STH-02 — parameters before start; exactly one emission | W6 | `StealthModeTest` (separate process, input seam) + O.3 | 2.1–2.2 |
| STH-03 — strict session mode at the outermost entry point | W6 | `StealthModeTest`, `SessionFixationPreventionTest`, `SessionNamePerInstallTest` | 2.1–2.2 |
| STH-04 — HSTS on secure responses | W6 | `SecurityHeadersTest` (predicate) + O.4 | 3.1–3.3 |
| STH-05 — no bare session start outside the installer | W6 | `AdminAgentesSessionBootstrapTest`, `CsrfManagerTest` | 4.1–4.2 |
| STH-06 — W-4 closed without re-opening SC-05 | W6 | `SessionManagerTest`, `SessionNamePerInstallTest`, `StealthSessionBootstrapTest` | 2.1–2.2, 4.1–4.2 |
| SC-01 — shared session name before `session_start()`, no residual deviation | W6 | `AdminAgentesSessionBootstrapTest`, `CsrfManagerTest`, `SessionNamePerInstallTest` | 4.1–4.2 |

---

## File Change Summary

| File | Action | Est. lines | PR | Tasks |
|------|--------|-----------|----|-------|
| `base/fs_auth_request.php` | **Add** (new static class) | 35 | 1 | 2.2 |
| `src/Core/StealthMode.php` | Modify (predicates; cookie params + strict mode; delete defensive `setcookie()`) | 20 | 1, 6 | 3.2, 2.2 |
| `src/Core/PublicAccessGate.php` | Modify (page-based exemption; narrowed public-path exemption) | 5 | 1 | 5.2 |
| `src/Core/Plugins.php` | Modify (extract exact-path predicate; probe guard) | 8 | 1 | 5.3 |
| `base/fs_maintenance_mode.php` | Modify (delegate predicate; delete local copy) | 5 | 1 | 4.1 |
| `phpstan.neon` | Modify (`scanFiles` + `paths` entry) | 2 | 1 | 2.3 |
| `base/fs_controller.php` | Modify (`requestHasCredentials` delegates; flag; accessor) | 15 | 2 | 1.2 |
| `controller/login.php` | Modify (`shouldSkipCredentialLogin()` guard) | 10 | 2 | 1.3 |
| `src/Core/Base/Controller.php` | Modify (credential CSRF guard, W2b) | 10 | 2 | 3.2 |
| `src/Security/LegacyAuthBridge.php` | Modify (delete cookie fallback; widen visibility for tests) | 15 | 3 | 1.2 |
| `base/fs_login.php` | Modify (delete duplicate revocation; neutral notice; `log_out(TRUE)`; audit attribution) | 30 | 3 | 1.4 |
| `themes/AdminLTE/view/login/default.html.twig` | Modify (remove `\|raw` at `:55`, `:63`) | 2 | 3 | 2.3 |
| `base/fs_ip_filter.php` | Modify (optional namespace) | 25 | 4 | 1.2 |
| `controller/password_reset.php` | Modify (namespaced filter; ordering; `requireCsrf()`) | 20 | 4 | 2.2 |
| `scripts/hooks/pre-commit` | Modify (secret scan above the early exit) | 40 | 5 | 2.1 |
| `openspec/changes/ventas-clientes-controller-dedup/verify-report.md` | Modify (redact `:62`) | 3 | 5 | 3.1 |
| `openspec/changes/ventas-clientes-dispatch-regression-test/verify-report.md` | Modify (redact the session identifier) | 2 | 5 | 3.2 |
| `nginx-sample.conf` | Modify (eight `^~` deny locations) | 20 | 5 | 4.2 |
| `src/Security/SessionManager.php` | Modify (`resolveCookiePath()` public; `sessionCookieParams()`) | 30 | 6 | 1.2 |
| `base/fs_session_manager.php` | Modify (consume the shared params) | 10 | 6 | 2.3 |
| `src/Security/SecurityHeaders.php` | Modify (HSTS) | 20 | 6 | 3.2 |
| `controller/admin_agentes.php` | Modify (shared session name before start) | 3 | 6 | 4.2 |
| `src/Security/CsrfManager.php` | Modify (shared session name before start) | 3 | 6 | 4.2 |
| `api.php`, `cron.php` | Modify *(optional, U6.7)* | 6 | 6 | 3.3 |
| `tests/Base/FsAuthRequestTest.php` | **Add** | 45 | 1 | 2.1 |
| `tests/Components/PublicAccessGateTest.php` | Modify (corrective + regressions) | 30 | 1 | 1.1, 5.1 |
| `tests/Components/StealthModeTest.php` | Modify (predicates; separate-process cookie/strict-mode case) | 25 | 1, 6 | 3.1, 2.1 |
| `tests/Components/LoginTemplateEscapingTest.php` | **Add** | 25 | 3 | 2.2 |
| `tests/Controller/LoginPublicCoreCsrfTest.php` | **Add** | 70 | 2 | 1.1 |
| `tests/Controller/AdminAgentesSessionBootstrapTest.php` | **Add** | 25 | 6 | 4.1 |
| `tests/Security/FsControllerCsrfBlockingTest.php` | Modify (exactly-one-attempt) | 45 | 2 | 2.1 |
| `tests/Security/ModernControllerCredentialCsrfTest.php` | **Add** | 35 | 2 | 3.1 |
| `tests/Security/LegacyAuthBridgeTest.php` | Modify (resolver cases) | 35 | 3 | 1.1 |
| `tests/Security/AnonymousLogoutRevocationTest.php` | **Add** | 65 | 3 | 1.3 |
| `tests/Security/FsLoginCookieAuthTest.php` | Modify (audit + notice) | 20 | 3 | 2.1 |
| `tests/Base/FsLoginCharacterizationTest.php` | Modify (notice + no `last_ip`) | 20 | 3 | 2.1 |
| `tests/Base/FsIpFilterTest.php` | Modify (namespace cases) | 45 | 4 | 1.1 |
| `tests/Security/PasswordResetThrottleIsolationTest.php` | **Add** | 55 | 4 | 2.1 |
| `tests/Security/ServedTreeSecretScanTest.php` | **Add** | 50 | 5 | 1.1 |
| `tests/Core/PreCommitSecretScanTest.php` | **Add** | 35 | 5 | 2.2 |
| `tests/Core/NginxDenyRulesTest.php` | **Add** | 40 | 5 | 4.1 |
| `tests/Security/SessionCookieParamsTest.php` | **Add** | 55 | 6 | 1.1 |
| `tests/Security/SecurityHeadersTest.php` | Modify (HSTS predicate) | 30 | 6 | 3.1 |
| `tests/Security/CsrfManagerTest.php` | Modify (shared session name) | 15 | 6 | 4.1 |

---

## Out of Scope (explicit — do not implement here)

- **VULN-0001 and VULN-0004.** Both live entirely in `plugins/system_updater/` (`recovery.php`; `lib/session_auth.php::system_updater_is_logged_in()`). `/plugins/*` is gitignored and each plugin is its own repository. Their SDD belongs in `plugins/system_updater/openspec/changes/{name}/`, committed there. **No file under `plugins/` is touched by this change** — including the isolated plugin suites, which are run for regression but never edited.
- **Post-authentication object-level authorization** across customer, sales, catalogue and company controllers. Untested because no credentials were available — a proof gap, not a clean result. Needs a follow-up authenticated scan with one low-privilege and one administrator account.
- **The other proof gaps from the same report**: session-id rotation on a successful login, server-side session invalidation on logout, and user/timing enumeration. PRs 2 and 6 touch those surfaces, but neither finding is closed by evidence, so neither is claimed.
- **Design D18 — deferred with a named owner.** `CsrfManager::$tokenVerified` intra-request invalidation and the non-discriminating `tests/Integration/CsrfTokenSurvivesMigrationTest`. Owner: a `session-integrity` follow-up change (SI-01). No finding maps to either; the archived verify run's own verdict is "latent; does not bite today".
- **Design D19 — deferred with a named owner.** The third page-canonicalisation copy in `fs_maintenance_mode::isBackendPageRequest()` (`base/fs_maintenance_mode.php:542-553`). Owner: a maintenance-mode follow-up. It diverges (`page=ADMIN_HOME` is a backend page to maintenance mode and an invalid page to `index.php`) but no finding attaches to it, and adopting the strict rule would change which pages skip maintenance mode.
- **`session-integrity` SI-02/SI-03 are not amended** (design D20). `SI-02` must keep admitting the password-reset flow, which legitimately rotates `log_key` with no authenticated session; the rotation-target invariant lives in the new `remember-me-revocation-authority` capability.
- **No git-history rewrite.** Redaction is hygiene; rotation (O.2) is the closure. The value is in the public upstream repository.
- **No document-root relocation.** The correct long-term fix, explicitly deferred; PR 5 ships the deny rules that make the current layout safe.
- **No general authentication redesign**: no centralised middleware for every entry point, no standalone plugin-script auth, no CSP `unsafe-inline` reduction.
- **No new PHPStan baseline, no `ignoreErrors` growth.** Findings are closed by correcting the contract or the code. Note for verification: `composer phpstan` analyses `src` + `tests` (+ `scanFiles`), so `base/fs_controller.php`, `base/fs_ip_filter.php`, `base/fs_login.php`, `controller/login.php` and `controller/password_reset.php` are **not** covered by that gate — the PHPUnit suite is their gate. `phpstan:plugins` is unaffected (no plugin file changes).

---

## Known Baseline & False Alarms

| Signal | Correct reading |
|---|---|
| Full suite `Failures: 12` | **Pre-existing.** All 12 are in `plugins/OidcProvider` — a separate repository with DB-fixture drift. Not caused by this change; not to be fixed here. |
| Full suite `Skipped: 27` | Pre-existing. |
| Security suite | Green baseline: `Tests: 269, Assertions: 601, Failures: 0, Skipped: 1`. Any Security failure is new. |
| Lockout now needs 6 failed submissions instead of 3 | **Intended** (PR 2). The throttle was incremented twice per submission; it is now incremented once. Documented in the PR body so it is not read as a regression. |
| A previously-adopted uninitialized session id is now discarded | **Intended** (PR 6, `use_strict_mode=1`). The report's own recommendation; fail-safe direction. |
| HSTS on `panel-ab.ddev.site` | **Intended** (PR 6). Set `FS_HSTS_MAX_AGE=0` before deploying to a dev host; a served `max-age` is not undone by a code revert. |
| `GET /index.php?page=LOGIN` and `?page=login%20` now return `302` | **Intended and fail-closed** (PR 1). The redirect still lands on the real login page. |
| `tests/bootstrap.php:62`'s deterministic 64-hex `FS_SECRET_KEY` | **Not a finding.** It is not adjacent to `logkey`/`auth_sig`/`password`/`token`, so the adjacency rule does not match it. The rule must not be "simplified" into a bare 64-hex pattern. |
| `FSSESS_xxx` occurrences in planning prose | **Not a finding.** Prose placeholders; only literal values are redacted. |
| Header-level acceptance criteria appear "untested" | **Expected.** The CLI SAPI cannot observe `Set-Cookie`/`header()`. Those criteria are the operator probes O.3–O.5, recorded as unverified gates rather than passed ones. |

---

## Dependency Graph

```
PR 1  W1 gate (VULN-0002)                 PR 2  W2 CSRF (VULN-0003)
────────────────────────────              ─────────────────────────────
1.1 [TEST] corrective test  ──┐           1.1 [TEST] LoginPublicCoreCsrf ──┐
                              │                                          │
2.1 [TEST] fs_auth_request ───┤           1.2 fs_controller flag ──────────┤
  └─► 2.2 fs_auth_request ────┤             └─► 1.3 login guard ──────────┤
        └─► 2.3 phpstan       │                                          │
                              │           2.1 [TEST] exactly-one-attempt ─┤
3.1 [TEST] StealthMode ───────┤                                          │
  └─► 3.2 StealthMode impl ───┤           3.1 [TEST] ModernController ────┤
                              │             └─► 3.2 Base/Controller ─────┘
4.1 fs_maintenance_mode ──────┤
                              │           (PR 2 needs PR 1's
5.1 [TEST] gate regressions ──┤            fs_auth_request::hasCredentialSubmission)
  └─► 5.2 PublicAccessGate ───┤
        └─► 5.3 Plugins ──────┘
                              │
                              ├──────────► PR 4  W4 throttle (VULN-0006)
                              │            (live retest only; code independent)
                              │
                              ├──────────► PR 6  W6 transport (VULN-0007)
                              │            MUST follow PR 1: shared StealthMode.php
                              │
PR 3  W3 revocation (VULN-0008) ──► independent
PR 5  W5 surface   (VULN-0005) ──► independent (operator handoff gates closure)
```

Merge order: **1 → 2 → 3 → 4 → 5 → 6**. No slice requires another slice's code to compile; the only hard orderings are the shared-file rule (PR 1 before PR 6) and PR 4's retest gate (after PR 1).
