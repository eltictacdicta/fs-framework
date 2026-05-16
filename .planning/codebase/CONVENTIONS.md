# Coding Conventions

**Analysis Date:** 2026-05-16

## Naming Patterns

**Files:**
- Legacy framework files: `fs_{name}.php` (snake_case, e.g., `fs_controller.php`, `fs_db2.php`, `fs_core_log.php`)
- Legacy controllers: `{name}.php` (snake_case, e.g., `admin_home.php`, `login.php`, `password_reset.php`)
- Legacy models: `{name}.php` (snake_case, e.g., `fs_user.php`, `fs_var.php`, `agente.php`)
- Modern PSR-4 classes: PascalCase matching class name (e.g., `StealthMode.php`, `CacheManager.php`, `PasswordHasherService.php`)
- Test files: `{Component}Test.php` (PascalCase + `Test` suffix, e.g., `FsModelMethodsTest.php`, `PasswordHasherServiceTest.php`)
- XML schemas: `{table_name}.xml` (match the database table name, e.g., `fs_users.xml`)
- Plugin metadata: `fsframework.ini` (required), `facturascripts.ini` (optional compatibility)
- Twig templates: `{name}.html.twig` (snake_case, e.g., `admin_home.html.twig`, `force_password_change.html.twig`)

**Functions:**
- Legacy (historical) global functions: snake_case names such as `fs_filter_input_req()`, `fs_get_requested_page_name()`, and `find_controller()`. These legacy names are deprecated and kept for compatibility only.
- Current conventions: class methods use camelCase, including `private_core()`, `new_error_msg()`, `isCsrfValid()`, `dispatchModelEvent()`, and abstract model methods `test()`, `save()`, `delete()`, `exists()`. CamelCase is the current standard for new and maintained code.
- Helper namespaced functions: `trans()`, `__()` (global shortcuts in `TranslationHelper.php`)

**Variables:**
- Class properties: `$camelCase` (e.g., `$tableName`, `$codejercicio`, `$items`, `$is_ajax`)
- Legacy: some snake_case in legacy code (e.g., `$table_name`, `$fsc_error`, `$pagename`)
- Global: `$GLOBALS['plugins']`, `$GLOBALS['config2']` (snake_case array keys)

**Types/Interfaces (modern):**
- PascalCase (e.g., `ApiAuthInterface`, `AllowedUserInterface`, `MiddlewareInterface`)
- Prefer typed properties (PHP 8.2+): `protected ?Empresa $empresa = null`
- Use union types: `string|int $id`
- Use named arguments: `length(min: 1, max: 100)`

**Constants:**
- UPPER_SNAKE_CASE for framework constants: `FS_DB_NAME`, `FS_FOLDER`, `FS_DEBUG`, `FS_COOKIES_EXPIRE`
- Class constants: UPPER_SNAKE_CASE with meaningful prefix (e.g., `Variable::TYPE_STRING`, `StealthMode::VAR_ENABLED`)
- PSR-3 log levels: lowercase (e.g., `LogLevel::ERROR`, `LogLevel::WARNING`)

**Database tables:**
- Plural lowercase with underscores: `fs_users`, `ejercicios`, `fs_roles`
- Prefix `fs_` for framework tables

## Code Style

**Formatting:**
- No enforced auto-formatter (no `.prettierrc`, `biome.json`, or `.editorconfig` detected)
- Consistent LGPL header block on all PHP files (see `AGENTS.md` template)
- Indentation: spaces (4-space observed in codebase)
- Bracket style: PSR-12 (opening brace on same line for classes/methods, control structures with space)

**Linting:**
- No ESLint/Prettier for PHP (not configured)
- PHPStan level 5 with baseline (`phpstan.neon`, `phpstan-baseline.neon`)
- Rector automated refactoring (`rector.php`) with PHP 8.3 + code quality + dead code rules
- `declare(strict_types=1)` in 15 base files and 9 src files (growing, targeted for full coverage)

**File encoding:**
- UTF-8 without BOM (standard for all PHP files)
- LF line endings (Unix standard)

## Import Organization

**Modern `src/` code (PSR-4):**
1. `use` statements for Symfony components
2. `use` statements for framework classes (`FSFramework\...`)
3. `use` statements for third-party libraries
4. No manual `require_once` (relies on Composer autoloader)

Example:
```php
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use FSFramework\Core\StealthMode;
```

**Legacy `base/` and `controller/` code:**
- Uses `require_once` at top of file for dependencies
- Grouped by category (framework base classes first, then plugins, then utilities)
- Example from `base/fs_controller.php`:
```php
require_once 'base/fs_app.php';
require_once 'base/fs_db2.php';
require_once 'base/fs_default_items.php';
require_once 'base/fs_extended_model.php';
require_once 'base/fs_login.php';
```

**Path Aliases:**
- PSR-4 root: `FSFramework\` → `src/`, `FSFramework\Plugins\` → `plugins/`
- Test namespace: `Tests\` → `tests/`
- Dynamic fallback in `index.php` uses `spl_autoload_register` for plugin classes

## Error Handling

**Patterns:**
- Framework logging rather than exceptions for business errors:
  - `$this->new_error_msg($message)` — Report error to user
  - `$this->new_message($message)` — Success notification
  - `$this->new_advice($message)` — Warning/tip
- Boolean return values: `save()`, `delete()`, `test()` return `bool`
- Fatal errors: `register_shutdown_function("fatal_handler")` in `index.php`
- Exceptions used for system-level failures (`\Throwable` catch blocks)
- `error_log()` for server-side logging of non-user-facing errors
- PSR-3 compatible logging in `fs_core_log` with levels: emergency, alert, critical, error, warning, notice, info, debug

**Error reporting:**
- `@` error suppression: Mostly eliminated in v0.10.8 from `base/` files
  - Remaining 5 instances in `base/fs_maintenance_mode.php` (3: `file_get_contents`, `session_start`)
  - Remaining 2 in `base/fs_functions.php` (2: `file_get_contents`)
  - No `@` suppression in `src/` code

**CSRF error handling:**
- Automatic CSRF validation in `pre_private_core()`
- `$this->isCsrfValid()` to check validation result
- `FS_CSRF_SOFT` mode for migration (warnings only, no blocking)

## Logging

**Framework:** Custom via `fs_core_log` + `error_log()`

**Patterns:**
- `$this->new_error_msg()` for user-visible errors (collected, rendered in template)
- `error_log()` for system/internal messages (server error log)
- `fs_log_manager` persists per-request log to `fs_logs` database table
- `LegacyUsageTracker` in `plugins/legacy_support/` tracks deprecated API usage
- Symfony deprecations logged via `SYMFONY_DEPRECATIONS_HELPER=weak` in tests

**When to log:**
- All user-facing errors (data validation failures, permission denied)
- System errors (DB connection failures, file system errors)
- Security events (failed logins, CSRF failures, session issues)
- Legacy component usage for migration tracking

## Comments

**When to Comment:**
- LGPL header block on every PHP file
- PHPDoc on public methods (observed in modern `src/` code)
- Inline comments in Spanish for business logic explanations (e.g., `/// ¿Qué controlador usar?`)
- Section separators: `// =========================================` style dividers in test files

**JSDoc/TSDoc:**
- Not used (no TypeScript)
- PHPDoc used for `@param`, `@return`, `@author`, `@throws` in modern code

## Function Design

**Size:** Varies widely
- Legacy controllers can be large (e.g., `fs_controller.php`: 1081 lines)
- Modern `src/` classes tend to be moderate (50-400 lines)
- `StealthMode.php` was reduced from 1073 to 508 lines in v0.10.8

**Parameters:**
- Legacy models: constructor accepts `$data = FALSE` (array or false)
- Modern services: constructor injection with nullable parameters
- Named arguments in PHP 8.2+ code for clarity (e.g., `length(min: 1, max: 100)`)

**Return Values:**
- `bool` for success/failure operations (model methods)
- `?string` or typed returns for data operations
- `Response` objects from modern `handle()` methods
- `$this->json()`, `$this->redirect()`, `$this->html()` using ResponseTrait

## Module Design

**Exports:**
- One class per file (strict convention throughout codebase)
- PSR-4 namespaced autoloading for `src/` and `tests/`
- Legacy `base/` files define single global classes without namespace

**Barrel Files:**
- Not used (PHP doesn't natively support barrels)
- `Init.php` in plugins serves as plugin initialization entry point

**Plugin conventions:**
- Each plugin must have `fsframework.ini` with `version`, `description`, `min_version`, `author`
- `Init.php` for event listener registration and Twig extension setup
- Plugin dependencies declared via `require` field in INI (comma-separated plugin names)
- Plugin tests placed in `plugins/{PluginName}/tests/` with auto-discovery

## PHP 8.2+ Features in Use

- **Attributes:** `#[FSRoute]`, `#[Assert\NotBlank]`, `#[ApiResource]`, `#[AllowDynamicProperties]`
- **Typed properties:** `protected ?Empresa $empresa = null`, `private string $tableName`
- **Union types:** `string|int $id`, `array|object $result`
- **Match expressions:** Used in `src/` code for cleaner switch-like logic
- **Named arguments:** `new Assert\Length(min: 1, max: 100)`
- **Constructor property promotion:** `public function __construct(private object $legacyUser) {}`
- **Readonly properties:** Used where applicable
- **Nullsafe operator:** Not widely adopted yet

## Anti-Patterns to Avoid

- **Do not use `@` error suppression** in new code (being actively removed from base files)
- **Do not add new SHA1/MD5 password hashing** — all legacy verification lives in `plugins/legacy_support/LegacyCompatibility.php`
- **Do not directly read `$_GET`, `$_POST`, `$_REQUEST`** — use Symfony Request helper or `fs_filter_input_req()`
- **Do not concatenate user input into SQL** — use `$this->var2str()` or prepared statements
- **Do not use `|raw` in Twig** with unsanitized user data

---

*Convention analysis: 2026-05-16*
