# Codebase Concerns

**Analysis Date:** 2026-05-16

## v0.10.8 — Resolved Issues (Fixed in This Release)

The following concerns from previous iterations have been resolved:

| Issue | Resolution |
|-------|-----------|
| 15 base files lacked `strict_types` | 15 files now have `declare(strict_types=1)`: `fs_app.php`, `fs_settings.php`, `fs_plugin_downloader.php`, `fs_ip_filter.php`, `fs_list_decoration.php`, `php_file_cache.php`, `fs_edit_form.php`, `fs_cache.php`, `fs_api.php`, `fs_list_filter_date.php`, `fs_list_filter_select.php`, `fs_list_filter_checkbox.php`, `fs_list_filter.php`, `fs_log_manager.php`, `fs_secret_migrator.php` |
| `extras/phpmailer/` and `phpmailer_compat.php` | Deleted; all email now uses Composer-managed `phpmailer/phpmailer` ^6.0 |
| `src/Core/StealthMode.php` had 1073 lines | Reduced to 508 lines; CSS sanitization extracted to `src/Core/CssSanitizer.php` (246 lines), HTML sanitization to `src/Core/HtmlSanitizer.php` (388 lines) |
| No reusable CSS sanitization | `src/Core/CssSanitizer.php` created using `sabberworm/php-css-parser` ^9.0 |
| No reusable HTML sanitization | `src/Core/HtmlSanitizer.php` created with CDN-safe script allowlist, DOM-based filtering |
| MySQL identifier normalization in `fs_mysql.php` | Extracted to `base/FsMysqlSchemaUtility.php` (81 lines of pure utility) |
| `@` error suppression in base files | All `@` removed from base files that received strict_types; 5 remaining instances only in 2 files |
| SHA1/MD5 password verification in `src/Security/PasswordHasherService.php` | Removed; all legacy password verification now lives exclusively in `plugins/legacy_support/LegacyCompatibility.php` |
| `business_data` and `catalogo_core` had no tests | New tests added: `BusinessDataModelTest.php`, `ArticuloModelEncodingTest.php`, `FabricanteModelTest.php`, `FamiliaModelTest.php` |

---

## Tech Debt

### Legacy `fs_controller` as Monolith

- Issue: `base/fs_controller.php` is 1081 lines and handles auth, menu, template rendering, error collection, request access, CSRF, and event dispatch — violating single responsibility
- Files: `base/fs_controller.php`, `base/fs_edit_controller.php`, `base/fs_list_controller.php`
- Impact: Hard to test (many dependencies), hard to extend without subclassing, blocks full Symfony migration
- Fix approach: Decompose into smaller trait-based or service-based components. Route auth/CSRF through existing `src/Security/` services. Extract template rendering to `src/Core/Html.php` (already started).

### Legacy `fs_model` Requires DB for Construction

- Issue: `fs_model::__construct()` requires a live database connection for table validation. Testing requires anonymous subclasses with empty constructors.
- Files: `base/fs_model.php` (585 lines)
- Impact: Every model instantiation hits the database (or test code uses workarounds). Impedes true unit testing.
- Fix approach: Separate table validation from construction. Use a factory or builder pattern. Allow model metadata to be cached.

### Mixed Routing Logic in index.php

- Issue: `index.php` contains ~170 lines of controller discovery logic mixing legacy `?page=` routing, modern plugin Controller scanning, and Symfony routing bridge. Adding a new controller type requires editing `index.php`.
- Files: `index.php` (lines 170-340)
- Impact: High coupling, difficult to understand routing flow, hard to extend
- Fix approach: Move all controller discovery to `src/Core/Router.php` or `Kernel::handleRequest()`. Let `index.php` be a thin bootstrap.

### Dual Model Systems (Legacy + Modern)

- Issue: Two parallel model systems exist: legacy `model/` and `plugins/*/model/` (flat PHP files) vs. modern `plugins/*/Model/` (PSR-4 with `ValidatorTrait`). Both extend `fs_model`.
- Files: `plugins/catalogo_core/model/` vs `plugins/catalogo_core/Model/`, `plugins/business_data/model/` (legacy only)
- Impact: Confusion about where to add new models. Duplicate schemas. Inconsistent validation patterns.
- Fix approach: Standardize on PSR-4 Model/ directory. Migrate legacy models gradually as plugins adopt modern patterns.

### Singleton Service Locator Instead of DI

- Issue: Most services accessed via `Container::get('name')` or `Container::db()` rather than constructor injection
- Files: `src/DependencyInjection/Container.php`, all callers of `Container::get()`
- Impact: Implicit dependencies, harder to test in isolation, tight coupling
- Fix approach: Add factory methods or interfaces. Use autowiring where Symfony DI is available. For legacy code, document dependencies via PHPDoc.

### Remaining `@` Error Suppression

- Issue: 5 `@` suppression operators remain in 2 base files (down from larger count in previous versions)
- Files: `base/fs_maintenance_mode.php` (3 instances: `file_get_contents`, 2× `session_start`), `base/fs_functions.php` (2 instances: `file_get_contents`)
- Impact: Suppressed errors make debugging harder
- Fix approach: Replace with explicit error handling using `is_file()`/`is_readable()` checks before access, or try/catch with `\ErrorException`

### Base Files Without strict_types

- Issue: ~30 of 45 base files still lack `declare(strict_types=1)` (15 now have it)
- Files: `base/fs_controller.php`, `base/fs_model.php`, `base/fs_db2.php`, `base/fs_mysql.php`, `base/fs_postgresql.php`, `base/fs_schema.php`, `base/fs_login.php`, `base/fs_functions.php`, etc.
- Impact: Inconsistent type safety. Core files like `fs_controller.php` (1081 lines) and `fs_model.php` (585 lines) are high-priority targets.
- Fix approach: Continue progressive rollout. Prioritize files with fewest type errors. Add after fixing `@` suppression in those files.

## Known Bugs

### Maintenance Mode Session Read

- Symptoms: `@session_start(['read_and_close' => true])` and `@session_start()` in `fs_maintenance_mode.php` suppress errors
- Files: `base/fs_maintenance_mode.php` (lines 548, 550)
- Trigger: Session already started, headers already sent
- Workaround: `@` suppression hides errors, but functionality may silently fail
- Fix: Check `session_status()` before calling, or wrap in try/catch

### Remote File Fetch Without Timeout

- Symptoms: `file_get_contents($url)` used in `fs_functions.php` without context timeout on some paths could hang
- Files: `base/fs_functions.php` (lines 389, 525)
- Trigger: Slow or unresponsive remote server
- Workaround: PHP `default_socket_timeout` ini setting
- Fix: Add stream context with explicit timeout, use `symfony/http-client` instead

## Security Considerations

### Legacy Password Hash Remains Functional

- Risk: SHA1 and MD5 password verification still works via `plugins/legacy_support/LegacyCompatibility.php`. While the core `PasswordHasherService` no longer does legacy verification (fixed in v0.10.8), users with legacy hashes who don't log in won't get migrated.
- Files: `plugins/legacy_support/LegacyCompatibility.php` (lines 94-107), `base/fs_login.php`
- Current mitigation: Automatic migration to argon2id on successful login via `verifyAndUpgradeLegacyPassword()`. Telemetry tracks legacy usage.
- Recommendations: Add a script to proactively rehash all passwords offline (partial: `scripts/remediate-legacy-passwords.php` exists). Consider adding a deadline for dropping legacy support.

### Index.php Stale `@set_time_limit`

- Risk: `@set_time_limit(300)` at line 125 of `index.php` still uses `@` suppression
- Files: `index.php` line 125
- Current mitigation: Minor; the `@` is on a reliable PHP function
- Recommendations: Replace with `set_time_limit(300)` without suppression, or move to configuration

### DebugBar Exposed When FS_DEBUG Enabled

- Risk: If `FS_DEBUG` is accidentally left true in production, DebugBar exposes SQL queries, config, and framework internals
- Files: `src/Core/DebugBar.php` (initialized at `index.php:94`)
- Current mitigation: `FS_DEBUG` defaults to false; must be explicitly set in `config.php`
- Recommendations: Add IP-based restriction to DebugBar output even when debug mode is on

## Performance Bottlenecks

### fs_model Constructor Table Validation

- Problem: Every model instantiation checks and potentially repairs the database table schema via `fs_schema`
- Files: `base/fs_model.php` constructor
- Cause: Schema validation runs on every request for every model
- Improvement path: Cache schema validation results (already partially done via `FS_LAZY_MODELS`). Consider schema version tracking to skip validation when known-good.

### Template Rendering Overhead

- Problem: Twig compiles templates on first use; RainTPL legacy templates also checked
- Files: `src/Core/Html.php`, theme rendering pipeline
- Cause: Multiple template engines, complex template inheritance chains
- Improvement path: Pre-compile Twig templates. Phase out RainTPL support entirely. Use Twig cache warming.

### Plugin Initialization Scans Filesystem

- Problem: `index.php` scans all plugin `Controller/` directories on every request to discover modern controllers (lines 196-263)
- Files: `index.php` lines 196-263
- Cause: `scandir()` + `ReflectionClass` for every plugin Controller directory on every page load
- Improvement path: Cache controller-to-class mapping. Build a registry at plugin enable/disable time rather than per-request.

## Fragile Areas

### index.php Routing Logic

- Files: `index.php` (lines 170-340)
- Why fragile: Interleaved modern/legacy controller discovery, multiple fallback paths, ReflectionClass usage on every request, manual class name validation
- Safe modification: Any routing changes should be made in `src/Core/Router.php` or a new routing service, not in `index.php`
- Test coverage: Limited — `PluginControllerDiscoveryTest.php` and `AdminHomePageDiscoveryTest.php` cover some paths

### Stealth Mode + Public Access Gate

- Files: `src/Core/StealthMode.php` (508 lines), `src/Core/PublicAccessGate.php`, `src/Core/HtmlSanitizer.php`, `src/Core/CssSanitizer.php`
- Why fragile: Complex interaction between stealth gate, public access logic, HTML/CSS sanitization, session management, and OIDC support
- Safe modification: The recent v0.10.8 extraction of `CssSanitizer` and `HtmlSanitizer` improved modularity. Further refactoring should follow the same pattern: extract concerns into focused classes.
- Test coverage: Gaps in stealth mode end-to-end flow testing

### fs_controller Lifecycle

- Files: `base/fs_controller.php` (1081 lines)
- Why fragile: Central controller orchestrates auth, menu, CSRF, events, template selection, and error handling. Changes can cascade.
- Safe modification: Add new behavior via event listeners (`controller.before_action`, `controller.after_action`) rather than modifying the base class.
- Test coverage: Good — `FsControllerSessionTouchTest.php`, `FsLoginCookieAuthTest.php`, `FsLoginPasswordVerificationTest.php`, `FsLoginSessionInitializationTest.php`

## Scaling Limits

### Session Storage

- Current capacity: PHP file-based sessions (default)
- Limit: Single-server deployment; no horizontal scaling without shared session storage
- Scaling path: Implement database-backed or Redis session handler via `SessionHandlerInterface`. The `SessionManager` in `src/Security/` provides an abstraction point.

### Cache Strategy

- Current capacity: Multi-adapter chain (Array + Filesystem + optional Memcached) with short TTLs (30-180s defaults)
- Limit: Filesystem adapter writes to disk; not suitable for high-concurrency distributed deployments
- Scaling path: Redis adapter via Symfony Cache (already compatible — just configure). Memcached already supported if extension is present.

### Plugin Scanning at Boot

- Current capacity: Scans plugin directories on every request
- Limit: Linear scaling with plugin count. With 8 core plugins, minimal impact. Would degrade with 50+ plugins.
- Scaling path: Pre-build a plugin registry file at plugin enable/disable time. Cache the mapping.

## Dependencies at Risk

### Bootstrap 3.4.1

- Risk: End-of-life, no longer receiving security updates. Bootstrap 5 is current.
- Impact: Styling bugs on modern browsers, potential unpatched CSS vulnerabilities in complex components
- Migration plan: Upgrade to Bootstrap 5 (requires significant template rework). Tailwind CSS 4.1 is already available as a build dependency — could be used for new UI while maintaining Bootstrap 3 for legacy pages.

### PHP CSS Parser (sabberworm/php-css-parser)

- Risk: Niche library; maintenance status uncertain. Only used in `CssSanitizer`.
- Impact: If unmaintained, CSS sanitization could miss new attack vectors or break on modern CSS syntax
- Migration plan: Monitor upstream. Alternative: Symfony CSS Selector or custom whitelist-based parser. Currently low risk as usage is limited to StealthMode admin configuration.

## Missing Critical Features

### Automated CI Pipeline

- Problem: No GitHub Actions, GitLab CI, or other automated CI pipeline detected
- Blocks: Automated testing on pull requests, code quality gates, automated deployments
- Recommendation: Add a `.github/workflows/ci.yml` running `ddev exec composer install && ddev exec php vendor/bin/phpunit` and `phpstan`

### Test Coverage Reports

- Problem: No coverage enforcement or historical tracking
- Blocks: Visibility into untested code paths, regression detection
- Recommendation: Enable coverage in CI pipeline, set initial thresholds, track over time

### OpenAPI/Swagger Spec Generation

- Problem: `zircote/swagger-php` is in dependencies but no generated spec or CI step to validate it
- Blocks: API consumers cannot discover endpoints programmatically
- Recommendation: Add a build step to generate `openapi.json`/`openapi.yaml` from API attributes

## Test Coverage Gaps

### StealthMode + PublicAccessGate Integration

- What's not tested: End-to-end flow of stealth mode gate blocking/redirecting requests, sanitization pipeline with HTMLSanitizer + CssSanitizer, session-based unlock
- Files: `src/Core/StealthMode.php`, `src/Core/PublicAccessGate.php`, `src/Core/HtmlSanitizer.php`, `src/Core/CssSanitizer.php`
- Risk: Stealth mode bypass or sanitization failures could expose admin panel
- Priority: High

### Plugin Dependency Resolution

- What's not tested: Plugin enable/disable with dependency chains, circular dependency detection, `require` field validation
- Files: `base/fs_plugin_manager.php`
- Risk: Plugin activation could break if dependencies aren't validated
- Priority: Medium

### Remaining base/ Files

- What's not tested: Several base files have no dedicated test coverage, particularly: `fs_secure_chunked_upload.php`, `fs_file_manager.php`, `fs_excel.php`, `fs_chunked_upload.php`, `fs_auth.php`, `fs_plugin_manager.php`
- Files: `base/fs_secure_chunked_upload.php`, `base/fs_file_manager.php`, `base/fs_excel.php`, `base/fs_auth.php`, `base/fs_plugin_manager.php`
- Risk: File upload security, authorization logic untested
- Priority: Medium

### Legacy Password Migration Path

- What's not tested: Full migration flow from SHA1/MD5 → argon2id including save failure, timeout edge cases, concurrent login scenarios
- Files: `plugins/legacy_support/LegacyCompatibility.php`
- Risk: Users could get stuck on legacy hashes without migration
- Priority: Medium

---

*Concerns audit: 2026-05-16*
