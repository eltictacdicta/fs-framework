# Testing Patterns

**Analysis Date:** 2026-05-16

## Test Framework

**Runner:**
- PHPUnit 11 (`phpunit/phpunit: ^11`)
- Symfony PHPUnit bridge via `dev-tools`
- Config: `phpunit.xml` (project root)
- Plugin-specific configs: `plugins/clientes_core/phpunit.xml`, `plugins/legacy_support/phpunit.xml`

**Assertion Library:**
- PHPUnit native assertions (`Assert::assertSame`, `Assert::assertTrue`, etc.)

**Run Commands:**
```bash
# Run all tests (requires DDEV)
ddev exec php vendor/bin/phpunit

# Run a specific suite
ddev exec php vendor/bin/phpunit --testsuite Base
ddev exec php vendor/bin/phpunit --testsuite Security
ddev exec php vendor/bin/phpunit --testsuite Plugins

# Run a specific test
ddev exec php vendor/bin/phpunit tests/Base/FsModelMethodsTest.php

# Filter by test name
ddev exec php vendor/bin/phpunit --filter testNoHtmlEscapes

# Run an isolated plugin suite
ddev exec php vendor/bin/phpunit -c plugins/OidcProvider/phpunit.xml

# With coverage
ddev exec php vendor/bin/phpunit --coverage-html coverage/
```

## Test File Organization

**Location:**
- Project root `tests/` — mirrors `src/` and `base/` source structure
- Plugin tests: `plugins/<PluginName>/tests/` (auto-discovered by root `phpunit.xml`)
- Some plugin tests also exist under `tests/<PluginName>/` for core plugins (e.g., `tests/ClientesCore/`)

**Naming:**
- Files: `*Test.php` suffix (e.g., `FsModelMethodsTest.php`, `CacheManagerTest.php`)
- Test classes match filename, under `Tests\` namespace
- Plugin test namespace: `Tests\<PluginName>\`

**Structure:**
```
tests/
├── bootstrap.php              # Defines framework constants, no DB
├── Base/                      # Tests for base/ classes
│   ├── FsModelMethodsTest.php
│   ├── FsCoreLogTest.php
│   ├── FsFunctionsTest.php
│   ├── FsIpFilterTest.php
│   ├── FsQueryBuilderTest.php
│   ├── FsMysqlDefaultNormalizationTest.php
│   └── ...
├── Security/                  # Tests for src/Security/ classes
│   ├── PasswordHasherServiceTest.php
│   ├── CsrfManagerTest.php
│   ├── SessionManagerTest.php
│   ├── SecretManagerTest.php
│   └── ...
├── Traits/                    # Tests for traits
│   └── ValidatorTraitTest.php
├── Cache/                     # Tests for caching
│   └── CacheManagerTest.php
├── Api/                       # Tests for API layer
│   ├── ChainedAuthAdapterTest.php
│   ├── RequestHelperTest.php
│   └── ResourceTransformerTest.php
├── Event/                     # Tests for event system
│   └── EventSystemTest.php
├── Form/                      # Tests for form helpers
│   └── FormHelperTest.php
├── Translation/               # Tests for i18n
│   └── TranslationTest.php
├── Core/                      # Tests for Core/ components
│   ├── PluginControllerDiscoveryTest.php
│   └── ...
└── Components/                # Cross-cutting component tests
    ├── StealthModeTest.php
    └── PublicAccessGateTest.php
```

## Test Bootstrap

`tests/bootstrap.php` initializes tests without a database connection:
1. Loads Composer autoloader
2. Loads `config.php` if present (DDEV environment)
3. Defines all framework constants with test-safe defaults (`FS_DB_TYPE='MYSQL'`, `FS_SECRET_KEY='phpunit-test-secret-key'`, etc.)
4. Initializes `$GLOBALS['plugins']` as empty array
5. Creates `tmp/` directory if missing
6. Loads `fs_model.php` and `fs_model_autoloader.php` via `require_once`
7. Registers the model autoloader

## Test Suites

| Suite | Directory | Coverage Source | What it covers |
|-------|-----------|-----------------|----------------|
| **Base** | `tests/Base/` | `base/` | Core legacy classes (`fs_model`, `fs_core_log`, `fs_functions`, `fs_ip_filter`, `fs_query_builder`, `fs_maintenance_mode`, `install_security`) |
| **Core** | `tests/Core/` | `src/` | Core modern components (`PluginControllerDiscoveryTest`, `AdminHomePageDiscoveryTest`, `InitialSetupFlagTest`, `LoginInitialCredentialsMessageTest`, `MailServiceTest`) |
| **Security** | `tests/Security/` | `src/` | Security components (`PasswordHasherService`, `CsrfManager`, `SessionManager`, `SecretManager`, `CookieSigner`, `EncryptionService`, `LegacyAuthBridge`, `SafeRedirect`) |
| **Traits** | `tests/Traits/` | `src/` | Traits (`ValidatorTrait`, `ConstraintBuilder`) |
| **Cache** | `tests/Cache/` | `src/` | Cache (`CacheManager`, `DataSrcRepository`) |
| **Api** | `tests/Api/` | `src/` | API (`ChainedAuthAdapter`, `RequestHelper`, `ResourceTransformer`) |
| **Plugins** | `plugins/*/tests/**/*Test.php` | plugin-specific | Auto-discovered plugin tests (e.g., `clientes_core`, `legacy_support`, `catalogo_core`) |

**Coverage source** is configured in `phpunit.xml` `<source>` block to track `base/`, `src/`, and `plugins/clientes_core/`.

**Environment:** `SYMFONY_DEPRECATIONS_HELPER=weak` — logs Symfony deprecations without failing tests.

## Test Structure

**Suite Organization — modern services (src/):**
```php
<?php

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use FSFramework\Security\PasswordHasherService;

class PasswordHasherServiceTest extends TestCase
{
    private PasswordHasherService $hasher;

    protected function setUp(): void
    {
        $this->hasher = new PasswordHasherService();
    }

    // =====================================================================
    // Hash & Verify
    // =====================================================================

    public function testHashReturnsNonEmptyString(): void
    {
        $hash = $this->hasher->hash('mi_password');
        $this->assertNotEmpty($hash);
        $this->assertNotSame('mi_password', $hash);
    }

    public function testVerifyCorrectPassword(): void
    {
        $hash = $this->hasher->hash('correct_password');
        $this->assertTrue($this->hasher->verify($hash, 'correct_password'));
    }
}
```

**Suite Organization — legacy classes (base/):**
```php
<?php

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsModelMethodsTest extends TestCase
{
    private object $model;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_model.php';

        // Anonymous subclass with empty constructor (avoids DB connection)
        $this->model = new class() extends \fs_model {
            public function __construct() {} // Skip DB
            public function delete() { return false; }
            public function exists() { return false; }
            public function save() { return false; }
        };
    }

    public function testNoHtmlEscapesAngleBrackets(): void
    {
        $this->assertSame('&lt;script&gt;', $this->model->no_html('<script>'));
    }
}
```

**Resetting static state:**
```php
protected function setUp(): void
{
    // Reset singleton via ::reset() method
    CacheManager::reset();
    $this->cache = CacheManager::getInstance();

    // Reset via Reflection for legacy singletons
    $ref = new \ReflectionClass('fs_core_log');
    $prop = $ref->getProperty('data_log');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}
```

**Setup/teardown patterns:**
- `setUp()`: create fresh instances, reset singletons, clear global state (`$_COOKIE`, `$_SESSION`, `$_FILES`, `$GLOBALS['plugins']`)
- `tearDown()`: clean up files (unlink temp files), reset state, restore globals
- `setUpBeforeClass()`: one-time `require_once` for legacy files (e.g., `fs_functions.php`)

## Assertion Patterns

**Common assertions used:**
- Exact match: `assertSame($expected, $actual)` — preferred over `assertEquals`
- Boolean: `assertTrue()`, `assertFalse()`
- Count: `assertCount($expected, $array)`
- Instance type: `assertInstanceOf(Class::class, $object)`
- Null check: `assertNull()`, `assertNotNull()`
- Array keys: `assertArrayHasKey('key', $array)`, `assertArrayNotHasKey('key', $array)`
- String content: `assertStringContainsString($needle, $haystack)`, `assertStringEndsWith($suffix, $string)`
- Empty: `assertEmpty()`, `assertNotEmpty()`
- JSON: `assertJson($string)`

**Expected exception assertions:**
```php
$this->expectException(\InvalidArgumentException::class);
$adapter = new ChainedAuthAdapter([]);
```

## Mocking

**Framework:** PHPUnit native `createMock()` for interfaces

**Pattern for interface mocks:**
```php
private function createMockAdapter(array $overrides = []): ApiAuthInterface
{
    $mock = $this->createMock(ApiAuthInterface::class);

    foreach ($overrides as $method => $returnValue) {
        $mock->method($method)->willReturn($returnValue);
    }

    return $mock;
}

// Usage:
$primary = $this->createMockAdapter([
    'validateToken' => ['success' => true, 'user' => ['nick' => 'admin']],
]);
```

**Pattern for legacy class mocks (anonymous class extension):**
```php
// Mock fs_db2 for fs_query_builder tests
$mockDb = new class() extends \fs_db2 {
    public function __construct() {} // Skip parent
    public function escape_string($str): string {
        return addslashes($str);
    }
};
$this->qb = new \fs_query_builder($mockDb);
```

**Pattern for model testing without DB:**
```php
// Anonymous subclass of fs_model with empty constructor
$this->model = new class() extends \fs_model {
    public function __construct() {} // Skip DB connection
    public function delete() { return false; }
    public function exists() { return false; }
    public function save() { return false; }
};
```

**What to Mock:**
- Database connection (`fs_db2`) — use anonymous class with minimal methods
- External interfaces (`ApiAuthInterface`) — use `createMock()`
- Global request state (`$_COOKIE`, `$_SESSION`, `$_FILES`, `$_SERVER`, `$GLOBALS['plugins']`) — set directly in test, restore in tearDown

**What NOT to Mock:**
- Framework singletons when `::reset()` is available — reset and get a fresh instance instead
- Value objects and DTOs — instantiate directly
- Symfony Session — use real `Session` with `PhpBridgeSessionStorage`

## Fixtures and Factories

**Test data:** Inline in test methods, no external fixture files.

**Pattern for model fixtures:**
```php
// Define a fixture class at the test file level
class FormModelFixture
{
    public string $nombre = 'Ada';
    public string $email = 'ada@example.com';
    public bool $activo = false;
}

// Or inline test data
$model = new TestModelWithValidation();
$model->nombre = 'Juan';
$model->email = 'juan@example.com';
$model->saldo = 100.50;
```

**Location:** Fixtures are defined inline in test files alongside the test class. No separate `fixtures/` directories.

**Private constants for repeated test data:**
```php
private const TEST_EMAIL = 'test@test.com';
private const SQL_TEST_QUERY = 'SELECT 1';
```

## Coverage

**Requirements:** No enforced coverage threshold. Coverage source directories configured in `phpunit.xml`: `base/`, `src/`, `plugins/clientes_core/`.

**View Coverage:**
```bash
ddev exec php vendor/bin/phpunit --coverage-html coverage/
```

## Test Types

**Unit Tests:**
- Pure logic tests for methods that don't require database (`fs_model::no_html()`, `floatcmp()`, `str2bool()`)
- Service-layer tests with mocked dependencies (`ChainedAuthAdapter`, `CacheManager`)
- Model validation tests with Symfony Validator
- Utility function tests (`bround()`, `fs_fix_html()`, `fs_is_local_ip()`)
- SQL generation tests (`fs_query_builder` with mock DB)
- Scope: isolated classes and methods, no real database, no HTTP requests

**Integration Tests:**
- Session management tests that interact with real PHP sessions (`SessionManager`)
- CSRF token lifecycle tests (generate → validate → refresh → remove)
- Translation loading tests (load YAML/JSON from filesystem)
- Plugin controller discovery tests (scan filesystem)
- Event dispatch with legacy extension integration
- Cache write/read round-trip tests
- Scope: multiple classes collaborating, filesystem interaction, PHP session handling

**E2E Tests:**
- Not used. No browser-based or HTTP request tests detected.

## Common Patterns

**Async Testing:** Not applicable (PHP is synchronous). No async patterns used.

**Error Testing:**
```php
// Expect an exception
$this->expectException(\InvalidArgumentException::class);
$adapter = new ChainedAuthAdapter([]);

// Verify method returns false
$this->assertFalse($this->hasher->verify($hash, 'wrong_password'));

// Check error messages
$model->validate();
$errors = $model->getValidationErrors();
$this->assertArrayHasKey('email', $errors);
```

**State isolation:**
```php
protected function setUp(): void
{
    parent::setUp();
    // Clear global superglobals
    $_COOKIE = [];
    $_SESSION = [];
    $_FILES = [];
    // Reset singletons
    SessionManager::reset();
    FSEventDispatcher::reset();
    LegacyUsageTracker::reset();
}

protected function tearDown(): void
{
    // Restore globals
    $_FILES = [];
    $_COOKIE = [];
    $_SESSION = [];
    // Reset singletons
    SessionManager::reset();
    parent::tearDown();
}
```

**Test method naming convention:**
- Descriptive camelCase with optional `test` prefix: `testNoHtmlEscapesAngleBrackets`, `testVerifyCorrectPassword`, `testSetAndGetItem`
- Pattern: `test{What}{Condition/Scenario}()` — describes action + expected behavior

**Section commenting in test files:**
```php
// =====================================================================
// Hash & Verify
// =====================================================================
```

## Plugin Testing

Plugin tests live in `plugins/<PluginName>/tests/` and are auto-discovered by root `phpunit.xml`:
```xml
<testsuite name="Plugins">
    <directory suffix="Test.php">plugins</directory>
</testsuite>
```

Plugins may also have their own `phpunit.xml` for isolated execution (referencing root `tests/bootstrap.php`):
```xml
<!-- plugins/clientes_core/phpunit.xml -->
<phpunit bootstrap="../../tests/bootstrap.php" ...>
    <testsuites>
        <testsuite name="clientes_core">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>model</directory>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

Plugin tests follow the same patterns: anonymous subclasses for models, require_once for legacy classes, `setUp()` with Reflection for static state reset.

## Test Configurations

**`phpunit.xml` key settings:**
- `bootstrap="tests/bootstrap.php"` — no DB required
- `colors="true"`
- `failOnWarning="true"` — warnings cause test failures
- `failOnRisky="true"` — risky tests cause failures
- `cacheDirectory=".phpunit.cache"`
- `displayDetailsOnTestsThatTriggerDeprecations="true"`
- Coverage source: `base/`, `src/`, `plugins/clientes_core/`

**No data providers** (`@dataProvider` annotations) detected in any test file.

**No test doubles** other than PHPUnit `createMock()` and anonymous classes.

**No code coverage enforcement** (no `requireCoverageMetadata` or coverage threshold).

---

*Testing analysis: 2026-05-16*
