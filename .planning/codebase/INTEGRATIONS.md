# External Integrations

**Analysis Date:** 2026-05-16

## APIs & External Services

**Email Delivery:**
- **PHPMailer 6.12** (`phpmailer/phpmailer`) — SMTP-based email sending
  - SDK/Client: `PHPMailer\PHPMailer\PHPMailer` (via `src/Core/MailService.php`)
  - Auth: SMTP credentials configured via framework settings (`mail_host`, `mail_port`, `mail_user`, `mail_password`, `mail_enc`, `mail_mailer`)
  - Fallback: Native PHP `mail()` when SMTP not configured
  - Config keys stored in `fs_vars` table (retrieved by `MailService`)
  - Legacy compatibility layer at `extras/phpmailer_compat.php`

**OIDC/OAuth Identity:**
- **mrd/oidc-core 0.2.2** — OIDC core library
  - SDK/Client: `mrd/oidc-core` (used by the `OidcProvider` plugin)
  - The `OidcProvider` plugin is not in the repository (plugins are gitignored except core ones), but the library is a declared dependency and is referenced in tests (`tests/Security/SqlInjectionPreventionTest.php`, `tests/Components/PublicAccessGateTest.php`, `tests/Components/StealthModeTest.php`)
  - Routes exposed by the plugin: `/oauth/*`, `/.well-known/*`, `/account/*` (public paths registered via `Plugins::registerPublicPathPrefixes`)
  - Integration patterns: `src/Security/SessionPolicy.php` coordinates with OidcProvider for session management; `src/Security/LegacyAuthBridge.php` handles OIDC-to-legacy session transitions

**JWT Token Handling:**
- **firebase/php-jwt 7.0.5** — JWT encoding/decoding
  - SDK/Client: `Firebase\JWT\JWT`
  - No direct usage found in `src/` or `base/` code. Likely consumed by the `OidcProvider` plugin or the REST API system for token-based auth.

**HTTP Client:**
- **symfony/http-client 7.4.9** — External HTTP requests
  - Used by `base/fs_plugin_downloader.php` for downloading plugins from remote URLs
  - Configuration: 60s timeout, max 5 redirects, custom User-Agent header (`FSFramework-PluginDownloader/1.0`)
  - Supports optional `Authorization: token` header for private repositories
  - Supports SHA256 checksum verification for downloaded files
  - URL allowlist validation at `fs_plugin_downloader::assertAllowedDownloadUrl()`

**Excel/CSV Import/Export:**
- **PhpSpreadsheet 2.4.5** (`phpoffice/phpspreadsheet`) — Spreadsheet file operations
  - SDK/Client: `PhpOffice\PhpSpreadsheet\Spreadsheet`, `\PhpOffice\PhpSpreadsheet\IOFactory`, etc.
  - Used by: `base/fs_excel.php` (framework wrapper) and `extras/xlsxwriter.class.php` (backward-compatible wrapper for `mk-j/php_xlsxwriter` API)
  - Output formats: XLSX (via `\PhpOffice\PhpSpreadsheet\Writer\Xlsx`), CSV (via `\PhpOffice\PhpSpreadsheet\Writer\Csv`)

**OpenAPI/Swagger:**
- **zircote/swagger-php 6.1.2** — API documentation generation
  - No direct usage detected in `src/` or `base/` source code. Installed as a dependency; likely intended for the REST API system but attribute-based registration (`#[ApiResource]` in `src/Api/Attribute/`) appears to use a custom approach.

**CSS Parsing:**
- **sabberworm/php-css-parser 9.3.0** — CSS parsing and sanitization
  - Used by `src/Core/StealthMode.php` for parsing and validating CSS in style-related functionality.

## Data Storage

**Databases:**
- **MySQL** — via `base/fs_mysql.php` (driver extends `fs_db_engine`)
  - Connection: uses `FS_DB_TYPE=mysql`, `FS_DB_HOST`, `FS_DB_PORT`, `FS_DB_NAME`, `FS_DB_USER`, `FS_DB_PASS` constants
  - Client: Native PHP `mysqli` extension
  - The `fs_db2` class (`base/fs_db2.php`) is the unified facade; it instantiates either `fs_mysql` or `fs_postgresql` based on `FS_DB_TYPE`

- **PostgreSQL** — via `base/fs_postgresql.php` (driver extends `fs_db_engine`)
  - Connection: uses `FS_DB_TYPE=postgresql` and same host/port/name/user/pass constants
  - Client: Native PHP `pgsql` extension

- **MariaDB 10.11** — Development database via DDEV (`.ddev/config.yaml`)
  - MySQL-compatible; uses the `fs_mysql` driver

- **Schema Definition:** XML files in `model/table/*.xml` and `plugins/*/model/table/*.xml` define table structures. The `fs_schema` class (`base/fs_schema.php`) reads these XMLs and creates/modifies tables.

- **ORM/Query Layer:** No ORM. Custom `fs_query_builder` (`base/fs_query_builder.php`) for programmatic SQL generation. Models extend `fs_model` (`base/fs_model.php`) which provides `save()`, `delete()`, `exists()` methods that use `$this->db` (`fs_db2` instance).

**File Storage:**
- **Local filesystem only** — No cloud storage integration detected.
  - Upload directories: project root for `apk/` (Android packages), `tmp/` for temporary/cache files
  - File management: `base/fs_file_manager.php`, `base/fs_chunked_upload.php`, `base/fs_secure_chunked_upload.php`
  - Backups: `backups/` directory for plugin backups via `fs_plugin_manager`

**Caching:**
- **Symfony Cache** (`src/Cache/CacheManager.php`) — Multi-adapter caching system
  - `ArrayAdapter` — Per-request in-memory cache (always active)
  - `FilesystemAdapter` — Persistent file-based cache in `tmp/{FS_TMP_NAME}symfony_cache/` (always active)
  - `MemcachedAdapter` — Optional distributed cache, activated when `class_exists('Memcached')` AND `FS_CACHE_HOST`/`FS_CACHE_PORT` constants are defined
  - All adapters combined via `ChainAdapter` for multi-level caching
  - TTL constants: `SHORT_TTL` 30s, `DEFAULT_TTL` 180s, `MEDIUM_TTL` 600s, `LONG_TTL` 3600s
  - Legacy compatibility: `base/fs_cache.php` wraps the same `CacheManager`, preserving the historical `fs_cache` API
  - Template caches: Twig (`tmp/twig_cache/`) and legacy RainTPL (`tmp/{FS_TMP_NAME}/`)

## Authentication & Identity

**Auth Provider:**
- **Custom (legacy)** — Built-in authentication via `fs_login` (`base/fs_login.php`), cookie-based sessions, CSRF tokens via Symfony Security CSRF
  - `src/Security/UserAdapter.php` — Wraps `fs_user` for Symfony Security compatibility
  - `src/Security/SessionManager.php` — Session handling with regeneration
  - `src/Security/CookieSigner.php` — HMAC-signed remember-me cookies
  - `src/Security/PasswordHasherService.php` — bcrypt password hashing with legacy SHA1/MD5 migration (`verifyAndMigrate()`)
  - `src/Security/CsrfManager.php` — CSRF token generation and validation (automatic in `pre_private_core()`)
  - `src/Security/LegacyAuthBridge.php` — Bridges legacy cookie-based auth with modern session management, including OIDC-to-legacy transitions

- **OIDC Provider** (plugin, not in repo) — Uses `mrd/oidc-core` for OpenID Connect authentication
  - Public routes: `/oauth`, `/.well-known`, `/account`
  - Referenced in tests and security components (`SessionPolicy`, `LegacyAuthBridge`, `PublicAccessGate`)

- **REST API Auth** — `src/Api/Auth/ChainedAuthAdapter.php` supports multi-provider authentication for API endpoints. Token strategies supplied by plugins like `api_base` and `OidcProvider`.

## Monitoring & Observability

**Error Tracking:**
- **None** — No external error tracking service (Sentry, Bugsnag, etc.) detected.
  - Internal logging: `fs_core_log` (`base/fs_core_log.php`) provides in-application logging with error messages (`new_error_msg()`), info messages (`new_message()`), warnings (`new_advice()`), and SQL history.
  - `fs_log_manager` (`base/fs_log_manager.php`) provides log file management.

**Logs:**
- **File-based logging** — `fs_core_log` writes to in-memory data structures accessible via `$core_log->get_errors()`, `$core_log->get_messages()`, `$core_log->get_advices()`. SQL queries are logged via `$db->get_history()`.
- PHP `error_log()` used for critical runtime issues (e.g., missing vendor autoloader, updater failures).
- Nginx access/error logs configured in `.ddev/nginx_full/nginx-site.conf` (`/var/log/nginx/access.log`).

**Analytics:**
- **None detected** — No Google Analytics, Matomo, or other analytics integration.

## CI/CD & Deployment

**Hosting:**
- **Self-hosted / Traditional LAMP** — No cloud platform deployment config detected. The application is a traditional PHP application designed for standard web hosting.
- DDEV for local development only (`.ddev/config.yaml`).

**CI Pipeline:**
- **None** — No `.github/workflows/` directory exists. The `.github/` directory contains only `copilot-instructions.md`.
- No GitLab CI, Jenkins, or other CI configuration detected.

**Auto-updater:**
- Internal plugin-based update system: `updater.php` redirects to `system_updater` plugin (if installed) or auto-downloads it from GitHub. The `fs_plugin_downloader` class handles authenticated downloads from GitHub with SHA256 integrity verification.

## Environment Configuration

**Required env vars / constants (defined in `config.php`):**
| Constant | Purpose |
|----------|---------|
| `FS_DB_TYPE` | Database type (`mysql` or `postgresql`) |
| `FS_DB_HOST` | Database hostname |
| `FS_DB_PORT` | Database port |
| `FS_DB_NAME` | Database name |
| `FS_DB_USER` | Database username |
| `FS_DB_PASS` | Database password |
| `FS_FOLDER` | Application root path (auto-defined in `index.php`) |
| `FS_COMMUNITY_URL` | GitHub repo URL (defined in `install.php`) |

**Optional constants:**
| Constant | Purpose |
|----------|---------|
| `FS_CACHE_HOST` | Memcached server hostname (defaults to `localhost`) |
| `FS_CACHE_PORT` | Memcached server port (defaults to `11211`) |
| `FS_CACHE_PREFIX` | Cache key prefix (defaults to `fs_`) |
| `FS_CSRF_SOFT` | Soft CSRF mode (warnings only, for migrations) |
| `FS_TMP_NAME` | Temp directory suffix for multi-tenant setups |
| `FS_TRUSTED_PROXIES` | Comma-separated proxy IPs/CIDRs |
| `FS_TRUSTED_HEADERS` | Comma-separated trusted header names |
| `FS_COOKIES_EXPIRE` | Session cookie lifetime |
| `FS_LAZY_MODELS` | Enable lazy model loading |

**Secrets location:**
- All secrets (DB credentials, cache config) stored in `config.php` — a PHP file generated by `install.php`.
- `config.php` is **gitignored** and protected from web access via `.htaccess` (`<FilesMatch>` rule).
- Encrypted values (e.g., `mail_password`) stored in `fs_vars` database table via `src/Security/EncryptionService.php`.
- `src/Security/SecretManager.php` provides a unified application secret with HMAC-derived sub-keys.

## Webhooks & Callbacks

**Incoming:**
- **None** — No incoming webhook endpoints detected in the codebase. API endpoints are available via `api.php` for REST resource access, but no webhook receiver patterns found.

**Outgoing:**
- **None** — No outgoing webhook calls detected. The only outbound HTTP calls are via `fs_plugin_downloader` for plugin ZIP downloads from external URLs (typically GitHub).

---

*Integration audit: 2026-05-16*
