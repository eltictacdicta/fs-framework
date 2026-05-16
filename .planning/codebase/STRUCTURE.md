# Codebase Structure

**Analysis Date:** 2026-05-16

## Directory Layout

```
[project-root]/
├── index.php                  # Main web entry point: bootstrap, routing, controller dispatch
├── api.php                    # REST API entry point: JSON responses via Container
├── install.php                # First-run setup wizard
├── config.php                 # Environment config (DB credentials, debug, secret — NOT committed)
├── composer.json              # PHP dependencies, PSR-4 autoload config
├── composer.lock              # Locked PHP dependency versions
├── package.json               # Frontend dependencies (Bootstrap, jQuery, Tailwind)
├── build.sh                   # Build: npm install + copy assets to view/
├── phpunit.xml                # PHPUnit 11 test suite configuration
├── phpstan.neon               # PHPStan baseline config (level 5)
├── phpstan-baseline.neon      # Known PHPStan issues (1 entry as of v0.10.8)
├── phpstan-dead-code.neon     # Dead code detection config
├── phpstan-dead-code-context.neon # Dead code detection with context
├── rector.php                 # Rector automated refactoring rules
├── VERSION                    # 0.10.8
├── VERSION-FS2017             # 2017.999 (legacy compatibility)
├── VERSION-FS2025             # 2025.000 (FS2025 compatibility)
│
├── base/                      # Core framework classes (legacy, non-PSR-4)
│   ├── fs_controller.php     # Main controller base class (1081 lines)
│   ├── fs_model.php          # Abstract model base class (585 lines)
│   ├── fs_db2.php            # Database abstraction layer
│   ├── fs_db_engine.php      # Database engine interface
│   ├── fs_mysql.php          # MySQL driver
│   ├── fs_postgresql.php     # PostgreSQL driver
│   ├── FsMysqlSchemaUtility.php # MySQL schema utility (NEW in v0.10.8)
│   ├── fs_core_log.php       # PSR-3 compatible logging system
│   ├── fs_cache.php          # Legacy cache (Memcache), strict_types since v0.10.8
│   ├── php_file_cache.php    # Filesystem cache fallback
│   ├── fs_controller.php     # Main controller class
│   ├── fs_edit_controller.php # Edit controller pattern
│   ├── fs_list_controller.php # List controller pattern
│   ├── fs_functions.php      # Global helper functions
│   ├── fs_ip_filter.php      # IP whitelist/blacklist
│   ├── fs_query_builder.php  # SQL query builder
│   ├── fs_schema.php         # Database schema operations
│   ├── fs_login.php          # Authentication utilities
│   ├── fs_app.php            # Base application class
│   ├── fs_api.php            # API utilities
│   ├── fs_auth.php           # Authentication helpers
│   ├── fs_autoload.php       # Legacy autoloader
│   ├── fs_chunked_upload.php # Chunked file uploads
│   ├── fs_secure_chunked_upload.php # Secure file uploads
│   ├── fs_default_items.php  # Default items management
│   ├── fs_edit_form.php      # Edit form utilities
│   ├── fs_excel.php          # Excel export/import
│   ├── fs_extended_model.php # Extended model functionality
│   ├── fs_file_manager.php   # File management
│   ├── fs_list_decoration.php # List view decoration
│   ├── fs_list_filter.php    # List filter base
│   ├── fs_list_filter_checkbox.php # Checkbox list filter
│   ├── fs_list_filter_date.php     # Date list filter
│   ├── fs_list_filter_select.php   # Select list filter
│   ├── fs_log_manager.php    # Log persistence to DB
│   ├── fs_maintenance_mode.php # Maintenance mode gate
│   ├── fs_model_autoloader.php # Lazy model autoloading
│   ├── fs_model_crud_trait.php # CRUD trait for models
│   ├── fs_plugin_downloader.php # Plugin download/install
│   ├── fs_plugin_manager.php # Plugin management
│   ├── fs_session_manager.php # Legacy session handling
│   ├── fs_settings.php       # Application settings
│   ├── fs_secret_migrator.php # Secret migration utility
│   ├── config2.php           # Framework constants, locale, plugin list
│   ├── install_security.php  # Installation security
│   └── cacert.pem            # CA certificate bundle
│
├── controller/               # Legacy page controllers
│   ├── admin_home.php        # Dashboard
│   ├── admin_users.php       # User management
│   ├── admin_user.php        # Single user edit
│   ├── admin_rol.php         # Role management
│   ├── admin_agentes.php     # Agent management
│   ├── admin_agente.php      # Single agent edit
│   ├── admin_info.php        # System info + cache clear
│   ├── admin_email.php       # Email configuration
│   ├── admin_orden_menu.php  # Menu ordering
│   ├── admin_stealth.php     # Stealth mode configuration
│   ├── admin_system_branding.php # System branding
│   ├── login.php             # Login controller
│   ├── password_reset.php    # Password reset
│   └── force_password_change.php # Password change enforcement
│
├── model/                     # Core legacy models
│   ├── core/                 # Core model classes
│   ├── table/                # XML schema definitions
│   ├── fs_user.php           # User model
│   ├── fs_rol.php            # Role model
│   ├── fs_var.php            # Settings key-value store
│   ├── fs_log.php            # Log entries model
│   ├── fs_page.php           # Page model
│   ├── fs_access.php         # Access control model
│   ├── fs_extension.php      # Extensions model
│   ├── fs_relation.php       # Relations model
│   ├── fs_rol_access.php     # Role-access mapping
│   ├── fs_rol_user.php       # Role-user mapping
│   └── agente.php            # Agent model
│
├── src/                       # Modern Symfony-based code (PSR-4, FSFramework\)
│   ├── Api/                  # REST API primitives
│   │   ├── Attribute/        # ApiResource, ApiField, ApiHidden, Operation
│   │   ├── Auth/             # ChainedAuthAdapter, auth contracts
│   │   ├── Exception/        # ApiException, ValidationException, etc.
│   │   ├── Helper/           # RequestHelper
│   │   └── Middleware/       # Auth, Cors, RateLimit middleware
│   ├── Attribute/            # PHP 8 Attributes (FSRoute.php)
│   ├── Cache/                # CacheManager, DataSrcRepository
│   ├── Controller/           # PageController base
│   ├── Core/                 # Kernel, Router, StealthMode, HtmlSanitizer, CssSanitizer, etc.
│   │   ├── Base/             # Controller, ControllerPermissions
│   │   ├── Exception/        # KernelNotBootedException
│   │   └── Template/         # InitClass
│   ├── DependencyInjection/  # Container (Service Locator)
│   ├── Dinamic/Model/        # Dynamic models (User)
│   ├── Event/                # FSEventDispatcher, ModelEvent, ControllerEvent, TwigInitEvent
│   ├── Form/                 # FormHelper (Symfony Forms)
│   ├── Security/             # 16 security service files
│   │   └── Exception/        # MissingSecretKeyException
│   ├── Traits/               # ValidatorTrait, ResponseTrait
│   ├── Translation/          # FSTranslator, FS2025JsonLoader, TranslationHelper
│   └── Twig/                 # TranslationExtension
│
├── plugins/                   # Plugin directory
│   ├── business_data/        # Core plugin: empresa, ejercicio, serie, divisa, forma_pago
│   │   ├── controller/       # Legacy controllers
│   │   ├── model/            # Legacy models
│   │   ├── tests/            # BusinessDataModelTest.php (NEW in v0.10.8)
│   │   ├── extras/           # Currency tools
│   │   └── Init.php          # Plugin init: Twig globals
│   ├── catalogo_core/        # Core plugin: articulo, familia, fabricante, impuesto
│   │   ├── Controller/       # PS2025 controllers (AdminAlmacenes, AdminDivisas)
│   │   ├── Model/            # PS2025 models (Articulo, Fabricante, Familia, etc.)
│   │   ├── model/            # Legacy model classes + table/ XML schemas
│   │   ├── tests/            # ArticuloModelEncodingTest, FabricanteModelTest, FamiliaModelTest (NEW)
│   │   └── View/             # Views
│   ├── clientes_core/        # Core plugin: cliente, direcciones, grupos
│   │   ├── controller/       # Legacy controllers
│   │   ├── model/            # Legacy models
│   │   ├── tests/            # ClienteModelTest, DireccionClienteModelTest, GrupoClientesModelTest
│   │   ├── src/              # Namespaced code
│   │   ├── translations/     # YAML translations
│   │   ├── phpunit.xml       # Isolated test configuration
│   │   └── Init.php          # Plugin init
│   ├── clientes_catalogo/    # Client-catalog bridge
│   ├── clientes_facturacion/ # Client billing (minimal: model/ only)
│   ├── legacy_support/       # Legacy compatibility layer
│   │   ├── LegacyCompatibility.php # SHA1/MD5 password verification (NEW owner in v0.10.8)
│   │   ├── LegacyTelemetry.php
│   │   ├── LegacyUsageTracker.php
│   │   ├── VersionValidator.php
│   │   ├── Template/         # Legacy template compatibility
│   │   └── tests/            # LegacyCompatibilityTest, LegacyUsageTrackerTest
│   ├── facturascripts_support/ # FS2025 compatibility
│   └── hola_mundo/           # Example plugin with portal_section.php
│
├── themes/                    # Theme directory
│   └── AdminLTE/             # Default AdminLTE theme
│       ├── view/             # Twig templates (*.html.twig)
│       │   ├── master/       # Base.html.twig, MenuTemplate.html.twig
│       │   ├── Macro/        # Menu.html.twig, Utils.html.twig
│       │   ├── block/        # Block templates
│       │   ├── tab/          # Tab templates
│       │   └── login/        # Login templates
│       ├── css/              # Theme stylesheets
│       ├── js/               # Theme JavaScript
│       ├── img/              # Theme images
│       ├── translations/     # Theme translations (YAML)
│       ├── functions.php     # Theme functions
│       └── theme.ini         # Theme metadata
│
├── view/                      # Shared static assets
│   ├── css/                  # Bootstrap, Font-Awesome, Tailwind output
│   ├── js/                   # jQuery, Bootstrap, Bootbox
│   ├── fonts/                # Font files
│   ├── img/                  # Shared images
│   └── admin_stealth.html.twig # Stealth mode admin template
│
├── translations/             # Core translations
│   ├── messages.en.yaml      # English strings
│   └── messages.es.yaml      # Spanish strings
│
├── tests/                    # PHPUnit test suites
│   ├── bootstrap.php         # Test constants (no DB required)
│   ├── Base/                 # Core class tests (13 files)
│   ├── Security/             # Security component tests (20 files)
│   ├── Core/                 # Core component tests (5 files)
│   ├── Cache/                # CacheManager, DataSrcRepository tests
│   ├── Api/                  # API tests
│   ├── Traits/               # ValidatorTrait tests
│   ├── Translation/          # Translation tests
│   ├── ClientesCore/         # Client model tests
│   ├── Components/           # Core plugin component tests
│   ├── Event/                # Event dispatcher tests
│   └── Form/                 # Form helper tests
│
├── config/                   # Symfony config
│   └── routes.php            # Route definitions (empty, for extension)
│
├── docs/                     # Documentation
│   ├── TRANSLATION.md        # i18n system guide
│   ├── MEJORAS_PROPUESTAS.md # Proposed improvements
│   └── reviews/              # Review documents
│
├── scripts/                  # Utility scripts
│   ├── install-dev-tools.sh  # PHPStan/Rector setup
│   ├── remediate-legacy-passwords.php # Legacy password migration
│   └── check-stealth-oidc-flow.sh # Stealth/OIDC flow checker
│
├── extras/                   # Extra tools
│   └── xlsxwriter.class.php  # Excel writer
│
├── .ddev/                    # ddev local dev config
│   └── config.yaml           # PHP 8.3, MariaDB 10.11, nginx-fpm
│
├── .cursor/                  # Cursor IDE rules
├── .github/                  # GitHub config (copilot instructions)
├── .planning/                # Planning documents (GSD)
│   └── codebase/             # Codebase maps (output directory)
├── tmp/                      # Runtime cache, compiled templates (gitignored)
├── backups/                  # Database backups (gitignored)
└── vendor/                   # Composer dependencies (gitignored)
```

## Directory Purposes

**`base/`:**
- Purpose: Core framework classes (non-PSR-4, legacy FacturaScripts 2017 code)
- Contains: Controllers, models, DB abstraction, caching, auth, helpers — 45 files
- Key files: `fs_controller.php`, `fs_model.php`, `fs_db2.php`, `fs_core_log.php`, `config2.php`
- 15 files now have `declare(strict_types=1)` as of v0.10.8

**`src/`:**
- Purpose: Modern Symfony 7.4-based code with PSR-4 autoloading (`FSFramework\` namespace)
- Contains: Security services, Cache, API primitives, Translation, Events, Forms, Core utilities — 13 subdirectories
- Key files: `Core/Kernel.php`, `Security/PasswordHasherService.php`, `Security/CsrfManager.php`, `Core/StealthMode.php`
- All new features should go here

**`controller/`:**
- Purpose: Legacy page controllers (web UI)
- Contains: 14 controller files extending `fs_controller`
- Key files: `admin_home.php`, `admin_users.php`, `login.php`

**`model/`:**
- Purpose: Core legacy model classes + XML table schemas
- Contains: 11 model PHP files, `core/` subdirectory, `table/` subdirectory
- Key files: `fs_user.php`, `fs_var.php`

**`plugins/`:**
- Purpose: Plugin system — extensions, business modules, compatibility layers
- Contains: 8 plugin directories (core plugins committed, others gitignored)
- Key plugins: `business_data`, `catalogo_core`, `clientes_core`, `legacy_support`

**`themes/AdminLTE/`:**
- Purpose: Default theme with Twig templates, CSS, JS, translations
- Contains: 7 subdirectories, ~28 Twig template files
- Key files: `view/master/Base.html.twig`, `view/master/MenuTemplate.html.twig`

**`tests/`:**
- Purpose: PHPUnit 11 test suites, PSR-4 autoloaded under `Tests\`
- Contains: 12 test directories organized by component
- Key files: `bootstrap.php`, `Base/FsModelMethodsTest.php`, `Security/PasswordHasherServiceTest.php`

**`translations/`:**
- Purpose: Core i18n strings in YAML format
- Contains: `messages.en.yaml`, `messages.es.yaml`

**`tmp/`:**
- Purpose: Runtime-generated files: compiled Twig templates, plugin list, config2.ini, rector cache
- Generated: Yes
- Committed: No (gitignored)

**`vendor/`:**
- Purpose: Composer dependencies
- Generated: Yes (by `composer install`)
- Committed: No (gitignored)

## Key File Locations

**Entry Points:**
- `/index.php`: Main web application entry
- `/api.php`: REST API entry
- `/install.php`: Installation wizard
- `/cron.php`: Scheduled task runner

**Configuration:**
- `/config.php`: Database, debug, secret key (not committed)
- `/base/config2.php`: Framework constants, locale, plugin list
- `/composer.json`: PHP dependencies + PSR-4 autoload
- `/phpunit.xml`: Test runner config
- `/phpstan.neon`: Static analysis config
- `/.ddev/config.yaml`: Local dev environment

**Core Logic:**
- `/src/Core/Kernel.php`: Framework bootstrap
- `/src/DependencyInjection/Container.php`: Service locator
- `/base/fs_controller.php`: Controller lifecycle (~1081 lines)
- `/base/fs_model.php`: Model abstraction (~585 lines)
- `/src/Core/StealthMode.php`: Admin panel stealth mode (reduced to 508 lines in v0.10.8)
- `/src/Core/HtmlSanitizer.php`: HTML sanitization (388 lines, NEW in v0.10.8)
- `/src/Core/CssSanitizer.php`: CSS sanitization (246 lines, NEW in v0.10.8)

**Security:**
- `/src/Security/PasswordHasherService.php`: Modern password hashing (argon2id, no SHA1/MD5)
- `/src/Security/CsrfManager.php`: CSRF token management
- `/src/Security/SessionManager.php`: Secure session handling
- `/plugins/legacy_support/LegacyCompatibility.php`: All legacy password verification

**Testing:**
- `/tests/bootstrap.php`: Test environment setup
- `/tests/Base/`: 13 test files for core base classes
- `/tests/Security/`: 20 test files for security services
- `/plugins/business_data/tests/BusinessDataModelTest.php`: New in v0.10.8
- `/plugins/catalogo_core/tests/`: 3 new test files in v0.10.8

## Naming Conventions

**Files:**
- Legacy base files: `fs_*.php` (snake_case with `fs_` prefix)
- Legacy controllers: `admin_*.php`, `login.php`, etc. (snake_case)
- Legacy models: `fs_*.php` in `model/`, descriptive names in plugin `model/` (ej: `empresa.php`, `divisa.php`)
- Modern PSR-4: PascalCase matching class name (ej: `CacheManager.php`, `StealthMode.php`)
- Test files: `*Test.php` suffix (PHPUnit convention)
- XML schemas: Match table name (ej: `fs_users.xml`)
- INI files: `fsframework.ini`, `facturascripts.ini`, `theme.ini`

**Directories:**
- PascalCase for modern PSR-4: `Core/`, `Security/`, `Controller/`, `Model/`
- lowercase for legacy: `base/`, `controller/`, `model/`, `view/`
- snake_case for plugins: `business_data/`, `clientes_core/`

**Twig templates:**
- `*.html.twig` extension
- Descriptive names: `admin_home.html.twig`, `login.html.twig`
- Blocks organized in `block/`, tabs in `tab/`, macros in `Macro/`

## Where to Add New Code

**New Feature (modern PHP):**
- Primary code: `src/{Component}/` (PSR-4, `FSFramework\{Component}\` namespace)
- Tests: `tests/{Component}/` (PSR-4, `Tests\{Component}\` namespace)

**New Feature (plugin):**
- Implementation: `plugins/{PluginName}/Model/` or `plugins/{PluginName}/Controller/`
- Legacy fallback: `plugins/{PluginName}/model/` or `plugins/{PluginName}/controller/`
- Tests: `plugins/{PluginName}/tests/` (auto-discovered by root phpunit.xml)

**New Component/Module:**
- Modern: `src/{NewComponent}/` with PSR-4 namespace
- Plugin: `plugins/{PluginName}/src/` with matching namespace

**Utilities:**
- Shared helpers: `src/{Component}/` (prefer over adding to `base/fs_functions.php`)
- Plugin utilities: `plugins/{PluginName}/src/`

**New Controller (legacy):**
- `controller/{name}.php` extending `fs_controller`
- Corresponding view: `themes/AdminLTE/view/{name}.html.twig`

**New Controller (modern):**
- `plugins/{PluginName}/Controller/{Name}.php` with `handle(Request): Response`
- Page metadata via `getPageData(): array`

**New Model (legacy):**
- `model/{name}.php` extending `fs_model`
- Schema: `model/table/{name}.php` (XML)

**New Model (modern):**
- `plugins/{PluginName}/Model/{Name}.php` extending `fs_model` with `ValidatorTrait`

**View/template:**
- Twig: `themes/AdminLTE/view/{template}.html.twig`
- Macros: `themes/AdminLTE/view/Macro/{group}.html.twig`
- Static assets: `view/css/`, `view/js/`, `view/img/`

**Translations:**
- Core: `translations/messages.{locale}.yaml`
- Plugin: `plugins/{PluginName}/translations/messages.{locale}.yaml`
- Theme: `themes/AdminLTE/translations/messages.{locale}.yaml`

## Special Directories

**`extras/`:**
- Purpose: Extra utility tools (single file: `xlsxwriter.class.php`)
- Generated: No
- Committed: Yes
- Note: `extras/phpmailer/` and `phpmailer_compat.php` were deleted in v0.10.8

**`dev-tools/`:**
- Purpose: Development dependencies (PHPStan, Rector) managed by separate `composer.json`
- Generated: Partially (via `scripts/install-dev-tools.sh`)
- Committed: Own `composer.json` and `composer.lock`

**`tmp/`:**
- Purpose: Runtime cache, compiled templates, plugin list, config override
- Generated: Yes
- Committed: No

**`backups/`:**
- Purpose: Database backups
- Generated: Yes
- Committed: No

**`.phpunit.cache/`:**
- Purpose: PHPUnit test result cache
- Generated: Yes
- Committed: No

---

*Structure analysis: 2026-05-16*
