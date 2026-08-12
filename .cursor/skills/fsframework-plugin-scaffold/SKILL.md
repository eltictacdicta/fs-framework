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
- [ ] Step 4: Create Init.php (init + update + uninstall via InitClass)
- [ ] Step 5: Create model(s) with XML schema and install() seeds
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

El core distingue **arranque diario** (`init()`) de **migraciones al activar o
actualizar** (`update()`). Al activar o actualizar un plugin, el core ejecuta
`fs_plugin_manager::applyPluginSchemaUpdates()` → `PluginSchemaSynchronizer`:

1. `Init::update()` — migraciones PHP del plugin
2. `fs_schema::syncPluginTables()` — sincroniza `model/table/*.xml`
3. Refresco de modelos legacy — `fs_model::check_table()` + `install()` para datos semilla

**Patrón recomendado** — extender `InitClass`:

```php
<?php

declare(strict_types=1);

namespace FSFramework\Plugins\NombrePlugin;

use FSFramework\Core\Template\InitClass;
use FSFramework\Event\FSEventDispatcher;
use FSFramework\Event\ModelEvent;

class Init extends InitClass
{
    /**
     * Se ejecuta en cada arranque del framework (plugin activo).
     * Solo wiring runtime: listeners, Twig, rutas, etc.
     */
    public function init(): void
    {
        $dispatcher = FSEventDispatcher::getInstance();
        $dispatcher->addListener(ModelEvent::AFTER_SAVE, function (ModelEvent $event) {
            // Side effects en runtime
        });
    }

    /**
     * Se ejecuta al activar o actualizar el plugin.
     * Migraciones de datos, SQL puntual, ajustes idempotentes de configuración.
     */
    public function update(): void
    {
        // Ejemplo: sembrar filas por defecto si no existen
        // Ejemplo: ALTER TABLE manual cuando XML no basta
    }

    /**
     * Se ejecuta al desinstalar/desactivar permanentemente el plugin.
     */
    public function uninstall(): void
    {
        // Limpieza opcional (tmp, settings del plugin, etc.)
    }
}
```

### Qué poner en cada capa

| Capa | Cuándo usarla | Ejemplo |
|------|---------------|---------|
| `model/table/*.xml` | Estructura declarativa (tablas, columnas, PK/FK) | Añadir columna `activo` |
| `fs_model::install()` | Datos semilla al crear tabla nueva | Registro `DEFAULT` en tabla vacía |
| `Init::update()` | Migraciones de datos o SQL no expresable en XML | Renombrar valores, backfill |
| `Init::init()` | **Nunca** migraciones de esquema/datos | Solo listeners y wiring |

### Reglas

- **No** pongas sincronización de BD en `init()` — se ejecuta en cada request.
- **Sí** haz `update()` idempotente (comprobar antes de insertar/alterar).
- Retrocompat: si no extiendes `InitClass`, puedes exponer `public function update(): void`
  o el legacy estático `Init::upgrade()`; el core los detecta en ese orden.
- Tras cambiar XML, basta con actualizar el plugin: el core llama al sincronizador
  automáticamente (activación, instalación con overwrite, descarga desde tienda/updater).

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

Opcionalmente implementa `install()` para datos semilla cuando la tabla se crea por
primera vez (el core lo invoca vía `check_table()` durante la sincronización):

```php
protected function install(): bool
{
    return $this->db->exec(
        "INSERT INTO " . $this->table_name . " (nombre) VALUES ('DEFAULT');",
        false
    );
}
```

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

## Step 10: Tests

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
| Model file | singular snake_case.php | `mi_tabla.php` |
| Table name in DB | plural snake_case | `mi_tablas` |
| XML schema | table name.xml (plural) | `mi_tablas.xml` |
| Controller (legacy) | snake_case | `admin_mi_modulo` |
| Controller (modern) | PascalCase | `MiController` |
| Translation keys | plugin-prefix-key | `mi-plugin-title` |
| Namespace | `FSFramework\Plugins\NombrePlugin\` | `FSFramework\Plugins\NombrePlugin\Controller` |
