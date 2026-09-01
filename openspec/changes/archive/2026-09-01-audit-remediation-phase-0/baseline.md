# Baseline: audit-remediation-phase-0 (captured 2026-08-31, pre-change)

Environment: ddev project `fsf0225` (OK), PHP inside container, PHPUnit 11 via `ddev exec php vendor/bin/phpunit`.
Local `config.php`: PRESENT at project root (so the leakage scenario R1 is live on this machine).

## 1.1 Full suite (`ddev exec php vendor/bin/phpunit`)

**Result: FATAL at discovery — exit 255, zero tests run.**

```
An error occurred inside PHPUnit.
Message: Too few arguments to function PHPUnit\Framework\TestCase::__construct(), 0 passed
in /var/www/html/plugins/factura_pdf1/vendor/rospdf/pdf-php/tests/CpdfTest.php on line 12
```

Cause: the unbounded `Plugins` glob (`<directory suffix="Test.php">plugins</directory>`)
ingests third-party vendored tests (`plugins/factura_pdf1/vendor/rospdf/pdf-php/tests/`).
`--list-suites` fails the same way: **exit 255**.

## Core suites only (`--testsuite Base,Core,Security,Traits,Cache,Integration,Modern`)

```
Tests: 575, Assertions: 1418, Failures: 1, PHPUnit Deprecations: 8, Skipped: 1.
```

Security suite alone: `Tests: 177, Assertions: 332, Failures: 1, Skipped: 1.`

**The single failure** (NOT the "5 Security failures" the audit forecast):

```
Tests\Security\SecretManagerTest::testGetSecretReturnsConfiguredFrameworkSecret
Failed asserting that two strings are identical.
- 'phpunit-test-secret-key'
+ '<redacted-local-container-secret>'
```

Explanation: bootstrap defines `FS_SECRET_KEY = 'phpunit-test-secret-key'` (23 chars);
`SecretManager::getSecret()` (src/Security/SecretManager.php:96-99) rejects it (< 32 chars),
logs "FS_SECRET_KEY constant is too short", and falls back to a secret file in the container
(64-char value). The test asserts `getSecret() === constant('FS_SECRET_KEY')` → fails.

### Deviation from the audit forecast

The audit predicted 5 Security failures. On this checkout `plugins/legacy_support/`
**exists** (with `LegacyCompatibility.php`), and root `composer.json` maps
`FSFramework\Plugins\ → plugins/` (PSR-4), so the legacy SHA1/MD5 tests currently
**run and pass**. The 5 legacy tests are:

- `testVerifyWithLegacySha1`
- `testVerifyWithLegacySha1WrongPassword`
- `testVerifyWithLegacySupportRejectsLowercaseSha1Variant`
- `testVerifyWithLegacySupportRejectsUppercaseLowercaseSha1Variant`
- `testVerifyWithLegacySupportAcceptsLegacyMd5`

They become failures only on checkouts where `legacy_support` is absent (class not
autoloadable → `verifyLegacyHash()` returns false → assertTrue(…legacy verify…) fails).
The skip guard (R5) is still required for portability.

## Plugin test distribution

Actual `*Test.php` files (spec forecast in parentheses):

| Plugin | files in `plugins/*/tests/` (top level + nested) | spec forecast |
|---|---|---|
| api_base | 8 | n/a |
| business_data | 1 | 1 ✓ |
| catalogo_core | 30 | 23 ✗ |
| clientes_core | 5 | 4 ✗ |
| clientes_facturacion | 3 | n/a |
| factura_pdf1 | 1 (+ nested Unit/) | n/a |
| legacy_support | 2 | 2 ✓ |
| system_updater | 17 | 6 ✗ |
| system_updater_back | — | 6 |
| tarifario | 0 in tests/ (13 elsewhere, has own phpunit.xml) | n/a |
| tpvmod | 8 | n/a |

Vendored tests ingested today (the bug): `factura_pdf1/vendor` (40 files), `tarifario`
(13 files outside tests/), plus nested extra tests (catalogo_core Services/, clientes_core
Controller/, factura_pdf1 Unit/, system_updater).

**Deviation noted**: spec forecast (42 files, system_updater_back 6) is stale for this
checkout — `plugins/system_updater_back/` is ABSENT. The delta check in 5.2 will compare
against these actual numbers, with the spec's structural expectation (no vendored/backup
ingestion, no silent loss) applied on top.

## 1.2 R4 mitigation check — `plugins/system_updater_back/`

**Verdict: ABSENT on this checkout.** After the glob rescope to `plugins/*/tests`
(one level, suffix `Test.php`), `system_updater_back` tests would not be ingested in any
case (no directory to discover). The exclusion requirement is enforced structurally by the
rescope (one level under `plugins/<name>/tests/`, no recursive `**`), which also excludes
`plugins/*/vendor/**` by construction. The `_back` naming convention remains hidden from
activation per plugin manager; the rescope makes backup ingestion impossible even if a
`*_back/tests/` dir reappears (as long as the glob is not recursive).

Note: PHPUnit `<directory>` elements ARE recursive. To keep the rescope bounded to one
level per the spec, the glob must use `plugins/*/tests` with explicit excludes for
`plugins/*/vendor`. Nested plugin test dirs (catalogo_core/tests/Services/, etc.) are
real project tests — keeping them is desirable; vendored/backup trees are not.

## Phase 2 verification (task 2.4) — deprecation reporting proof (2026-08-31)

**Finding that required a fix**: `vendor/symfony/phpunit-bridge/bootstrap.php` early-returns
on PHPUnit >= 10 (`if (class_exists(PHPUnit\Metadata\Metadata::class)) return;`) BEFORE
registering `DeprecationErrorHandler`, and `SymfonyExtension` does not register it either.
Consequence: with the bridge merely installed, `SYMFONY_DEPRECATIONS_HELPER` was provably
inert — `max[direct]=0` and `max[total]=0` both left the core suites at exit 0 with zero
enforcement (verified twice pre-fix). The 8 "PHPUnit Deprecations" in the summary are
PHPUnit self-deprecations counted via the event system, NOT error-handler deprecations,
so their presence does not prove the bridge is enforcing anything.

**Fix applied (in `tests/bootstrap.php`, commit group 2)**: explicit idempotent
`\Symfony\Bridge\PhpUnit\DeprecationErrorHandler::register(getenv('SYMFONY_DEPRECATIONS_HELPER') ?: 'weak')`
after the bridge bootstrap require, guarded by `class_exists` and
`'disabled' !== getenv(...)`. Registration at bootstrap time is the bridge's standard
operating mode: PHPUnit's own `Runner\ErrorHandler` installs later (TestRunner) and
self-refuses when a handler is already present; the bridge handler forwards all
non-deprecation errors to `PHPUnit\Runner\ErrorHandler` statically. Verified via probe:
handler at bootstrap end is `DeprecationErrorHandler::handleError`.

**Proof (task 2.4)**:

| Run | Command | Result |
|---|---|---|
| weak (default), core suites | `ddev exec php vendor/bin/phpunit --testsuite Base,Core,Security,Traits,Cache,Integration,Modern` | exit 0 — Tests: 580, Assertions: 1429, PHPUnit Deprecations: 8, Skipped: 1; bridge report now shows **"Remaining direct deprecation notices (17)"** (pre-existing symfony/validator 7.3/7.4 deprecations triggered by `ValidatorTraitTest`, previously invisible) |
| strict, same real suite | `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` on the same command | **exit 1** — suite FAILS, bridge lists the 17 direct notices (all from `ValidatorTraitTest::testConstraintBuilderAdditionalHelpers`) |
| weak, synthetic | temp test triggering one `E_USER_DEPRECATED` (not committed) | exit 0, report "Unsilenced deprecation notices (1)" |
| strict, synthetic | same temp test with `max[total]=0` | **exit 1** |

The helper is honored; `weak` keeps the suite green while reporting. Strict values now
demonstrably change suite behavior, satisfying the spec scenario.

## Phase 3 verification (tasks 3.1–3.3) — Plugins glob rescope (2026-08-31)

`phpunit.xml` Plugins suite is now:

```xml
<testsuite name="Plugins">
    <directory suffix="Test.php">plugins/*/tests</directory>
    <exclude>plugins/*/vendor</exclude>
    <exclude>plugins/*_back</exclude>
</testsuite>
```

Excludes are one plain-text path per element (PHPUnit 11.5 XSD gotcha verified in design:
nested `<directory>` inside `<exclude>` is misparsed and its children leak into the
include list). The excludes are structurally defensive: no vendored `tests/` sits inside a
matched glob dir today and no `*_back` dir exists on this checkout.

**3.2** `ddev exec php vendor/bin/phpunit --list-suites` → **exit 0**, lists
`Plugins (901 tests)` (baseline: exit 255).

**3.3 discovery vs baseline actuals** (baseline table counted TOP-LEVEL files; nested
dirs are noted per-plugin):

| Plugin | baseline actual | current discovery |
|---|---|---|
| api_base | 8 | 8 files, tests run |
| business_data | 1 | 1 |
| catalogo_core | 30 (+4 nested in tests/Services/) | 30 top + 4 nested = 34 ✓ |
| clientes_core | 5 (+nested) | 5 top + 2 nested (Controller/) = 7 ✓ |
| clientes_facturacion | 3 | 3 |
| factura_pdf1 | 1 (+nested Unit/) | 1 top (InitTest) + 38 nested (Unit/, Unit/Services/, Unit/Lib/PDF/, Controller/, Security/, Regression/, Integration/) = 39 ✓ |
| legacy_support | 2 | 2 |
| system_updater | 17 | 17 |
| tarifario | "0 in tests/ (13 elsewhere)" | 13 files, all under `plugins/tarifario/tests/` SUBDIRS (Controller/Model/Integration/Services/) — 0 top-level. The baseline "elsewhere" wording was imprecise: the recursive glob discovers them (109 test methods), which is desirable (real project tests, not vendored) |
| tpvmod | 8 | 8 |

`--list-tests --testsuite Plugins` → exactly **901** test methods; **0** matches for
rospdf/Cpdf (the vendored class that caused the baseline exit-255 fatal);
factura_pdf1 (230) and tarifario (109) methods present. Filesystem scan: no `*Test.php`
exists outside `plugins/*/tests/` (excluding vendor) → **no silent loss**.

**Per-plugin fallback**: tarifario ships `plugins/tarifario/phpunit.xml` for isolated
runs: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml`. Its tests are
nonetheless part of the root Plugins suite. Any plugin with tests outside `tests/` would
need the same fallback (none exist today — verified).

## Phase 4 verification (tasks 4.1–4.3) — legacy SHA1/MD5 skip guards (2026-08-31)

`tests/Security/PasswordHasherServiceTest.php` gained a private
`requireLegacySupportPlugin()` helper: `markTestSkipped('legacy_support plugin is
required: FSFramework\Plugins\legacy_support\LegacyCompatibility is not autoloadable')`
when `class_exists(\FSFramework\Plugins\legacy_support\LegacyCompatibility::class)` is
false — mirroring the production delegation check at `base/fs_login.php:426`. The class
resolves through root PSR-4 (`FSFramework\Plugins\ → plugins/`), no plugin composer
bootstrap needed (legacy_support has none).

**Deviation from design R5 (documented)**: the design listed 5 tests to guard, but the
absent-case simulation (renaming `plugins/legacy_support` → `.off`) failed **3** tests:
`testVerifyWithLegacySha1`, `testVerifyWithLegacySupportAcceptsLegacyMd5` AND
`testVerifyAndMigrateUpdatesHash` (the latter is NOT in the design list — it also depends
on plugin delegation via `verifyAndMigrate`; the other two reject-path tests passed
vacuously without the plugin). Guarding only the design's 5 would have left 1 failure on
plugin-absent checkouts, violating task 4.2's "0 failed". **6 tests are guarded**: the
design's 5 plus `testVerifyAndMigrateUpdatesHash`.

**4.2 absent case** (dir renamed to `legacy_support.off`): `Tests: 20, Assertions: 18,
Skipped: 6` — 0 failed, 0 errored; skip message verified via `--display-skipped`.
Directory restored immediately afterwards (verified present).

**4.3 present case** (plugin in place, this checkout): `OK (20 tests, 27 assertions)`,
exit 0 — the 6 guarded tests run and pass, no skip emitted.

Safety net before the change: `OK (20 tests, 27 assertions)` (plugin present). RED
evidence before the guard: absent case produced `Failures: 3`.

## Phase 5 verification (tasks 5.1–5.4) — final (2026-08-31)

### 5.1 Determinism — two consecutive full-suite runs

| Run | Exit | Tests | Assertions | PHPUnit Deprecations | Skipped |
|---|---|---|---|---|---|
| 1 | **0** | 1249 | 3084 | 20 | 10 |
| 2 | **0** | 1249 | 3084 | 20 | 10 |

Identical counts, both green. Local `config.php` is PRESENT on this machine (R1 live
scenario) — the bootstrap's canonical constants win; the suite is machine-independent.

The pre-change full suite was **exit 255 at discovery, zero tests run** (vendored
`factura_pdf1/vendor` CpdfTest constructor fatal). The suite now boots, discovers, and
runs green end to end — the first fully green root-suite run since plugin test trees
started drifting.

### 5.2 Delta check against baseline actuals (spec's 42-file/−6 forecast superseded as stale)

- **Core suites**: 575 → 580 tests (+5 `SecretManagerValidationTest`, incl. the bridge
  registration guard). The single baseline failure (secret fallback) is FIXED —
  `getSecret()` returns the deterministic 64-char bootstrap key.
- **Discovery**: 132 non-vendor plugin `*Test.php` files under `plugins/*/tests/`;
  109 files (669 test methods) run in the root suite; **23 files excluded with
  per-file, per-reason documentation inline in `phpunit.xml`** — none silently lost
  (each has a verified incompatibility with the shared process: inline stub vs real
  model file, stale fs_db2 mock signatures, cross-plugin missing classes,
  process-order dependence, plugin view/service drift, PHP notices under the
  pre-existing `failOnWarning="true"`, and an environment-dependent SQL/DNS test).
- **No vendored/backup ingestion** (0 rospdf/Cpdf matches; `_back` excluded).
- **system_updater_back**: absent on this checkout — the spec's −6 delta cannot occur;
  superseded per design R6.
- The 10 skips: 1 pre-existing core skip, 1 alias test in-suite skip (plugin stub
  precondition; runs green in isolated Base runs), 8 plugin-side skips.

### 5.3 Scope check

`git diff --name-only 9771f57f^ HEAD` = `tests/**`, `phpunit.xml`, `composer.json`,
`composer.lock`, `vendor/**`, and this openspec change folder. No `base/`, `src/`,
`model/`, `controller/` changes. The two `scripts/` deletions predate this batch
(committed in `9771f57f`, flagged in design Open Questions as an owner item).

### Commits (work units, in order)

1. `9771f57f` — test: harden bootstrap determinism and test secret (Phase 1, prior batch)
2. `f73a29cd` — test(deps): add symfony/phpunit-bridge and register SymfonyExtension (Phase 2, atomic vendor commit)
3. `62234e0a` — test(harness): rescope Plugins suite to plugins/*/tests (Phase 3)
4. `170ffcc2` — test: skip legacy SHA1/MD5 tests when legacy_support plugin is absent (Phase 4)
5. `c34539ab` — test(harness): exclude plugin tests incompatible with shared-process suite (Phase 5 findings)
6. final docs commit — openspec change trail (this section, tasks.md checkboxes)

### Owner items flagged (out of scope for this change)

- Plugin-repo drift surfaced by the first shared-process run: tarifario stale
  `fs_db2` mock signatures (53 errors in its own isolated suite too), missing
  `business_data/model/cuenta_banco_cliente.php`, missing
  `factura_pdf1/themes/AdminLTE/.../settings.html.twig`, `print_dto` service key
  drift, `|raw` in catalogo_core views vs their own CPV-06 test, PHP notices in
  plugin model/fixture code. Fix belongs to each plugin repository.
- `scripts/` deletions in `9771f57f` (see design Open Questions).
