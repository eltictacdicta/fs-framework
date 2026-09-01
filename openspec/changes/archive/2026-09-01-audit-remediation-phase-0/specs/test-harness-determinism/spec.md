# Delta Spec: Test Harness Determinism

## ADDED Requirements

### Requirement: Test bootstrap defines constants unconditionally without loading config.php

The test bootstrap (`tests/bootstrap.php`) MUST define every required framework constant unconditionally and MUST NOT load the application-local `config.php` under any circumstance, so that the suite runs identically on any machine regardless of whether a developer has a local `config.php` present.

The bootstrap MUST still register the Composer autoloader and plugin composer bootstraps (`plugins/*/composer_autoload.php`), as those are part of the test environment, not machine-local configuration.

#### Scenario: Suite runs on a machine without config.php

- **Given** a checkout of the repository with no `config.php` file at the project root
- **When** `ddev exec php vendor/bin/phpunit` is executed
- **Then** the suite boots without a "failed opening required config.php" error or missing-constant fatal
- **And** all constants consumed by tested classes (e.g. `FS_FOLDER`, `FS_TMP_NAME`, `FS_DB_*`, `FS_SECRET_KEY`, `FS_NF0`) are defined from the bootstrap's canonical test values

#### Scenario: Local config.php values never leak into tests

- **Given** a developer machine where a real `config.php` exists with production-like values (different `FS_DB_NAME`, `FS_SECRET_KEY`, `FS_NF0`, etc.)
- **When** the test suite is executed
- **Then** tests observe the bootstrap's canonical test constants, not the local config values
- **And** `tests/bootstrap.php` contains no `require`/`require_once` of `config.php`

### Requirement: Test FS_SECRET_KEY satisfies SecretManager validation

The bootstrap-defined `FS_SECRET_KEY` MUST be a string of at least 32 characters, so that `SecretManager::getSecret()` (which returns `null` and logs an error for shorter values) yields a valid secret during tests instead of silently degrading.

#### Scenario: SecretManager returns a valid secret in the test environment

- **Given** the test bootstrap has defined `FS_SECRET_KEY`
- **When** `SecretManager::getSecret()` is called in a unit test
- **Then** it returns a non-null string of length >= 32
- **And** no "FS_SECRET_KEY constant is too short" error is logged

#### Scenario: Secret-dependent components initialize without secret fallback

- **Given** the current placeholder `'phpunit-test-secret-key'` (23 chars) is replaced by a >=32-char deterministic test constant
- **When** tests exercise secret-derived services (e.g. `SecretManager::hmac()`, signed-cookie flows)
- **Then** they operate on the valid test secret rather than a `null` secret path

### Requirement: Symfony PHPUnit bridge is installed, autoloaded, and enforcing

The `symfony/phpunit-bridge` package MUST be installed as a Composer dev dependency (with `composer.json`, `composer.lock`, and `vendor/` committed together per project policy), registered in the test bootstrap autoload, and its deprecation reporting active — meaning `SYMFONY_DEPRECATIONS_HELPER` (currently `weak`) is honored by the bridge, not ignored because no bridge is loaded.

#### Scenario: Bridge present and registered

- **Given** a fresh clone with `vendor/` committed
- **When** `ddev exec composer install --dry-run` runs and `tests/bootstrap.php` is loaded
- **Then** `symfony/phpunit-bridge` is resolvable as an installed dev dependency
- **And** the bridge's autoload (e.g. `Symfony\Bridge\PhpUnit\SymfonyTestsListener` / bootstrap script) is registered from the test bootstrap before the suite runs

#### Scenario: Deprecation reporting is functional

- **Given** `phpunit.xml` sets `SYMFONY_DEPRECATIONS_HELPER=weak` and `displayDetailsOnTestsThatTriggerDeprecations="true"`
- **When** the suite runs and a test triggers a Symfony deprecation
- **Then** the deprecation is detected and reported by the bridge (logged/displayed), without failing the suite while `weak` mode is retained
- **And** changing `SYMFONY_DEPRECATIONS_HELPER` to a strict value demonstrably changes suite behavior (proving the helper is honored)

### Requirement: Plugins suite uses bounded deterministic discovery

The `Plugins` testsuite in `phpunit.xml` MUST discover only files matching `plugins/*/tests/**/*Test.php` (one directory level: `plugins/<name>/tests/`), excluding vendored trees (`plugins/*/vendor/`) and backup directories (`plugins/*_back/`). The suite definition MUST be valid such that `ddev exec php vendor/bin/phpunit --list-suites` exits 0.

#### Scenario: list-suites exits successfully

- **Given** the repository with plugins present (including vendored plugin vendors and any `*_back` directories)
- **When** `ddev exec php vendor/bin/phpunit --list-suites` is executed
- **Then** the command exits with code 0
- **And** the `Plugins` suite is listed

#### Scenario: Vendored and backup tests are not ingested

- **Given** plugin trees that contain third-party `vendor/*/tests/*Test.php` files and backup directories like `plugins/<name>_back/tests/`
- **When** the Plugins suite runs
- **Then** no test files located under `plugins/*/vendor/` or `plugins/*_back/` are executed
- **And** only `plugins/<active-plugin>/tests/*Test.php` files are discovered

#### Scenario: Plugins without a tests directory do not break discovery

- **Given** active plugins that have no `tests/` directory at all
- **When** the Plugins suite runs
- **Then** those plugins contribute zero test files without errors
- **And** per-plugin isolated suites (`-c plugins/<name>/phpunit.xml`) remain the documented fallback for plugins with tests outside `tests/`

### Requirement: Legacy SHA1 password tests skip cleanly without legacy_support

Legacy SHA1/MD5 password tests in `tests/Security/PasswordHasherServiceTest.php` that exercise `verifyWithLegacySupport` behavior aligned with the `legacy_support` plugin delegation (`base/fs_login.php` delegates legacy verification to `FSFramework\Plugins\legacy_support\LegacyCompatibility` when the class exists) MUST be skipped with a clear, explicit skip message when the `legacy_support` plugin is not present/active, and MUST run when it is.

#### Scenario: Legacy tests skip when legacy_support is absent

- **Given** the `legacy_support` plugin is not installed/active (its `LegacyCompatibility` class is not autoloadable)
- **When** the Security suite runs
- **Then** the affected legacy SHA1/MD5 tests are reported as skipped (not failed, not errored)
- **And** each skip message clearly states the `legacy_support` plugin is required

#### Scenario: Legacy tests run when legacy_support is present

- **Given** the `legacy_support` plugin is installed and its composer bootstrap is loaded by the test bootstrap
- **When** the Security suite runs
- **Then** the legacy SHA1/MD5 tests execute and pass
- **And** no skip is emitted for them

### Requirement: Verification procedure proves green, repeatable, and non-lossy suite

The change MUST be verified by: (1) a full suite run before the change capturing test counts and failures, (2) a full suite run after the change, (3) a repeat run confirming identical results, and (4) a test-count comparison demonstrating that the scoped Plugins glob does not silently drop plugin tests (baseline: 42 plugin test files across `business_data` 1, `catalogo_core` 23, `clientes_core` 4, `legacy_support` 2, `system_updater` 6, `system_updater_back` 6 — the last excluded by design).

#### Scenario: Full suite is green and repeatable

- **Given** all harness changes applied
- **When** `ddev exec php vendor/bin/phpunit` is executed twice in succession
- **Then** both runs exit 0 with identical test/skip/assertion counts
- **And** the result does not depend on the presence or content of a local `config.php`

#### Scenario: Test-count comparison proves no silent loss

- **Given** the pre-change captured test counts (including the expected 42 plugin test files, of which the 6 `system_updater_back` files are intentionally excluded)
- **When** the post-change suite run is compared against the baseline
- **Then** the expected net delta is exactly: −6 tests (`system_updater_back` excluded by design) and the 5 legacy SHA1 tests move from failed/errored to skipped
- **And** no other plugin or core test file is missing from discovery; any unexplained delta blocks approval

## ADDED Requirements (Verification Commands)

The verification procedure MUST use these exact commands:

- Baseline capture: `ddev exec php vendor/bin/phpunit` (record counts before changes)
- Suite validity: `ddev exec php vendor/bin/phpunit --list-suites` (must exit 0)
- Repeat determinism: two consecutive full-suite runs (must match)
- Secret validity: a test or script asserting `strlen(SecretManager::getSecret()) >= 32`
