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
+ 'a79fa967d030b4cb71026d6c33dd54e26011b1fbbd8cf11ecdddfb33520616c7'
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
