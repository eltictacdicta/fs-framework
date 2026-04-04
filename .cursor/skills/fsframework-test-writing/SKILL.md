---
name: fsframework-test-writing
description: >-
  Write PHPUnit 11 tests for FSFramework following project conventions: anonymous
  subclasses for fs_model, mock fs_db2, static state reset, plugin test isolation,
  and DDEV execution. Use when writing tests, adding test coverage, creating test
  files, or when the user asks to test a class or feature.
---

# FSFramework Test Writing

## Quick Reference

| What | Where |
|------|-------|
| Core tests | `tests/` |
| Plugin tests | `plugins/<Name>/tests/` |
| Run all | `ddev exec php vendor/bin/phpunit` |
| Run plugin | `ddev exec php vendor/bin/phpunit -c plugins/<Name>/phpunit.xml` |
| Run one test | `ddev exec php vendor/bin/phpunit --filter TestName` |
| Bootstrap | `tests/bootstrap.php` |

## Test File Template

```php
<?php

declare(strict_types=1);

namespace Tests\MiSuite;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(MiClase::class)]
class MiClaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset state here
    }

    #[Test]
    public function happyPathWorks(): void
    {
        // Arrange → Act → Assert
    }

    #[Test]
    public function invalidInputFails(): void
    {
        // Edge case
    }

    #[Test]
    #[DataProvider('provideValues')]
    public function handlesMultipleInputs(string $input, bool $expected): void
    {
        // Parametric test
    }

    public static function provideValues(): array
    {
        return [
            'valid'   => ['hello', true],
            'empty'   => ['', false],
            'html'    => ['<script>', false],
        ];
    }
}
```

## Mocking Patterns

### fs_model Without DB

Legacy models require DB in the constructor. Use an anonymous subclass:

```php
$model = new class() extends \fs_model {
    public function __construct() {}
    public function delete() { return false; }
    public function exists() { return false; }
    public function save() { return false; }
};

// Now test pure methods
$this->assertEquals('test', $model->no_html('test'));
$this->assertTrue($model->str2bool('true'));
```

### fs_model with Data

```php
private function createModel(array $overrides = []): \mi_modelo
{
    $defaults = ['id' => 1, 'nombre' => 'Test', 'email' => 'test@example.com'];
    $data = array_merge($defaults, $overrides);

    return new class($data) extends \mi_modelo {
        public function __construct($data = false)
        {
            if ($data) {
                foreach ($data as $k => $v) {
                    if (property_exists($this, $k)) {
                        $this->$k = $v;
                    }
                }
            }
        }
        public function delete() { return false; }
        public function exists() { return false; }
        public function save() { return $this->test(); }
    };
}
```

### Query Builder with Mock DB

```php
$mockDb = new class {
    public function escape_string(string $str): string
    {
        return addslashes($str);
    }
};
$qb = new \fs_query_builder($mockDb);
```

### Reset Static State

For singletons or static classes (fs_core_log, CacheManager):

```php
protected function setUp(): void
{
    parent::setUp();

    // Reset fs_core_log
    $ref = new \ReflectionClass('fs_core_log');
    $prop = $ref->getProperty('data_log');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}
```

```php
protected function setUp(): void
{
    parent::setUp();

    // Reset CacheManager singleton
    \FSFramework\Cache\CacheManager::reset();
}
```

### Reset FSEventDispatcher

```php
protected function setUp(): void
{
    parent::setUp();

    $ref = new \ReflectionClass(\FSFramework\Event\FSEventDispatcher::class);
    $prop = $ref->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}
```

## Loading Non-Autoloaded Classes

Classes in `base/` and `model/` are not PSR-4 autoloaded:

```php
require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/plugins/MiPlugin/model/mi_modelo.php';
```

`FS_FOLDER` is defined in `tests/bootstrap.php`.

## Plugin Test phpunit.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="../../vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="../../tests/bootstrap.php"
         colors="true"
         cacheDirectory="../../.phpunit.cache">
    <testsuites>
        <testsuite name="MiPlugin">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>model</directory>
            <directory>Controller</directory>
            <directory>Service</directory>
        </include>
    </source>
    <php>
        <env name="SYMFONY_DEPRECATIONS_HELPER" value="weak"/>
    </php>
</phpunit>
```

## Coverage Requirements

Every new class must have tests covering:

1. **Happy path** — normal input produces correct output
2. **Error/edge cases** — invalid input, empty strings, null, zero, boundary values
3. **Security** — HTML sanitization works, SQL escaping is used

## Test Naming Convention

Use descriptive method names that explain the scenario:

```php
public function testValidClientPassesValidation(): void {}
public function testEmptyNameFailsValidation(): void {}
public function testHtmlTagsAreSanitized(): void {}
public function testDuplicateClientIdIsRejected(): void {}
```

Or use `#[Test]` attribute with camelCase:

```php
#[Test]
public function validClientPassesValidation(): void {}
```

## Running Tests

```bash
# All tests
ddev exec php vendor/bin/phpunit

# Single suite
ddev exec php vendor/bin/phpunit --testsuite Base

# Plugin suite (isolated)
ddev exec php vendor/bin/phpunit -c plugins/MiPlugin/phpunit.xml

# Specific test method
ddev exec php vendor/bin/phpunit --filter testValidClientPassesValidation

# With coverage
ddev exec php vendor/bin/phpunit --coverage-html coverage/
```
