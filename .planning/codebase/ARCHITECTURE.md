<!-- refreshed: 2026-05-16 -->
# Architecture

**Analysis Date:** 2026-05-16

## System Overview

```text
┌─────────────────────────────────────────────────────────────┐
│                    Entry Points                              │
├──────────────────────────────┬──────────────────────────────┤
│         index.php            │          api.php              │
│    (Web UI / Controllers)     │    (REST API via Container)  │
└─────────────┬────────────────┴──────────────┬───────────────┘
              │                               │
              ▼                               ▼
┌─────────────────────────────────────────────────────────────┐
│               Symfony Kernel / Container                     │
│  `src/Core/Kernel.php`  `src/DependencyInjection/Container.php`
├─────────────────────────────────────────────────────────────┤
│  Controller Layer                                           │
│  ┌─────────────────────┐  ┌────────────────────────────────┐│
│  │ Legacy Controllers    │  │ Modern Controllers             ││
│  │ `controller/*.php`    │  │ `plugins/*/Controller/*.php`   ││
│  │ extend fs_controller  │  │ PSR-4 namespaced, handle()    ││
│  └──────────┬───────────┘  └──────────────┬─────────────────┘│
│             │                              │                  │
├─────────────┴──────────────────────────────┴─────────────────┤
│  Model Layer (Data Access)                                    │
│  ┌─────────────────────┐  ┌────────────────────────────────┐│
│  │ Legacy Models         │  │ Modern Models                  ││
│  │ `model/*.php`         │  │ `plugins/*/Model/*.php`        ││
│  │ extend fs_model       │  │ extend fs_model + Validator    ││
│  └──────────┬───────────┘  └──────────────┬─────────────────┘│
│             │                              │                  │
├─────────────┴──────────────────────────────┴─────────────────┤
│  Database Abstraction                                         │
│  `base/fs_db2.php` → `base/fs_mysql.php` / `fs_postgresql.php`│
└─────────────────────────────────────────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────────────────────────┐
│  Storage Layer                                               │
│  MariaDB/MySQL/PostgreSQL  |  `tmp/` (cache)  |  Filesystem  │
└─────────────────────────────────────────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| Kernel | App bootstrap, request creation, trusted proxies, routing bridge | `src/Core/Kernel.php` |
| Container | Service locator; DB, cache, password hasher, CSRF manager access | `src/DependencyInjection/Container.php` |
| fs_controller | Legacy controller base: auth, menu, page lifecycle, template rendering | `base/fs_controller.php` |
| fs_model | Legacy model base: DB connection, table validation, CRUD abstract | `base/fs_model.php` |
| fs_db2 | Database abstraction: connect, select, exec, escape, transactions | `base/fs_db2.php` |
| fs_core_log | Message/error/advice/SQL history collection (PSR-3 compatible) | `base/fs_core_log.php` |
| CacheManager | Multi-adapter cache: Symfony Cache + Memcached + RainTPL purge | `src/Cache/CacheManager.php` |
| PasswordHasherService | Password hashing (argon2id/bcrypt), legacy migration bridge | `src/Security/PasswordHasherService.php` |
| CsrfManager | CSRF token generation and validation via Symfony Security | `src/Security/CsrfManager.php` |
| FSEventDispatcher | Plugin event hooks (controller, model lifecycle events) | `src/Event/FSEventDispatcher.php` |
| FSTranslator | Translation via Symfony Translation + YAML catalogs | `src/Translation/FSTranslator.php` |
| FormHelper | Symfony Form builder with CSRF protection | `src/Form/FormHelper.php` |
| StealthMode | Public homepage gate; hides admin behind secret URL parameter | `src/Core/StealthMode.php` |
| HtmlSanitizer | HTML sanitization; blocks inline scripts, event handlers | `src/Core/HtmlSanitizer.php` |
| CssSanitizer | CSS sanitization via PHP CSS Parser; whitelist-based | `src/Core/CssSanitizer.php` |
| PublicAccessGate | Stealth/public access interceptor (runs early in index.php) | `src/Core/PublicAccessGate.php` |
| SessionManager | Secure session handling with CSRF token rotation | `src/Security/SessionManager.php` |
| LegacyCompatibility | All legacy password verification (SHA1/MD5), migration | `plugins/legacy_support/LegacyCompatibility.php` |

## Pattern Overview

**Overall:** Hybrid architecture — legacy FacturaScripts 2017 patterns coexist with modern Symfony 7.4 patterns, converging toward Symfony-first.

**Key Characteristics:**
- **Dual controller/model system:** Legacy flat files (`controller/`, `model/`) alongside PSR-4 namespaced classes (`plugins/*/Controller/`, `plugins/*/Model/`)
- **Service Locator via Container:** `Container::get('service')` rather than constructor injection for most services
- **Event-driven plugin extension:** Plugins hook into framework lifecycle via `FSEventDispatcher`
- **Thin bridges for legacy:** Modern Symfony services (`PasswordHasherService`, `CsrfManager`) exist in `src/Security/`; legacy compatibility lives in `plugins/legacy_support/` and bridges like `LegacyAuthBridge`
- **Symfony-first policy:** New code prefers Symfony components; legacy code gets thin wrappers
- **PHP 8.2+ features throughout:** Attributes (`#[FSRoute]`, `#[Assert]`), typed properties, union types, named arguments, `match` expressions

## Layers

**Entry Points Layer:**
- Purpose: Bootstrap and route incoming HTTP requests
- Location: `index.php` (web UI), `api.php` (REST API), `cron.php` (scheduled tasks), `install.php` (setup)
- Contains: Request creation, security header injection, maintenance mode check, stealth mode gate, routing dispatch
- Depends on: Kernel, Container, config.php
- Used by: All HTTP traffic

**Kernel/Container Layer:**
- Purpose: Framework initialization, service registry, Symfony integration
- Location: `src/Core/Kernel.php`, `src/DependencyInjection/Container.php`
- Contains: Request from globals, trusted proxies, router initialization, service definitions
- Depends on: Symfony HTTP Foundation, Routing, DI
- Used by: Entry points, controllers

**Controller Layer:**
- Purpose: Handle page requests, orchestrate logic, prepare view data
- Location: `controller/` (legacy), `plugins/*/controller/` (legacy), `plugins/*/Controller/` (modern PSR-4), `src/Controller/` (base)
- Contains: Page controllers extending `fs_controller` or implementing `handle()`
- Depends on: Model layer, DB, Container, Template engine
- Used by: Entry point routing

**Model Layer:**
- Purpose: Data access, validation, business logic, database schema management
- Location: `model/` (legacy), `plugins/*/model/` (legacy), `plugins/*/Model/` (modern PSR-4)
- Contains: Classes extending `fs_model` with `test()`, `save()`, `delete()`, `exists()`
- Depends on: fs_db2, fs_core_log, ValidatorTrait
- Used by: Controllers

**Database Abstraction Layer:**
- Purpose: Unified interface over MySQL/PostgreSQL
- Location: `base/fs_db2.php`, `base/fs_db_engine.php`, `base/fs_mysql.php`, `base/fs_postgresql.php`, `base/FsMysqlSchemaUtility.php`
- Contains: Query execution, escaping, transactions, schema operations
- Depends on: PHP PDO extensions
- Used by: Models, controllers (direct queries)

**Security Layer:**
- Purpose: Authentication, authorization, CSRF, password hashing, encryption
- Location: `src/Security/` (16 files)
- Contains: PasswordHasherService, CsrfManager, SessionManager, CookieSigner, EncryptionService, SafeRedirect, SecretManager, LegacyAuthBridge, SecurityHeaders, etc.
- Depends on: Symfony Security CSRF, PasswordHasher
- Used by: Controllers, login flow, session management

**Template Layer:**
- Purpose: Render HTML views with translations
- Location: `themes/AdminLTE/view/` (Twig), `view/` (shared assets)
- Contains: `.html.twig` templates, macros, header/footer, blocks
- Depends on: Twig 3, TranslationExtension
- Used by: Controllers via `Html::render()`

## Data Flow

### Primary Web Request Path

1. `index.php` — Check PHP version, load config, maintenance mode gate, autoloader (`index.php:20-74`)
2. `src/Core/Kernel::boot()` — Initialize plugins, create Request from globals (`index.php:91`)
3. `src/Core/StealthMode` + `PublicAccessGate` — Intercept if stealth mode active (`index.php:100-108`)
4. `src/Core/Kernel::handleRequest()` — Try Symfony routing bridge (`index.php:113`)
5. Controller discovery — Match `?page=` parameter to legacy or modern controller class (`index.php:186-320`)
6. Controller `private_core()` or `handle()` — Business logic, DB queries, view preparation
7. `src/Core/Html::render()` — Render Twig template with `fsc` context (`index.php:349`)
8. `fs_log_manager::save()` — Persist log entries (`index.php:325,354`)

### REST API Request Path

1. `api.php` — Load config, maintenance mode gate, autoloader (`api.php:35-71`)
2. `Kernel::boot()` — Initialize Container (`api.php:78`)
3. `Container::get('api.runtime')` — Get API runtime handler (`api.php:81`)
4. DB connection via `fs_db2::connect()` (`api.php:118-127`)
5. `api.runtime->handle()` — Dispatch to API endpoint/middleware chain (`api.php:137`)

**State Management:**
- Session: PHP native sessions managed by `src/Security/SessionManager.php`
- User auth: `base/fs_login.php` verifies credentials, sets session user
- Global state: `$GLOBALS['plugins']` holds active plugin list (loaded from `tmp/enabled_plugins.list`)
- Config: Constants defined in `config.php`/`config2.php`, runtime overrides in `tmp/config2.ini`
- Cache: Multi-adapter chain via `CacheManager` singleton

## Key Abstractions

**fs_controller (Legacy Controller Base):**
- Purpose: Base class for all page controllers; manages auth, menu, templates, error/message handling
- Examples: `controller/admin_home.php`, `controller/login.php`, `plugins/business_data/controller/`
- Pattern: `private_core()` method invoked after auth check; uses `ResponseTrait` for modern response types

**fs_model (Legacy Model Base):**
- Purpose: Abstract base for all database models; auto-creates/validates table schemas
- Examples: `model/fs_user.php`, `plugins/business_data/model/empresa.php`
- Pattern: Four abstract methods (`test()`, `save()`, `delete()`, `exists()`), XML schema in `model/table/`

**Modern Controller Pattern:**
- Purpose: PSR-4 namespaced controllers with Symfony Request/Response
- Examples: `plugins/catalogo_core/Controller/AdminAlmacenes.php`, `plugins/catalogo_core/Controller/AdminDivisas.php`
- Pattern: `handle(Request): Response` method, `getPageData()` for metadata, discovered by index.php scanning

**Modern Model Pattern:**
- Purpose: Namespaced models extending fs_model with ValidatorTrait
- Examples: `plugins/catalogo_core/Model/Articulo.php`, `plugins/catalogo_core/Model/Fabricante.php`
- Pattern: `#[Assert]` attributes for validation, extends `\fs_model`

## Entry Points

**index.php:**
- Location: `/index.php`
- Triggers: All web UI requests (`?page=...`)
- Responsibilities: Bootstrap, auth, controller dispatch, template rendering, security headers

**api.php:**
- Location: `/api.php`
- Triggers: REST API requests (`/api.php/v1/...`)
- Responsibilities: API routing, authentication middleware, JSON responses

**cron.php:**
- Location: `/cron.php`
- Triggers: Scheduled tasks (system cron)
- Responsibilities: Plugin cron jobs, maintenance tasks

**install.php:**
- Location: `/install.php`
- Triggers: First-run setup
- Responsibilities: Database configuration, initial admin creation

**maintenance.php:**
- Location: `/maintenance.php`
- Triggers: Direct access
- Responsibilities: Maintenance mode management

## Architectural Constraints

- **Threading:** Single-threaded PHP execution per request (shared-nothing architecture). No worker threads or async operations at framework level.
- **Global state:** `$GLOBALS['plugins']` (active plugin list), `$GLOBALS['config2']` (locale/number format settings), `CacheManager` singleton, `FSEventDispatcher` singleton, `Container` singleton. Test isolation requires resetting singletons.
- **Circular imports:** Legacy code uses `require_once` chains (`fs_controller.php` → `fs_model.php` → `fs_db2.php`). Modern `src/` code uses autoloader; no known circular dependencies.
- **Database coupling:** Many models require a live DB connection even for read-only operations. Tests use anonymous subclasses to bypass the constructor.
- **Plugin system:** Plugins are gitignored except core plugins; discovered via `$GLOBALS['plugins']` from `tmp/enabled_plugins.list`.

## Anti-Patterns

### Singleton Service Locator

**What happens:** Services are accessed via `Container::get('name')` or `Container::db()` rather than injected as constructor dependencies.
**Why it's wrong:** Makes dependencies implicit, harder to test, creates tight coupling to the Container class.
**Do this instead:** Use constructor injection where possible. In legacy code where injection isn't feasible, document the dependency at the top of the method.

### Direct require_once in src/ Code

**What happens:** `index.php` uses `require_once` for specific `src/` files (`StealthMode.php`, `PublicAccessGate.php`) rather than relying on autoloading.
**Why it's wrong:** Bypasses PSR-4 autoloading, creates fragile file-path dependencies.
**Do this instead:** Use `use` statements and let the Composer autoloader handle it. The files are only `require_once`'d because they run before the autoloader is fully initialized.

### Mixed Legacy/Modern Patterns in index.php

**What happens:** `index.php` mixes modern Kernel boot with legacy `require_once` chains and fallback controller discovery logic spanning ~200 lines.
**Why it's wrong:** The entry point is complex and fragile; adding new controller types requires modifying routing logic.
**Do this instead:** Move controller discovery to `Router.php` or `Kernel.php`, let the routing bridge handle all dispatch.

## Error Handling

**Strategy:** Framework-level error collection rather than exception propagation. Most methods return `bool`; errors/messages are collected via `fs_core_log`.

**Patterns:**
- `$this->new_error_msg($message)` — Add error to collection
- `$this->new_message($message)` — Add success message
- `$this->new_advice($message)` — Add warning
- `$this->get_errors()` — Retrieve all errors
- `fatal_handler()` — Registered shutdown function for fatal errors (`index.php:166`)
- PSR-3 compatible logging in `fs_core_log` (emergency, alert, critical, error, warning, notice, info, debug)
- Legacy `@` suppression: 5 remaining instances in `base/fs_maintenance_mode.php` (3) and `base/fs_functions.php` (2), all related to `file_get_contents` and `session_start` (down from all base files before v0.10.8)

## Cross-Cutting Concerns

**Logging:** `fs_core_log` collects everything per-request; `fs_log_manager` persists to DB. `error_log()` for system-level logging. DebugBar renders when `FS_DEBUG` is enabled.
**Validation:** Symfony Validator via `ValidatorTrait` in modern models; legacy `test()` method pattern in models.
**Authentication:** `fs_controller` `pre_private_core()` checks login, CSRF. `base/fs_login.php` handles credential verification. `src/Security/PasswordHasherService.php` for modern hashing; `plugins/legacy_support/LegacyCompatibility.php` for legacy password fallback.
**Security Headers:** `src/Security/SecurityHeaders.php::applyDefaultHeaders()` applied early in `index.php`.

---

*Architecture analysis: 2026-05-16*
