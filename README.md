# FSFramework

Fork modernizado de FacturaScripts 2017 con integración Symfony 7.4, motor Twig 3 y arquitectura **Symfony-first** con compatibilidad legacy controlada.

**Versión actual:** ver [`VERSION`](VERSION) (v0.12.x)

Software libre bajo licencia GNU/LGPL.

## Arquitectura

FSFramework mantiene la lógica operativa de FacturaScripts 2017 pero organiza el código en dos capas:

| Capa | Ubicación | Rol |
|------|-----------|-----|
| **Legacy** | `base/`, `controller/`, `model/` | Controladores, modelos y acceso a BD heredados |
| **Moderna (PSR-4)** | `src/` | Kernel, seguridad, caché, eventos, API, traducciones |

Principio **Symfony-first**: cuando existe un servicio moderno en Symfony 7.4, el core delega en él y deja la compatibilidad legacy en bridges finos (`LegacyAuthBridge`, `legacy_support`, etc.).

```
index.php / api.php
       │
       ▼
  Kernel + Router          ← src/Core/
       │
       ├── fs_controller / fs_model   (legacy)
       ├── Container + servicios      (Symfony DI)
       ├── ThemeManager + Twig        (themes/)
       └── Plugins activos            (plugins/)
```

> **Advertencia:** no es 100% compatible con la funcionalidad de facturación original de FacturaScripts 2017. La compatibilidad con plugins de FacturaScripts 2025 es **parcial** y debe probarse en desarrollo antes de producción.

## Entorno de desarrollo

El entorno local recomendado es **[DDEV](https://ddev.com/)** (PHP 8.3, MariaDB 10.11, nginx-fpm).

```bash
ddev start
ddev exec composer install          # dependencias de producción
./scripts/verify-vendor-integrity.sh # repara vendor incompleto si faltan archivos nuevos
./scripts/install-dev-tools.sh      # PHPStan, PHPUnit tooling (opcional)
./build.sh                          # assets frontend (Bootstrap, jQuery, Font Awesome)
```

Acceso web: `https://panel-ab.ddev.site` (o el nombre del proyecto en `.ddev/config.yaml`).

Comandos habituales:

```bash
ddev exec php vendor/bin/phpunit                    # tests
ddev exec composer phpstan                          # análisis estático (requiere dev-tools)
ddev exec php scripts/remediate-legacy-passwords.php # migración offline de contraseñas legacy
```

## Dependencias de producción y desarrollo

Este repositorio puede mantener en GitHub el `vendor/` necesario para producción y, al mismo tiempo, dejar las herramientas de desarrollo fuera de lo versionado.

- **Producción:** usa el `vendor/` principal del proyecto
- **Desarrollo:** instala las herramientas locales con `ddev exec composer install --working-dir=dev-tools`
- Las herramientas se instalan en `vendor/dev-tools/`, que está ignorado por Git

Atajos disponibles en el `composer.json` principal una vez instaladas las herramientas:

- `ddev exec composer phpstan`
- `ddev exec composer phpstan:baseline`
- `ddev exec composer phpstan:dead-code`

## Novedades principales (v0.12.x)

### Temas separados de plugins

**AdminLTE** ya no es un plugin: vive en `themes/AdminLTE/` y se gestiona con `ThemeManager`. Los plugins pueden sobreescribir vistas del tema en `plugins/<Plugin>/themes/AdminLTE/view/`.

```
themes/AdminLTE/
├── theme.ini
├── view/
│   ├── master/              # Layouts base
│   ├── login/               # Login
│   ├── Macro/               # Macros reutilizables
│   └── ...
├── css/  js/  translations/
```

Prioridad de resolución de plantillas Twig: **tema activo → plugins (orden inverso) → vistas core**.

### Ecosistema de plugins core

Los dominios de negocio se distribuyen en plugins modulares con dependencias declaradas en `fsframework.ini`:

```
catalogo_core          ← artículos, familias, fabricantes, impuestos, divisas, almacenes, países
    │
business_data          ← empresa, ejercicio, serie, formas de pago, cuentas bancarias
    │
clientes_core          ← clientes, direcciones, grupos
    │
clientes_facturacion   ← integración comercial para facturación
    │
clientes_catalogo      ← puente opcional clientes ↔ catálogo
```

Plugins de compatibilidad:

| Plugin | Función |
|--------|---------|
| `legacy_support` | Traducción RainTPL → Twig, hashes de contraseña legacy (SHA1/MD5) con migración automática al login, registro de uso legacy |
| `facturascripts_support` | Capa de compatibilidad para plugins FacturaScripts 2025 (en desarrollo) |

Orden de activación recomendado:

1. `catalogo_core`
2. `business_data`
3. `clientes_core`
4. Resto según necesidad (`clientes_facturacion`, `clientes_catalogo`, …)

### API REST declarativa

El core expone contratos en `src/Api/` (atributos `#[ApiResource]`, `#[ApiField]`, excepciones) y un bootstrap mínimo en [`api.php`](api.php). El **runtime** (router, middleware, transformadores) vive en el plugin **`api_base`** (repositorio separado).

```
/api.php/v1/{plugin}/{resource}
/api.php/v1/{plugin}/{resource}/{id}
```

Si `api_base` no está activo, `api.php` responde `404` con `"API no habilitada"` sin exponer trazas.

Definir un recurso en un plugin consumidor:

```php
use FSFramework\Api\Attribute\ApiResource;
use FSFramework\Api\Attribute\ApiField;
use FSFramework\Api\Attribute\Operation;

#[ApiResource(
    operations: [Operation::LIST, Operation::GET, Operation::CREATE],
    version: 'v1',
    plugin: 'mi_plugin',
    resource: 'cliente',
    requiresAuth: true
)]
class cliente extends fs_model {
    #[ApiField(readable: true, writable: true)]
    public $nombre;
}
```

### Seguridad reforzada

Hardening del milestone v0.12.0 documentado en [`SECURITY.md`](SECURITY.md):

- **CSRF** estricto en mutaciones (`CsrfManager`, validación automática en `pre_private_core()`)
- **Contraseñas** con `PasswordHasherService` (argon2id/bcrypt); soporte legacy solo vía plugin `legacy_support`
- **Sesiones** con `SessionManager` y regeneración de ID tras login
- **Cabeceras HTTP** (`SecurityHeaders.php`) incluyendo CSP configurable
- **Open redirect** bloqueado con `SafeRedirect`
- **Login throttle** y política de sesión
- **DebugBar** visible solo en IP local con `FS_DEBUG=true`

Script de remediación offline para instalaciones con hashes legacy:

```bash
ddev exec php scripts/remediate-legacy-passwords.php
```

### Migración legacy planificada

Roadmap de retirada progresiva de RainTPL, aliases API y rutas legacy hacia **v3.0**: [`docs/reviews/legacy-migration-roadmap.md`](docs/reviews/legacy-migration-roadmap.md).

El plugin `legacy_support` instrumenta el uso de componentes legacy y lo expone en el panel de administración.

## Motor de plantillas Twig

Migración completa a **Twig 3.x** con soporte dual:

- `.html.twig` — nativo (preferido)
- `.html` — RainTPL traducido en tiempo real vía `legacy_support`

| RainTPL | Twig |
|---------|------|
| `{$variable}` | `{{ variable }}` |
| `{loop="$items"}` | `{% for value in items %}` |
| `{if="$cond"}` | `{% if cond %}` |
| `{include="file"}` | `{{ include('file.html') }}` |

Los assets estáticos compartidos (Bootstrap, jQuery, Font Awesome) viven en `view/css/`, `view/js/` y `view/fonts/`.

## Integración Symfony 7.4

| Componente | Uso |
|------------|-----|
| `symfony/http-foundation` | Request/Response HTTP |
| `symfony/routing` | Rutas con atributos PHP 8 (`#[FSRoute]`) |
| `symfony/security-csrf` | Protección CSRF |
| `symfony/event-dispatcher` | Sistema de eventos (`FSEventDispatcher`) |
| `symfony/validator` | Validación de modelos (`ValidatorTrait`) |
| `symfony/dependency-injection` | Contenedor de servicios |
| `symfony/form` | Formularios con CSRF (`FormHelper`) |
| `symfony/translation` | i18n (YAML + JSON FS2025) |
| `symfony/cache` | Caché unificada (`CacheManager`) |
| `symfony/http-client` | Cliente HTTP |
| `twig/twig` | Motor de plantillas |

**API REST (`api_base`):** dependencias propias en `plugins/api_base/composer.json` (p. ej. `zircote/swagger-php`). Instalar con `ddev exec composer install --working-dir=plugins/api_base`. Tests: `ddev exec php vendor/bin/phpunit -c plugins/api_base/phpunit.xml`.

### Ejemplos rápidos

**Routing con atributos:**

```php
use FSFramework\Attribute\FSRoute;

#[FSRoute('/api/users/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
class api_user extends fs_controller { /* ... */ }
```

**CSRF en Twig:**

```twig
<form method="post" action="{{ fsc.url() }}">
    {{ csrf_field() }}
    <button type="submit">Guardar</button>
</form>
```

**Contenedor de servicios:**

```php
use FSFramework\DependencyInjection\Container;

$db = Container::db();
$cache = Container::cache();
$hasher = Container::passwordHasher();
```

**Traducciones:**

```php
echo \FSFramework\Translation\trans('login-text');
```

```twig
{{ trans('save') }}
{{ 'hello'|trans({'%name%': user.nombre}) }}
```

Formatos: `translations/messages.{locale}.yaml` (recomendado) y `Translation/{locale}.json` (FS2025).

**Caché:**

```php
use FSFramework\Cache\CacheManager;

$cache = CacheManager::getInstance();
$users = $cache->get('all_users', fn() => $this->db->select("SELECT * FROM fs_users"));
$cache->clearAll(); // Symfony + Twig + legacy
```

## Compatibilidad FacturaScripts 2025

Soporte **parcial** vía plugin `facturascripts_support`:

- Estructura dual: `Controller/` (FS2025) y `controller/` (legacy)
- Vistas: `View/` (PascalCase) además de `view/`
- Traducciones JSON en `Translation/{locale}.json`
- Bridges en `src/Core/` (`Html`, `Tools`, `Controller`)

Probar siempre en entorno de desarrollo antes de producción.

## Estructura del proyecto

```
/
├── base/                    # Clases core legacy (fs_model, fs_controller, fs_db2…)
├── controller/              # Controladores del core
├── model/                   # Modelos y esquemas XML (model/table/)
├── src/                     # Código moderno PSR-4 (FSFramework\)
│   ├── Api/                 # Contratos REST (atributos, excepciones, auth)
│   ├── Attribute/           # FSRoute
│   ├── Cache/               # CacheManager, DataSrcRepository
│   ├── Core/                # Kernel, Router, ThemeManager, Html, Plugins
│   ├── Database/            # SchemaComparator, TypeNormalizer
│   ├── DependencyInjection/ # Container
│   ├── Event/               # FSEventDispatcher, ModelEvent, ControllerEvent
│   ├── Form/                # FormHelper
│   ├── Security/            # CSRF, PasswordHasher, SessionManager, SafeRedirect…
│   ├── Translation/         # FSTranslator, FS2025JsonLoader
│   └── Twig/                # Extensiones Twig
├── themes/                  # Temas (AdminLTE por defecto)
│   └── AdminLTE/
├── plugins/                 # Plugins de dominio y compatibilidad
│   ├── catalogo_core/
│   ├── business_data/
│   ├── clientes_core/
│   ├── legacy_support/
│   └── facturascripts_support/
├── view/                    # Assets estáticos compartidos (css, js, fonts)
├── translations/            # Traducciones del core (YAML)
├── tests/                   # PHPUnit 11 (Base, Core, Security, Traits, Cache, Api)
├── scripts/                 # Utilidades CLI (remediación passwords, dev-tools)
├── docs/                    # Documentación
├── api.php                  # Entrada API REST
├── index.php                # Entrada web
├── install.php              # Asistente de instalación
└── SECURITY.md              # Modelo de amenazas y controles
```

## Requisitos

- PHP 8.2+ (preferible **8.3**)
- MySQL o PostgreSQL
- Servidor web (Apache/Nginx) o DDEV
- Composer (vía DDEV en desarrollo)

## Instalación

1. Clonar el repositorio
2. Arrancar DDEV e instalar dependencias:
   ```bash
   ddev start
   ddev exec composer install
   ./build.sh
   ```
3. Copiar y configurar `config.php` (conexión BD, secretos)
4. Acceder a `install.php` o `index.php` en el navegador
5. Activar plugins según el dominio necesario (ver sección de ecosistema)

## Pruebas unitarias

**PHPUnit 11** con suites organizadas por área:

```bash
ddev exec php vendor/bin/phpunit                        # todas
ddev exec php vendor/bin/phpunit --testsuite Base       # clases core (base/)
ddev exec php vendor/bin/phpunit --testsuite Core       # src/Core, Database…
ddev exec php vendor/bin/phpunit --testsuite Security   # CSRF, sesiones, headers…
ddev exec php vendor/bin/phpunit --testsuite Plugins    # tests en plugins/*/tests/
ddev exec php vendor/bin/phpunit -c plugins/OidcProvider/phpunit.xml  # suite aislada
```

Los tests de plugins viven en `plugins/<PluginName>/tests/` y se descubren automáticamente desde el `phpunit.xml` raíz.

Cobertura actual: **400+ tests** en core, seguridad, caché, traducciones y plugins versionados.

## Documentación adicional

- [Sistema de traducciones](docs/TRANSLATION.md)
- [Roadmap migración legacy](docs/reviews/legacy-migration-roadmap.md)
- [Mejoras propuestas](docs/MEJORAS_PROPUESTAS.md)
- [Seguridad](SECURITY.md)
- [Guía para agentes de IA](AGENTS.md)

## Contribuciones

Se anima a quien quiera contribuir al proyecto a realizar pull requests.

## Contacto

Para cualquier consulta, visita: https://misterdigital.es/contacto/
