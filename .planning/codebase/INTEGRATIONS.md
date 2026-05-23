# External Integrations

**Analysis Date:** 2026-05-23 (API docs section updated v0.13.0)

## APIs & External Services

**Email:**
- PHPMailer ^6.0 - Email sending via SMTP
  - SDK/Client: `phpmailer/phpmailer`
  - Configured through: `src/Core/MailService.php`
  - Auth: SMTP credentials stored in `config.php` (FS_SMTP_* constants) or `tmp/` settings

**Authentication (External):**
- OpenID Connect - `mrd/oidc-core` ^0.2
  - Used for: Optional OIDC-based authentication in consumer plugins
  - Core contracts: `src/Api/Auth/Contract/` (interfaces only)
  - Runtime: separate plugins (not required for REST API token auth in `api_base`)

**Document Generation:**
- PhpSpreadsheet ^2.0 - Excel/CSV file reading and writing
  - SDK/Client: `phpoffice/phpspreadsheet`
  - Used in: `base/fs_excel.php` for export/import operations

**API Documentation:**
- Swagger-PHP ^6.0 - OpenAPI spec generation from PHP 8 attributes
  - SDK/Client: `zircote/swagger-php` (plugin vendor)
  - Declared in: `plugins/api_base/composer.json`
  - Generation: `plugins/api_base/model/swagger/SwaggerGenerator.php`
  - Model annotations: `src/Api/Attribute/` (contracts in core; runtime in plugin)

**HTTP Client:**
- Symfony HTTP Client ^7.4 - HTTP requests to external services
  - SDK/Client: `symfony/http-client`
  - Used by: plugins for remote API calls, plugin downloader

**CSS Parsing:**
- PHP CSS Parser ^9.0 - Parse and sanitize user-supplied CSS
  - SDK/Client: `sabberworm/php-css-parser`
  - Used in: `src/Core/CssSanitizer.php` (extracted from StealthMode in v0.10.8)

**No active third-party SaaS integrations detected.** The framework has no hardcoded external API keys or service dependencies beyond the libraries above.

## Data Storage

**Databases:**
- MariaDB 10.11 (primary, via ddev) / MySQL
  - Connection: `config.php` defines `FS_DB_HOST`, `FS_DB_PORT`, `FS_DB_NAME`, `FS_DB_USER`, `FS_DB_PASS`
  - Client: `fs_db2` via `base/fs_db2.php` (abstraction layer), MySQL driver in `base/fs_mysql.php`
- PostgreSQL (supported)
  - Driver: `base/fs_postgresql.php`
  - Same `config.php` constants used

**File Storage:**
- Local filesystem only
  - `tmp/` - Application cache, compiled templates, enabled plugin list, config2.ini
  - `backups/` - Database backups
  - `documentos/` (configurable via `FS_MYDOCS`) - User documents

**Caching:**
- Symfony Cache ^7.4 (multi-adapter chain)
  - FilesystemAdapter - Default for single-server deployments
  - ArrayAdapter - Per-request cache
  - Memcached (optional, if extension available)
  - Managed by: `src/Cache/CacheManager.php`
  - Legacy: `base/fs_cache.php` (Memcache), `base/php_file_cache.php`
  - Template cache: Twig compile cache in `tmp/`, legacy RainTPL cache

## Authentication & Identity

**Auth Provider:**
- Custom (built-in legacy system + Symfony CSRF)
  - Implementation: `base/fs_login.php` handles login verification, `src/Security/PasswordHasherService.php` for hashing (argon2id/bcrypt), `src/Security/LegacyAuthBridge.php` bridges legacy sessions
  - Legacy password fallback: `plugins/legacy_support/LegacyCompatibility.php` owns all SHA1/MD5 verification (moved from core in v0.10.8)
  - CSRF: `src/Security/CsrfManager.php` using Symfony Security CSRF
  - Session management: `src/Security/SessionManager.php`, `base/fs_session_manager.php`
  - Cookie signing: `src/Security/CookieSigner.php`
  - Login throttling: `src/Security/LoginThrottle.php`

**Optional OIDC:**
- `mrd/oidc-core` - OpenID Connect provider integration (plugin-level)

## Monitoring & Observability

**Error Tracking:**
- Custom: `base/fs_core_log.php` provides PSR-3 compatible logging
  - SQL query history, error collection, advice/message accumulation
  - `src/Core/DebugBar.php` - Debug bar (only when `FS_DEBUG` enabled)

**Logs:**
- PHP `error_log()` for system-level errors
- `fs_core_log` tracks messages, errors, advices, and SQL history per request
- `fs_log_manager` persists log entries to database (`fs_logs` table)
- Symfony deprecation logging via `SYMFONY_DEPRECATIONS_HELPER=weak`

**Telemetry:**
- `plugins/legacy_support/LegacyTelemetry.php` - Tracks legacy component usage
- `plugins/legacy_support/LegacyUsageTracker.php` - Counts deprecated API calls

## CI/CD & Deployment

**Hosting:**
- Self-hosted PHP application (no cloud-specific bindings)
- ddev for local development

**CI Pipeline:**
- GitHub repository (`github.com/eltictacdicta/fs-framework`)
- Manual testing via `ddev exec php vendor/bin/phpunit`
- No automated CI pipeline detected in repository

**Build:**
- `build.sh` - Copies npm assets to `view/` and cleans up `node_modules/`
- `scripts/install-dev-tools.sh` - PHPStan/Rector tooling setup
- `scripts/remediate-legacy-passwords.php` - Legacy password migration script

## Environment Configuration

**Required env vars (defined in `config.php`):**
- `FS_DB_HOST`, `FS_DB_PORT`, `FS_DB_NAME`, `FS_DB_USER`, `FS_DB_PASS`
- `FS_DB_TYPE` (`'MYSQL'` or `'POSTGRESQL'`)
- `FS_SECRET_KEY` - Application secret for HMAC
- `FS_TMP_NAME` - Temporary directory prefix

**Optional env vars:**
- `FS_DEBUG` - Enable debug mode
- `FS_CSRF_SOFT` - Soft CSRF mode
- `FS_LAZY_MODELS` - Lazy model autoloading
- `FS_TRUSTED_PROXIES` - Trusted proxy IPs/CIDRs
- `FS_TRUSTED_HEADERS` - Trusted header set
- `FS_DEFAULT_THEME` - Auto-active theme
- `FS_HOMEPAGE` - Default landing page
- `SYMFONY_DEPRECATIONS_HELPER` - Set to `weak` in testing

**Secrets location:**
- `config.php` (not committed to git)
- `.env` files (Symfony Dotenv, also not committed)
- `tmp/` contains `config2.ini` with runtime settings

## Webhooks & Callbacks

**Incoming:**
- REST API entry: `api.php` - Routes requests to `api.runtime` service in container
- Web controller entry: `index.php` - Routes page requests to legacy/modern controllers
- Plugin portal sections: `plugins/*/portal_section.php` for public content

**Outgoing:**
- None configured at framework level. Plugin-level integrations would be added by individual plugins.

## Plugin Architecture

**Core Plugins (committed):**
- `business_data` - Base data: empresa, ejercicio, serie, divisa, forma_pago
- `catalogo_core` - Catalog: articulo, familia, fabricante, impuesto, almacen
- `clientes_core` - Client management with addresses, groups
- `clientes_catalogo` - Client-catalog bridge
- `clientes_facturacion` - Client billing
- `legacy_support` - Legacy compatibility layer (SHA1/MD5 passwords, telemetry)
- `facturascripts_support` - FacturaScripts 2025 compatibility
- `hola_mundo` - Example/minimal plugin with portal section

**Plugin Dependency Graph:**
```
business_data          (no dependencies)
catalogo_core          (no dependencies)
clientes_core          → catalogo_core, business_data
clientes_catalogo      → clientes_core, catalogo_core, business_data
clientes_facturacion   → clientes_core, catalogo_core, business_data
legacy_support         (no dependencies)
```

---

*Integration audit: 2026-05-16*
