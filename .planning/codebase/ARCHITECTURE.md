<!-- refreshed: 2026-05-16 -->
# Architecture

**Analysis Date:** 2026-05-16

## System Overview

```text
┌──────────────────────────────────────────────────────────────────────────┐
│                         Entry Points (Web / API / Cron)                   │
├──────────────────────┬──────────────────────┬────────────────────────────┤
│    index.php          │    api.php             │    cron.php / updater.php│
│   (Web UI + Legacy    │   (REST API Gateway)   │    install.php           │
│     dispatching)      │                         │    maintenance.php       │
│  `index.php:188-265`  │   `api.php:136-137`     │                          │
└──────────┬───────────┴──────────┬───────────┴──────────────┬───────────────┘
           │                      │                          │
           ▼                      ▼                          ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                      Kernel & Routing Layer                               │
│   `src/Core/Kernel.php`  →  `src/Core/Router.php`  (Symfony Routing)     │
│   `src/Core/Plugins.php` → plugin Init classes & event wiring             │
│   `src/Core/Html.php`    → Twig template rendering bridge                  │
└───────────────────────────────┬──────────────────────────────────────────┘
                                │
           ┌────────────────────┼────────────────────┐
           ▼                    ▼                    ▼
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
│  Legacy Layer    │  │  Modern Layer     │  │  Plugin Layer     │
│  `base/`          │  │  `src/`           │  │  `plugins/*/`     │
│  `controller/`    │  │  (PSR-4)          │  │  (PSR-4 + legacy) │
│  `model/`         │  │                   │  │                   │
└──────┬───────────┘  └──────┬────────────┘  └──────┬────────────┘
       │                     │                      │
       ▼                     ▼                      ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                      Data & Storage Layer                                 │
│   `base/fs_db2.php` → `fs_mysql.php` / `fs_postgresql.php`              │
│   `base/fs_cache.php` → `src/Cache/CacheManager.php` (Symfony Cache)     │
│   `model/table/*.xml` → schema definitions → auto-creates/adapts tables   │
└──────────────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                      Presentation Layer                                   │
│   `src/Core/Html.php`  →  `themes/AdminLTE/view/*.html.twig` (Twig)     │
│   `src/Twig/TranslationExtension.php`  →  `src/Translation/` (Symfony)   │
│   `view/`  →  static assets (CSS, JS, fonts via npm)                     │
└──────────────────────────────────────────────────────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| Kernel | Singleton bootstrapper: trusted proxies, Request from globals, plugin init, route loading | `src/Core/Kernel.php` |
| Router | Symfony routing: loads `config/routes.php`, discovers controller attributes via `FSFrame`, caches collections | `src/Core/Router.php` |
| Plugins | Plugin lifecycle: scans `$GLOBALS['plugins']`, calls `Init::init()`, registers public paths/stealth overrides | `src/Core/Plugins.php` |
| Html | Template bridge: resolves Twig templates across theme, legacy view folders, and plugin overrides; handles AJAX partial render | `src/Core/Html.php` |
| ThemeManager | Active theme resolution from `fs_vars` config; theme discovery from `themes/` | `src/Core/ThemeManager.php` |
| StealthMode | Public facade gating: HTML homepage with embedded CSS, secret login access parameter, CSP enforcement | `src/Core/StealthMode.php` |
| fs_controller | Legacy page controller base: auth, menu, form handling, CSRF, template output | `base/fs_controller.php` |
| fs_model | Abstract model base: table existence checks via XML schemas, DB ops, cached table metadata | `base/fs_model.php` |
| fs_db2 | DB abstraction: delegates to `fs_mysql` or `fs_postgresql` engine based on `FS_DB_TYPE` constant | `base/fs_db2.php` |
| FSEventDispatcher | Symfony EventDispatcher singleton: bridges legacy `fs_extension` hooks, dispatches model/controller events | `src/Event/FSEventDispatcher.php` |
| FSTranslator | Symfony Translation wrapper: loads YAML/JSON from core `translations/` + plugin catalogs; static API via `trans()` | `src/Translation/FSTranslator.php` |
| CacheManager | Unified caching: Symfony Cache chain (Array + Filesystem + Memcached), clears Twig + legacy + php_file_cache | `src/Cache/CacheManager.php` |
| Container | Service locator: lazy-loaded DI container holding core services (db, request, csrf, password hasher, api.runtime) | `src/DependencyInjection/Container.php` |
| PageController | Modern controller base for FS2025-style plugins; extends `FSFramework\Core\Base\Controller` | `src/Controller/PageController.php` |

## Pattern Overview

**Overall:** Dual-layer architecture — legacy procedural/MVC (`base/` + `controller/` + `model/`) coexisting with modern PSR-4 Symfony-based services (`src/`). The two layers are bridged via adapters, facades, and wrapper classes.

**Key Characteristics:**
- **Singleton bootstrapping**: `Kernel::boot()` initializes exactly once; `Kernel::request()` provides the Symfony `Request` globally.
- **Service Locator pattern**: `Container` provides lazy access to services; no full DI container wiring for legacy code.
- **Plugin architecture**: Plugins register via `fsframework.ini`; discovered through `$GLOBALS['plugins']` array; each can have an `Init.php` class that hooks into `FSEventDispatcher`.
- **Event-driven extensibility**: Plugins extend behavior through Symfony events (`TwigInitEvent`, `ModelEvent`, `ControllerEvent`) rather than modifying core files.
- **Dual template engines**: Twig (primary, `.html.twig`) with RainTPL compatibility for legacy `.html` templates.
- **Cache layering**: Legacy `fs_cache` APIs delegate to modern `CacheManager` (Symfony Cache) internally.
- **Feature gating**: Stealth mode, public access gate, and maintenance mode provide operational modes without modifying code.

## Layers

**Entry Points Layer:**
- Purpose: Receive HTTP requests, bootstrap the framework, dispatch to correct handler
- Location: `index.php`, `api.php`, `cron.php`, `install.php`, `maintenance.php`, `updater.php`
- Contains: Bootstrap logic, security headers, maintenance/stealth gating, controller resolution
- Depends on: Kernel, config.php, base/ classes

**Kernel & Routing Layer:**
- Purpose: Initialize framework services, load plugins, resolve Symfony routes, handle fallback dispatching
- Location: `src/Core/Kernel.php`, `src/Core/Router.php`, `src/Core/Plugins.php`
- Contains: Route collection, URL generation, plugin lifecycle, controller discovery
- Depends on: Symfony Routing, HttpFoundation; config/routes.php; plugin Init classes
- Used by: Entry points, URL generation helpers

**Controllers Layer (Legacy):**
- Purpose: Handle page requests, authenticate users, manage forms, render templates
- Location: `controller/` (14 files), `plugins/*/controller/`
- Contains: Classes extending `fs_controller` with `private_core()` as the main entry
- Depends on: `base/fs_controller.php`, `fs_db2`, models, extensions
- Used by: `index.php` regex-based page dispatch

**Controllers Layer (Modern):**
- Purpose: FS2025-style controllers with `handle()` methods returning Symfony Responses
- Location: `src/Controller/PageController.php`, `plugins/*/Controller/` (PSR-4)
- Contains: Classes extending `FSFramework\Controller\PageController` (→ `FSFramework\Core\Base\Controller`)
- Depends on: Symfony HttpFoundation, `fs_db2`, legacy models
- Used by: `index.php` reflection-based dispatch, `Router` attribute-based routing

**Models Layer (Legacy):**
- Purpose: Database entity representation, validation, CRUD operations
- Location: `model/` (12 files), `model/core/`, `plugins/*/model/core/`
- Contains: Classes extending abstract `fs_model` with `delete()`, `exists()`, `save()` methods
- Depends on: `fs_db2`, `fs_cache`, XML schemas (`model/table/`, `plugins/*/model/table/`)
- Used by: Controllers, event listeners, API endpoints

**Models Layer (Modern / Dynamic):**
- Purpose: Namespaced model wrappers providing compatibility layer for FS2025 plugins
- Location: `plugins/*/Model/` (PSR-4), `src/Dinamic/Model/User.php`
- Contains: Lightweight classes that proxy to legacy models
- Depends on: Legacy models
- Used by: Modern controllers, API runtime (plugin)

**Services Layer (Modern):**
- Purpose: Reusable business logic components using Symfony 7.4
- Location: `src/` subdirectories
- Contains:
  - `Security/`: CsrfManager, PasswordHasherService, UserAdapter, CookieSigner, SignedUrlService, EncryptionService, SecretManager, SafeRedirect, SessionManager, SecurityHeaders, LoginThrottle, PrivacyMasker, LegacyAuthBridge, LegacyUserService, SessionPolicy
  - `Event/`: FSEventDispatcher, ModelEvent, ControllerEvent, TwigInitEvent, TwigLoaderEvent
  - `Translation/`: FSTranslator, TranslationHelper, FS2025JsonLoader
  - `Cache/`: CacheManager, DataSrcRepository
  - `Form/`: FormHelper (Symfony Forms)
  - `Traits/`: ValidatorTrait (Symfony Validator)
  - `Twig/`: TranslationExtension
  - `Api/`: API primitives (attributes, auth interfaces, middleware, exceptions, helpers)
  - `Attribute/`: FSRoute
- Depends on: Symfony components, legacy models/controllers
- Used by: Controllers, plugin Init classes, API runtime

**Presentation Layer:**
- Purpose: Render HTML output via Twig templates with AdminLTE theme
- Location: `themes/AdminLTE/view/`, `view/` (static assets)
- Contains: `.html.twig` templates organized as master layouts, macros, blocks, tabs; legacy `.html` fallbacks
- Depends on: Twig 3, `src/Core/Html.php`, `FSTranslator`, CSRF manager, `Kernel::router()` for URL generation
- Used by: Html::render() called from `index.php:350` and `index.php:347`

**Data & Storage Layer:**
- Purpose: Database connectivity, query execution, schema management, caching
- Location: `base/fs_db2.php`, `base/fs_mysql.php`, `base/fs_postgresql.php`, `base/fs_schema.php`, `base/fs_cache.php`, `src/Cache/CacheManager.php`
- Contains: DB engines, query builder (`fs_query_builder.php`), XML-based schema management, cache adapters
- Depends on: PHP PDO, Memcached (optional), Symfony Cache
- Used by: Models, controllers, services

## Data Flow

### Primary Web Request Path

1. **Entry point** — `index.php:25-29`: Check for `config.php` existence; redirect to installer if missing.
2. **Framework constants** — `index.php:31-33`: Define `FS_FOLDER`, load `config.php`, check maintenance mode.
3. **Composer autoload** — `index.php:49-60`: Load `vendor/autoload.php` with graceful error on missing deps.
4. **Config bootstrap** — `index.php:77-78`: Run `fs_secret_migrator::ensure()`, load `base/config2.php`.
5. **Self-heal** — `index.php:82-89`: Ensure core DB tables exist via `fs_schema::selfHealCoreTables()`.
6. **Kernel boot** — `index.php:92`: `Kernel::boot()` creates Request, initializes plugins (calls each `Init::init()`), loads routes.
7. **Security gate** — `index.php:98-109`: Apply security headers, check stealth mode / public access gate.
8. **Symfony Router** — `index.php:113-121`: `Kernel::handleRequest()` → `Router::handle()` tries to match a Symfony route; returns Response if matched.
9. **Page name resolution** — `index.php:172-185`: Extract `?page=X` parameter, validate with regex `^[a-z][a-z0-9_]*$`.
10. **Modern controller dispatch** — `index.php:198-264`: Scan active plugins' `Controller/` dirs for class matching page name; instantiate, call `handle()` or `run()`.
11. **Legacy fallback** — `index.php:266-322`: Use `find_controller()` to locate file, require it, instantiate class; 404 if not found.
12. **Controller execution** — Constructor: loads user, menu, page metadata; `private_core()` processes the request.
13. **Template rendering** — `index.php:338-352`: If controller has a template, render via `Html::render()` (full page) or `Html::renderAjax()` (AJAX partial).
14. **Log + cleanup** — `index.php:325-326,355-358`: Save log entries, close DB connections.

### REST API Request Path

1. **Entry point** — `api.php:35-37`: Define `FS_FOLDER`, chdir, load config.
2. **Maintenance check** — `api.php:52-66`: Return 503 JSON if maintenance mode active.
3. **Kernel boot + Container** — `api.php:77-91`: `Kernel::boot()`, verify `api.runtime` service exists in container.
4. **DB connect + self-heal** — `api.php:117-134`: Create `fs_db2`, connect, run `selfHealCoreTables()`.
5. **API runtime handle** — `api.php:137`: `$container->get('api.runtime')->handle()` — delegates to plugin-provided runtime (e.g., `api_base` plugin).

### Stealth Mode Flow

1. `index.php:100-109`: `PublicAccessGate::intercept()` checks if stealth mode is enabled.
2. If enabled and request URL lacks the secret parameter: renders stealth homepage HTML (from `fs_vars`), sets CSP headers, prevents admin panel access.
3. If secret parameter is present: stores flag in session, allows passage to login/backend.

### Event Flow (Plugin Extensions)

1. `Kernel::boot()` → `Plugins::init()` iterates enabled plugins.
2. For each plugin with `Init.php` → calls `init()` method.
3. `Init::init()` registers listeners on `FSEventDispatcher::getInstance()`.
4. At runtime, controllers/models dispatch events: `dispatcher->dispatch(new ModelEvent('before_save', $model))`.
5. Listeners execute, can cancel operations (e.g., `$event->cancel('reason')` prevents save).

**State Management:**
- `$GLOBALS['plugins']`: Active plugin list. Set in `config2.php`.
- `Kernel` singleton: Holds Request and Router instances.
- `FSEventDispatcher` singleton: Holds legacy extension listeners.
- `FSTranslator` singleton: Holds Symfony Translator with loaded catalogs.
- `CacheManager` singleton: Holds Symfony Cache pool.
- `Container`: Lazy service locator with `has()`, `get()` methods.
- `$_SESSION` / `$_COOKIE`: Legacy session and cookie-based state (user nick, log key).
- `fs_core_log` static `$data_log`: In-memory message/error buffer per request.
- `fs_model` static `$checked_tables` and `$base_dir`: Cached table metadata.

## Key Abstractions

**fs_model (abstract model base):**
- Purpose: Base class for all database entities. Provides table existence verification, XML schema parsing, CRUD operations.
- Examples: `model/fs_user.php`, `plugins/business_data/model/empresa.php`, `plugins/catalogo_core/model/core/articulo.php`
- Pattern: Template Method — defines `delete()`, `exists()`, `save()` as abstract; provides concrete helpers for DB queries, escaping, error logging.

**fs_controller → fs_app (legacy controller):**
- Purpose: Page controller with authentication, authorization, menu generation, form handling, error messaging.
- Examples: `controller/login.php`, `controller/admin_users.php`, `plugins/business_data/controller/admin_empresa.php`
- Pattern: Template Method — constructor sets up page config; `private_core()` is the hook for child implementations.

**FSFramework\Core\Base\Controller → PageController (modern controller bridge):**
- Purpose: FS2025-compatible controller for plugins with `handle(Request): Response` and `getPageData(): array` methods.
- Examples: `plugins/catalogo_core/Controller/AdminAlmacenes.php`, `plugins/catalogo_core/Controller/AdminDivisas.php`
- Pattern: Adapter — provides legacy-compatible properties (`$user`, `$db`, `$menu`) while using Symfony Response objects.

**UserAdapter (security bridge):**
- Purpose: Wraps `fs_user` to implement Symfony `UserInterface`, `PasswordAuthenticatedUserInterface`, `EquatableInterface`.
- Location: `src/Security/UserAdapter.php`
- Pattern: Adapter — delegates to legacy `fs_user` properties while exposing modern interface methods (`getRoles()`, `getUserIdentifier()`, `eraseCredentials()`).

**CacheManager (cache unification):**
- Purpose: Single cache entry point combining Symfony Cache adapters (Array + Filesystem + optional Memcached) with legacy cache clearing.
- Location: `src/Cache/CacheManager.php`
- Pattern: Facade / Singleton — `fs_cache` (legacy) delegates internally to CacheManager; provides `clearAll()` that purges Twig, RainTPL, and php_file_cache.

**FSEventDispatcher (event system):**
- Purpose: Symfony EventDispatcher singleton bridged with legacy `fs_extension` hooks.
- Location: `src/Event/FSEventDispatcher.php`
- Pattern: Observer — plugins subscribe via `addListener()`; models/controllers dispatch typed events.

**FSTranslator (i18n):**
- Purpose: Wraps Symfony Translator with YAML/JSON catalog loading from core and plugins.
- Location: `src/Translation/FSTranslator.php`
- Pattern: Singleton + static API — `FSTranslator::trans('key')` or `trans('key')` from helper functions.

**fs_db2 (database abstraction):**
- Purpose: Unified interface over MySQL and PostgreSQL drivers. Static engine singleton.
- Location: `base/fs_db2.php`
- Pattern: Bridge — instantiated per model/controller, delegates all operations to static `fs_mysql` or `fs_postgresql` engine.

## Entry Points

**index.php (Web UI):**
- Location: `index.php`
- Triggers: All browser page requests (`?page=X`)
- Responsibilities: Bootstrap framework, resolve page controller (modern or legacy), render HTML response via Twig

**api.php (REST API):**
- Location: `api.php`
- Triggers: REST API calls (`/api.php/v1/{plugin}/{resource}` or `?api_path=...`)
- Responsibilities: Bootstrap, verify `api.runtime` service, delegate to plugin-provided API handler

**cron.php (Scheduled tasks):**
- Location: `cron.php`
- Triggers: Server cron jobs or manual invocation
- Responsibilities: Execute scheduled plugin operations (batch processing, cleanup)

**install.php (Installer):**
- Location: `install.php`
- Triggers: First-time setup, database configuration
- Responsibilities: Create `config.php`, initialize database schema, create admin user

**maintenance.php (Maintenance mode):**
- Location: `maintenance.php`
- Triggers: Admin maintenance operations
- Responsibilities: Toggle maintenance mode flag

**updater.php (Plugin updates):**
- Location: `updater.php`
- Triggers: Admin-initiated plugin update operations
- Responsibilities: Download, extract, and install plugin updates with backup support via `fs_plugin_manager`

**visual_flow_demo.php (Dev tool):**
- Location: `visual_flow_demo.php`
- Triggers: Development/demo access
- Responsibilities: Visual flow demonstration for internal architecture

## Architectural Constraints

- **Threading:** Single-threaded PHP execution model. No async workers. `set_time_limit(300)` in `index.php:126` extends execution for long-running operations.
- **Global state:** `$GLOBALS['plugins']` (active plugin list), `Kernel::$instance` (singleton), `FSEventDispatcher::$instance`, `FSTranslator::$instance`, `CacheManager::$instance`, `fs_db2::$engine` (static DB engine), `fs_model::$checked_tables` (static table metadata cache). Resetting these statics requires explicit methods (e.g., `CacheManager::reset()`, Reflection in tests).
- **Circular imports:** `fs_controller.php` conditionally includes `plugins/business_data/extras/fs_divisa_tools.php` at line 28-31. `model/fs_user.php` requires `model/core/fs_user.php` which extends `fs_model`. Some plugins require core models via `require_once` in their controller files (e.g., `catalogo_core/Controller/AdminAlmacenes.php:23-25`).
- **Class aliasing:** `model/fs_user.php:28` creates `class fs_user extends FSFramework\model\fs_user` to provide a global alias for the namespaced core class. `model/core/agente.php` follows the same pattern. This is fragile and depends on exact include order.
- **PSR-4 / non-PSR-4 coexistence:** `src/` and `plugins/` are PSR-4 autoloaded via Composer. `base/`, `controller/`, `model/` use `require_once` chains and `fs_autoload` fallback. Plugin model files in `plugins/*/model/core/` use `require_once` within controller files, bypassing autoloading.
- **Symfony integration depth:** Only some Symfony components are wired through the DI Container (`src/DependencyInjection/Container.php`). The `config/routes.php` file is present but is a minimal placeholder (returns an empty function). Routes are discovered primarily through reflection on `FSRoute` and plugin `FSFrame` attributes in `Router.php`.

## Anti-Patterns

### Dual Namespace for Core Models

**What happens:** `fs_user` exists as both `FSFramework\model\fs_user` (in `model/core/fs_user.php`) and a global-alias `class fs_user extends FSFramework\model\fs_user` (in `model/fs_user.php`). Some files reference one, some the other. Similarly, `agente` follows the same pattern.

**Why it's wrong:** Creates confusion about which class is canonical. Tests and plugins may reference different versions, leading to subtle bugs with class existence checks (`class_exists('fs_user')` passing while `FSFramework\model\fs_user` is the real implementation).

**Do this instead:** Consolidate to a single canonical class per entity. Use the global alias only as a thin compatibility shim, and ensure all new code references the namespaced version directly.

### Plugin Model Loading via require_once in Controllers

**What happens:** `plugins/catalogo_core/Controller/AdminAlmacenes.php:23-25` does `require_once` for individual model files (`almacen.php`, `pais.php`) before using them.

**Why it's wrong:** Bypasses PSR-4 autoloading. Creates fragile dependency chains. Makes it hard to refactor or move model files without updating every controller that uses them.

**Do this instead:** Register model directories in Composer autoload or use `fs_model_autoloader` consistently. Let the autoloader resolve model classes by convention.

### Heavy Controller Constructor

**What happens:** `fs_controller::__construct()` (792 lines in total) loads user authentication, builds the full menu tree, checks permissions, processes cookies, generates page metadata, and more — all in the constructor.

**Why it's wrong:** Every page request incurs the full initialization cost, even for pages that don't need the menu or user session (e.g., AJAX endpoints). Makes testability difficult.

**Do this instead:** Split initialization into separate lifecycle hooks (e.g., `init()`, `authenticate()`, `buildMenu()`) that can be called lazily or overridden. Use lazy loading for expensive operations (already partially addressed by `FS_LAZY_MODELS`).

### Static Singleton Proliferation

**What happens:** `Kernel`, `FSEventDispatcher`, `FSTranslator`, `CacheManager`, `ThemeManager`, and `fs_db2::$engine` all use static singleton patterns. Resetting them for testing requires Reflection-based manipulation.

**Why it's wrong:** Creates hidden dependencies, makes unit testing harder, prevents parallel test execution, couples code to global state.

**Do this instead:** Use the DI Container for service wiring where possible. For legacy code that must use statics, provide explicit `reset()` methods and document them in `tests/bootstrap.php`.

### Mixed Error Handling (errors array vs exceptions)

**What happens:** Legacy code uses `$this->new_error_msg()` / `$this->get_errors()` stored in `fs_core_log::$data_log`. Modern `src/` code uses exceptions (`ApiException`, `ValidationException`). The two systems coexist without bridge in some areas.

**Why it's wrong:** Callers need to know which error system a component uses. Errors from legacy models don't propagate as exceptions to modern controllers and vice versa.

**Do this instead:** Add a bridge that converts `fs_core_log` errors into exceptions for modern consumers, or wrap legacy model calls in try/convert patterns.

## Error Handling

**Strategy:** Dual system — legacy error arrays (`fs_core_log::$data_log`) for `base/` and `controller/` code; exceptions for `src/` and modern plugin code.

**Legacy Patterns:**
- `$this->new_error_msg('message')` — adds error to in-memory buffer
- `$this->new_message('message')` — success/info message
- `$this->new_advice('message')` — warning/tip
- Accessible via `$this->get_errors()`, `$this->get_messages()`, `$this->get_advices()`
- Buffered errors flushed to database in `fs_log_manager::save()` at end of request

**Modern Patterns:**
- `throw new ApiException('message', 400)` — REST API errors
- `throw new ValidationException('Invalid data')` — validation failures
- Try/catch in `index.php:119-121` catches Symfony Router exceptions
- Try/catch in `api.php:136-152` catches API runtime exceptions

## Cross-Cutting Concerns

**Logging:** Legacy `fs_core_log` stores messages/errors/advices/sql history in static `$data_log`. Saved to `fs_logs` table by `fs_log_manager::save()` at request end. Optional Monolog integration suggested in `composer.json:42`.

**Validation:** Dual approach — legacy `test()` methods on models (calling `$this->new_error_msg()`); modern `ValidatorTrait` using Symfony Validator constraints (`#[Assert\NotBlank]`). Both can coexist in a single model.

**Authentication:** Legacy cookie-based (`fsNick` + `fsLogKey`) checked in `fs_controller` constructor. Modern `UserAdapter` wraps `fs_user` for Symfony Security compatibility. `ChainedAuthAdapter` in `src/Api/Auth/` supports multiple API auth methods.

**CSRF Protection:** Symfony CSRF via `CsrfManager` (`src/Security/CsrfManager.php`). Auto-validated in `fs_controller::pre_private_core()`. Templates use `{{ csrf_field() }}` in forms. Soft mode available via `FS_CSRF_SOFT=true` for migration.

**Security Headers:** `SecurityHeaders::applyDefaultHeaders()` (`src/Security/SecurityHeaders.php`) called in `index.php:98`. Stealth mode adds additional CSP headers via `StealthMode`.

**URL Generation:** `Kernel::router()->generate('route_name')` for modern routes. `$this->url()` on controllers for legacy `?page=X` URLs. `SafeRedirect::validate()` prevents open redirect attacks.

---

*Architecture analysis: 2026-05-16*
