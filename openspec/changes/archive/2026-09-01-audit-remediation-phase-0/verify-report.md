# Verify Report: audit-remediation-phase-0 (Test Harness Determinism)

**Change**: audit-remediation-phase-0
**Verified at**: 2026-09-01, checkout `/home/javier/fsf0225`, HEAD `a0c011fe` (all 6 change commits present: `9771f57f`, `f73a29cd`, `62234e0a`, `170ffcc2`, `c34539ab`, `a0c011fe`)
**Mode**: Strict TDD (verify module loaded; runner `ddev exec php vendor/bin/phpunit`)
**Artifacts read**: proposal.md, specs/test-harness-determinism/spec.md, design.md, tasks.md, baseline.md
**Verification contract**: read-only. No code/test/config file was modified. Two reversible filesystem simulations were performed (plugin-dir and config.php rename → run → immediate restore, both verified restored byte-identical).

```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:17c26c9a609260738ceee46b64ba1ab9e3068b2880dc4136d31c2632b5464d4c
verdict: pass-with-warnings
blockers: 0
critical_findings: 0
requirements: 6/6
scenarios: 11/11
test_command: ddev exec php vendor/bin/phpunit
test_exit_code: 0
test_output_hash: sha256:a7c7673cea1ec4b2bf34efc6bb326b2541ab2926be2df7f310cfd8ac7780bdb1
build_command: ddev exec composer install --dry-run
build_exit_code: 0
build_output_hash: sha256:bd80b948483899d0e6242e68172730848e1018aad8b43ff7fc80e78d956e5449
```

> `test_exit_code` / `test_output_hash` anchor run #4 of the full suite (authoritative capture: container-internal log, exit 0, Tests 1249 / Assertions 3084 / PHPUnit Deprecations 20 / Skipped 10, 9941 bytes). Runs #2 and #3 (both exit 0, byte-identical counts) precede it; run #1 exposed one out-of-scope environmental flake — documented under WARNING-1, not attributed to the change.

## Verification Report

### Completeness
| Metric | Value |
|--------|-------|
| Tasks total | 20 |
| Tasks complete | 20 (all `[x]`, verified by grep: 20 checked / 0 unchecked) |
| Tasks incomplete | 0 |

### Build & Tests Execution

**Build** (composer.json/lock/vendor atomicity, R3 fresh-clone scenario): ✅ Passed
```text
$ ddev exec composer install --dry-run
Nothing to install, update or remove
65 packages you are using are looking for funding.
EXIT=0  (sha256 bd80b948483899d0e6242e68172730848e1018aad8b43ff7fc80e78d956e5449)
Bridge vendor tree committed: git ls-files vendor/symfony/phpunit-bridge → 38 files (v7.4.18 in composer.lock)
```

**Suite validity** (R4): ✅ exit 0
```text
$ ddev exec php vendor/bin/phpunit --list-suites
 - Base (180 tests)  - Cache (18)  - Core (119)  - Integration (10)
 - Modern (56)  - Plugins (669 tests)  - Security (182)  - Traits (15)
EXIT=0   (baseline pre-change: exit 255 — vendored CpdfTest constructor fatal)
```

**Tests** (full suite, R6 determinism): ✅ exit 0 — Tests 1249, Assertions 3084, PHPUnit Deprecations 20, Skipped 10
```text
Run #1: exit 1  — 1 failure: Tests\Tarifario\Integration\LegacyImportRegressionTest::
        importExcelChunkRedirectsToSseEntryPoint ("Server did not respond.")  → 1249/3082/10
Run #2: exit 0  — OK  Tests: 1249, Assertions: 3084, Deprecations: 20, Skipped: 10
Run #3: exit 0  — OK  identical counts (1249/3084/20/10)
Run #4: exit 0  — OK  identical counts (1249/3084/20/10)  ← authoritative capture (hash above)
→ three consecutive green runs with identical counts; exact match to baseline.md §5.1
  (1249 / 3084 / 10) and to the orchestrator-expected figures.
```

**Strict deprecation enforcement spot-run** (R3 scenario 2): ✅ helper honored
```text
$ ddev exec env SYMFONY_DEPRECATIONS_HELPER='max[direct]=0' php vendor/bin/phpunit \
    --testsuite Base,Core,Security,Traits,Cache,Integration,Modern
→ exit 1; bridge lists the 17 direct deprecation notices (ValidatorTraitTest) and fails the suite.
  Same suites with default weak: exit 0 with the 17 notices reported (matching baseline Phase 2 proof).
```

**Coverage**: ➖ Not available / not part of the change contract — no coverage tool configured for this change; skipped (informational, not a failure).

### Spec Compliance Matrix

| Requirement | Scenario | Evidence (command → observed) | Result |
|---|---|---|---|
| **R1** bootstrap unconditional, no config.php | Suite boots without config.php | `mv config.php config.php.off` → `phpunit tests/Security/SecretManagerValidationTest.php` → **OK (5 tests, 11 assertions)**, no missing-constant fatal, no config.php error → restored (930 bytes intact). Structural: `grep -n config.php tests/bootstrap.php` → only a comment (line 34); zero `require` of config.php | ✅ COMPLIANT |
| R1 | Local config.php values never leak | `config.php` PRESENT on this machine, gitignored (`.gitignore:31`); full suite green with it present; `SecretManagerValidationTest::testGetSecretReturnsBootstrapSecret` (asserts `getSecret() === constant('FS_SECRET_KEY')`, the exact pre-change leak path per baseline §1.1) passes; FS_DB_NAME probe: canonical `db` observed | ✅ COMPLIANT |
| **R2** FS_SECRET_KEY ≥ 32 | SecretManager returns valid secret | `FS_SECRET_KEY` = deterministic 64-hex literal (`tests/bootstrap.php:63`); `phpunit tests/Security/SecretManagerValidationTest.php` → **OK (5 tests, 11 assertions)**: defined / ≥32 / `getSecret()` identical to constant / `hmac()` non-empty / bridge extension registered | ✅ COMPLIANT |
| R2 | Secret-dependent components initialize without fallback | Same file green in the full suite; pre-change failure (`SecretManagerTest` secret-file fallback, baseline §1.1) no longer occurs — no "too short" error path in any run | ✅ COMPLIANT |
| **R3** bridge installed, autoloaded, enforcing | Bridge present and registered | require-dev `symfony/phpunit-bridge ^7.4` (lock v7.4.18); 38 vendor files committed; `composer install --dry-run` exit 0; bootstrap requires bridge bootstrap + explicit idempotent `DeprecationErrorHandler::register(getenv(...) ?: 'weak')`; `SymfonyExtension` in phpunit.xml `<extensions>`; guard test green | ✅ COMPLIANT |
| R3 | Deprecation reporting functional | weak → exit 0 with "Remaining direct deprecation notices (17)" reported; `max[direct]=0` → **exit 1** on the same real suites (reproduced on this checkout); baseline also documents synthetic E_USER_DEPRECATED weak/strict proof | ✅ COMPLIANT |
| **R4** bounded deterministic Plugins discovery | list-suites exits successfully | `--list-suites` → **exit 0**, `Plugins (669 tests)` listed (baseline: exit 255) | ✅ COMPLIANT |
| R4 | Vendored and backup tests not ingested | `--list-tests --testsuite Plugins` → **669** methods; grep Cpdf/rospdf → **0 matches**; suite = `<directory suffix="Test.php">plugins/*/tests</directory>` + plain-text excludes `plugins/*/vendor`, `plugins/*_back`; `ls -d plugins/*_back` → none (excludes structurally defensive, as designed) | ✅ COMPLIANT |
| R4 | Plugins without tests dir don't break discovery | Discovery bounded to `plugins/*/tests`; per-plugin fallback documented (`-c plugins/tarifario/phpunit.xml`, plugin has own phpunit.xml); no plugin with tests outside `tests/` beyond the 23 documented excludes (baseline §Phase 3: filesystem scan, no silent loss) | ✅ COMPLIANT |
| **R5** legacy SHA1/MD5 skips | Skip when legacy_support absent | Simulation (dir renamed `legacy_support` → `.off`, run, restored — verified present): `phpunit tests/Security/PasswordHasherServiceTest.php --display-skipped` → **OK, Tests: 20, Assertions: 18, Skipped: 6, 0 failed, 0 errored**; all 6 messages: "legacy_support plugin is required: FSFramework\Plugins\legacy_support\LegacyCompatibility is not autoloadable" | ✅ COMPLIANT |
| R5 | Run when legacy_support present | Plugin in place (this checkout): same file → **OK (20 tests, 27 assertions)**, zero skips; guard = `class_exists(LegacyCompatibility::class)` mirroring `base/fs_login.php:426`; resolves via root PSR-4 (no composer_autoload dependency) | ✅ COMPLIANT |
| **R6** green, repeatable, non-lossy | Full suite green and repeatable | Runs #2/#3/#4: **exit 0, identical 1249/3084/20/10** (three consecutive); exact match to baseline §5.1 (1249/3084/10); config.php present and provably ignored; run #1 flake = plugin-owned environmental test, out of change diff (WARNING-1) | ✅ COMPLIANT |
| R6 | Test-count comparison proves no silent loss | Delta vs baseline actuals documented in baseline.md §5.2: core 575→580 (+5 SecretManagerValidationTest incl. bridge guard); 669 plugin test methods in root suite; **23 per-file excludes each with a verified incompatibility reason documented inline in phpunit.xml + baseline.md** — none silent; no vendored/backup ingestion; `system_updater_back` absent → spec's −6 delta superseded per design R6; no unexplained delta | ✅ COMPLIANT |

**Compliance summary**: 11/11 scenarios compliant (6/6 requirements).

### Correctness (Static Evidence)
| Requirement | Status | Notes |
|---|---|---|
| R1 bootstrap | ✅ Implemented | Unconditional canonical `define()`s (FS_FOLDER, FS_TMP_NAME, FS_DB_*, FS_NF0, …); Composer autoload + `plugins/*/composer_autoload.php` glob + `fs_model_autoloader` retained; only a comment mentions config.php |
| R2 secret | ✅ Implemented | 64-hex literal; guard test asserts constant, length, `getSecret()` identity, `hmac()` |
| R3 bridge | ✅ Implemented | Dev dep + lock + committed vendor; bootstrap require; explicit DeprecationErrorHandler registration (PHPUnit ≥ 10 gap documented in baseline Phase 2); SymfonyExtension in phpunit.xml; `<env>` weak without `force` so real env vars win (verified by strict run) |
| R4 rescope | ✅ Implemented | Wildcard dir + plain-text excludes (XSD gotcha honored — no nested `<directory>` in `<exclude>`); 23 documented per-file excludes (phpunit.xml:100–122 region) |
| R5 guards | ✅ Implemented | `requireLegacySupportPlugin()` helper; 6 call sites; explicit skip message |
| R6 verification | ✅ Implemented | baseline.md anchors the delta; tasks 20/20 [x]; scope guard clean |

### Coherence (Design)
| Decision | Followed? | Notes |
|---|---|---|
| R1 unconditional constants, no config.php | ✅ Yes | Matches design verbatim |
| R2 fixed 64-hex secret + guard test | ✅ Yes | Matches design verbatim |
| R3 extension API (not legacy listener) + explicit handler registration | ✅ Yes | Design documented the PHPUnit ≥ 10 bootstrap early-return gap and the fix; reproduced at runtime |
| R4 `plugins/*/tests` + plain-text excludes | ✅ Yes | Plus 23 per-file excludes added later (c34539ab) — documented deviation, see Deviations |
| R5 `class_exists` guard, no plugin hard-dep | ✅ Yes | 6 tests guarded vs design's 5 — documented deviation, see Deviations |
| R6 verification anchored to baseline.md actuals | ✅ Yes | Spec's 42-file/−6 forecast superseded as stale (system_updater_back absent), exactly as design R6 prescribes |
| No production code touched | ✅ Yes | Scope guard proves it (below) |

### Scope Guard (task 5.3)
```text
$ git diff --name-only 9771f57f..HEAD   → 56 files:
  composer.json, composer.lock, phpunit.xml  (1 each)
  tests/          → 4 files (bootstrap.php, Security/SecretManagerValidationTest.php,
                    Security/PasswordHasherServiceTest.php, Base/FsModelAutoloaderAliasTest.php)
  vendor/         → 44 files (bridge tree, committed per project policy)
  openspec/       → 5 files (this change's SDD trail)
No base/, src/, model/, controller/ paths. ✅
Untracked workspace files scripts/git-clone-plugins.sh and tmp/ are pre-existing native-review
follow-ups explicitly OUT of this change's scope (per orchestrator: must not count against it).
```

### TDD Compliance
> No standalone `apply-progress` artifact exists in the openspec store (change folder holds proposal/spec/design/tasks/baseline only). TDD evidence is embedded in tasks.md (RED tasks 1.3 and 2.1 marked [x]) and baseline.md (R5 guard RED: absent case produced Failures: 3 before the guard; GREEN counts per phase). All embedded evidence was independently re-verified at runtime on this checkout.

| Check | Result | Details |
|---|---|---|
| TDD Evidence reported | ✅ | Embedded in tasks.md/baseline.md (no standalone apply-progress artifact — process note, WARNING-2) |
| All tasks have tests | ✅ | 20/20 tasks carry runtime-verifiable evidence (tests, suite commands, or verification records) |
| RED confirmed (tests exist) | ✅ | RED test files exist: `SecretManagerValidationTest.php` (secret + bridge guards); R5 guard RED documented (3 failures pre-guard) |
| GREEN confirmed (tests pass) | ✅ | 5/5 SecretManagerValidationTest green (direct + in-suite); R5 absent 6-skip/0-fail and present OK 20/27 reproduced; full suite green ×3 |
| Triangulation adequate | ✅ | Secret contract triangulated across 5 assertions/behaviors; R5 verified in both plugin-present and plugin-absent worlds |
| Safety Net for modified files | ✅ | PasswordHasherServiceTest full file run pre/post (OK 20/27 with plugin); full suite before (baseline) and after |

**TDD Compliance**: 6/6 checks passed (evidence location process note recorded).

### Test Layer Distribution
| Layer | Tests | Files | Tools |
|---|---|---|---|
| Unit (change-authored) | 6 new tests (5 SecretManagerValidationTest incl. bridge guard, 1 FsModelAutoloaderAliasTest) + guards on 20 existing | 3 files | PHPUnit 11 |
| Harness verification (commands, not assertions) | `--list-suites`, `--list-tests`, strict/weak deprecation runs, composer dry-run, scope guard | phpunit.xml, composer.json/lock | PHPUnit 11 + Composer |
| Integration | 0 added by the change (the flaky tarifario HTTP test is plugin-owned, pre-existing, out of diff) | — | — |
| E2E | 0 | — | not installed |
| **Total (suite)** | **1249 tests / 3084 assertions / 10 skips** | — | — |

### Changed File Coverage
Coverage analysis skipped — no coverage tool detected in the change contract (informational, not a failure). Changed files are harness/config plus 3 test files; all are exercised green at runtime.

### Assertion Quality
| File | Line | Assertion | Issue | Severity |
|---|---|---|---|---|
| — | — | — | — | — |

**Assertion quality**: ✅ All assertions verify real behavior (audited: `SecretManagerValidationTest` — defined/length/identity/hmac/extension-interface assertions; `FsModelAutoloaderAliasTest` — precondition-guarded behavioral assertions with false→true class-load transition and alias identity; `PasswordHasherServiceTest` — hash/verify truth-value assertions, guard is a skip-precondition not an assertion). No tautologies, no ghost loops, no smoke-only tests. The alias test's in-suite skip is a documented, legitimate precondition (plugin stub collision), green in isolated runs — 1 of the 10 suite skips, per baseline §5.2.

### Quality Metrics
**Linter**: ➖ Not available in the change contract
**Type Checker**: ➖ Not available in the change contract

### Deviations Ledger (vs proposal/spec/design/tasks — all documented in baseline.md)
| # | Deviation | Where documented | Assessment |
|---|---|---|---|
| 1 | **6 legacy tests guarded** vs design's 5 (`testVerifyAndMigrateUpdatesHash` additionally depends on plugin delegation via `verifyAndMigrate`) | baseline.md Phase 4 (absent-case simulation found 3 failures with only the design's 5 guarded — would have violated task 4.2's "0 failed") | ✅ Justified, runtime-verified (6 skips / 0 failures) |
| 2 | **23 per-file plugin-test excludes** beyond the design's original "no exclusions needed" (commit c34539ab) — shared-process incompatibilities: inline stub vs real model file, stale fs_db2 mock signatures, cross-plugin missing classes, process-order dependence, plugin view/service drift, PHP notices under pre-existing `failOnWarning="true"`, environment-dependent SQL/DNS test | phpunit.xml inline comments (per-file, per-reason) + baseline.md §5.2 | ✅ Documented; root causes are plugin-repo drift (owner items, plugin repositories); spec's structural requirements unaffected; no silent loss (explained delta) |
| 3 | **system_updater_back absent** on this checkout → spec's −6 delta superseded | baseline.md §1.2 + §5.2; design R6 declares the spec forecast stale | ✅ Superseded per design R6; `_back` exclude kept as structural guard |
| 4 | Deprecation enforcement proof performed with `weak` (exit 0 + notices) vs strict (`max[direct]=0` → exit 1), matching spec's "demonstrably changes behavior" | baseline.md Phase 2 table + reproduced at verify time | ✅ Spec scenario satisfied |
| 5 | `scripts/` deletions inside commit `9771f57f` predate this change's scope (owner item, already in history) | design.md Open Questions | ✅ Out of change scope; restoring would itself violate the scope guard |

### Issues Found

**CRITICAL**: None.

**WARNING**:
1. **Flaky full-suite run (environmental, out of change diff)**: Run #1 failed (exit 1) on `Tests\Tarifario\Integration\LegacyImportRegressionTest::importExcelChunkRedirectsToSseEntryPoint` — "Server did not respond." The plugin test boots PHP's built-in web server via `proc_open` and curls it (10s timeout); under full-suite load the server did not respond in time. It skips/passes in isolation (auth gate → `markTestSkipped`, verified) and the suite is green in runs #2/#3/#4 with counts identical to baseline. The file is plugin-owned (tarifario, separate repo), untouched by the change's diff (scope guard), and belongs to the same environment-dependence class the change already documents and excludes for its sibling `TarifTarifasGuardarTest`. R6's letter is satisfied (three consecutive identical green runs); the flake is recorded for the plugin owner. Not attributed to the change.
2. **No standalone apply-progress artifact** in the openspec store: Strict-TDD evidence lives embedded in tasks.md/baseline.md. Re-verified at runtime (all GREEN). Process deviation only; evidence quality unaffected.
3. **Plugin-repo drift surfaced by the first shared-process run** (root cause of deviation #2 and of 8 of the 10 skips): stale fs_db2 mock signatures in tarifario, missing cross-plugin model/view/service files, PHP notices in plugin fixture code — fix belongs to each plugin repository (owner items per baseline.md).

**SUGGESTION**:
1. Consider adding `LegacyImportRegressionTest` to the documented per-file exclude list (or hardening its server boot against load) in a follow-up plugin-side change — deliberately NOT done here (verification-only contract; phpunit.xml edits would alter the change under verification).
2. The 10-suite-skips breakdown (1 core pre-existing, 1 alias in-suite precondition, 8 plugin-side) could be surfaced in a CI annotation for visibility.
3. Informational for the orchestrator: the session's command-capture harness intermittently mislabeled long `ddev` runs ("Command timed out after 0.9s") while the commands completed inside the container; all reported exit codes/counts were re-captured authoritatively (container-internal logs) — session tooling artifact, not a repo issue.

**Excluded per contract**: untracked `scripts/git-clone-plugins.sh` and `tmp/` are known native-review follow-ups outside this change — not counted.

### Verdict

**PASS WITH WARNINGS**

All 6 requirements and 11/11 scenarios are compliant with runtime evidence on the current checkout: the suite boots deterministically without and with `config.php`, the secret contract holds, the bridge is installed/registered/provably enforcing, Plugins discovery is bounded (exit 0, 669 methods, zero vendored/backup ingestion, no silent loss), legacy skips work in both plugin worlds, and the full suite is green and repeatable (three consecutive runs at 1249 tests / 3084 assertions / 10 skipped, exact match to baseline). The warnings are an environmental flake in a plugin-owned test outside the change's diff and documented process/deviation ledger items — none blocking. Recommended next phase: **archive**.
