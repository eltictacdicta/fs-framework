# Codebase Structure

**Analysis Date:** 2026-05-16

## Directory Layout

```
[project-root]/
├── index.php                  # Main web entry point (page dispatch, rendering)
├── api.php                    # REST API entry point
├── cron.php                   # Cron job entry point
├── install.php                # First-time installer
├── maintenance.php            # Maintenance mode control
├── updater.php                # Plugin update handler
├── visual_flow_demo.php       # Dev visualization tool
├── debug_loader.php           # Debug mode loader
├── build.sh                   # Frontend asset build script
├── composer.json              # PHP dependencies + PSR-4 autoload config
├── composer.lock              # Composer lockfile
├── package.json               # Frontend npm dependencies
├── phpunit.xml                # PHPUnit 11 configuration
├── phpstan.neon               # PHPStan static analysis config
├── phpstan-baseline.neon      # PHPStan baseline (known issues)
├── rector.php                 # Rector PHP upgrade rules
├── tailwind.config.js         # Tailwind CSS configuration
├── VERSION                    # Framework version
├── VERSION-FS2017             # Legacy FacturaScripts version reference
├── VERSION-FS2025             # Modern FS2025 version reference
├── README.md                  # Project README
├── CONTRIBUTING.md            # Contribution guidelines
├── COPYING                    # LGPL 3.0 license
├── AGENTS.md                  # Agentic coding guide (canonical baseline)
├── .htaccess                  # Apache URL rewriting rules
├── robots.txt                 # Search engine directives
├── htaccess-sample            # Sample .htaccess for deployments
├── .gitignore                 # Git ignored files
├── .gitmodules                # Git submodules config
├── .ddev/                     # DDEV local development environment
├── .agent/                    # Agent configuration
├── .cursor/                   # Cursor IDE rules
├── .github/                   # GitHub workflows and Copilot instructions
├── .planning/                 # Planning artifacts
│   └── codebase/              # Codebase map output (this directory)
├── apk/                       # APK packaging support
├── backups/                   # Backup storage directory
├── base/                      # Legacy core framework classes (44 files)
├── config/                    # Framework configuration
│   └── routes.php             # Symfony route definitions (placeholder)
├── controller/                # Legacy page controllers (14 files)
├── dev-tools/                 # Development utilities
├── docs/                      # Documentation
│   ├── TRANSLATION.md         # i18n system documentation
│   ├── MEJORAS_PROPUESTAS.md  # Proposed improvements
│   ├── examples/              # Example code
│   └── reviews/               # Code review documents
├── extras/                    # Legacy third-party libraries
│   └── phpmailer/             # PHPMailer (extracted from vendor)
├── imgs/                      # Static images
├── model/                     # Core legacy models + XML schemas
│   ├── core/                  # Namespaced model implementations
│   └── table/                 # XML table schema definitions
├── plugins/                   # Plugin architecture (8 plugins)
│   ├── business_data/         # Base business data (empresa, divisa, serie)
│   ├── catalogo_core/         # Product catalog core (articulos, familias)
│   ├── clientes_catalogo/     # Client catalog integration
│   ├── clientes_core/         # Client management core
│   ├── clientes_facturacion/  # Client billing models
│   ├── facturascripts_support/ # FS2025 compatibility support
│   ├── hola_mundo/            # Example/hello-world plugin
│   └── legacy_support/        # FS2017 legacy compatibility
├── scripts/                   # Utility scripts
├── src/                       # Modern PSR-4 code (FSFramework namespace)
│   ├── Api/                   # REST API primitives
│   ├── Attribute/             # PHP 8 attributes (FSRoute)
│   ├── Cache/                 # Cache manager
│   ├── Controller/            # Modern controller bases
│   ├── Core/                  # Kernel, Router, Html, Plugins, Theme
│   ├── DependencyInjection/   # Service container
│   ├── Dinamic/               # Dynamic model wrappers
│   ├── Event/                 # Event dispatcher system
│   ├── Form/                  # Symfony Forms helper
│   ├── Security/              # Authentication, CSRF, crypto
│   ├── Traits/                # Shared traits (ValidatorTrait)
│   ├── Translation/           # i18n system
│   └── Twig/                  # Twig extensions
├── tests/                     # PHPUnit test suites
│   ├── bootstrap.php          # Test environment bootstrap
│   ├── Api/                   # API component tests
│   ├── Base/                  # Core legacy class tests
│   ├── Cache/                 # Cache component tests
│   ├── ClientesCore/          # Client core plugin tests
│   ├── Components/            # Core plugin component tests
│   ├── Core/                  # Modern core tests
│   ├── Event/                 # Event system tests
│   ├── Form/                  # Form helper tests
│   ├── Security/              # Security component tests
│   ├── Traits/                # Trait tests
│   └── Translation/           # Translation system tests
├── themes/                    # Theme templates
│   └── AdminLTE/              # Default theme
│       ├── css/               # Theme stylesheets
│       ├── img/               # Theme images
│       ├── js/                # Theme JavaScript
│       ├── translations/      # Theme-specific translations
│       ├── view/              # Twig template files
│       ├── functions.php      # Theme helper functions
│       └── theme.ini          # Theme metadata
├── tmp/                       # Runtime temporary files
├── translations/              # Core translation catalogs (YAML)
├── vendor/                    # Composer dependencies (gitignored)
└── view/                      # Static shared assets
    ├── css/                   # Bootstrap, Font Awesome
    ├── fonts/                 # Web fonts
    └── js/                    # jQuery, Bootstrap JS
```

## Directory Purposes

**`base/` — Legacy Core Framework:**
- Purpose: Foundation classes inherited by all controllers and models. The original FacturaScripts 2017 codebase, partially modernized.
- Contains: Controller base (`fs_controller` extends `fs_app`), model base (`fs_model`), DB layer (`fs_db2`, `fs_mysql`, `fs_postgresql`), cache (`fs_cache` bridges to modern CacheManager), logging (`fs_core_log`), auth (`fs_login`), plugin management (`fs_plugin_manager`), schema management (`fs_schema`), query builder (`fs_query_builder`), Excel export (`fs_excel`), file management (`fs_file_manager`), IP filtering (`fs_ip_filter`), list controllers (`fs_list_controller`, `fs_edit_controller`).
- Key files: `fs_controller.php`, `fs_model.php`, `fs_db2.php`, `fs_app.php`, `fs_cache.php`

**`controller/` — Legacy Page Controllers:**
- Purpose: Concrete page implementations that handle HTTP requests for specific admin pages.
- Contains: `login.php`, `admin_home.php`, `admin_users.php`, `admin_user.php`, `admin_agente.php`, `admin_agentes.php`, `admin_info.php`, `admin_email.php`, `admin_rol.php`, `admin_stealth.php`, `admin_system_branding.php`, `admin_orden_menu.php`, `password_reset.php`, `force_password_change.php`
- Key files: `login.php` (authentication), `admin_users.php` (user management)

**`model/` — Core Models:**
- Purpose: Database entity models for framework-level data (users, roles, pages, extensions, logs).
- Contains: `fs_user.php` (user), `fs_rol.php` (role), `fs_page.php` (menu page), `fs_access.php` (role-page access), `fs_rol_access.php` (role access rules), `fs_rol_user.php` (user-role assignment), `fs_extension.php` (legacy hooks), `fs_log.php` (log entry), `fs_var.php` (key-value settings), `fs_relation.php` (relationships), `agente.php` (agent).
- Subdirectory `core/`: Namespaced implementations (`FSFramework\model\fs_user`, `FSFramework\model\agente`).
- Subdirectory `table/`: 11 XML files defining database table structures.

**`src/` — Modern PSR-4 Code:**
- Purpose: Symfony 7.4-based modern services, bootstrapper, and API primitives. All code is namespaced under `FSFramework\`.
- Contains: 13 subdirectories organized by concern (see below).

**`src/Core/` — Kernel and Bootstrapping:**
- Purpose: Application kernel, routing, HTML rendering bridge, plugin lifecycle, theme management, stealth mode, mail service, debug bar.
- Key files: `Kernel.php`, `Router.php`, `Html.php`, `Plugins.php`, `ThemeManager.php`, `StealthMode.php`, `PublicAccessGate.php`, `DebugBar.php`, `MailService.php`, `Tools.php`, `UploadedFile.php`, `Response.php`
- Subdirectory `Base/`: `Controller.php` (modern controller base), `ControllerPermissions.php` — these are the parent classes for FS2025-style plugin controllers.
- Subdirectory `Template/`: `InitClass.php` — template initialization.
- Subdirectory `Exception/`: Kernel exceptions.

**`src/Security/` — Security Services:**
- Purpose: Authentication, authorization, cryptography, session management, CSRF protection.
- Key files: `CsrfManager.php`, `PasswordHasherService.php`, `UserAdapter.php`, `CookieSigner.php`, `SignedUrlService.php`, `EncryptionService.php`, `SecretManager.php`, `SafeRedirect.php`, `SessionManager.php`, `SecurityHeaders.php`, `LoginThrottle.php`, `PrivacyMasker.php`, `LegacyAuthBridge.php`, `LegacyUserService.php`, `SessionPolicy.php`
- Subdirectory `Exception/`: Security-specific exceptions.

**`src/Event/` — Event System:**
- Purpose: Symfony EventDispatcher extended with legacy `fs_extension` compatibility.
- Key files: `FSEventDispatcher.php`, `FSEvent.php`, `ModelEvent.php`, `ControllerEvent.php`, `TwigInitEvent.php`, `TwigLoaderEvent.php`

**`src/Translation/` — i18n System:**
- Purpose: Symfony Translation wrapper supporting YAML and JSON catalogs from core and plugins.
- Key files: `FSTranslator.php`, `TranslationHelper.php`, `FS2025JsonLoader.php`

**`src/Cache/` — Caching:**
- Purpose: Unified cache manager (Symfony Cache) with legacy bridge.
- Key files: `CacheManager.php`, `DataSrcRepository.php`

**`src/Api/` — REST API Primitives:**
- Purpose: API attributes, authentication contracts, middleware interfaces, exceptions, helpers. The actual API runtime (router, endpoint registry) lives in a plugin (e.g., `api_base`).
- Subdirectory `Attribute/`: `ApiResource.php`, `ApiField.php`, `ApiHidden.php`, `Operation.php` — PHP 8 attributes for API resource declaration.
- Subdirectory `Auth/`: `ChainedAuthAdapter.php`, `Contract/` (interfaces).
- Subdirectory `Middleware/`: `AuthMiddleware.php`, `CorsMiddleware.php`, `RateLimitMiddleware.php`, `MiddlewareInterface.php`.
- Subdirectory `Exception/`: API-specific exception hierarchy.
- Subdirectory `Helper/`: `RequestHelper.php`.

**`src/Attribute/` — PHP 8 Attributes:**
- Purpose: Framework-level attributes for routing and configuration.
- Key files: `FSRoute.php` — attribute for controller route registration.

**`src/Controller/` — Modern Controller Base:**
- Purpose: FS2025-style controller base classes. Thin wrapper over `src/Core/Base/Controller.php`.
- Key files: `PageController.php`

**`src/DependencyInjection/` — Service Container:**
- Purpose: Service locator with lazy-loaded core services.
- Key files: `Container.php`

**`src/Dinamic/Model/` — Dynamic Models:**
- Purpose: Lightweight model wrappers for FS2025 compatibility (note: intentional typo `Dinamic` from original codebase).
- Key files: `User.php`

**`src/Form/` — Form Handling:**
- Purpose: Symfony Forms helper with CSRF integration.
- Key files: `FormHelper.php`

**`src/Traits/` — Shared Traits:**
- Purpose: Reusable behavior mixins.
- Key files: `ValidatorTrait.php` — Symfony Validator integration for models.

**`src/Twig/` — Twig Extensions:**
- Purpose: Custom Twig functions and filters.
- Key files: `TranslationExtension.php` — `trans()` function and `|trans` filter.

**`plugins/` — Plugin Architecture:**
- Purpose: Extensible plugin system. Each plugin is a self-contained module with its own controllers, models, views, translations, and tests.
- Contains: 8 plugins with varying complexity.

**`themes/AdminLTE/` — Default Theme:**
- Purpose: Twig template theme based on AdminLTE CSS framework.
- Contains: `view/` (templates organized as master layouts, macros, blocks, tabs), `css/`, `js/`, `img/` (theme assets), `translations/` (theme-specific strings), `functions.php` (theme helpers), `theme.ini` (metadata).

**`view/` — Shared Static Assets:**
- Purpose: Frontend dependencies copied from `node_modules/` via `build.sh`.
- Contains: Bootstrap CSS/JS, Font Awesome fonts/CSS, jQuery.

**`tests/` — PHPUnit Test Suites:**
- Purpose: Unit tests for core classes and components. Tests are organized by area matching the source code organization.
- Contains: 12 test suite directories, `bootstrap.php` (minimal test environment setup without database).

**`translations/` — Core Translations:**
- Purpose: YAML translation catalogs for framework-level strings.
- Contains: `messages.*.yaml` files — the source of truth for core i18n.

**`tmp/` — Runtime Cache:**
- Purpose: Symfony cache, Twig template cache, RainTPL cache, route cache, portal config.
- Generated: Yes
- Committed: No (gitignored)

**`config/` — Configuration:**
- Purpose: Framework configuration files loaded at bootstrap.
- Contains: `routes.php` (Symfony route definitions — currently a placeholder).

**`vendor/` — Composer Dependencies:**
- Purpose: Third-party PHP libraries managed by Composer.
- Generated: Yes
- Committed: No (gitignored)

**`extras/` — Legacy Third-Party Code:**
- Purpose: Extracted/shipped copies of third-party libraries that predate Composer adoption.
- Contains: `phpmailer/` (PHPMailer 5.x series), `phpmailer_compat.php` (compatibility bridge), `xlsxwriter.class.php` (Excel export).

**`docs/` — Documentation:**
- Purpose: Project documentation beyond the README.
- Contains: `TRANSLATION.md`, `MEJORAS_PROPUESTAS.md`, `reviews/`, `examples/`

**`dev-tools/` — Development Tools:**
- Purpose: PHPStan, Rector, and other static analysis tooling.
- Contains: Bundled PHPStan and Rector binaries.

**`.ddev/` — DDEV Environment:**
- Purpose: DDEV local development configuration (Docker-based PHP, MySQL/PostgreSQL, web server).
- Generated: Yes (partially)

**`scripts/` — Utility Scripts:**
- Purpose: Build, deployment, and maintenance scripts.

**`backups/` — Backup Storage:**
- Purpose: Plugin backup directories created by `fs_plugin_manager` during update operations.

**`apk/` — APK Packaging:**
- Purpose: Android APK build/packaging support for mobile companion apps.

## Key File Locations

**Entry Points:**
- `index.php`: Main web UI entry point — bootstraps framework, dispatches to page controllers, renders HTML
- `api.php`: REST API entry point — JSON-only, delegates to `api.runtime` service
- `cron.php`: Scheduled task runner
- `install.php`: First-time installation wizard
- `updater.php`: Plugin update handler

**Configuration:**
- `config.php`: Database credentials and framework constants (gitignored, created by installer)
- `base/config2.php`: Secondary configuration and plugin loading logic
- `config/routes.php`: Symfony route definitions (placeholder, routes discovered via attributes)
- `phpunit.xml`: PHPUnit 11 test configuration with test suites
- `phpstan.neon`: PHPStan static analysis rules
- `phpstan-baseline.neon`: Known PHPStan issues (accepted baseline)
- `composer.json`: Dependencies, PSR-4 autoload mapping, scripts

**Core Logic:**
- `base/fs_controller.php`: Legacy controller base class (1081 lines) — auth, menu, template, CSRF
- `base/fs_model.php`: Abstract model base class (585 lines) — DB ops, schema checking, caching
- `base/fs_db2.php`: Database abstraction layer (510 lines) — MySQL/PostgreSQL bridge
- `base/fs_app.php`: Base application class (235 lines) — error/message/advice buffers, timing
- `src/Core/Kernel.php`: Modern kernel singleton (217 lines) — bootstrap, Request creation, router init
- `src/Core/Router.php`: Symfony routing (536 lines) — route loading, caching, URL generation, attribute discovery
- `src/Core/Html.php`: Template rendering bridge (677 lines) — Twig loader, RainTPL compatibility, AJAX render
- `src/Core/Plugins.php`: Plugin lifecycle manager (226 lines) — Init class invocation, public path registration
- `src/Core/StealthMode.php`: Stealth mode (1073 lines) — public homepage, CSS inlining, CSP enforcement
- `src/Security/UserAdapter.php`: fs_user to Symfony UserInterface adapter (270 lines)

**Template Engine:**
- `src/Core/Html.php`: Main rendering entry point — `Html::render()` and `Html::renderAjax()`
- `themes/AdminLTE/view/master/Base.html.twig`: Root layout template with blocks for header, content, footer
- `themes/AdminLTE/view/master/MenuTemplate.html.twig`: Layout with AdminLTE sidebar menu
- `themes/AdminLTE/view/Macro/Menu.html.twig`: Reusable menu rendering macros
- `themes/AdminLTE/view/Macro/Utils.html.twig`: Utility macros (forms, inputs, tables)
- `themes/AdminLTE/view/block/`: Reusable content block templates
- `themes/AdminLTE/view/tab/`: Tab panel templates

**Testing:**
- `tests/bootstrap.php`: Test environment setup — defines framework constants without database connection
- `tests/Base/`: Tests for `base/` classes (fs_model methods, core log, functions, IP filter, query builder, DBAL helper, MySQL normalization)
- `tests/Security/`: Security component tests (PasswordHasherService, CsrfManager)
- `tests/Traits/`: ValidatorTrait tests
- `tests/Cache/`: CacheManager tests
- `tests/Api/`: ChainedAuthAdapter tests
- `tests/Components/`: Core plugin component tests (StealthMode)
- `tests/Event/`: Event system tests
- `tests/Core/`: Modern core tests
- `tests/Form/`: FormHelper tests
- `tests/Translation/`: Translation system tests
- `plugins/*/tests/`: Plugin-specific test suites (auto-discovered by root `phpunit.xml`)

**Translations:**
- `translations/messages.es.yaml`: Spanish translations (core)
- `translations/messages.en.yaml`: English translations (core)
- `themes/AdminLTE/translations/`: Theme-specific translation strings
- `plugins/*/translations/messages.{locale}.yaml`: Plugin translations (YAML, preferred format)
- `plugins/*/Translation/{locale}.json`: Plugin translations (JSON, FS2025 compatibility)

**Database Schema:**
- `model/table/fs_users.xml`: Users table definition
- `model/table/fs_roles.xml`: Roles table definition
- `model/table/fs_pages.xml`: Menu pages table definition
- `model/table/fs_access.xml`: Role-page access table definition
- `model/table/fs_vars.xml`: Key-value settings table
- `model/table/fs_logs.xml`: Log entries table
- `plugins/*/model/table/*.xml`: Plugin-specific table schemas

## Naming Conventions

**Files:**
- Legacy PHP: `snake_case.php` (e.g., `fs_controller.php`, `admin_users.php`, `fs_db2.php`)
- Modern PSR-4 PHP: `PascalCase.php` matching class name (e.g., `Kernel.php`, `CacheManager.php`, `UserAdapter.php`)
- XML schemas: `snake_case.xml` matching table name (e.g., `fs_users.xml`, `agentes.xml`)
- Twig templates: `snake_case.html.twig` (e.g., `admin_home.html.twig`, `force_password_change.html.twig`)
- YAML translations: `messages.{locale}.yaml` (e.g., `messages.es.yaml`)
- Config: `fsframework.ini` (plugin metadata), `facturascripts.ini` (legacy plugin metadata)
- Description: `description` (no extension) — plain text plugin description

**Directories:**
- Legacy: lowercase (e.g., `controller/`, `model/`, `view/`, `extras/`)
- Modern PSR-4: `PascalCase/` (e.g., `src/Core/`, `src/Security/`, `plugins/*/Controller/`)
- Plugin top-level: `snake_case/` (e.g., `business_data/`, `clientes_core/`, `hola_mundo/`)
- Theme: `PascalCase/` (e.g., `themes/AdminLTE/`)
- Translation catalogs: `translations/` (lowercase)
- Test suite dirs: `PascalCase/` matching source (e.g., `tests/Security/`, `tests/Cache/`)

**Classes:**
- Legacy: `snake_case` without namespace (e.g., `class fs_controller`, `class fs_model`, `class login`)
- Modern: `PascalCase` with `FSFramework\` namespace (e.g., `FSFramework\Core\Kernel`, `FSFramework\Security\CsrfManager`)
- Plugin controllers (legacy): `snake_case` without namespace (e.g., `class admin_empresa`)
- Plugin controllers (modern): `PascalCase` with `FSFramework\Plugins\<plugin>\Controller\` namespace (e.g., `FSFramework\Plugins\catalogo_core\Controller\AdminAlmacenes`)
- Plugin models (legacy): `snake_case` without namespace (e.g., `class empresa`, `class articulo`)
- Plugin Init classes: `Init` in `FSFramework\Plugins\<plugin>\` namespace
- Test classes: `PascalCaseTest` in `Tests\` namespace

**Functions:**
- Legacy global functions: `snake_case` (e.g., `find_controller()`, `fatal_handler()`, `require_all_models()`)
- Modern namespaced helper functions: declared in `TranslationHelper.php` (e.g., `FSFramework\Translation\trans()`, `FSFramework\Translation\__()`)

**Constants:**
- `UPPER_SNAKE_CASE` with `FS_` prefix (e.g., `FS_FOLDER`, `FS_DB_TYPE`, `FS_COOKIES_EXPIRE`, `FS_DEBUG`, `FS_LAZY_MODELS`, `FS_HOMEPAGE`, `FS_CSRF_SOFT`, `FS_TRUSTED_PROXIES`)

## Where to Add New Code

**New Legacy Page Controller:**
- Primary code: `controller/{page_name}.php` — extend `fs_controller`, implement `private_core()`
- Template: `themes/AdminLTE/view/{page_name}.html.twig` — Twig template for the page
- Menu registration: Controller constructor calls `parent::__construct()` with title, folder, and menu flags

**New Modern Page Controller (FS2025 plugin):**
- Primary code: `plugins/<MyPlugin>/Controller/{PascalCase}.php` — extend `FSFramework\Controller\PageController`, implement `getPageData()` and `handle()` or `privateCore()`
- Template: `plugins/<MyPlugin>/View/{template}.html.twig` or `themes/AdminLTE/view/{template}.html.twig`
- Route: Use `#[FSRoute]` attribute on the controller class (defined in `src/Attribute/FSRoute.php`)

**New Model (Legacy):**
- Primary code: `model/{entity}.php` (global class) or `model/core/{entity}.php` (namespaced `FSFramework\model\{entity}`)
- Schema: `model/table/{table_name}.xml` — XML table definition
- Pattern: Extend `fs_model`, implement `delete()`, `exists()`, `save()`, plus `test()` for validation

**New Model (Plugin):**
- Legacy: `plugins/<MyPlugin>/model/core/{entity}.php` — extend `fs_model`
- Modern: `plugins/<MyPlugin>/Model/{Entity}.php` — PSR-4 class that may wrap or extend legacy model
- Schema: `plugins/<MyPlugin>/model/table/{table_name}.xml`
- Plugin registration: Add dependency to `fsframework.ini` `require` field if needed

**New Symfony Service:**
- Implementation: `src/{Area}/{ServiceName}.php` — namespaced under `FSFramework\{Area}\{ServiceName}`
- DI registration: Either via `src/DependencyInjection/Container.php` or plugin `config/services.php`
- Tests: `tests/{Area}/{ServiceName}Test.php`

**New Plugin:**
- Create directory: `plugins/<plugin_name>/`
- Create `fsframework.ini` with metadata (version, description, min_version, author)
- Optional: `Init.php` in `FSFramework\Plugins\<plugin_name>\` namespace with `init()` method
- Optional: `facturascripts.ini` for legacy compatibility
- Optional: `controller/`, `model/`, `view/` for legacy structure
- Optional: `Controller/`, `Model/`, `View/` for FS2025 structure
- Optional: `translations/messages.{locale}.yaml` for i18n
- Optional: `phpunit.xml` + `tests/` for isolated test suite
- Registration: Add plugin name to `$GLOBALS['plugins']` array (via `config2.php` or database)

**New Theme:**
- Implementation: `themes/{ThemeName}/` — create directory with `theme.ini`, `view/`, `css/`, `js/`
- Template: `themes/{ThemeName}/view/*.html.twig` — Twig templates
- Activation: Set `default_theme` in `fs_vars` table or via admin UI

**Tests:**
- Legacy core: `tests/Base/{TestName}Test.php` — PHPUnit class in `Tests\` namespace
- Modern component: `tests/{Component}/{TestName}Test.php`
- Plugin: `plugins/<MyPlugin>/tests/{TestName}Test.php` — auto-discovered by root `phpunit.xml`
- Bootstrap: No DB connection needed — use `tests/bootstrap.php` which defines constants only

**Utilities & Helpers:**
- Shared helpers: `src/{Area}/` — Symfony-style services
- Legacy helpers: `base/fs_functions.php` — global functions (avoid adding new ones here; prefer modern services)
- Plugin helpers: `plugins/<MyPlugin>/extras/` — plugin-specific utilities (legacy) or `plugins/<MyPlugin>/src/` (modern)

## Special Directories

**`vendor/`:**
- Purpose: Composer-managed PHP dependencies
- Generated: Yes (via `composer install`)
- Committed: No (gitignored)

**`tmp/`:**
- Purpose: Runtime cache and temporary files (Symfony cache, Twig cache, RainTPL cache, route cache, portal config)
- Generated: Yes (at runtime)
- Committed: No (gitignored, except `.gitkeep`)

**`node_modules/`:**
- Purpose: npm frontend dependencies (Bootstrap, jQuery, Font Awesome, Bootbox)
- Generated: Yes (via `npm install`)
- Committed: No (gitignored)

**`backups/`:**
- Purpose: Plugin backup snapshots created by `fs_plugin_manager` during updates (e.g., `plugins/foo_back/`)
- Generated: Yes (at runtime during plugin updates)
- Committed: Partially (the `backups/` directory exists; `*_back` subdirectories are runtime artifacts)

**`view/` (shared assets):**
- Purpose: Static frontend assets copied from `node_modules/` via `build.sh`. Served directly by web server.
- Generated: Yes (via `build.sh` → `cp node_modules/*/dist/* view/`)
- Committed: Yes (committed pre-built for deployments without npm)

**`.phpunit.cache/`:**
- Purpose: PHPUnit test runner cache
- Generated: Yes
- Committed: No (gitignored)

**`apk/`:**
- Purpose: Android APK packaging support
- Generated: Partially
- Committed: Yes

**`.ddev/`:**
- Purpose: DDEV local development environment configuration
- Generated: Partially (some files generated by `ddev config`/`ddev start`)
- Committed: Yes (project configuration is committed; runtime data is gitignored)

---

*Structure analysis: 2026-05-16*
