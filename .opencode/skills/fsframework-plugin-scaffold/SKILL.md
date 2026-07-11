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

### Plugin Controller Requires (HARD RULE)

> **Every `base/*` class used in a plugin controller needs an explicit
> `require_once` at the top of the file. Without exceptions. The
> framework autoloader (`fs_autoload::register()`) is not reliable in
> the plugin-controller execution path: only some legacy `fs_*`
> classes are registered in its class map (e.g., `fs_settings` is, but
> `fs_session_manager` is not), and the composer PSR-4 autoloader for
> namespaced `FSFramework\…` classes does not always fire in time
> during a plugin request.**

**Path pattern** (use the exact same depth from any plugin controller at
`plugins/{name}/controller/*.php`):

```php
require_once dirname(__DIR__, 3) . '/base/<class>.php';
```

`dirname(__DIR__, 3)` resolves three directory levels up from the
controller's directory, landing on the project root
(`/var/www/html/` in ddev, or whatever `FS_FOLDER` points to in
production). The trailing `/base/<class>.php` then references the
target file in the core's `base/` directory.

**Common `base/*` classes that need explicit requires in a plugin
controller**:

| Class | When you need it |
|-------|------------------|
| `fs_controller` | Always (or rely on the legacy autoload if your project is already working — explicit is safer) |
| `fs_settings` | Any read/write via the global INI config (`new fs_settings()`) |
| `fs_session_manager` | Any CSRF field generation, session helpers, or `csrfField()` calls |
| `fs_auth` | Any cookie signing, `isCsrfValid()` custom checks, or auth helpers |
| `fs_functions` | Any global helper like `bround()`, `fs_fix_html()`, `fs_is_local_ip()` |

**Minimal legacy controller template with the requires** (note the
explicit `require_once` block before the `class` declaration):

```php
<?php
/**
 * This file is part of NombrePlugin.
 * Copyright (C) <year> <author> <email>
 * License: LGPL-3.0-or-later
 */

require_model('...');   // plugin-local models, as before

// === Plugin Controller Requires (HARD RULE) ============================
// Any base/* class used in this controller MUST be required explicitly.
// The framework autoloader is unreliable in plugin context.
require_once dirname(__DIR__, 3) . '/base/fs_controller.php';
require_once dirname(__DIR__, 3) . '/base/fs_settings.php';      // if used
require_once dirname(__DIR__, 3) . '/base/fs_session_manager.php'; // if used
// =======================================================================

class admin_mi_modulo extends fs_controller
{
    public function __construct()
    {
        parent::__construct(__CLASS__, 'Mi Módulo', 'admin', true, true);
    }

    protected function private_core(): void
    {
        $settings = new fs_settings();
        // ...
    }
}
```

**Modern route controllers** in `Controller/*.php` (PSR-4, namespaced
under `FSFramework\Plugins\{NamePlugin}\Controller`) follow a
different rule: they use the composer PSR-4 autoloader plus
`use FSFramework\…;` statements, so the explicit `require_once` of
`base/*` is generally **not** needed. The only exception is when
calling legacy helpers like `fs_session_manager::csrfField()` from a
modern controller — in that case, add the explicit
`require_once dirname(__DIR__, 4) . '/base/fs_session_manager.php';`
(one extra level up because modern controllers sit in
`Controller/`, not `controller/`).

**Why this rule exists (background, not a runtime concern)**: a
real-world example is the `terminal-opcional` change in
`plugins/tpvmod/` (2026-06-20), which hit the bug three times in
succession: F2 (`fs_settings` not found), F3 (no F4 consequence but
it would have been), F4 (`fs_session_manager` not found). Each fix
followed the same pattern: "add a new `base/*` class use without the
require, get a runtime fatal, add the require, re-verify." Scaffolding
new plugin controllers with the requires block in place from day
one eliminates the entire class of bug.

**Verification rule** (also a hard rule): after every controller
edit, run an actual HTTP request to the new/modified page in
ddev, e.g.:

```bash
curl -sL "https://<project>.ddev.site/index.php?page=<controller_name>" \
    -o /tmp/resp.html -w "HTTP %{http_code}\n"
grep -ci 'fatal\|class .* not found' /tmp/resp.html
```

If the grep returns 0, the class loading is fine. `php -l` and
PHPUnit do NOT catch this class of error — they only check syntax
and pre-existing test coverage. The HTTP smoke is the only safety
net for missing requires.

### Legacy Controller Template

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
- All POST forms include `{{ csrf_field() }}` (Twig) or `{$fsc->csrf_field}` (RainTPL — see Step 6)
- All controllers processing POST validate CSRF with `$this->isCsrfValid()`
- All user input is sanitized with `$this->no_html()` or Symfony Request type methods
- No plaintext passwords, `md5()`, or `sha1()` — only `PasswordHasherService`
- No open redirects from request parameters without validation

### Runtime Smoke (Required for any controller change)

`php -l`, PHPUnit, and PHPStan do NOT catch runtime class-loading
errors introduced by a new `base/*` class use in a plugin controller.
After any edit to a plugin controller (scaffold, feature add, or
bug fix), verify the page actually loads in a browser via ddev:

```bash
# 1. Syntax check (catches parse errors only)
ddev exec php -l plugins/<Plugin>/controller/<controller>.php

# 2. PHPUnit (catches test regressions; does NOT catch missing requires)
ddev exec php vendor/bin/phpunit --testsuite Base
ddev exec php vendor/bin/phpunit --testsuite Plugins

# 3. HTTP smoke (catches the F2/F4 class — missing requires — and fatals)
curl -sL "https://<project>.ddev.site/index.php?page=<controller>" \
    -o /tmp/resp.html -w "HTTP %{http_code}\n"
grep -ci 'fatal\|class .* not found' /tmp/resp.html   # must be 0

# 4. For templates: also grep for literal Twig tokens in RainTPL files (and vice versa)
grep -nE '\{\{[ ]*[a-z_]+\(\)[ ]*\}\}' plugins/<Plugin>/view/*.html      # Twig-style calls in RainTPL — bad
grep -nE '\{[ ]*[a-z_]+\(\)[ ]*\}' plugins/<Plugin>/view/*.html.twig    # RainTPL-style in Twig — bad
```

If step 3 returns any matches OR a non-200 status, the change is
broken at runtime even if the syntax check and tests passed. The
fix is almost always "add the missing `require_once`" per the
**Plugin Controller Requires** rule in Step 6.

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
