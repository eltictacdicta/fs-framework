# Technology Stack

**Analysis Date:** 2026-05-16

## Languages

**Primary:**
- PHP 8.2+ (runtime config enforces 8.3) - Entire backend, controllers, models, core framework
- Twig 3 - Template rendering for views

**Secondary:**
- JavaScript (jQuery 3.7.1, Bootbox 5.5.3) - Frontend interactivity
- CSS (Bootstrap 3.4.1, Bootswatch 3.x, Tailwind CSS 4.1) - Styling
- YAML - Translations, configuration
- XML - Database schema definitions in `model/table/`
- INI - Plugin metadata (`fsframework.ini`, `facturascripts.ini`)

## Runtime

**Environment:**
- PHP 8.3 (configured via `ddev`, `.ddev/config.yaml` sets `php_version: "8.3"`)
- Web server: nginx-fpm (via ddev)
- Database: MariaDB 10.11 (via ddev), also supports PostgreSQL (driver in `base/fs_postgresql.php`)

**Package Manager:**
- Composer 2 (`composer.json`, `composer.lock`)
- Lockfile: present
- Platform config pins to PHP 8.3 (`config.platform.php: "8.3"`)

**Frontend Package Manager:**
- npm (`package.json`)
- No lockfile committed (per project convention)

## Frameworks

**Core:**
- Symfony 7.4 Components - Integrates HTTP Foundation, Routing, Cache, Config, YAML, Translation, Security CSRF, Event Dispatcher, Validator, Dependency Injection, Form, HTTP Client (`composer.json` requires `^7.4`)
- Twig 3 - Primary template engine via `themes/AdminLTE/view/`
- Legacy framework (FacturaScripts 2017 fork) - Controllers in `base/fs_controller.php`, `base/fs_model.php`, `base/fs_db2.php`

**Testing:**
- PHPUnit 11 (`require-dev`, `phpunit/phpunit: ^11`)
- Symfony PHPUnit Bridge (via deprecation helper: `SYMFONY_DEPRECATIONS_HELPER=weak`)

**Static Analysis:**
- PHPStan (level 5, configured for `src/` and `tests/`)
- Rector (PHP 8.3 rules, code quality & dead code sets)
- Baseline files: `phpstan-baseline.neon`, `phpstan-dead-code.neon`, `phpstan-dead-code-context.neon`

**Build/Dev:**
- ddev - Local development environment (containerized PHP, MariaDB, nginx)
- Tailwind CSS CLI (`@tailwindcss/cli ^4.1.18`) - Build CSS
- Traditional `build.sh` - Copies npm assets to `view/`

## Key Dependencies

**Critical:**
- `symfony/http-foundation` ^7.4 - Request/Response objects used throughout Kernel, controllers, and API
- `twig/twig` ^3.0 - Template engine for all views
- `symfony/cache` ^7.4 - Centralized caching via `src/Cache/CacheManager.php`
- `symfony/security-csrf` ^7.4 - CSRF token generation and validation
- `symfony/validator` ^7.4 - Model validation through `ValidatorTrait`
- `symfony/event-dispatcher` ^7.4 - Plugin event hook system
- `symfony/dependency-injection` ^7.4 - Service container (`src/DependencyInjection/Container.php`)
- `symfony/routing` ^7.4 - Symfony routing bridge in Kernel

**Security:**
- `firebase/php-jwt` ^7.0 - JWT token handling
- `symfony/security-csrf` ^7.4 - CSRF protection
- `symfony/dotenv` ^7.4 - Environment variable loading
- `mrd/oidc-core` ^0.2 - OpenID Connect provider

**Infrastructure:**
- `phpmailer/phpmailer` ^6.0 - Email sending via `src/Core/MailService.php`
- `phpoffice/phpspreadsheet` ^2.0 - Excel/CSV import/export
- `sabberworm/php-css-parser` ^9.0 - CSS sanitization in `src/Core/CssSanitizer.php`
- `symfony/http-client` ^7.4 - HTTP client for remote operations

**API Documentation:**
- `zircote/swagger-php` ^6.0 - OpenAPI/Swagger documentation generation

**Frontend Assets:**
- `jquery` ^3.7.1 - DOM manipulation
- `bootstrap` ^3.4.1 - UI framework
- `bootswatch` 3.* - Bootstrap themes (9 variants)
- `bootbox` ^5.5.3 - JavaScript dialog boxes
- `font-awesome` 4.* - Icon font

**Development:**
- `phpunit/phpunit` ^11
- `tailwindcss` ^4.1.18
- `@tailwindcss/cli` ^4.1.18

## Configuration

**Environment:**
- `config.php` (dev-installed, not committed) - Database credentials, debug mode, secret key
- `.env` and Symfony Dotenv - Additional environment overrides
- `base/config2.php` - Framework constants (`FS_PATH`, locale, number formatting, plugin list from `tmp/enabled_plugins.list`)

**Key configuration constants (set in `config.php`):**
- `FS_DB_HOST`, `FS_DB_PORT`, `FS_DB_NAME`, `FS_DB_USER`, `FS_DB_PASS`
- `FS_DB_TYPE` - `'MYSQL'` or `'POSTGRESQL'`
- `FS_SECRET_KEY` - Application secret for HMAC operations
- `FS_DEBUG` - Enable verbose logging and DebugBar
- `FS_CSRF_SOFT` - Soft mode for CSRF (warnings instead of blocking)
- `FS_LAZY_MODELS` - Enable lazy model autoloading
- `FS_HOMEPAGE` - Default landing page
- `FS_DEFAULT_THEME` - Theme to auto-activate on fresh install

**Build:**
- `composer.json` - PSR-4 autoloading (`FSFramework\` → `src/`, `FSFramework\Plugins\` → `plugins/`)
- `phpunit.xml` - Test suites, source coverage, deprecation helper
- `phpstan.neon` - Static analysis baseline (level 5)
- `rector.php` - Automated refactoring rules
- `package.json` - npm scripts for Tailwind CSS builds

## Platform Requirements

**Development:**
- ddev (Docker-based PHP environment)
- PHP 8.2 minimum, 8.3 recommended
- MariaDB 10.11 or MySQL, or PostgreSQL
- Composer 2
- Node.js (for frontend asset builds)

**Production:**
- PHP 8.2+ with extensions: `pdo_mysql` or `pdo_pgsql`, `mbstring`, `gd`, `openssl`, `json`, `curl`
- MySQL 5.7+ / MariaDB 10.3+ or PostgreSQL 12+
- Web server: Apache with mod_rewrite or nginx
- Write access to `tmp/` directory
- HTTPS recommended (CSRF and session security)

---

*Stack analysis: 2026-05-16*
