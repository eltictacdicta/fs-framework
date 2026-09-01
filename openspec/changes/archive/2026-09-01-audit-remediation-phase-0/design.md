# Design: Test Suite Determinism Remediation (Audit Phase 0)

## Technical Approach

Formalize the harness fixes already applied (Phase 1 committed as `9771f57f`; Phase 2 in-flight uncommitted) and specify the remaining work (R4 glob rescope, R5 skip guards, R6 verification). Everything lives in `tests/`, `phpunit.xml`, `composer.json`/`composer.lock`/`vendor/`, and this change folder. `base/`, `src/`, `model/`, `controller/` are untouched. All PHP/Composer/PHPUnit commands run via DDEV: `ddev exec php vendor/bin/phpunit`, `ddev exec composer ...`.

## Architecture Decisions

### R1 — Bootstrap defines constants unconditionally, never loads config.php

**Choice**: `tests/bootstrap.php` (committed, `9771f57f`) defines every framework constant (`FS_FOLDER`, `FS_TMP_NAME`, `FS_DB_*`, `FS_NF0`, …) with canonical test values via unconditional `define()`; no `require` of `config.php`; Composer autoloader + `plugins/*/composer_autoload.php` glob retained; `tmp/FS_TMP_NAME` ensured; `fs_model_autoloader::register()` kept.
**Alternatives**: loading `config.php` with defaults (`??` merge) — rejected: machine-local values leak, the audit's exact complaint; env-var-driven config — rejected: overkill for a unit suite.
**Rationale**: constants are cheap, deterministic, and identical on every machine; plugin composer bootstraps are test-environment (not machine config) per spec R1.
**Risk**: a future constant needed by tested code but missing from bootstrap → fatal with clear "Undefined constant" message; mitigation: add it to the bootstrap canonical list (documented convention).

### R2 — Deterministic test secret satisfying SecretManager

**Choice**: `FS_SECRET_KEY` = fixed 64-hex-char literal; `tests/Security/SecretManagerValidationTest.php` guards the contract (constant defined, `>= 32` chars, `SecretManager::getSecret()` returns exactly it, `hmac()` works) — all green at `9771f57f`.
**Alternatives**: random per-run secret — rejected: breaks determinism and any reproducible signed-URL/cookie fixture; reusing the production file-secret fallback — rejected: `getSecret()` returning a container-local file value is the nondeterminism being removed.
**Rationale**: `SecretManager::getSecret()` (`src/Security/SecretManager.php:96-99`) rejects `< 32` chars and silently falls back; a valid literal kills the fallback path. Test-only value, no production exposure.

### R3 — Bridge as real dev dependency, PHPUnit 11 extension API

**Choice**: `symfony/phpunit-bridge:^7.4` in `require-dev`; bridge bootstrap `require_once`d in `tests/bootstrap.php` right after the Composer autoloader; `Symfony\Bridge\PhpUnit\SymfonyExtension` registered in `phpunit.xml` `<extensions>`. `SYMFONY_DEPRECATIONS_HELPER=weak` stays in `phpunit.xml`.
**Alternatives**: legacy `SymfonyTestsListener` — rejected: PHPUnit ≥ 10 removed the listener API; it does not apply on PHPUnit 11 (task 2.1's RED check was adjusted to `testSymfonyPhpunitBridgeIsRegistered`, asserting `Extension` interface + `SymfonyExtension` class + instanceof). Skipping the bridge — rejected: audit found deprecation reporting currently decorative.
**Rationale**: extension API is the supported PHPUnit 11 integration; the bootstrap require makes bridge classes autoloadable for the guard test; weak mode keeps the current 8 PHPUnit deprecations non-fatal. Prove enforcement per task 2.4: strict spot run (e.g. `SYMFONY_DEPRECATIONS_HELPER=max[total]=0`) changes suite behavior — record in baseline.md.
**Risk**: bridge later promoting deprecations to failures → gated behind explicit helper change, deliberate act.

### R4 — Plugins glob rescope: `plugins/*/tests` + plain-text excludes

**Choice**: in `phpunit.xml`, replace `<directory suffix="Test.php">plugins</directory>` with:

```xml
<testsuite name="Plugins">
    <directory suffix="Test.php">plugins/*/tests</directory>
    <exclude>plugins/*/vendor</exclude>
    <exclude>plugins/*_back</exclude>
</testsuite>
```

**Alternatives**: enumerating each plugin dir explicitly — rejected: requires editing phpunit.xml whenever plugins are added/removed; recursive `plugins` with substring excludes — rejected: reintroduces the bug; `exclude><directory>` nesting — **rejected and verified harmful** (see Rationale).
**Rationale** (all empirically verified on PHPUnit 11.5.56, this checkout):
- The wildcard glob works: `plugins/*/tests` discovers **901 tests**, `--list-suites` exits 0.
- PHPUnit `<directory>` IS recursive *within each matched dir*: nested real tests (`catalogo_core/tests/Services/`, `clientes_core/tests/Controller/`, `factura_pdf1/tests/Unit/` etc.) are intentionally retained.
- **Critical gotcha**: inside `<testsuite>`, the XSD declares `<exclude>` as plain `xs:string`. Writing `<exclude><directory>…</directory></exclude>` (the `<source>`-section style) is misparsed by the XML loader: it reads the `<exclude>` node's `textContent` as a *file* exclude (garbage path → silently no-op) AND its descendant-search `getElementsByTagName('directory')` pulls the exclude's `<directory>` children into the **include** list — reintroducing vendored-test ingestion (`CpdfTest` constructor fatal, exit 255). Excludes MUST be one plain-text path per `<exclude>` element.
- Negative control: `<exclude>plugins/tpvmod/tests</exclude>` drops discovery 901 → 827, proving plain-text excludes are honored (prefix match by `ExcludeIterator`).
- `plugins/*/vendor` is structurally unreachable today (sibling of `tests/`, not nested inside), so the exclude is defensive; `plugins/*_back` exclude is the structural guard if a backup dir with `tests/` reappears (none exists — `system_updater_back` is absent on this checkout).
**Risk**: plugin with tests outside `tests/` (today: `tarifario`, 13 files elsewhere, has own `phpunit.xml`) silently drops out of the Plugins suite — accepted by spec, documented fallback `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml`; recorded as an intentional delta in R6.

### R5 — Legacy SHA1/MD5 tests skip without legacy_support

**Choice**: guard the 5 legacy tests (`testVerifyWithLegacySha1`, `testVerifyWithLegacySha1WrongPassword`, `testVerifyWithLegacySupportRejectsLowercaseSha1Variant`, `testVerifyWithLegacySupportRejectsUppercaseLowercaseSha1Variant`, `testVerifyWithLegacySupportAcceptsLegacyMd5`) in `tests/Security/PasswordHasherServiceTest.php` with `markTestSkipped('legacy_support plugin is required …')` when `class_exists(\FSFramework\Plugins\legacy_support\LegacyCompatibility::class)` is false.
**Alternatives**: hard dependency on the plugin — rejected: breaks portability of the core suite; splitting tests into a plugin repo — rejected: they guard `PasswordHasherService::verifyWithLegacySupport()` core behavior.
**Rationale**: mirrors the production delegation check at `base/fs_login.php:426` (`class_exists('FSFramework\\Plugins\\legacy_support\\LegacyCompatibility')`); the class resolves through root PSR-4 (`FSFramework\Plugins\` → `plugins/`), so `class_exists` is the single truth. **On this checkout the plugin is present, so the 5 tests run and pass and emit no skip** — the audit's "5 Security failures" forecast only materializes on checkouts without the plugin. Absent-case verification here requires simulation: temporarily rename `plugins/legacy_support` → `legacy_support.off`, run the file (expect 5 skipped, 0 failed), rename back.

### R6 — Verification anchored to baseline.md actuals

**Choice**: (1) `--list-suites` exit 0 with `Plugins` listed; (2) two consecutive full-suite runs, both exit 0, identical counts; (3) delta check against baseline.md actuals — per-plugin `*Test.php` files: api_base 8, business_data 1, catalogo_core 30, clientes_core 5, clientes_facturacion 3, factura_pdf1 1+nested, legacy_support 2, system_updater 17, tpvmod 8, tarifario 0-in-tests; (4) scope check `git diff --name-only` limited to allowed paths.
**Alternatives**: the delta spec's original forecast (42 files, net −6 via `system_updater_back`) — **superseded as stale**: `plugins/system_updater_back/` is ABSENT; the −6 expectation cannot be computed on this checkout.
**Rationale**: baseline.md actuals are the only trustworthy pre-change anchor. Intentional deltas: `factura_pdf1/vendor` 40 vendored files stop being ingested (bug fixed); tarifario's 13 out-of-tests files move to documented per-plugin fallback; 5 legacy tests pass here (skip path proven only by R5 simulation). Any other delta blocks approval.
**Risk**: none beyond watch-count discipline.

## Data Flow

```
phpunit.xml ──bootstrap──▶ tests/bootstrap.php
                             ├─ vendor/autoload.php
                             ├─ vendor/symfony/phpunit-bridge/bootstrap.php   (R3)
                             ├─ plugins/*/composer_autoload.php               (only factura_pdf1, tpvmod; legacy_support resolves via root PSR-4)
                             ├─ unconditional define()s (R1) + FS_SECRET_KEY  (R2)
                             └─ base/fs_model_autoloader
testsuites ──▶ core suites + Plugins: plugins/*/tests (recursive) − excludes (R4)
PasswordHasherServiceTest ──▶ class_exists(LegacyCompatibility) ? run : skip (R5)
```

## File Changes

| File | Action | Group | Description |
|------|--------|-------|-------------|
| `tests/bootstrap.php` | Modified | 1 (committed) + 2 (bridge require, uncommitted) | R1/R2 constants; bridge bootstrap |
| `tests/Security/SecretManagerValidationTest.php` | Created | 1 + 2 (bridge test, uncommitted) | R2 contract guard; R3 registration guard |
| `composer.json`, `composer.lock`, `vendor/**` | Modified | 2 | bridge dev dep; atomic commit per policy |
| `phpunit.xml` | Modified | 2 (extensions) + 3 (suite rescope) | R3 extension; R4 glob + plain-text excludes |
| `tests/Security/PasswordHasherServiceTest.php` | Modified | 4 | R5 skip guards |
| `baseline.md`, `tasks.md` | Updated | 5 | verification notes, checkbox updates |

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Unit (R2) | secret contract | `SecretManagerValidationTest` (done, green) |
| Unit (R3) | bridge registered | `testSymfonyPhpunitBridgeIsRegistered` (done, green) |
| Harness (R4) | bounded discovery | `--list-suites` exit 0; 901-test Plugins discovery; no `/vendor/` files in `--list-tests` |
| Unit (R5) | skip vs run | file run with plugin present (pass, no skip) and with dir renamed (5 skipped) |
| Full (R6) | determinism | two identical green runs; delta vs baseline.md actuals; scope check |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary is introduced; only test-harness configuration changes.

## Migration / Rollout

No data or production migration. Rollback = revert the offending commit group (group 2 reverts `composer.json`+`composer.lock`+`vendor/` atomically; group 3 reverts `phpunit.xml` alone; group 4 reverts the test file alone).

## Open Questions

- None blocking. **Flag for the owner (out of this change's scope)**: committed group 1 (`9771f57f`) also deleted `scripts/check-stealth-oidc-flow.sh` and `scripts/extract-core-plugins-to-repos.sh` — outside the change's scope guard. It is already in history; restoring would itself violate the scope guard, so it is recorded here and left to the owner. Also, baseline.md §1.2 self-contradiction ("one level, no recursive `**`" vs "`<directory>` IS recursive") is resolved by this design: rescope = recursive-within-`tests/` plus structural plain-text excludes.
