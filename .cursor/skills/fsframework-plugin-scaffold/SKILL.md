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
- [ ] Step 4: Create Init.php
- [ ] Step 5: Create model(s) with XML schema
- [ ] Step 6: Create controller(s)
- [ ] Step 7: Create view template(s)
- [ ] Step 8: Create translations
- [ ] Step 9: Create config/services.php (if needed)
- [ ] Step 10: Create tests
- [ ] Step 11: Create phpunit.xml for isolated execution
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

```php
<?php

declare(strict_types=1);

namespace FSFramework\Plugins\nombre_plugin;

class Init
{
    public function init(): void
    {
        // Register event listeners, auth providers, etc.
    }
}
```

## Step 5: Model with XML Schema

**XML schema** in `model/table/mi_tabla.xml`:

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
        <nombre>mi_tabla_pkey</nombre>
        <consulta>PRIMARY KEY (id)</consulta>
    </restriccion>
</tabla>
```

**Model class** in `model/mi_tabla.php` — must implement `test()`, `save()`, `delete()`, `exists()`.
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

namespace FSFramework\Plugins\nombre_plugin\Controller;

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

use FSFramework\Plugins\nombre_plugin\Service\MiServicio;

return function (\Symfony\Component\DependencyInjection\ContainerBuilder $container) {
    $container->register('nombre_plugin.mi_servicio', MiServicio::class)
        ->setPublic(true);
};
```

## Step 10: Tests

In `tests/MiModeloTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\NombrePlugin;

use PHPUnit\Framework\TestCase;

class MiModeloTest extends TestCase
{
    public function testSomething(): void
    {
        $this->assertTrue(true);
    }
}
```

## Step 11: Plugin phpunit.xml

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

## Verification

After scaffolding, run:

```bash
ddev exec php vendor/bin/phpunit -c plugins/NombrePlugin/phpunit.xml
```

## Quick Reference: Naming Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| Plugin dir | PascalCase or snake_case | `MiPlugin` |
| Model class | snake_case (legacy) | `mi_tabla` |
| Model file | snake_case.php | `mi_tabla.php` |
| Table name | plural snake_case | `mi_tablas` |
| XML schema | table name.xml | `mi_tablas.xml` |
| Controller (legacy) | snake_case | `admin_mi_modulo` |
| Controller (modern) | PascalCase | `MiController` |
| Translation keys | plugin-prefix-key | `mi-plugin-title` |
| Namespace | `FSFramework\Plugins\nombre\` | |
