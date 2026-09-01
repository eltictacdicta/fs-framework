# Tasks: Test Suite Determinism Remediation (Phase 0)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~100–150 (core files ~80; `composer.lock`/`vendor/` are policy-committed noise, excluded from review) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | 5 work-unit commits (see phases) |
| Delivery strategy | auto-chain |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: stacked-to-main
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Baseline + bootstrap hardening + secret fix | PR 1 | `ddev exec php vendor/bin/phpunit tests/Security/CsrfManagerTest.php` | `ddev exec php vendor/bin/phpunit` (baseline) | revert tests/bootstrap.php + docs; no behavior coupling |
| 2 | Bridge dev dependency + atomic vendor commit | PR 1 (same) | `ddev exec composer install --dry-run` | bridge loads via bootstrap | revert composer.json/lock/vendor together |
| 3 | phpunit.xml Plugins glob rescope | PR 1 (same) | `ddev exec php vendor/bin/phpunit --list-suites` | exit 0 + suite listed | revert phpunit.xml only |
| 4 | Legacy SHA1 test skips | PR 1 (same) | `ddev exec php vendor/bin/phpunit tests/Security/PasswordHasherServiceTest.php` | 5 skips, 0 failures | revert test file only |
| 5 | Final verification | PR 1 | two consecutive full-suite runs | full suite | n/a (verification) |

## Phase 1: Baseline Capture & Bootstrap (R1, R2, R3) — Commit Group 1

- [x] 1.1 Baseline capture (R6): run `ddev exec php vendor/bin/phpunit`, record test/skip/assertion counts, the 5 Security failures, and the `--list-suites` exit 255 into `openspec/changes/audit-remediation-phase-0/baseline.md`. Confirm plugin test distribution (business_data 1, catalogo_core 23, clientes_core 4, legacy_support 2, system_updater 6, system_updater_back 6).
- [x] 1.2 R4 mitigation check: confirm `plugins/system_updater_back/` exists on this checkout and whether its 6 test files would be ingested/excluded after glob rescoping; record verdict in baseline.md.
- [x] 1.3 RED (R2): add `tests/Security/SecretManagerValidationTest.php` asserting `strlen(SecretManager::getSecret()) >= 32` — must FAIL with current 23-char placeholder. Test: `ddev exec php vendor/bin/phpunit tests/Security/SecretManagerValidationTest.php`.
- [x] 1.4 GREEN (R1, R2): rewrite `tests/bootstrap.php` — define all required constants unconditionally (FS_FOLDER, FS_TMP_NAME, FS_DB_*, FS_NF0, FS_SECRET_KEY as deterministic ≥32-char test value, etc.), remove any `require`/`require_once` of `config.php`, keep Composer + `plugins/*/composer_autoload.php` registration. Verify 1.3 turns green.
- [x] 1.5 Verify R1 leakage scenario: with a real `config.php` present locally, run the suite and confirm bootstrap test constants win (spot-check FS_DB_NAME via a temp test or var dump); confirm no config.php reference in bootstrap (`grep -n config.php tests/bootstrap.php` → empty).

## Phase 2: Bridge Dependency (R3) — Commit Group 2 (atomic)

- [x] 2.1 RED (R3): assert `class_exists(\Symfony\Bridge\PhpUnit\SymfonyTestsListener::class)` fails (add to SecretManagerValidationTest or temp check) — confirms bridge absent.
- [x] 2.2 GREEN: `ddev exec composer require --dev symfony/phpunit-bridge:^7.4`.
- [x] 2.3 Register bridge autoload in `tests/bootstrap.php` (before suite run); verify RED from 2.1 turns green.
- [x] 2.4 Prove deprecation reporting functional: run suite with `SYMFONY_DEPRECATIONS_HELPER=weak` (no failures), then a spot run with a strict value showing helper is honored (suite behaves differently). Record in baseline.md.
- [x] 2.5 Commit `composer.json` + `composer.lock` + `vendor/` atomically per project policy (never add `/vendor/` to .gitignore).

## Phase 3: Plugins Glob Rescope (R4) — Commit Group 3

- [x] 3.1 Change `phpunit.xml` Plugins testsuite to `plugins/*/tests` with `suffix="Test.php"` (one level: `plugins/<name>/tests/`), excluding `plugins/*/vendor/` and `plugins/*_back/`.
- [x] 3.2 Verify: `ddev exec php vendor/bin/phpunit --list-suites` exits 0 and lists `Plugins` (was exit 255).
- [x] 3.3 Verify no vendored/backup tests ingested: confirm discovery finds only `plugins/<active-plugin>/tests/*Test.php`; document per-plugin fallback (`-c plugins/<name>/phpunit.xml`) for plugins with tests outside `tests/`.

## Phase 4: Legacy SHA1 Test Skips (R5) — Commit Group 4

- [x] 4.1 In `tests/Security/PasswordHasherServiceTest.php`, guard the 5 legacy SHA1/MD5 tests with a `markTestSkipped()` when `legacy_support`'s `LegacyCompatibility` class is not autoloadable; message must state the `legacy_support` plugin is required.
- [x] 4.2 Verify absent case: `ddev exec php vendor/bin/phpunit tests/Security/PasswordHasherServiceTest.php` → 5 skipped, 0 failed, 0 errored.
- [x] 4.3 Verify present case (R5 second scenario): with `legacy_support` plugin composer bootstrap loaded, confirm the 5 tests run and pass, no skip emitted.

## Phase 5: Final Verification (R6) — Commit Group 5

- [x] 5.1 Run `ddev exec php vendor/bin/phpunit` twice consecutively; both exit 0 with identical counts; results independent of local `config.php`.
- [x] 5.2 Delta check against baseline (R6): net −6 tests (system_updater_back excluded), 5 legacy tests failed→skipped, no other file lost from discovery; any unexplained delta blocks approval.
- [x] 5.3 Scope check: `git diff --name-only` shows only `tests/`, `phpunit.xml`, `composer.json`, `composer.lock`, `vendor/` — no `base/`, `src/`, `model/`, `controller/`.
- [x] 5.4 Commit final verification notes in `baseline.md`; update tasks checkboxes.
