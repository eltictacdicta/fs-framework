# Codebase Concerns

**Analysis Date:** 2026-05-16

## Tech Debt

### Incorrect PHP Version Guards in Entry Points

- **Issue:** `index.php:20` and `install.php:267` still check `phpversion() < 5.6` but `composer.json` requires `PHP >=8.2`. These guards are misleading dead code.
- **Files:** `index.php`, `install.php`
- **Impact:** No functional issue (Composer enforces the real constraint), but the message displayed is incorrect and the check itself is useless.
- **Fix approach:** Bump the version check to `8.2` in both files, or remove the guard since Composer validates PHP before autoloading. Use `version_compare(PHP_VERSION, '8.2', '<')` as the replacement.

### Model Duplication Between Core Plugins

- **Issue:** `business_data` and `catalogo_core` plugins both define models for shared domain concepts: `almacen`, `pais`, and `divisa`. This creates ambiguity about which version is authoritative and risks divergent behavior.
- **Files:**
  - `plugins/business_data/model/almacen.php` vs `plugins/catalogo_core/model/core/almacen.php`
  - `plugins/business_data/model/pais.php` vs `plugins/catalogo_core/model/core/pais.php`
  - `plugins/business_data/model/divisa.php` vs `plugins/catalogo_core/model/core/divisa.php`
- **Impact:** Plugin dependency order determines which class loads; a plugin depending on the "wrong" version may get missing fields or unexpected behavior. Makes refactoring and extraction risky.
- **Fix approach:** Extract shared domain models into a single-source plugin. Follow the existing refactoring pattern: move models, XML schemas, and translations as a unit. Consumers (`business_data`, `facturacion_base`, etc.) become dependents of the shared plugin.

### Entire Legacy `base/` Directory Lacks `declare(strict_types=1)`

- **Issue:** All 43 PHP files in `base/` lack `declare(strict_types=1)`. None of the controllers under `controller/` have it either.
- **Files:** `base/*.php` (43 files), `controller/*.php` (14 files), `model/core/agente.php`, `model/core/fs_user.php`
- **Impact:** Type coercion bugs are silently swallowed. Makes it harder to safely refactor legacy code toward modern PHP 8.2+ patterns. Contradicts the AGENTS.md guidance that modern files should declare strict types.
- **Fix approach:** Add `declare(strict_types=1)` incrementally to base files, starting with the simplest ones (`fs_ip_filter.php`, `fs_secret_migrator.php`, `config2.php`). For complex files (`fs_mysql.php`, `fs_plugin_manager.php`, `fs_controller.php`), add types to method signatures first, then enable strict types. Use the existing test suite as a safety net.

### `#[AllowDynamicProperties]` on `fs_model` and `fs_controller`

- **Issue:** Both `fs_model` (`base/fs_model.php:32`) and `fs_controller` (`base/fs_controller.php:50`) use the `#[AllowDynamicProperties]` attribute. This suppresses the PHP 8.2 deprecation for dynamic property assignment but masks the underlying problem: these classes have no centralized property declaration.
- **Files:** `base/fs_model.php`, `base/fs_controller.php`
- **Impact:** Dynamic properties are error-prone — typos in property names create new properties silently instead of errors. Plugin compatibility relies on this behavior, so removing it is non-trivial.
- **Fix approach:** Audit which dynamic properties plugins actually set. Declare them as typed properties in each base class. Remove the attribute only after all known dynamic property usage is migrated.

### Deprecated API Helper — Migration Target v3.0

- **Issue:** `src/Api/Helper/RequestHelper.php` is entirely deprecated for removal in v3.0, with methods like `getParam()`, `getRequiredParam()`, and `getBoolParam()` tagged for removal.
- **Files:** `src/Api/Helper/RequestHelper.php`
- **Impact:** Any plugin using the legacy API helper will break on v3.0. The migration path (using Symfony Request methods directly) is documented but no automated migration tooling exists.
- **Fix approach:** Create a migration script or deprecation shim that logs warnings when legacy methods are called. Audit all plugins for usage. Document the exact replacement patterns per method.

### Deprecated `Kernel::legacyFrontController()` — Migration Target v3.0

- **Issue:** `Kernel::legacyFrontController()` at `src/Core/Kernel.php:180` is deprecated for removal in v3.0.
- **Files:** `src/Core/Kernel.php`
- **Impact:** Legacy-style direct controller dispatch will be removed. Affects any code bypassing the Symfony routing bridge.
- **Fix approach:** Ensure the Symfony routing bridge in `index.php` handles all entry points. Verify no plugins rely on the legacy dispatch path.

### Widespread Error Suppression (`@` Operator)

- **Issue:** Over 25 usages of the `@` error suppression operator across `base/` files, particularly `fs_secure_chunked_upload.php` (6 occurrences), `fs_plugin_manager.php` (4), `fs_core_log.php` (3), `fs_model_autoloader.php` (4).
- **Files:** `base/fs_secure_chunked_upload.php`, `base/fs_plugin_manager.php`, `base/fs_core_log.php`, `base/fs_model_autoloader.php`, `base/config2.php`, `base/fs_file_manager.php`, `base/fs_plugin_downloader.php`, `base/fs_postgresql.php`, `base/fs_maintenance_mode.php`, `index.php`
- **Impact:** Real errors (disk full, permission denied, corrupt cache) are hidden. Debugging production issues becomes much harder.
- **Fix approach:** Replace `@mkdir()` with explicit `is_dir()` + `mkdir()` checks. Replace `@unlink()` and `@file_put_contents()` with `try/catch` blocks that log failures. Replace `@fs_file_get_contents_auth()` with proper error handling in the helper.

## Security Considerations

### Weak Random String Generation in Installer

- **Issue:** `install.php:237` defines `random_string()` using `str_shuffle()`, which is not cryptographically secure. The fallback in `random_secret_key()` at line 246 delegates to this weak function when `random_bytes()` is unavailable.
- **Files:** `install.php:235-247`
- **Risk:** On PHP installations without the `random_bytes()` function (unlikely on PHP 8.2+, but the fallback exists), the generated `FS_SECRET_KEY` would be predictable, compromising CSRF tokens, signed URLs, and cookie signatures.
- **Current mitigation:** PHP 8.2+ always has `random_bytes()`, so the fallback is effectively dead code. However, its presence is misleading and could be copied to other contexts.
- **Recommendations:** Remove the `str_shuffle()` fallback. Replace with `random_bytes()` only. If a fallback is truly needed, use `bin2hex(openssl_random_pseudo_bytes($length/2))`.

### SHA1 Legacy Password Support Still Present

- **Issue:** `PasswordHasherService.php` at `src/Security/PasswordHasherService.php:245-270` contains code to verify SHA1 and MD5 password hashes and auto-migrate them to bcrypt/argon2id. The `fs_user` model at `model/core/fs_user.php:525-530` has `is_legacy_sha1_password()` to detect SHA1 hashes.
- **Files:** `src/Security/PasswordHasherService.php`, `model/core/fs_user.php`, `base/fs_login.php`
- **Risk:** While auto-migration is a good pattern for existing installations, legacy SHA1 support should eventually be removed. If the migration period has been sufficient, continuing to accept SHA1 hashes adds unnecessary attack surface.
- **Current mitigation:** The `PasswordHasherService` actively migrates on successful verification. Sessions using `CookieSigner` and `log_key` are cryptographically secure.
- **Recommendations:** Add a `last_password_migration` timestamp to track when users were migrated. Consider a forced password-reset flow for users who haven't been migrated within a defined window.

### `md5()` Used as Cache/Identifier Key (Non-Security Contexts)

- **Issue:** `md5()` is used in several places for generating cache keys and identifiers: `RateLimitMiddleware.php`, `CsrfManager.php`, `LoginThrottle.php`, `SessionManager.php`, `php_file_cache.php`.
- **Files:** `src/Api/Middleware/RateLimitMiddleware.php:97`, `src/Security/CsrfManager.php:294,324`, `src/Security/LoginThrottle.php:134`, `src/Security/SessionManager.php:142`, `base/php_file_cache.php:64`
- **Risk:** In cache-key contexts, md5 is not a security risk per se (these aren't hashing secrets), but it's a code smell suggesting outdated crypto awareness. In `SessionManager` and `fs_maintenance_mode`, `sha1()` is similarly used for non-cryptographic key derivation.
- **Recommendations:** Replace with `hash('sha256', ...)` or `bin2hex(random_bytes(16))` where actual cryptographic randomness is needed. For pure cache keys, `hash('xxh3', ...)` is faster.

### Oracle

- **Issue:** No detected `eval()`, `extract()`, `unserialize()` without `allowed_classes`, or URL-based includes. The codebase appears free of the most common severe injection vectors.

## Performance Bottlenecks

### `StealthMode.php` — 55 Methods in One Class

- **Issue:** `src/Core/StealthMode.php` is 1073 lines with 55 methods handling HTML sanitization, CSS sanitization, URL validation, session management, login redirects, and database access — all in a single class. Instantiation at `index.php:103` creates a heavy object on every request.
- **Files:** `src/Core/StealthMode.php`
- **Cause:** The class bundles too many concerns: HTML/CSS sanitization (lines 562-1002), stealth access logic (lines 114-282), and legacy login integration (lines 440-551). The CSS sanitization alone uses the `sabberworm/php-css-parser` library with recursive tree traversal.
- **Improvement path:** Split into focused services: `StealthAccessGate`, `HtmlSanitizer`, `CssSanitizer`. The CSS sanitizer could cache whitelist decisions. The stealth gate should be lazily evaluated rather than constructed on every request.

### `fs_mysql.php` — 1596 Lines, 72 Methods

- **Issue:** The MySQL driver class is monolithic. Schema comparison, constraint normalization, column type mapping, multi-query execution, and connection management all coexist in one file.
- **Files:** `base/fs_mysql.php`
- **Cause:** The file has grown organically without decomposition. Method count and line count suggest it violates the Single Responsibility Principle several times over.
- **Improvement path:** Extract schema operations (`compare_columns`, `compare_constraints`, `generate_table`) into an `FsMysqlSchema` class. Extract constraint normalization into a dedicated utility. Keep the driver focused on connection, query execution, and escaping.

### `admin_home.php` — 1053 Lines Dashboard Controller

- **Issue:** The admin dashboard controller at `controller/admin_home.php` has 1053 lines and 32 methods. It handles dashboard rendering, plugin management UI, system info, cache clearing, update checking, and more.
- **Files:** `controller/admin_home.php`
- **Cause:** The controller has accumulated unrelated responsibilities — plugin management, system health checks, cache administration, and dashboard widgets.
- **Improvement path:** Extract cache management into a dedicated `admin_cache` controller. Extract plugin management into a dedicated `admin_plugins` controller. Extract system info into `admin_info` (which already exists and overlaps). Keep `admin_home` focused on dashboard widgets only.

### `install.php` — 1212 Lines with Mixed PHP/HTML

- **Issue:** The installer is a single PHP file with deeply nested HTML output, database probing logic, form handling, and old-school inline styles.
- **Files:** `install.php`
- **Cause:** Legacy single-file installer with no template separation.
- **Improvement path:** Migrate to a multi-file installer with Twig templates. Separate PHP logic (database probing, configuration writing) from presentation.

### Only 32 Twig Templates for the Entire Application

- **Issue:** With the strategic push toward Twig as the primary template engine, only 32 `.twig` templates exist across the themes directory. One of them (`view/admin_stealth.html.twig`) is 26KB — a massive monolithic template.
- **Files:** `themes/AdminLTE/view/` (32 .twig files), `view/admin_stealth.html.twig`
- **Impact:** Low Twig adoption means most controllers still render inline HTML or use legacy view files. The Twig migration is far from complete, making the framework's template story inconsistent.
- **Improvement path:** Prioritize extracting common layout components (header, footer, sidebar) into Twig templates. Break `admin_stealth.html.twig` into composable blocks. Create a migration checklist for remaining RainTPL/legacy views.

## Fragile Areas

### Plugin Test Coverage Gap — 5 of 8 Plugins Untested

- **Area:** 5 out of 8 plugins have zero test coverage.
- **Files:** `plugins/business_data/` (no tests), `plugins/clientes_catalogo/` (no tests), `plugins/clientes_facturacion/` (no tests), `plugins/facturascripts_support/` (no tests), `plugins/hola_mundo/` (no tests)
- **Why fragile:** Business-critical plugins (`business_data` provides empresa, ejercicios, series, divisas; `clientes_facturacion` provides invoicing) have no automated tests. Any refactoring in these areas risks regressions.
- **Safe modification:** Run the existing test suite before and after changes. Add tests incrementally — start with `business_data` model tests (empresa, ejercicio, serie, divisa) since those are the most depended-upon models.
- **Test coverage:** `phpunit.xml` only configures source coverage for `base/`, `src/`, and `plugins/clientes_core`. Other plugins and `model/core/` are not included in coverage reports.

### `agente` Model — No Dedicated Tests

- **Issue:** `model/core/agente.php` has no dedicated test class. The only test reference is in `tests/Security/SecurityHelpersTest.php` where `codagente = 'AG01'` is set as a mock property, not testing `agente` model behavior.
- **Files:** `model/core/agente.php` (358 lines)
- **Risk:** `agente` is a core model (users link to agents, agents link to documents). Without tests, model changes risk breaking user-document associations.
- **Priority:** Medium

### `config2.php` — Global Mutable State as Bootstrap

- **Issue:** `base/config2.php` populates `$GLOBALS['config2']` and `$GLOBALS['plugins']` as mutable global arrays, then defines constants from `$GLOBALS['config2']`. The file also reads a `config2.ini` file from `tmp/` and merges values into `$GLOBALS['config2']`.
- **Files:** `base/config2.php`
- **Why fragile:** Global mutable state makes it difficult to test in isolation. The merge from `config2.ini` can override any constant value. Plugin loading depends on `$GLOBALS['plugins']` being populated before any controller/model code runs.
- **Safe modification:** Do not modify the global array structure without updating all consumers. When adding new configuration, use the Symfony Dotenv component or dedicated configuration services instead of extending `config2.php`.
- **Test coverage:** No direct test for `config2.php`. Covered only through integration via the full bootstrap in `tests/bootstrap.php`.

### `base/fs_controller.php` — 45 Methods, Permissions + Routing + Rendering

- **Issue:** The base controller at 1081 lines handles permission checking, CSRF validation, menu building, extension loading, AJAX detection, page option resolution, Twig/RainTPL rendering dispatch, and more.
- **Files:** `base/fs_controller.php`
- **Why fragile:** Changes to the base controller affect every page in the application and every plugin. The method count (45) and line count (1081) indicate tight coupling of responsibilities.
- **Safe modification:** Always run the full test suite when modifying `fs_controller.php`. Prefer extracting new behavior into traits or services rather than adding methods to this class.
- **Test coverage:** Several focused tests (FsControllerSessionTouchTest, FsLoginSessionInitializationTest, etc.) cover specific aspects, but no comprehensive controller lifecycle test exists.

### `fs_plugin_manager.php` — 1293 Lines, Heavy File I/O

- **Issue:** The plugin manager handles installation, removal, backup, enabling/disabling, controller registration, private plugin downloads, GitHub API integration, and ZIP extraction — all in one class.
- **Files:** `base/fs_plugin_manager.php`
- **Why fragile:** Plugin installation involves filesystem operations, HTTP requests to GitHub, ZIP extraction, and database writes. A failure at any step can leave the plugin in an inconsistent state.
- **Safe modification:** Ensure the backup system (`create_backup()` / `restore_backup()`) is tested before modifying installation logic. Run the plugin-related tests and manually verify installation of a test plugin.
- **Test coverage:** No dedicated `fs_plugin_manager` test class.

## Scaling Limits

### Single Database Connection Model

- **Current capacity:** `fs_db2` and `fs_mysql`/`fs_postgresql` use a single static database connection (`self::$link`).
- **Limit:** No connection pooling or read-replica support. All queries go through one connection. Multi-tenancy via `FS_TMP_NAME` directories only affects configuration, not database routing.
- **Scaling path:** Introduce a connection pool abstraction behind `fs_db2`. For read replicas, add a `FS_DB_READ_HOST` configuration constant and route SELECT queries through read connections while writes go to the primary.

### File-Based Plugin Registry

- **Current capacity:** Plugin list is stored in `tmp/{FS_TMP_NAME}/enabled_plugins.list` as a comma-separated file. Plugin metadata is parsed from `fsframework.ini` on every request.
- **Limit:** No caching of plugin metadata beyond the raw `.list` file. Every request reads and parses plugin INI files.
- **Scaling path:** Cache parsed plugin metadata in `CacheManager` with a long TTL. Invalidate on plugin install/remove/enable/disable operations.

## Dependencies at Risk

### `sabberworm/php-css-parser` ^9.0

- **Risk:** Used exclusively by `StealthMode.php` for CSS sanitization. The library parses arbitrary CSS into an AST for validation. If the library is abandoned or has unresolved vulnerabilities, the sanitizer becomes a liability.
- **Impact:** Without it, the stealth mode admin panel CSS sanitizer would need to be rewritten or the CSS sanitization feature removed.
- **Migration plan:** Evaluate whether the CSS sanitization feature justifies the dependency. Consider a simpler regex-based whitelist approach for the limited CSS properties the stealth mode supports. The `ALLOWED_CSS_URL_PROPERTIES` constant already lists only 5 properties.

### Vendored `extras/phpmailer/`

- **Risk:** The `extras/phpmailer/` directory contains vendored PHPMailer 5.x code (class files: `class.phpmailer.php`, `class.smtp.php`, `class.pop3.php`) alongside a `phpmailer_compat.php` bridge. Meanwhile, `composer.json` depends on `phpmailer/phpmailer:^6.0`.
- **Impact:** Two versions of PHPMailer exist in the project. The vendored 5.x code has deprecated methods, and the 6.x Composer version has a different API. Maintenance burden of the compatibility layer.
- **Migration plan:** Audit all code paths that go through `phpmailer_compat.php`. Migrate them to use the Composer-provided PHPMailer 6.x directly. Remove the `extras/phpmailer/` directory and the compatibility bridge.

## Missing Critical Features

### No Database Migration System

- **Problem:** Schema changes are applied via XML table definitions (`model/table/*.xml`) compared against live database structure at runtime. There is no versioned migration system (no `doctrine/migrations`, no Phinx, no custom migration runner).
- **Blocks:** Safe schema evolution. Renaming a column requires dropping and recreating it. Complex data migrations must be done manually via SQL scripts.

### No Queue/Job System

- **Problem:** Long-running operations (email sending, report generation, plugin downloads) execute synchronously during the HTTP request.
- **Blocks:** Scalable email delivery, background report generation, async plugin installation.

### No API Versioning Strategy Beyond v1

- **Problem:** API resources use `version: 'v1'` in `ApiResource` attributes, but there is no multi-version routing, deprecation policy, or sunset schedule defined.
- **Blocks:** Making breaking API changes without disrupting existing API consumers.

## Test Coverage Gaps

### No Controller-Level Integration Tests

- **What's not tested:** None of the 14 controllers in `controller/` have dedicated test classes. Controllers are exercised only indirectly through focused tests (e.g., `LoginInitialCredentialsMessageTest`, `AdminHomePageDiscoveryTest`).
- **Files:** `controller/*.php` (14 files)
- **Risk:** Controllers handle the bulk of HTTP request processing. Changes to request handling, permission checks, or template rendering can break silently.
- **Priority:** Medium. Focus on `login.php` and `admin_home.php` first (most critical entry points).

### Plugin Models Without Test Coverage

- **What's not tested:** `plugins/catalogo_core/model/core/articulo.php` (1391 lines — the single largest model in the codebase), `plugins/business_data/model/empresa.php` (558 lines).
- **Files:** `plugins/catalogo_core/model/core/articulo.php`, `plugins/business_data/model/empresa.php`, `plugins/catalogo_core/model/core/*.php`
- **Risk:** `articulo` is the product model and one of the most complex domain objects. Untested changes could corrupt inventory or pricing data.
- **Priority:** High

### `php_file_cache.php` — No Tests for File-Based Caching

- **What's not tested:** The file-based cache implementation at `base/php_file_cache.php` has no dedicated test. Cache tests focus on the Symfony-based `CacheManager`.
- **Files:** `base/php_file_cache.php`
- **Risk:** If `CacheManager` falls back to file-based cache, the behavior is untested. File locking, TTL enforcement, and concurrent access are not validated.
- **Priority:** Low (file cache is a fallback, not the primary path).

---

*Concerns audit: 2026-05-16*
