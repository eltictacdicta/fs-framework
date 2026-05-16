# Coding Conventions

**Analysis Date:** 2026-05-16

## Naming Patterns

**Files:**
- Legacy PHP files: `snake_case` with `fs_` prefix (e.g., `fs_model.php`, `fs_core_log.php`, `fs_db2.php`)
- Legacy controllers: `snake_case` sometimes prefixed by role (e.g., `admin_users.php`, `login.php`)
- Modern PSR-4 classes in `src/`: PascalCase matching class name (e.g., `CacheManager.php`, `CsrfManager.php`, `FSEventDispatcher.php`)
- Plugin controllers: mixed — legacy files in `controller/` use `snake_case`, FS2025 in `Controller/` use PascalCase
- Test files: PascalCase with `Test` suffix (e.g., `FsModelMethodsTest.php`, `PasswordHasherServiceTest.php`)
- XML schema files: match table name (e.g., `model/table/fs_users.xml`)
- Translation files: `messages.{locale}.yaml` (new) or `{locale}.json` (FS2025 compatibility)

**Classes:**
- Legacy: `snake_case` with `fs_` prefix (`fs_model`, `fs_controller`, `fs_db2`, `fs_core_log`, `fs_ip_filter`, `fs_query_builder`)
- Modern `src/`: PascalCase under `FSFramework\` namespace (`CacheManager`, `CsrfManager`, `PasswordHasherService`, `FSEventDispatcher`, `FSTranslator`)
- New code should use PascalCase with `FSFramework\` namespace
- Controllers in `controller/` extend `fs_controller` and use `snake_case` (e.g., `class login extends fs_controller`)
- Plugin Init classes: `Init` class in plugin root namespace

**Methods:**
- Legacy: `camelCase` mixed with `snake_case` — key methods: `private_core()`, `new_error_msg()`, `new_message()`, `new_advice()`, `no_html()`, `var2str()`, `floatcmp()`, `str2bool()`, `intval()`
- Modern: `camelCase` — `getInstance()`, `validate()`, `verify()`, `hash()`, `sign()`, `getHashInfo()`, `generateToken()`, `validateRequest()`
- Abstract model methods (required by `fs_model`): `delete()`, `exists()`, `save()`, `test()`
- Test methods: `camelCase` with descriptive names, often starting with `test` (e.g., `testNoHtmlEscapesAngleBrackets`, `testSetAndGetItem`)

**Variables:**
- CamelCase for properties and local variables
- Prefix `$this->` for instance members
- Legacy models define public properties matching DB columns (e.g., `$codcliente`, `$nombre`, `$cifnif`)
- Modern code uses typed properties: `private static ?CacheManager $instance = null`, `private PasswordHasherInterface $hasher`
- Array variable naming: plural for collections (`$adapters`, `$items`, `$constraints`)

**Constants:**
- Global framework constants: `UPPER_SNAKE_CASE` with `FS_` prefix (e.g., `FS_FOLDER`, `FS_DB_TYPE`, `FS_SECRET_KEY`, `FS_ITEM_LIMIT`)
- Class constants: PascalCase or UPPER_SNAKE_CASE (e.g., `CacheManager::DEFAULT_TTL`, `CsrfManager::FIELD_NAME`, `fs_ip_filter::MAX_ATTEMPTS`)
- Private class constants: self-explanatory names (e.g., `EncryptionService::VERSION = 'v1'`)

**Types:**
- Typed properties widely used in `src/`: `private static ?CsrfTokenManager $manager = null`, `protected array $validationErrors = []`
- Return types declared: `: void`, `: string`, `: bool`, `: array`, `: self`, `: ?string`
- Union types: `string|int`, `array|object`
- Nullable types: `?string`, `?float`, `?int`
- Constructor property promotion used: `public function __construct(private object $legacyUser)`

## File Headers

Standard LGPL v3 header. Two variants observed:

**Legacy files** (`base/`, `controller/`, plugins):
```php
<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com> (lead developer of Facturascript)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
```

**Modern files** (`src/`):
```php
<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * [same LGPL text follows]
 */
```

**`declare(strict_types=1)`**: Present in ~9 modern `src/Security/` files and ~14 newer test files (placed immediately after `<?php` opening tag, before the header comment in some files, after in others). Most modern code and legacy code does NOT use `declare(strict_types=1)`.

## Code Style

**Formatting:**
- No `.php-cs-fixer` or `.phpcs` config detected — no automated PHP formatting tool configured
- **PHPStan** level 5 (`phpstan.neon`) with baseline, Symfony extension, Doctrine extension
- **Rector** configured for PHP 8.3 level, code quality, dead code, early return rules (`rector.php`)
- Indentation: 4 spaces (consistent across codebase)
- Brace placement: K&R style — opening brace on same line as declaration
- Array syntax: both `array()` and `[]` short syntax used (modern code prefers `[]`)

**Linting:**
- PHPStan level 5 via `phpstan.neon` with `phpstan-baseline.neon` for known issues
- Dead code detection: `phpstan-dead-code.neon` and `phpstan-dead-code-context.neon`
- Rector rules: `UP_TO_PHP_83`, `CODE_QUALITY`, `DEAD_CODE`, `EARLY_RETURN` (with specific skips for `ReadOnlyPropertyRector`, `FlipTypeControlToUseExclusiveTypeRector`, `DisallowedEmptyRuleFixerRector`)
- No ESLint/Prettier/CS fixer detected for PHP

**PHP 8.2+ features actively used:**
- Attributes (`#[Assert\NotBlank]`, `#[FSRoute]`, `#[ApiResource]`, `#[AllowDynamicProperties]`)
- Typed properties (`private string $algorithm`, `protected bool $csrf_valid`)
- Match expressions (`match ($type) { ... }`)
- Named arguments (`length(min: 1, max: 100)`)
- Union types (`string|int`)
- Nullsafe operator
- Constructor property promotion

## Import Organization

**Modern `src/` files (PSR-4):**
```php
namespace FSFramework\Security;

use Throwable;
use FSFramework\Core\Kernel;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
...
```

Imports grouped: PHP extensions first, then framework classes, then Symfony components. No `require_once` in modern code — relies on Composer autoloading.

**Legacy `base/` files:**
```php
require_once 'base/fs_core_log.php';
require_once 'base/fs_cache.php';
require_once 'base/fs_db2.php';
require_once 'base/fs_functions.php';
```
Uses `require_once` with relative paths; no namespace; no `use` statements.

**Test files (mixed):**
```php
namespace Tests\Base;

use PHPUnit\Framework\TestCase;

// Legacy classes loaded via require_once
require_once FS_FOLDER . '/base/fs_model.php';
// Modern classes imported via use
use FSFramework\Security\CsrfManager;
```

**Path Aliases:**
- `FS_FOLDER` constant points to project root
- No Composer path aliases configured

## Error Handling

**Legacy pattern (controllers/models):**
- `$this->new_error_msg($message)` — adds error to `fs_core_log`
- `$this->new_message($message)` — success/info message
- `$this->new_advice($message)` — warning/tip
- Errors accessed via `$this->get_errors()`, messages via `$this->get_messages()`
- Model `test()` method returns `bool` and calls `$this->new_error_msg()` on failure

**Modern pattern (src/):**
- Throw exceptions: `new \InvalidArgumentException('message')`, `new RuntimeException('message')`
- Custom exception hierarchy in `src/Api/Exception/`:
  - `ApiException` (base, with HTTP status code and JSON serialization)
  - `ConflictException`, `ForbiddenException`, `NotFoundException`, `UnauthorizedException`, `ValidationException`
- Static error handling: `CsrfManager::isValid()` returns bool, `PasswordHasherService::verify()` returns bool
- Service methods return arrays with `success` key for API responses: `['success' => false, 'error' => 'message']`

**Validation:**
- Symfony Validator via `ValidatorTrait` with PHP 8 attributes
- `ConstraintBuilder` for fluent dynamic validation
- Legacy `test()` method for custom validation logic

## Logging

**Framework:** Legacy `fs_core_log` class (in `base/fs_core_log.php`) — singleton storing messages, errors, advices, and SQL history
- Accessed via `$this->log` in controllers/models
- Channels: `messages`, `errors`, `advices`, `sql_history`
- Clean methods: `clean_messages()`, `clean_errors()`, `clean_advices()`, `clean_sql_history()`, `clear()`
- Export: `toArray()`, `toJson()`, `getStats()`
- Controller name and user nick tracking

**Monolog:** Suggested in `composer.json` (`monolog/monolog: ^3.0`) but not installed as direct dependency

## Comments

**When to Comment:**
- PHPDoc on classes: description, `@author` tag, usage examples in docblock
- PHPDoc on key methods: `@param`, `@return`, `@var` for typed properties when helpful
- Section separators in test files: `// ====...====` comment blocks between test groups
- Inline comments: Spanish and English mixed, explaining business logic

**JSDoc/TSDoc:**
- Not applicable (PHP codebase, no JavaScript type checking)
- PHPDoc used for type hints in legacy code (where types can't be declared in PHP 5.x-compatible style)

**Example class-level docblock:**
```php
/**
 * Gestor unificado de caché para FSFramework.
 * 
 * Características:
 * - Usa Symfony Cache con adaptadores múltiples
 * - TTL corto por defecto (180s) ideal para sistemas de administración
 * - Soporte legacy (fs_cache, RainTPL)
 * - Limpieza de todas las cachés: aplicación, Twig, templates legacy
 * 
 * Uso:
 *   $cache = CacheManager::getInstance();
 *   $value = $cache->get('my_key', function() {
 *       return $this->expensiveCalculation();
 *   });
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
```

## Function Design

**Size:** Methods tend to be focused and small (<40 lines), with helper private methods for decomposition. Example: `login.php` splits `private_core()` into `process_login_logic()` → `handleCredentialLogin()`, `handleAutoLogin()`, `showInitialSetupMessageIfPending()`.

**Parameters:** 
- Named arguments used in modern code for clarity (e.g., `length(min: 1, max: 100)`)
- Nullable parameters with defaults: `public function __construct(?string $algorithm = null, ?int $cost = null)`
- Array destructuring for configuration: `['nombre' => FormHelper::TEXT, 'email' => FormHelper::EMAIL]`

**Return Values:**
- Methods returning success/failure: `bool`
- Service methods: return typed objects, arrays, or nullables
- API service methods: return arrays with `['success' => bool, ...]`
- Legacy model methods: `save()`, `delete()`, `exists()`, `test()` all return `bool`

## Module Design

**Exports:**
- Legacy files: no namespace, global class definition
- Modern files: single class per file, PSR-4 namespace matching directory structure
- Traits: `ValidatorTrait` in `src/Traits/`, used via `use ValidatorTrait;` in model classes

**Barrel Files:** Not used. Each class is in its own file.

**Singleton pattern** used extensively in modern code:
- `CacheManager::getInstance()` / `CacheManager::reset()`
- `FSEventDispatcher::getInstance()` / `FSEventDispatcher::reset()`
- `SessionManager::getInstance()` / `SessionManager::reset()`
- `FSTranslator` — static methods with singleton Translator
- `CsrfManager` — all-static methods with private static `$manager` and `$session`

**Service Locator pattern:**
- `FSFramework\DependencyInjection\Container` provides static access to services
- `Container::get('db')`, `Container::cache()`, `Container::passwordHasher()`
- Used for bridging legacy code with Symfony DI

## Legacy vs Modern Split

| Aspect | Legacy (`base/`, `controller/`, `model/`) | Modern (`src/`) |
|--------|-------------------------------------------|-----------------|
| Autoloading | `require_once` | PSR-4 Composer autoload |
| Namespace | None (global) | `FSFramework\*` |
| Class prefix | `fs_` | None (PascalCase) |
| Method naming | `snake_case` + `camelCase` mix | `camelCase` |
| Error handling | `$this->new_error_msg()` | Exceptions |
| Type declarations | PHPDoc only | PHP 8.2 typed properties |
| strict_types | No | Partial (~9 files) |
| File header | References FacturaScripts 2017 origin | References FSFramework only |

---

*Convention analysis: 2026-05-16*
