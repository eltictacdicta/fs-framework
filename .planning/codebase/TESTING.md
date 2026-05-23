# Testing Patterns

**Analysis Date:** 2026-05-23 (Api suite removed v0.13.0)

## Test Framework

**Runner:**
- PHPUnit 11 (`phpunit/phpunit: ^11`)
- Config: `phpunit.xml` (project root)
- Symfony PHPUnit Bridge for deprecation handling

**Assertion Library:**
- PHPUnit native assertions (no Hamcrest, Phake, or other external assertion libs)
- `TestCase` extended directly by all test classes

**Run Commands:**
```bash
ddev exec php vendor/bin/phpunit                    # Run all tests
ddev exec php vendor/bin/phpunit --testsuite Base   # Run base/core tests only
ddev exec php vendor/bin/phpunit --testsuite Plugins # Run plugin tests only
ddev exec php vendor/bin/phpunit tests/Base/FsModelMethodsTest.php  # Single test file
ddev exec php vendor/bin/phpunit -c plugins/api_base/phpunit.xml  # Isolated api_base API tests
ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml  # Isolated plugin suite
```

## Test File Organization

**Location:**
- Co-located with source: `tests/` mirrors `src/` and `base/` structure
- Plugin tests: `plugins/{PluginName}/tests/` (auto-discovered by root `phpunit.xml`)

**Naming:**
- `{ClassOrComponent}Test.php` (PascalCase + `Test` suffix)
- Examples: `PasswordHasherServiceTest.php`, `FsModelMethodsTest.php`, `ArticuloModelEncodingTest.php`

**Structure:**
```
tests/
├── bootstrap.php              # Defines framework constants (no DB)
├── Base/                      # Tests for base/ classes (13 test files)
│   ├── FsModelMethodsTest.php # no_html, str2bool, floatcmp, intval
│   ├── FsCoreLogTest.php      # Messages, errors, advices, SQL history, stats
│   ├── FsFunctionsTest.php    # bround, fs_fix_html, fs_is_local_ip
│   ├── FsIpFilterTest.php     # IP ban/whitelist logic
│   ├── FsQueryBuilderTest.php # SQL generation
│   ├── FsUserTest.php
│   ├── FsMaintenanceModeTest.php
│   ├── InstallSecurityTest.php
│   ├── FsMysqlConstraintComparisonTest.php
│   ├── FsMysqlDefaultNormalizationTest.php
│   ├── FsMysqlExecMultiQueryTest.php
│   ├── FsMysqlIdentifierValidationTest.php
│   └── LegacyUsageTrackerTest.php
├── Security/                  # Security component tests (20 test files)
│   ├── PasswordHasherServiceTest.php  # Hash, verify, legacy migration, salt
│   ├── CsrfManagerTest.php           # CSRF token generation and validation
│   ├── SessionManagerTest.php
│   ├── LoginThrottleTest.php
│   ├── LegacyAuthBridgeTest.php
│   ├── SecretManagerTest.php
│   ├── SecurityHeadersTest.php
│   ├── SessionFixationPreventionTest.php
│   ├── ForcePasswordChangeSessionTest.php
│   ├── FsAuthTest.php
│   ├── FsControllerSessionTouchTest.php
│   ├── FsLoginCookieAuthTest.php
│   ├── FsLoginPasswordVerificationTest.php
│   ├── FsLoginSessionInitializationTest.php
│   ├── FsSecretMigratorTest.php
│   ├── LegacyUserServiceTest.php
│   ├── SecurityHelpersTest.php
│   ├── SessionPolicyTest.php
│   ├── SqlInjectionPreventionTest.php
│   └── DebugBarTest.php
├── Core/                      # Core component tests (5 test files)
│   ├── AdminHomePageDiscoveryTest.php
│   ├── InitialSetupFlagTest.php
│   ├── LoginInitialCredentialsMessageTest.php
│   ├── MailServiceTest.php
│   └── PluginControllerDiscoveryTest.php
├── Cache/                     # Cache component tests (2 files)
│   ├── CacheManagerTest.php   # Singleton, set/get/has/delete, callbacks
│   └── DataSrcRepositoryTest.php
├── Traits/                    # Trait tests
│   └── ValidatorTraitTest.php # Attribute validation, ConstraintBuilder
├── Translation/               # Translation tests
├── ClientesCore/              # Client model tests
├── Components/                # Core plugin component tests
├── Event/                     # Event dispatcher tests
└── Form/                      # Form helper tests

plugins/
├── api_base/tests/
│   ├── ResourceTransformerTest.php
│   └── ApiAllowedUserSchemaGuardTest.php
├── business_data/tests/
│   └── BusinessDataModelTest.php   # NEW in v0.10.8
├── catalogo_core/tests/
│   ├── ArticuloModelEncodingTest.php  # NEW in v0.10.8
│   ├── FabricanteModelTest.php        # NEW in v0.10.8
│   └── FamiliaModelTest.php           # NEW in v0.10.8
├── clientes_core/tests/
│   ├── ClienteModelTest.php
│   ├── DireccionClienteModelTest.php
│   └── GrupoClientesModelTest.php
└── legacy_support/tests/
    ├── LegacyCompatibilityTest.php
    └── LegacyUsageTrackerTest.php
```

## Test Structure

**Suite Organization:**
```php
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
    }

    public function testVerifyCorrectPassword(): void
    {
        $hash = $this->hasher->hash('correct_password');
        $this->assertTrue($this->hasher->verify($hash, 'correct_password'));
    }
}
```

**Patterns:**
- Setup: `setUp()` method initializes fresh instances (also resets singletons via `::reset()`)
- Teardown: Typically not needed (stateless tests); singletons reset in next `setUp()`
- Assertion: Simple PHPUnit assertions: `assertTrue()`, `assertFalse()`, `assertSame()`, `assertNotEmpty()`
- Section dividers: `// =====================================` style for grouping related tests
- One assertion concept per test method (generally followed)
- Test method naming: `test{What}{Condition}()` pattern (e.g., `testHashReturnsNonEmptyString`)

## Mocking

**Framework:** PHPUnit native mock/stub support + anonymous classes for test doubles

**Patterns:**
```php
// Anonymous subclass to avoid DB connection (most common pattern)
$this->model = new class() extends \fs_model {
    public function __construct() {
        // No llamar al constructor padre — evita DB/cache
    }
    public function delete() { return false; }
    public function exists() { return false; }
    public function save() { return false; }
};

// Anonymous mock for fs_db2 query builder
$mockDb = new class {
    public function escape_string(string $str): string {
        return addslashes($str);
    }
};
$qb = new \fs_query_builder($mockDb);
```

**What to Mock:**
- Database connections (always — tests use `FS_DB_TYPE` constant but no real connection)
- External services and HTTP clients
- Filesystem operations where behavior must be controlled

**What NOT to Mock:**
- Pure functions and utility methods (test directly)
- Symfony services (test their integration — many tests create real instances)

**Singleton Reset Pattern:**
```php
// Reset static state in setUp()
$ref = new \ReflectionClass('fs_core_log');
$prop = $ref->getProperty('data_log');
$prop->setAccessible(true);
$prop->setValue(null, null);

// Or for CacheManager
\FSFramework\Cache\CacheManager::reset();
```

## Fixtures and Factories

**Test Data:**
- Inline test data (no separate fixture files or database seeds)
- Constants defined in `tests/bootstrap.php` provide minimal configuration
- Example: `define('FS_SECRET_KEY', 'phpunit-test-secret-key')`
- Data arrays created directly in test methods

**Location:**
- No dedicated `fixtures/` directory
- Test data lives inline in test methods
- Bootstrap defines environment constants only

## Coverage

**Requirements:** None enforced (no `--coverage-clover` or coverage thresholds in `phpunit.xml`)

**Source directories configured:**
- `base/` — Legacy framework classes
- `src/` — Modern Symfony-based code
- `plugins/clientes_core/` — Core plugin code

**View Coverage:**
```bash
ddev exec php vendor/bin/phpunit --coverage-text
ddev exec php vendor/bin/phpunit --coverage-html coverage/
```

## Test Types

**Unit Tests:**
- Primary test type — tests individual classes in isolation
- Base classes tested via anonymous subclasses to avoid DB
- Security services tested with real constructor (no external deps)
- Cache tests: reset singleton in `setUp()`

**Integration Tests:**
- Plugin tests that exercise model-to-DB interactions (some require DB)
- Isolated plugin suites (e.g., `clientes_core/phpunit.xml`) for plugin-specific testing
- MailServiceTest tests PHPMailer integration

**E2E Tests:**
- Not used (no Selenium, Playwright, or browser automation)
- Manual browser testing via ddev web access

## Common Patterns

**Async Testing:**
- Not applicable (PHP is synchronous, no async features used)

**Error Testing:**
```php
// Test that error messages are logged
public function testValidationReturnsFalseForInvalidData(): void
{
    $model = new class() extends \fs_model { /* ... */ };
    $model->invalid_field = 'bad data';
    
    $result = $model->test();
    $this->assertFalse($result);
    
    $errors = $model->get_errors();
    $this->assertNotEmpty($errors);
}

// Test that exception is thrown
public function testThrowsException(): void
{
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/class not found/');
    // code that throws
}
```

**Static State Isolation:**
```php
protected function setUp(): void
{
    // Reset singleton state before each test
    \FSFramework\Event\FSEventDispatcher::reset();
    \FSFramework\Cache\CacheManager::reset();
}
```

**Data Providers:**
- Not commonly used in this codebase — most tests have focused single-scenario methods
- Available via standard `@dataProvider` annotation when needed

## Test Suites

| Suite | Dir | Covers |
|-------|-----|--------|
| **Base** | `tests/Base/` | Core classes in `base/` (fs_model, fs_core_log, fs_functions, fs_ip_filter, fs_query_builder, fs_user, maintenance, install security) |
| **Security** | `tests/Security/` | All `src/Security/` components (20 files — largest suite) |
| **Core** | `tests/Core/` | Kernel-adjacent modules (page discovery, initial setup, mail, plugin controller discovery) |
| **Cache** | `tests/Cache/` | CacheManager and DataSrcRepository |
| **Traits** | `tests/Traits/` | ValidatorTrait attribute and constraint testing |
| **Plugins** | `plugins/*/tests/**/*Test.php` | Auto-discovered plugin tests (business_data, catalogo_core, clientes_core, api_base, legacy_support) |

API tests live in `plugins/api_base/tests/` — run via **Plugins** suite or `ddev exec php vendor/bin/phpunit -c plugins/api_base/phpunit.xml`.

---

*Testing analysis: 2026-05-16*
