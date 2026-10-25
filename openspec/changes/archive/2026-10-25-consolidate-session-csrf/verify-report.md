# Verify Report: `consolidate-session-csrf`

- **Change**: `consolidate-session-csrf` (CORE FSFramework change)
- **Artifact store**: openspec
- **Phase**: verify (independent)
- **Verifier**: SDD executor — verification performed by reading the implementation and running
  the commands directly. `tasks.md` checkmarks were treated as claims, not evidence. No
  implementation file, delta, canonical spec or `tasks.md` was modified.
- **Date**: 2026-10-25

---

## Re-verification (2026-10-25) — SUPERSEDES the original verdict below

- **Trigger**: after the first run, a spec phase rewrote the three delta files. `git status`
  shows **exactly three modified files** — `specs/session-consolidation/spec.md`,
  `specs/session-integrity/spec.md`, `specs/login-csrf/spec.md` — and **no change to any
  implementation file, test, canonical spec, `tasks.md`, `proposal.md` or `design.md`**.
- **Method**: every previously-failing clause was re-read against the code at `HEAD` (`507b1840`).
  The `Security` suite was re-run. The full-suite numbers are **carried over, not re-measured**,
  because no implementation or test byte changed, so that baseline cannot have moved.
- **Supersedes**: the `DO NOT ARCHIVE AS-IS` verdict of the first run.

### What changed in the deltas

| Delta | Change |
|---|---|
| `session-consolidation/spec.md` | SC-04 inverted → discard semantics; the `CSRF token survives session migration` scenario and the `Concurrent requests during migration` edge case replaced by `CSRF token is unaffected by a stale legacy cookie` and `Concurrent requests carrying a stale legacy cookie`; a `Security rationale (do not revert)` block added; SC-01 reworded + `### Known deviation` section; SC-05 tightened; `FSSESS_xxxx` → `FSSESS_xxx`. |
| `session-integrity/spec.md` | SI-01 rewritten: the observable guarantee is kept, the invented "session bag" mechanism is replaced by the real `NativeSessionCsrfStorage` / `$_SESSION['_csrf']` mechanism; `getToken()`-not-`refreshToken()` and the per-request cache are now stated explicitly. |
| `login-csrf/spec.md` | LC-02 amended: the bag clause is replaced with the real reason; `after migration` → `after the legacy cookie is discarded (SC-04)`; scenario 2 rewritten to the discard model; scenario 3's stale cross-ref dropped; a Spanish/English merge note added. |

### Per-clause resolution table

| # | Previous finding | Class | Re-verified against code | Resolution |
|---|---|---|---|---|
| **C-1** | SC-04 demanded copying foreign `PHPSESSID` session data — contradicted by the shipped `discardLegacyPhpSession()` | CRITICAL | `src/Security/SessionManager.php:100-106` (guard), `:215-220` (`discardLegacyPhpSession()` = `clearLegacyPhpSessionCookie()` + `unset($_COOKIE['PHPSESSID'])`, session never opened), `:201-214` (docblock rationale) | **RESOLVED.** New SC-04 states exactly the shipped behaviour: *"MUST NOT read, open or copy that foreign session's data; … MUST discard the cookie and run on its own `FSSESS_xxx` session."* The security rationale is preserved and hardened with an explicit "MUST NOT be reintroduced". Both migration-dependent scenarios are gone; their replacements match the discard model. |
| **W-1** | SI-01 asserted a "session bag" mechanism that does not exist | WARNING | `src/Security/CsrfManager.php:92-118` (guard calls `getToken()`, caches in `$tokenVerified`), `:370-415` (`NativeSessionCsrfStorage` reads/writes `$_SESSION['_csrf']` directly — no Symfony bag) | **RESOLVED.** The bag is gone; the requirement describes the real mechanism, including "the guard never calls `refreshToken()`" and the per-request cache. Every clause is literally true. |
| **W-2** | LC-02 carried two false clauses and a dangling SC-04 cross-reference | WARNING | `base/fs_controller.php:399-440` (`validateCsrf()`) → `CsrfManager::isValid()` (`:162-166`); `controller/login.php` has no independent CSRF path | **RESOLVED.** All three amendments landed. Clause 3 now reads "…after the legacy cookie is discarded (SC-04)" and SC-04 is no longer inverted, so the cross-ref resolves. Scenarios 2 and 3 match the discard model. |
| **W-3** | SC-01 wording wrong + two undocumented bare `session_start()` sites | WARNING | grep of every non-vendor `session_start()`: **bare** at `controller/admin_agentes.php:271` and `src/Security/CsrfManager.php:133`; **named** at `src/Core/StealthMode.php:474-477`, `src/Security/CsrfManager.php:403-407`, `base/fs_session_manager.php:126-129`, `base/fs_maintenance_mode.php:603-609`; **installer** at `base/install_security.php:28-37` (`FSINSTALL_` prefix, `base/fs_session_identity.php:51-57`) | **RESOLVED as a spec defect** (residual R-1). SC-01 is reworded to "a session name derived from the shared session-identity resolver", the installer carve-out is stated, and a `### Known deviation` section names exactly the two bare sites. The deviation list is **complete and accurate** — verified there are exactly two. |
| **S-3** | `FSSESS_xxxx` typo | SUGGESTION | grep over the deltas | **RESOLVED.** No occurrence remains. |
| **S-1** | English delta vs Spanish canonical spec | SUGGESTION | `specs/login-csrf/spec.md:3-8` | **RESOLVED as a decision.** The merge note converts the drift into a deliberate, documented choice. |

### New problems introduced by the corrections

| # | Finding | Class | Blocking? |
|---|---|---|---|
| **N-1** | `proposal.md`, `design.md` and `tasks.md` still describe the **removed** migration: `design.md:5,13,14,33,42,55,57,59,66,70,78,82,91` (`migrateLegacyPhpSession()`, `_migrated_from_phpsessid`, "session bag", "data was already copied"), `tasks.md:23,32,34,50,61,67,88,104` (1.2/1.4/1.6/3.2 and the traceability table), `proposal.md:11,13`. They are now **inconsistent with the corrected deltas** and with the code. | WARNING | **No** — these files are not merged into the canonical spec; they are archived as history. But the archive record would be internally contradictory without a note. |
| **R-1** | SC-01 still states a `MUST` over "core bootstrap paths that open the application session" while two such paths (`admin_agentes.php:271`, `CsrfManager.php:133`) do not comply. The deviation is **documented**, not eliminated. | WARNING (residual) | **No** — the spec is now honest about it and names the follow-up; a reader sees the MUST and its named exceptions together. |
| **R-2** | SC-05's "when a legacy cookie was present, the response MUST expire it" is scoped in practice to requests that reach `SessionManager::initialize()` with headers not yet sent (`SessionManager.php:94-106`, `:255-259` early-returns on `headers_sent()`). | WARNING (residual) | **No** — a response that never initializes the session has no session state to protect. Defensible wording, not literally universal. |
| **R-3** | SC-04/SC-05 say "its own `FSSESS_xxx` session", but `FS_SESSION_NAME` can rename it (`base/fs_session_identity.php:104-111`, `:292-300`); the code itself guards on `resolveSessionName() !== 'PHPSESSID'` (`SessionManager.php:104`). | SUGGESTION | **No** — pre-existing looseness shared with SC-02/SC-03; the intent ("the installation's own session, not the foreign one") is unambiguous. |

**No dangling cross-reference remains.** `grep -n "migrat|session bag|FSSESS_xxxx|_migrated_from"
openspec/changes/consolidate-session-csrf/specs/` returns only two intentional sentences — "not
from any migration of session data" (`session-consolidation:58`) and "MUST NOT be reintroduced"
(`session-consolidation:48`). No corrected requirement became untestable: every scenario still
names a GIVEN/WHEN/THEN with an observable assertion.

### Commands run in this re-verification

| Command | Exact result | Status |
|---|---|---|
| `ddev exec php vendor/bin/phpunit --testsuite Security` | `OK, but some tests were skipped! Tests: 269, Assertions: 601, Skipped: 1` — exit `0` | **Re-measured.** Byte-identical to the first run's Security baseline. |
| `ddev exec php vendor/bin/phpunit` | `Tests: 4311, Assertions: 16196, Failures: 12, Skipped: 27` — exit `1` | **CARRIED OVER, not re-measured.** `git status` proves no implementation or test file changed since the first run. The 12 failures are the same `plugins/OidcProvider` DB-fixture failures analysed below. |
| change-scoped subset (6 files) | `OK (54 tests, 132 assertions)` | **CARRIED OVER**, same reason. |

### Out of scope for this change — still open (carried from the first run)

Implementation/test-quality items, untouched by the delta correction and not blockers for
archiving:

- **W-4** — `StealthMode`'s cookie sync (`src/Core/StealthMode.php:494-499`) emits `FSSESS_xxx`
  with a hardcoded `path => '/'` and no `secure`, while `SessionManager` uses `resolveCookiePath()`
  + `SecureRequestDetector::isSecure()` (`:137,143-144`). A same-named cookie with divergent
  attributes on subdirectory/HTTPS installs.
- **W-5** — the E2E deliverable (`tasks.md` 3.3, `proposal.md` 67) does not exist; `scripts-onlogin/`
  was never created. The user-visible guarantee has unit/integration evidence only.
- **W-6** — `tests/Integration/CsrfTokenSurvivesMigrationTest` is non-discriminating: `generateToken()`
  auto-creates, so the assertion holds with or without the guard. SI-01's core claim is not
  protected by a test that can go red.
- **W-7** — `CsrfManager::$tokenVerified` is never invalidated within a request
  (`src/Security/CsrfManager.php:55,111-114`). Latent; does not bite today.
- **W-3 residual** — the two bare `session_start()` sites (`controller/admin_agentes.php:271`,
  `src/Security/CsrfManager.php:133`). The spec now documents them as "a separate hardening
  follow-up"; the code fix is not part of this change.
- **S-2** — stale test counts in `proposal.md:66` / `tasks.md:52`.
- **S-4** — `tasks.md` checkboxes are not trustworthy evidence for this change.

### New verdict

**ARCHIVE-READY — with one recommended archive note.**

The change **may now be archived**. The first run's blocker (C-1) is fully resolved: `SC-04` now
states the shipped, deliberately-hardened discard semantics and preserves the security rationale;
the two scenarios that presupposed a migration are gone and their replacements describe the
discard model. `SI-01` and `LC-02` no longer assert a mechanism the code does not have, and
`SC-01` is reworded to the real invariant with its two residual call sites documented as a named
deviation. All five `session-consolidation` requirements, `SI-01` and `LC-02` now match the code
at `HEAD`. The `Security` suite is green (269/269, exit 0).

**Recommended, non-blocking:** record in `archive-report.md` that `proposal.md`/`design.md`/
`tasks.md` still describe the removed `migrateLegacyPhpSession()` data-copying migration (N-1) and
that the two bare `session_start()` sites (R-1 residual) remain a follow-up. This keeps the
archived record self-consistent without editing the change's historical documents.

---

> ### ⚠️ Everything below this line is the ORIGINAL first-run report, retained verbatim for history.
>
> Its `DO NOT ARCHIVE AS-IS` verdict is **superseded** by the re-verification above. The findings
> below are kept so the reasoning and the evidence trail stay legible; the W-3…W-7 and S-2/S-4
> items they raise are carried forward as out-of-scope open items.

## Verdict

**DO NOT ARCHIVE AS-IS.** The implementation is live and healthy — the unified session name is
real, the `StealthMode` name-before-start ordering is real, and the named-session gate is real —
but **one delta requirement is contradicted by the shipped code** and **two more describe a
mechanism that does not exist**. Merging the deltas verbatim into `openspec/specs/` would write
three false statements into the canonical specification.

| Delta | Ready to merge into canonical spec? |
|---|---|
| `session-integrity` (SI-01) | **NO** — outcome holds, mechanism text is wrong (W-1) |
| `login-csrf` (LC-02) | **NO as written** — outcome holds, two clauses are false (W-2) |
| `session-consolidation` (SC-01…SC-05) | **NO** — SC-04 is **contradicted** by the code (C-1); SC-01 is partial (W-3) |

`LC-02` **cannot be safely merged in its current wording.** It is mergeable after a specific,
bounded amendment — see [LC-02 merge assessment](#lc-02-merge-assessment).

**Issue counts: CRITICAL 1 · WARNING 7 · SUGGESTION 4.**

## What was verified

Read in this order, then checked against the code as it stands today:

1. `proposal.md`, `design.md`, `tasks.md`
2. Deltas: `specs/login-csrf/spec.md`, `specs/session-consolidation/spec.md`,
   `specs/session-integrity/spec.md`
3. Canonical specs: `openspec/specs/login-csrf/spec.md`, `openspec/specs/session-integrity/spec.md`
4. Implementation: `src/Core/StealthMode.php`, `src/Security/SessionManager.php`,
   `src/Security/CsrfManager.php`, `base/fs_controller.php`, `base/fs_login.php`,
   `controller/login.php`, `controller/admin_agentes.php`, `index.php`,
   `src/Core/PublicAccessGate.php`, `base/fs_session_identity.php`, `base/fs_session_manager.php`,
   `base/fs_maintenance_mode.php`, `base/install_security.php`
5. Tests: `tests/Security/{SessionManagerTest,SessionLegacyPhpSessionMigrationTest,CsrfManagerTest}.php`,
   `tests/Components/StealthModeTest.php`,
   `tests/Integration/{StealthSessionBootstrapTest,CsrfTokenSurvivesMigrationTest}.php`
6. Git history for the touched files

**Canonical-spec existence check**: `openspec/specs/session-consolidation/` **does not exist**.
`session-consolidation` is a genuinely new capability and archiving must **create** it.
Its delta is written as a full spec (`# … Specification` / `## Purpose` / `## Requirements`),
which matches the established convention for new capabilities
(compare `openspec/changes/archive/2026-09-05-htmx4-core-adoption/specs/htmx-core-support/spec.md`
→ `openspec/specs/htmx-core-support/`). No structural change needed there.

## Commands run (via `ddev exec` only)

| Command | Exact result |
|---|---|
| `ddev exec php vendor/bin/phpunit` | `Tests: 4311, Assertions: 16196, Failures: 12, Skipped: 27` — exit code `1` |
| `ddev exec php vendor/bin/phpunit --testsuite Security` | `OK, but some tests were skipped! Tests: 269, Assertions: 601, Skipped: 1` — exit code `0` |
| `ddev exec php vendor/bin/phpunit tests/Security/SessionManagerTest.php tests/Security/SessionLegacyPhpSessionMigrationTest.php tests/Security/CsrfManagerTest.php tests/Components/StealthModeTest.php tests/Integration/StealthSessionBootstrapTest.php tests/Integration/CsrfTokenSurvivesMigrationTest.php` | `OK (54 tests, 132 assertions)` |
| `ddev exec php vendor/bin/phpunit -c plugins/OidcProvider/phpunit.xml --filter testValidSelectionCallsSyncGruposThenApprove` | `Tests: 1, Assertions: 1, Failures: 1` (isolation reproduction, see below) |

### The 12 full-suite failures are NOT caused by this change

All 12 failures are in `plugins/OidcProvider` — a **separate git repository**
(`plugins/OidcProvider/.git`), clean at commit `5290af0`, which this change never touched. The
core working tree is clean apart from an unrelated untracked `openspec/changes/harden-auth-boundaries/`.

```
1)  admin_oidc_customer_access_htmxTest::testHoldRegistrationMovesProfileToPendingHoldWithInternalNote
    seed probe failed — profile must be readable before mutation (direct rows=[], db_error=Syntax error near the second statement)
2)  admin_oidc_customer_access_htmxTest::testHoldRegistrationRejectsWhenCsrfInvalid
3)  admin_oidc_customer_access_htmxTest::testProcessHtmxFragmentActionRendersHoldModal
4)  admin_oidc_customer_access_htmxTest::testRenderActiveTabFragmentTargetsHoldRows
5)  admin_oidc_customer_access_htmxTest::testRenderActiveTabFragmentFallsBackToPendingRows
6)  admin_oidc_customer_access_htmxTest::testForceExpireVerificationExpiresTheLinkButKeepsTheTokenResolvable
7)  admin_oidc_customer_access_htmxTest::testForceExpireVerificationRejectsProfilesOutsidePendingEmail
8)  admin_oidc_customer_access_htmxTest::testForceExpireVerificationRejectsWhenCsrfInvalid
9)  admin_oidc_customer_access_validation_groupsTest::testValidSelectionCallsSyncGruposThenApprove
    No error must be queued; got: Array ( [0] => Perfil no encontrado. )
10) ClienteRoleResolverIntegrationTest::testMultiGrupoUnionResolvesAcrossDbRows
11) ClienteRoleResolverIntegrationTest::testOrphanGrupoMembershipIsSilentlySkipped
12) migration011_cliente_gruposTest::testCreatedTableCollationMatchesReferencedTables
```

Failure classification: DB-fixture / schema-drift, not session or CSRF logic. Failures 2 and 8
carry "Csrf" in their names but fail at `assertNotFalse($profile)` /
`$this->assertNotFalse($profile)` **after** a `seedPendingProfile()` that never persisted —
i.e. `Perfil no encontrado`, not a token mismatch. Test 9 reproduces **in isolation**
(`Tests: 1, Failures: 1`), which rules out suite-ordering interaction with this change's tests.
`git log --all -- plugins/OidcProvider` is a different history and the plugin tree is clean.

**I cannot prove these 12 were failing before this change** (that would require checking out a
pre-change tree, which a read-only verify must not do). What **is** established: they are
plugin-owned, DB-fixture-driven, reproduce without any core session code in the loop, and the
core-only and `Security` suites are fully green.

## Compliance table

### Delta: `session-consolidation` (NEW capability — canonical dir absent)

| ID | Status | Evidence (`file:line` / test) |
|---|---|---|
| **SC-01** All `session_start()` sites call `session_name(...)` first | **PARTIAL** | Audited all core `session_start()` sites. Compliant: `src/Core/StealthMode.php:475-476`, `src/Security/CsrfManager.php:405-407`, `base/fs_session_manager.php:127-129`, `base/fs_maintenance_mode.php:603-609`, `base/install_security.php:28-37`. **Violations**: `controller/admin_agentes.php:271` (bare `session_start()`, no name) and `src/Security/CsrfManager.php:133` (fallback branch). See W-3. |
| **SC-02** `StealthMode::ensurePhpSessionStarted()` sets `session_name()` before `session_start()` | **COMPLIANT** | `src/Core/StealthMode.php:474-477` — inside the `PHP_SESSION_NONE` guard: `session_name(SessionManager::resolveSessionName());` then `@session_start();`. Ordering is exactly as specified. Tests: `StealthModeTest::testEnsurePhpSessionStartedSetsSessionName`, `StealthSessionBootstrapTest::stealthAccessOpensSessionUnderFssessName`. |
| **SC-03** `initialize()` detects an already-active correctly-named session and skips re-init | **COMPLIANT** | `src/Security/SessionManager.php:109-115` — `session_status() === PHP_SESSION_ACTIVE && session_name() === self::resolveSessionName()` → wraps with `PhpBridgeSessionStorage`, no `session_name()`/`session_start()` re-issue. `PhpBridgeSessionStorage::start()` only calls `loadSession()`; `NativeSessionStorage::start()` returns early at `vendor/.../NativeSessionStorage.php:111` when already active, so no double-start is possible. Tests: `SessionManagerTest::testInitializeSkipsReinitOnMatchingNamedSession`, `StealthSessionBootstrapTest::sessionManagerWrapsStealthSessionViaPhpBridge`. |
| **SC-04** Legacy `PHPSESSID` → copy `$_SESSION` to `FSSESS_xxx` and expire the old cookie | **CONTRADICTED — CRITICAL** | The code does the **opposite**. `src/Security/SessionManager.php:100-106` → `discardLegacyPhpSession()` (`:215-220`) calls `clearLegacyPhpSessionCookie()` and `unset($_COOKIE['PHPSESSID'])`; the session is **never opened, never read, and no data is copied**. The `migrateLegacyPhpSession()` method named in `design.md` and `tasks.md` 1.2 **does not exist**, and the `_migrated_from_phpsessid` flag does not exist. Commit `d6ab89fd` ("never merge data from a foreign PHPSESSID session", 2026-10-17) inverted this deliberately and documented why: copying a foreign session's keys allowed login injection on shared hosts because `isValid()` only checks `user_nick` + `login_time` + `last_activity`. See C-1. |
| **SC-05** Responses set only `FSSESS_xxx`; `PHPSESSID` absent after transition | **COMPLIANT (with caveat)** | `clearLegacyPhpSessionCookie()` (`:255-271`) emits `setcookie('PHPSESSID', '', ['expires' => time()-3600, …])` → `Set-Cookie: PHPSESSID=deleted; Max-Age=0`. Only emitted when `!empty($_COOKIE['PHPSESSID']) && resolveSessionName() !== 'PHPSESSID'`, and only for paths that can belong to this install (`legacyPhpSessionCookiePaths()`). Caveat W-4 (StealthMode's own cookie path/secure mismatch). |

Scenarios: *StealthMode uses correct session name* ✅ · *SessionManager skips re-init for named
session* ✅ · *Legacy PHPSESSID cookie migration* ❌ (**refuted**, see C-1) · *CSRF token survives
session migration* ⚠️ premise refuted (no migration happens) but the CSRF guarantee holds by a
different mechanism · *Concurrent requests during migration* — **N/A**, there is no migration ·
*Expired session after migration* ✅ (`session_start()` creates a fresh `FSSESS_xxx`; no
`PHPSESSID` recovery is attempted — literally true, trivially).

### Delta: `session-integrity` (MODIFIED SI-01)

| ID | Status | Evidence |
|---|---|---|
| **SI-01** CSRF token survives `migrate(true)` in **both** backends; after `PhpBridgeSessionStorage` wraps, `CsrfManager` verifies token presence **in the bag** and refreshes if absent | **COMPLIANT in outcome / mechanism mismatch (W-1)** | `src/Security/CsrfManager.php:92-118`: guard at `:111-114` calls `self::$manager->getToken(self::DEFAULT_TOKEN_ID)` once per request (cached by `$tokenVerified`). Storage is `NativeSessionCsrfStorage` (`:370-415`) reading/writing `$_SESSION['_csrf'][$tokenId]` **directly** — there is no Symfony session bag in the CSRF path. `session_regenerate_id(true)` preserves `$_SESSION`, so the token survives. Tests: `CsrfTokenSurvivesMigrationTest` (4 tests), `CsrfManagerTest::testTokenPresenceGuardRefreshesWhenAbsent`, `::testTokenPresenceGuardCachesPerRequest`. |

Scenarios: *Native migration* ✅ · *PhpBridge migration* ✅ outcome, but the test is
non-discriminating (W-6) · *Token refresh guard caches per-request* ✅.

### Delta: `login-csrf` (MODIFIED LC-02)

| ID | Status | Evidence |
|---|---|---|
| **LC-02** Login POST validates CSRF under the unified session name; token reads consistent regardless of backend; legacy `PHPSESSID` must not cause a mismatch | **COMPLIANT in outcome / two clauses false (W-2)** | `base/fs_controller.php:399-440` (`validateCsrf()`) → `CsrfManager::isValid()` (`src/Security/CsrfManager.php:162-166`) → `NativeSessionCsrfStorage` over `$_SESSION['_csrf']` of the single `FSSESS_xxx` session. `controller/login.php` has no independent CSRF path (grep: zero `csrf` matches) — it relies on `fs_controller::pre_private_core()`. Tests: `CsrfTokenSurvivesMigrationTest::tokenConsistencyAcrossBothBackends`, `StealthSessionBootstrapTest`, `FsControllerCsrfBlockingTest`. |

Scenarios: *CSRF validated under unified session* ✅ · *CSRF survives stealth-to-normal
transition* ✅ outcome / **false stated reason** ("session is migrated to `FSSESS_xxx` per SC-04"
— it is not migrated) · *CSRF token mismatch with dual cookies* ✅ outcome / stale SC-04 cross-ref.

## LC-02 merge assessment

**Direct answer: `LC-02`'s modified clause CANNOT be merged into
`openspec/specs/login-csrf/spec.md` as it is written today.** The merge is safe only after the
three corrections below. Merging verbatim would write two false statements and one dangling
cross-reference into the canonical spec.

Current canonical `openspec/specs/login-csrf/spec.md:12` is a single table row
(`LC-02 | El flujo de login **MUST** validar token CSRF en el POST | MUST`) plus the
`CSRF validated during login` scenario at `:24-29`. The delta restates the whole requirement and
adds three scenarios. Clause-by-clause:

| Delta clause | True against today's code? | Action |
|---|---|---|
| "The login flow **MUST** validate the CSRF token on POST under the unified session name (`FSSESS_xxx`)." | ✅ **TRUE** — `validateCsrf()` reads the token from the single `FSSESS_xxx` session | **Merge as-is.** This is the load-bearing clause and it is currently missing from the canonical spec. |
| "Token reads from the session bag **MUST** be consistent regardless of whether the session storage backend is `NativeSessionStorage` or `PhpBridgeSessionStorage`." | ❌ **FALSE as worded** — there is no session bag in the CSRF path; `NativeSessionCsrfStorage` reads `$_SESSION['_csrf']` directly, deliberately bypassing Symfony bags | **Amend.** Keep the observable contract, drop the bag mechanism: *"CSRF token reads MUST be consistent regardless of the session storage backend, because the CSRF storage reads the PHP session directly (`$_SESSION['_csrf']`) instead of a Symfony session bag."* |
| "Legacy `PHPSESSID` cookies **MUST NOT** cause token mismatch after migration." | ⚠️ **TRUE in effect, wrong word** — there is no migration; the cookie is discarded | **Amend** `after migration` → `after the legacy cookie is discarded (SC-04)`. This is the clause most at risk of being silently lost, and it **is** worth keeping. |
| Scenario 2: "**THEN** session is migrated to `FSSESS_xxx` per SC-04" | ❌ **FALSE** — no migration exists | **Amend.** Replace with: *"THEN the request runs on the `FSSESS_xxx` session opened by `StealthMode`, and any stale `PHPSESSID` is discarded without being read."* |
| Scenario 3: "**AND** `PHPSESSID` is expired in the response per SC-04" | ⚠️ Outcome ✅, cross-ref now points at an inverted requirement | **Amend** the cross-ref: *"AND `PHPSESSID` is expired in the response."* |

**Silent-loss risk**: the clause with real value and no current home in the canonical spec is
*"legacy `PHPSESSID` cookies MUST NOT cause token mismatch"*. If the merge is skipped entirely,
LC-02 in the canonical spec stays at "validar token CSRF en el POST" and loses the
unified-session-name guarantee. If the merge is done verbatim, the spec gains a mechanism
(`session bag`) that no code implements — which will mislead the next reader into "fixing" the
code to match a spec that was already wrong.

**House-style note (S-1)**: the canonical `login-csrf/spec.md` and `session-integrity/spec.md`
are written in Spanish; the delta is in English. Merging the English LC-02 text into the Spanish
canonical file is a consistency change that should be a deliberate decision, not a side effect.

## Correctness (static evidence)

| Claim from proposal / design / tasks | Verified? | Evidence |
|---|---|---|
| `ensurePhpSessionStarted()` sets the name before `session_start()` | ✅ | `src/Core/StealthMode.php:474-477` |
| `resolveSessionName()` made `public static` | ✅ | `src/Security/SessionManager.php:273`; formula delegated to `base/fs_session_identity.php` |
| Named-session gate in `initialize()` | ✅ | `src/Security/SessionManager.php:109-115` |
| Legacy `PHPSESSID` is **copied** into `FSSESS_xxx` | ❌ | `:100-106`, `:215-220` — discarded, never read |
| `migrateLegacyPhpSession()` method added | ❌ | does not exist |
| `_migrated_from_phpsessid` flag set for logging | ❌ | does not exist; `SessionLegacyPhpSessionMigrationTest::foreignSessionDataIsNeverMerged` asserts its **absence** as a regression sentinel |
| `private static bool $tokenVerified` added + guard in `getManager()` | ✅ | `src/Security/CsrfManager.php:55`, `:111-114` |
| `refreshToken('fs_form')` called by the guard when the token is absent | ⚠️ | guard calls `getToken()`, which auto-creates on absence; `refreshToken()` is never invoked by the guard. Behaviourally equivalent, textually different |
| `SessionManager::initialize()` skips re-init on a matching named session | ✅ | `:109-115` |
| Only `FSSESS_xxx` cookie survives in the response | ⚠️ | `PHPSESSID` is emitted as an **expiry** cookie, not omitted |
| Bootstrap audit covers all `session_start()` sites | ⚠️ | 2 literal violations (W-3) |
| Task 1.6 test `testMigrateLegacyPhpSessionTransfersData` exists | ❌ | **does not exist**; replaced by the inverted `SessionLegacyPhpSessionMigrationTest::legacyCookieIsOnlyDiscarded` + `SessionManagerTest::testLegacyPhpSessionDataIsNotTransferred` |
| Task 3.3 Playwright E2E in `scripts-onlogin/` | ❌ | directory does not exist and never did (`git log --all -- 'scripts-onlogin*'` is empty) |
| Task 3.4 "494 tests pass (baseline 436)" | ❌ stale | suite is now 4311 tests |

## Issues

### CRITICAL

**C-1 — `SC-04` is contradicted by the shipped code; the delta must not be merged as written.**

The delta (`specs/session-consolidation/spec.md:14`) requires: *"Request with legacy `PHPSESSID`
cookie **MUST** copy `$_SESSION` to `FSSESS_xxx` and expire the old cookie."* The implementation
does the **opposite**: `src/Security/SessionManager.php:100-106` detects `$_COOKIE['PHPSESSID']`
and calls `discardLegacyPhpSession()` (`:215-220`), which only clears the cookie and unsets the
superglobal. The session is never opened, its data is never read and never copied. Commit
`d6ab89fd` inverted this on purpose, with the rationale recorded in the method docblock
(`:201-214`): opening the foreign session and copying its keys allowed login injection on shared
hosts, because `isValid()` (`:710-720`) only checks `user_nick` + `login_time` + `last_activity`.

Consequences:
- Merging `SC-04` verbatim creates a canonical requirement that the code **actively forbids**.
- `tests/Security/SessionLegacyPhpSessionMigrationTest::foreignSessionDataIsNeverMerged`
  (`:135-152`) is a **sentinel** that greps the source for `_migrated_from_phpsessid` and
  `legacyData` and fails if either returns. Implementing `SC-04` literally would **break the
  suite**.
- `design.md:47-59` ("Migration Path") and `tasks.md` 1.2/1.4/1.6 describe a method
  (`migrateLegacyPhpSession()`) and a test (`testMigrateLegacyPhpSessionTransfersData`) that do
  not exist in the tree.
- The delta's `CSRF token survives session migration` scenario and the `Concurrent requests
  during migration` edge case both presuppose a migration that no longer happens.

*Required action before archiving*: rewrite `SC-04` to the discard semantics actually shipped —
e.g. *"A request carrying a legacy `PHPSESSID` cookie **MUST NOT** read, copy or adopt that
session's data; it **MUST** discard the cookie and run on the installation's own `FSSESS_xxx`
session."* Then delete the `CSRF token survives session migration` and `Concurrent requests
during migration` scenarios or rewrite them against the discard model. Do not implement the
delta.

### WARNING

**W-1 — `SI-01`'s mechanism text does not match the implementation.**
`specs/session-integrity/spec.md:7` says *"`CsrfManager` **MUST** verify token presence in the
bag; if absent, it **MUST** refresh the token."* The code has no bag in this path: the guard
(`src/Security/CsrfManager.php:111-114`) calls `CsrfTokenManager::getToken()`, and the storage is
`NativeSessionCsrfStorage` (`:370-415`) over raw `$_SESSION['_csrf']`. `refreshToken()` is never
called by the guard. The *outcome* (a valid token is always available after `migrate(true)`) holds
and is what the tests assert; only the described mechanism is wrong. Merge only with amended
wording.

**W-2 — `LC-02`'s modified text contains two false clauses.** See
[LC-02 merge assessment](#lc-02-merge-assessment). "Token reads from the session bag" is false,
and scenario 2's "session is migrated to `FSSESS_xxx` per SC-04" is false. The scenario also
depends on `SC-04`, which C-1 shows is itself inverted.

**W-3 — `SC-01` is only partially satisfied, and its wording is not the real invariant.**
Literal requirement: *"All `session_start()` sites **MUST** call
`session_name(SessionManager::resolveSessionName())` first."*
- `controller/admin_agentes.php:271` calls bare `session_start()`. Reachable from
  `private_core()` → `process_action()` when `$_REQUEST['action']` is set and
  `isDuplicatedPetition()` finds `session_status() !== PHP_SESSION_ACTIVE`. In practice
  `SessionManager` has normally already started the session, so the risk is bounded — but if it
  has not (e.g. the `headers_sent()` branch at `SessionManager.php:130-134` leaves no session
  started), this call starts one under PHP's default `session.name` — i.e. `PHPSESSID`, exactly
  the dual-cookie class this change exists to eliminate. Pre-existing, not introduced here.
- `src/Security/CsrfManager.php:133` calls `session_start()` with no name in the branch where
  `SessionManager` is unavailable (`:127-134`).
- The wording is also wrong as an invariant: `base/install_security.php:28` deliberately uses a
  **different** name (`fs_session_identity_installer_name()` → `FSINSTALL_` prefix, by design,
  so the installer never shares the app session), and `base/fs_session_manager.php:127` /
  `base/fs_maintenance_mode.php:603` use the shared resolver but not literally
  `SessionManager::resolveSessionName()`.

*Remediation*: sweep `admin_agentes.php` and `CsrfManager.php:133`, and reword `SC-01` as
*"every `session_start()` site sets a session name derived from the shared identity resolver
before starting, except the installer, which uses its own prefix by design."*

**W-4 — `StealthMode`'s cookie sync can still create a same-named cookie with different attributes.**
`src/Core/StealthMode.php:494-499` emits the session cookie with a hardcoded
`'path' => '/'` and **no `secure` flag**, while `SessionManager` uses `resolveCookiePath()`
(subdirectory-aware, `:442-489`) and `cookie_secure = SecureRequestDetector::isSecure()`
(`:137,144`). On a subdirectory install and/or under HTTPS, a single response can carry two
cookies with the same `FSSESS_xxx` name and different `Path`/`Secure` attributes — a residual
variant of the dual-cookie hazard `SC-05` is meant to close. No test covers the emitted headers
(CLI tests cannot assert `Set-Cookie`). *Remediation*: reuse `resolveCookiePath()` and
`SecureRequestDetector::isSecure()` in `StealthMode`.

**W-5 — The E2E deliverable (task 3.3) does not exist.**
`tasks.md:51` claims *"E2E — Playwright: stealth admin panel access `?page=login&adminpanel=HASH`
… assert no 'Token de seguridad inválido' error"*, and `proposal.md:67` lists it as a success
criterion. `scripts-onlogin/` does not exist, no `playwright*` config exists anywhere outside
`node_modules`, and `git log --all -- 'scripts-onlogin*'` is empty — it never existed. The
user-visible guarantee that motivated the whole change (no CSRF error on stealth admin access)
therefore has **only unit/integration evidence**, no end-to-end evidence.

**W-6 — The PhpBridge integration test cannot fail if the guard is removed.**
`tests/Integration/CsrfTokenSurvivesMigrationTest::csrfTokenAvailableAfterMigrationViaPhpBridge`
calls `$this->resetCsrfState()` **after** `migrate(true)` (line 99, with the comment *"force
re-evaluation of the guard"*), then asserts `assertNotEmpty(CsrfManager::generateToken(...))`.
`generateToken()` → `CsrfTokenManager::getToken()` auto-creates a token when absent, so the
assertion is true with or without any guard. The test proves "a token can be minted after
migration", not "the token survived migration". The other three tests in the file share the same
shape. SI-01's core claim is therefore **not** protected by a discriminating test.

**W-7 — `$tokenVerified` is never invalidated within a request.**
`src/Security/CsrfManager.php:55` is set to `true` at `:113` and reset only by tests via
Reflection. If a `migrate(true)` emptied the token storage *after* a first `getManager()` in the
same request, the guard would not re-run. It does not bite today only because
`NativeSessionCsrfStorage` reads raw `$_SESSION` (which `session_regenerate_id(true)` preserves)
and `base/fs_login.php:543-548` regenerates only on form login. Latent weakness, not a live bug.

### SUGGESTION

**S-1 — Language drift on merge.** Canonical `login-csrf/spec.md` and `session-integrity/spec.md`
are Spanish; the deltas are English. Merging changes the canonical language for the modified
requirements. Decide deliberately.

**S-2 — Stale test counts.** `proposal.md:66` ("436 tests pass") and `tasks.md:52`
("494 tests pass") do not match the current suite (4311 tests). Harmless, but they were already
stale when written relative to the archive report that recorded 2355.

**S-3 — Cosmetic inconsistency.** `specs/session-consolidation/spec.md:21` writes `FSSESS_xxxx`
(4 `x`) where the rest of the file writes `FSSESS_xxx`.

**S-4 — `tasks.md` checkboxes are not trustworthy for this change.** All 16 are checked, yet
task 1.6 names a test that does not exist and task 3.3 names an artifact that never existed.
Future verifies of this repo should treat `tasks.md` as claims only.

## Could not verify

- **Whether the 12 `OidcProvider` failures pre-date this change.** Establishing that would
  require checking out a pre-change tree, which the read-only verify phase forbids. What is
  established: they are confined to a separate plugin repository, driven by DB fixtures/schema
  drift, and reproduce in isolation without core session code in the loop.
- **Actual `Set-Cookie` headers on a live HTTP response** (SC-05, W-4). The test harness runs in
  CLI, where `headers_sent()` is true and `setcookie()` is a no-op. All SC-05 evidence is
  source-level.
- **`StealthMode`'s name-before-start under a real web server.** Verified by source ordering and
  by a `RunInSeparateProcess` unit test that forces `PHP_SESSION_NONE`; no live HTTP probe was run.
- **A mutation test** (deleting the guard / reverting the name assignment to confirm the tests
  go red). The verify phase forbids modifying implementation files, so W-6's non-discriminating
  assertion is reasoned analytically rather than executed.
- **The `LC-02` "no 'Token de seguridad inválido'" guarantee end to end** — see W-5.

## Conclusion

**DO NOT ARCHIVE YET.** The implementation is sound and green (`Security` suite 269/269 with
0 failures; 54/54 on the change's own tests; the 12 full-suite failures are pre-existing,
plugin-owned and unrelated). `SC-02`, `SC-03` and `SC-05` are satisfied as written. `SI-01` and
`LC-02` are satisfied in observable outcome but described with mechanisms the code does not have.

The blocker is **C-1**: `SC-04` demands a data-copying migration that the code deliberately
removed for security reasons, and that a sentinel test now guards against. Rewrite `SC-04` (and
its dependent scenarios) to the discard semantics actually shipped, amend the `SI-01` and
`LC-02` mechanism clauses, then re-verify and archive. `LC-02` **must not** be merged into
`openspec/specs/login-csrf/spec.md` verbatim — amend first, or the canonical spec will assert a
`session bag` mechanism that no code implements and a `SC-04` migration that no longer exists.
