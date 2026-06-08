---
name: fsframework-plugin-scaffold
description: >-
  Scaffold a complete FSFramework plugin with all required files: fsframework.ini,
  Init.php, models, controllers, views, translations, tests, services.php, and XML
  schemas. Use when creating a new plugin, bootstrapping a plugin skeleton, or when
  the user asks to add a new module to the framework.
---

# FSFramework Plugin Scaffold

## Workflow

Copy this checklist and track progress:

```
Plugin Scaffold:
- [ ] Step 1: Gather requirements (name, description, dependencies)
- [ ] Step 2: Create directory structure
- [ ] Step 3: Create fsframework.ini
- [ ] Step 4: Create Init.php (register event listeners, delegate to core)
- [ ] Step 5: Create model(s) with XML schema
- [ ] Step 6: Create controller(s)
- [ ] Step 7: Create view template(s)
- [ ] Step 8: Create translations
- [ ] Step 9: Create config/services.php (if needed)
- [ ] Step 10: Use CacheManager for expensive or frequent data
- [ ] Step 11: Create tests
- [ ] Step 12: Create phpunit.xml for isolated execution
- [ ] Step 13: Run security audit (fsframework-security-review)
```

## Step 1: Gather Requirements

Before scaffolding, determine:
- **Plugin name**: snake_case for legacy, PascalCase for modern (e.g., `mi_plugin` or `MiPlugin`)
- **Description**: one-line summary
- **Dependencies**: other plugins required (comma-separated in `require`)
- **Models**: tables and fields needed
- **Controllers**: admin pages, API endpoints, or public routes
- **Modern vs legacy**: prefer `Controller/` + `Model/` (PSR-4) for new code

## Step 2: Directory Structure

```bash
mkdir -p plugins/NombrePlugin/{controller,Controller,model/table,view,translations,tests,config}
```

Minimal structure for a modern plugin:

```
plugins/NombrePlugin/
├── fsframework.ini
├── Init.php
├── config/services.php
├── Controller/
├── model/
│   └── table/
├── view/
├── translations/
│   ├── messages.es.yaml
│   └── messages.en.yaml
└── tests/
```

## Step 3: fsframework.ini

```ini
version = 1
description = "Descripción del plugin"
min_version = "0.4"
author = "Nombre del Autor"
author_url = "https://example.com"
require = ""
```

## Step 4: Init.php

The `Init` class is the plugin's bootstrap hook. Use it to register event listeners,
Twig extensions, and other runtime wiring. **Never modify core files** — extend behavior
through events instead.

### Event System (FSEventDispatcher)

Plugins MUST use `FSEventDispatcher` to hook into core flows without touching core code.
This is the FSFramework delegation pattern: the core fires events, plugins listen.

Available events:

| Event Constant | When | Use Case |
|---------------|------|----------|
| `ModelEvent::BEFORE_SAVE` | Before any model save | Validation, enrichment, audit |
| `ModelEvent::AFTER_SAVE` | After successful save | Cache invalidation, notifications, side effects |
| `ModelEvent::BEFORE_DELETE` | Before deletion | Dependency checks, soft-delete logic |
| `ModelEvent::AFTER_DELETE` | After deletion | Cleanup, cache invalidation |
| `ControllerEvent::BEFORE_ACTION` | Before `private_core()` | Access control, request modification |
| `ControllerEvent::AFTER_ACTION` | After `private_core()` | Response modification, logging |

```php
<?php

declare(strict_types=1);

namespace FSFramework\Plugins\NombrePlugin;

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Event\ModelEvent;
use FSFramework\Event\ControllerEvent;

class Init
{
    public function init(): void
    {
        $dispatcher = FSEventDispatcher::getInstance();

        // Example: validate plugin-specific rules before saving a core model
        $dispatcher->addListener(ModelEvent::BEFORE_SAVE, function (ModelEvent $event) {
            $model = $event->getModel();
            if ($model instanceof \cliente) {
                // Enrich or validate without touching clientes_core
                if (!$this->cumpleRequisitosPlugin($model)) {
                    $event->cancel('No cumple los requisitos del plugin');
                }
            }
        });

        // Example: invalidate cache after a model is saved
        $dispatcher->addListener(ModelEvent::AFTER_SAVE, function (ModelEvent $event) {
            $model = $event->getModel();
            if ($model instanceof \articulo) {
                \FSFramework\Cache\CacheManager::getInstance()
                    ->delete('nombre_plugin:latest_articles');
            }
        });

        // Example: controller-level access control
        $dispatcher->addListener(ControllerEvent::BEFORE_ACTION, function (ControllerEvent $event) {
            $controller = $event->getController();
            if ($controller instanceof \admin_mi_modulo) {
                // Custom permission check
            }
        });
    }

    private function cumpleRequisitosPlugin(\cliente $cliente): bool
    {
        // Plugin-specific business rules
        return true;
    }
}
```

### Delegation Rule

> **Delegate to core whenever possible.** If `fs_controller`, `fs_model`, `Container`, 
> or a Symfony component already provides the capability, use it. Only create 
> plugin-specific logic for truly plugin-specific behavior.

## Step 5: Model with XML Schema

**XML schema** in `model/table/mi_tablas.xml` (DB table names use plural `snake_case`; the PHP model file remains singular, for example `model/mi_tabla.php`):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<tabla>
    <columna>
        <nombre>id</nombre>
        <tipo>serial</tipo>
        <nulo>NO</nulo>
    </columna>
    <columna>
        <nombre>nombre</nombre>
        <tipo>character varying(150)</tipo>
        <nulo>NO</nulo>
    </columna>
    <restriccion>
        <nombre>mi_tablas_pkey</nombre>
        <consulta>PRIMARY KEY (id)</consulta>
    </restriccion>
</tabla>
```

**Model class** in `model/mi_tabla.php` — must implement `test()`, `save()`, `delete()`, `exists()`, and should point to the plural DB table name (for example, `mi_tablas`).
See skill [fsframework-model-crud](../fsframework-model-crud/SKILL.md) for the complete pattern.

## Step 6: Controller

**Legacy controller** in `controller/admin_mi_modulo.php`:

```php
class admin_mi_modulo extends fs_controller
{
    public function __construct()
    {
        parent::__construct(__CLASS__, 'Mi Módulo', 'admin', true, true);
    }

    protected function private_core(): void
    {
        // Logic here
    }
}
```

**Modern route controller** in `Controller/MiController.php`:

```php
<?php

declare(strict_types=1);

namespace FSFramework\Plugins\NombrePlugin\Controller;

use FSFramework\Attribute\FSRoute;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class MiController
{
    #[FSRoute('/mi-ruta', methods: ['GET'], name: 'mi_ruta')]
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
```

## Step 7: View Template

In `view/admin_mi_modulo.html.twig`:

```twig
{% extends "master/MenuTemplate.html.twig" %}

{% block body %}
<div class="container-fluid">
    <div class="row">
        <div class="col-sm-12">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">{{ trans('nombre-plugin-title') }}</h3>
                </div>
                <div class="panel-body">
                    {# Content here #}
                </div>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

## Step 8: Translations

`translations/messages.es.yaml`:

```yaml
nombre-plugin-title: "Mi Plugin"
nombre-plugin-save: "Guardar"
```

`translations/messages.en.yaml`:

```yaml
nombre-plugin-title: "My Plugin"
nombre-plugin-save: "Save"
```

All keys MUST use a plugin-specific prefix.

## Step 9: DI Services

`config/services.php`:

```php
<?php

use FSFramework\Plugins\NombrePlugin\Service\MiServicio;

return function (\Symfony\Component\DependencyInjection\ContainerBuilder $container) {
    $container->register('nombre_plugin.mi_servicio', MiServicio::class)
        ->setPublic(true);
};
```

## Step 10: CacheManager

FSFramework includes a unified cache system via `CacheManager`. Plugins MUST use it
instead of raw `$_SESSION`, file-based cache, or manual Memcache. The default TTL
is 180 seconds — ideal for admin systems where data changes frequently.

### When to Use Cache

| Scenario | Recommendation |
|----------|---------------|
| Expensive queries run on every request | Cache the result set |
| Dashboard stats, counters, summaries | Cache with `SHORT_TTL` (30s) |
| Dropdown lists, config values | Cache with `DEFAULT_TTL` (180s) |
| Rarely-changing reference data | Cache with `MEDIUM_TTL` (600s) or `LONG_TTL` (3600s) |

### TTL Constants

| Constant | Value | Use Case |
|----------|-------|----------|
| `CacheManager::SHORT_TTL` | 30s | Counters, status, very dynamic data |
| `CacheManager::DEFAULT_TTL` | 180s | Standard admin system cache |
| `CacheManager::MEDIUM_TTL` | 600s | Semi-static data (config, menus) |
| `CacheManager::LONG_TTL` | 3600s | Rarely changing data |

### Usage in Controllers

```php
use FSFramework\Cache\CacheManager;

// Inside private_core() or any controller method:
$cache = CacheManager::getInstance();

// Get with callback (auto-generates if missing — PREFERRED pattern)
$items = $cache->get('nombre_plugin:dashboard_stats', function () {
    return $this->loadExpensiveData();
}, CacheManager::SHORT_TTL);

// Simple get/set
if ($cache->has('nombre_plugin:config')) {
    $config = $cache->getItem('nombre_plugin:config', []);
} else {
    $config = $this->loadConfigFromDB();
    $cache->set('nombre_plugin:config', $config, CacheManager::MEDIUM_TTL);
}
```

### Cache Invalidation

Invalidate cache keys on write (save/delete) to avoid stale data:

```php
public function save(): bool
{
    if (!parent::save()) {
        return false;
    }

    // Invalidate affected cache keys
    CacheManager::getInstance()->deleteMultiple([
        'nombre_plugin:dashboard_stats',
        'nombre_plugin:latest_items',
    ]);

    return true;
}
```

### Cache Key Convention

All plugin cache keys MUST use a plugin-specific prefix to avoid collisions:

```
nombre_plugin:feature_identifier
nombre_plugin:feature:sub_key
```

**Never** use generic keys like `all_users` or `config` — another plugin might use the same key.

### Cache in Event Listeners

Invalidate cache from `Init.php` event listeners when core models change:

```php
$dispatcher->addListener(ModelEvent::AFTER_SAVE, function (ModelEvent $event) {
    $model = $event->getModel();
    if ($model instanceof \articulo) {
        CacheManager::getInstance()->delete('nombre_plugin:article_cache');
    }
});
```

## Step 11: Tests

In `tests/MiModeloTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\NombrePlugin;

use FSFramework\Plugins\NombrePlugin\Model\MiTabla;
use PHPUnit\Framework\TestCase;

class MiModeloTest extends TestCase
{
    public function testSomething(): void
    {
        $this->assertSame(MiTabla::class, 'FSFramework\\Plugins\\NombrePlugin\\Model\\MiTabla');
    }
}
```

## Step 12: Plugin phpunit.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="../../vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="../../tests/bootstrap.php"
         colors="true"
         cacheDirectory="../../.phpunit.cache"
>
    <testsuites>
        <testsuite name="NombrePlugin">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>model</directory>
            <directory>Controller</directory>
        </include>
    </source>
    <php>
        <env name="SYMFONY_DEPRECATIONS_HELPER" value="weak"/>
    </php>
</phpunit>
```

## Step 13: Security Audit

After scaffolding and before merging, run the security audit via
[fsframework-security-review](../fsframework-security-review/SKILL.md). At minimum,
verify:

- All SQL uses `$this->var2str()` — never string concatenation
- All POST forms include `{{ csrf_field() }}`
- All controllers processing POST validate CSRF with `$this->isCsrfValid()`
- All user input is sanitized with `$this->no_html()` or Symfony Request type methods
- No plaintext passwords, `md5()`, or `sha1()` — only `PasswordHasherService`
- No open redirects from request parameters without validation

## API REST (Deferred)

Plugins do **not** bake in their own REST API. The API layer is provided by the
`api_base` plugin, which scans models with `#[ApiResource]` attributes and exposes
generic CRUD endpoints.

When a plugin needs a REST API later:
1. Add `#[ApiResource]` attributes to the plugin's model classes
2. Install and activate `api_base` (separate repository)
3. No custom routing or controllers required — `api_base` handles it

This keeps plugins focused on business logic and avoids duplicated API infrastructure.

## Verification

After scaffolding, run:

```bash
# Unit tests (isolated)
ddev exec php vendor/bin/phpunit -c plugins/NombrePlugin/phpunit.xml

# Full suite (includes plugin tests via auto-discovery)
ddev exec php vendor/bin/phpunit --testsuite Plugins
```

## Related Skills

| Skill | Use When |
|-------|----------|
| [fsframework-model-crud](../fsframework-model-crud/SKILL.md) | Creating models with XML schema, CRUD, and validation |
| [fsframework-security-review](../fsframework-security-review/SKILL.md) | Auditing plugin code for vulnerabilities before merge |
| [fsframework-test-writing](../fsframework-test-writing/SKILL.md) | Writing PHPUnit 11 tests following project conventions |

## Quick Reference: Naming Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| Plugin dir | PascalCase or snake_case | `MiPlugin` |
| Model class | snake_case (legacy) | `mi_tabla` |
| Model file | singular snake_case.php | `mi_tabla.php` |
| Table name in DB | plural snake_case | `mi_tablas` |
| XML schema | table name.xml (plural) | `mi_tablas.xml` |
| Controller (legacy) | snake_case | `admin_mi_modulo` |
| Controller (modern) | PascalCase | `MiController` |
| Translation keys | plugin-prefix-key | `mi-plugin-title` |
| Namespace | `FSFramework\Plugins\NombrePlugin\` | `FSFramework\Plugins\NombrePlugin\Controller` |
