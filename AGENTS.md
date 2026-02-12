# AGENTS.md - FSFramework Development Guide

## Overview
FSFramework is a modernized PHP-based ERP/accounting software fork of FacturaScripts 2017, with Symfony 7.4 integration and PHP 8.2+ features. This guide provides comprehensive instructions for agentic coding agents working in this repository.

## Build Commands

### Install Dependencies
```bash
# Install PHP dependencies
composer install

# Install frontend dependencies and build assets
./build.sh

# Manual frontend build steps (if build.sh fails):
npm install
cp node_modules/bootbox/bootbox.min.js view/js/
cp node_modules/bootstrap/dist/css/bootstrap.min.css view/css/
cp node_modules/bootstrap/dist/fonts/* view/fonts/
cp node_modules/bootstrap/dist/js/bootstrap.min.js view/js/
cp node_modules/font-awesome/css/* view/css/
cp node_modules/font-awesome/fonts/* view/fonts/
cp node_modules/jquery/dist/jquery.min.js view/js/
```

### Running the Application
- Requires PHP 8.2 or higher
- Requires MySQL or PostgreSQL database
- Configure database connection in `config.php`
- Access via `index.php` in browser

### Testing

This codebase uses **PHPUnit 11** with **symfony/phpunit-bridge** for unit testing.

#### Running Tests

```bash
# Run all tests (via ddev)
ddev exec php vendor/bin/phpunit

# Run only base/core tests
ddev exec php vendor/bin/phpunit --testsuite Base

# Run only Symfony component tests
ddev exec php vendor/bin/phpunit --testsuite Components

# Run a specific test file
ddev exec php vendor/bin/phpunit tests/Base/FsModelMethodsTest.php

# Run without ddev (if PHP is available locally)
php vendor/bin/phpunit
```

#### Test Structure

```
tests/
├── bootstrap.php              # Defines minimal framework constants (no DB)
├── Base/                      # Core class tests (base/ directory)
│   ├── FsModelMethodsTest.php # no_html, str2bool, floatcmp, intval
│   ├── FsCoreLogTest.php      # Messages, errors, advices, SQL history, stats
│   ├── FsFunctionsTest.php    # bround, fs_fix_html, fs_is_local_ip
│   ├── FsIpFilterTest.php     # IP ban/whitelist logic
│   └── FsQueryBuilderTest.php # SQL generation (SELECT, WHERE, JOIN, INSERT, UPDATE, DELETE)
├── Security/                  # Security component tests
│   └── PasswordHasherServiceTest.php  # Hash, verify, legacy migration, salt
├── Traits/                    # Trait tests
│   └── ValidatorTraitTest.php # Attribute validation, ConstraintBuilder
└── Cache/                     # Cache component tests
    └── CacheManagerTest.php   # Singleton, set/get/has/delete, callbacks
```

#### Writing New Tests

- **Namespace**: `Tests\` (PSR-4 autoloaded via `autoload-dev` in `composer.json`)
- **Base classes**: Non-autoloaded classes in `base/` must be loaded with `require_once`
- **Abstract `fs_model`**: Use an anonymous subclass with empty constructor to test pure methods without DB:
  ```php
  $model = new class() extends \fs_model {
      public function __construct() {} // Skip DB
      public function delete() { return false; }
      public function exists() { return false; }
      public function save() { return false; }
  };
  ```
- **Query builder**: Pass a mock `fs_db2` to the constructor to avoid real DB connections:
  ```php
  $mockDb = new class {
      public function escape_string(string $str): string {
          return addslashes($str);
      }
  };
  $qb = new \fs_query_builder($mockDb);
  ```
- **Static state** (e.g., `fs_core_log`): Reset via Reflection in `setUp()`:
  ```php
  $ref = new \ReflectionClass('fs_core_log');
  $prop = $ref->getProperty('data_log');
  $prop->setAccessible(true);
  $prop->setValue(null, null);
  ```
- **Singletons** (e.g., `CacheManager`): Call `::reset()` in `setUp()` to get a fresh instance

#### Test Suites

| Suite | Dir | Covers |
|-------|-----|--------|
| **Base** | `tests/Base/` | Core classes in `base/` (fs_model, fs_core_log, fs_functions, fs_ip_filter, fs_query_builder) |
| **Components** | `tests/Security/`, `tests/Traits/`, `tests/Cache/` | Symfony-based components in `src/` |

#### Configuration

- **Config file**: `phpunit.xml` (project root)
- **Bootstrap**: `tests/bootstrap.php` — defines `FS_FOLDER`, `FS_DB_TYPE`, `FS_TMP_NAME` and other constants
- **Deprecation detection**: `SYMFONY_DEPRECATIONS_HELPER=weak` — logs Symfony deprecations without failing tests

## Code Style Guidelines

### PHP Version Compatibility
- Minimum PHP 8.2 (required for Symfony 7.4)
- Uses modern PHP 8 features including attributes and typed properties

### File Header Template
```php
<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
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

### Naming Conventions
- **Classes**: PascalCase matching filename (e.g., `class admin_users extends fs_controller`)
- **Methods**: camelCase (e.g., `private_core()`, `new_error_msg()`)
- **Variables**: camelCase (e.g., `$this->table_name`, `$codejercicio`)
- **Constants**: UPPER_SNAKE_CASE (e.g., `FS_DB_NAME`, `FS_COOKIES_EXPIRE`)
- **Table names**: Plural lowercase with underscores (e.g., `fs_users`, `ejercicios`)
- **XML model files**: Match table names (e.g., `model/table/fs_users.xml`)
- **Namespaces**: PSR-4 autoloading with `FSFramework\` prefix for modern code

### Code Structure

#### Models (`model/` and `plugins/*/model/`)
- Extend `fs_model`
- Define abstract methods: `delete()`, `exists()`, `save()`
- Use XML files in `model/table/` for schema definition
- Use `$this->table_name` for table references
- Example structure:
```php
class ejercicio extends \fs_model
{
    public $codejercicio;
    public $nombre;
    
    public function __construct($data = FALSE)
    {
        parent::__construct('ejercicios');
        // ...
    }
    
    public function test() { /* validation */ }
    public function save() { /* insert/update */ }
}
```

#### Controllers (`controller/`)
- Extend `fs_controller`
- Place in appropriate folder (`admin`, etc.)
- Implement `private_core()` for authenticated logic
- Use `$this->new_error_msg()` for errors, `$this->new_message()` for success
- Example:
```php
class admin_users extends fs_controller
{
    public function __construct()
    {
        parent::__construct(__CLASS__, 'Usuarios', 'admin', TRUE, TRUE);
    }
    
    protected function private_core() { /* ... */ }
}
```

### Error Handling
Use the framework's logging system instead of native PHP exceptions:
- `$this->new_error_msg($message)` - Report errors
- `$this->new_message($message)` - Success/info messages
- `$this->new_advice($message)` - Warnings/tips
- Access errors via `$this->get_errors()`
- Access messages via `$this->get_messages()`

### Database Operations
- Use `$this->db` (fs_db2 instance) for queries
- Use `$this->var2str()` for escaping values in SQL
- Use `$this->table_name` for dynamic table references
- See `base/fs_db2.php` for query methods

### Input Sanitization
Use provided helper functions:
- `fs_filter_input_req($name, $default)` - Sanitize REQUEST variables
- `$this->no_html($text)` - Escape HTML special characters
- `$this->var2str()` - Convert values to SQL-safe strings

### Directory Structure

```
/                              # Root - index.php, config.php, build.sh
/base/                         # Core framework classes
  fs_controller.php           # Main controller base class
  fs_model.php                # Base model class
  fs_db2.php                  # Database abstraction layer
  fs_cache.php                # Legacy cache (Memcache)
  fs_app.php                  # Base application class
  fs_login.php                # Authentication utilities
  fs_functions.php            # Global helper functions
/controller/                   # Main application controllers
  admin_*.php                 # Admin controllers
  login.php                   # Login controller
/model/                        # Core models
  /table/                     # XML schema definitions
/plugins/                      # Plugins (business_data, adminlte, etc.)
  /*/controller/              # Plugin controllers
  /*/model/                   # Plugin models
  /*/view/                    # Plugin views
  /*/translations/            # Plugin translations (YAML format)
/src/                          # Modern Symfony-based code (PSR-4)
  /Attribute/                 # PHP 8 Attributes (FSRoute, etc.)
  /Cache/                     # CacheManager (Symfony Cache)
  /Controller/                # Modern controllers (BaseController)
  /Core/                      # Kernel, Router, ThemeManager
  /DependencyInjection/       # Service Container
  /Event/                     # Event dispatcher system
  /Form/                      # FormHelper (Symfony Forms)
  /Security/                  # CsrfManager, UserAdapter, PasswordHasher
  /Traits/                    # ValidatorTrait, ResponseTrait
  /Translation/               # FSTranslator, FS2025JsonLoader
  /Twig/                      # Twig extensions
  /Api/                       # REST API system
/themes/                       # Theme templates (Twig)
  /AdminLTE/                  # AdminLTE theme (default)
    /view/                    # Template files (.html.twig)
      /master/                # Base templates
      /Macro/                 # Reusable macros
      /block/                 # Block templates
      /tab/                   # Tab templates
    /css/                     # Theme stylesheets
    /js/                      # Theme JavaScript
    /img/                     # Theme images
    /translations/            # Theme translations
/view/                         # Static assets (shared)
  /css/                       # Stylesheets (bootstrap, font-awesome)
  /js/                        # JavaScript files (jquery, bootstrap)
  /fonts/                     # Font files
/translations/                 # Core translations (YAML format)
/tests/                        # Unit tests (PHPUnit)
  /Base/                       # Tests for core classes (base/)
  /Security/                   # PasswordHasherService tests
  /Traits/                     # ValidatorTrait tests
  /Cache/                      # CacheManager tests
  bootstrap.php                # Test bootstrap (constants, no DB)
/vendor/                       # Composer dependencies
```

### Imports and Includes
- Use `require_once` for core dependencies
- Group includes at top of files
- Example:
```php
require_once 'base/fs_core_log.php';
require_once 'base/fs_cache.php';
require_once 'base/fs_db2.php';
```

### Database Schema
- Define tables via XML files in `model/table/` or plugin `model/table/`
- XML format:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<tabla>
    <columna>
        <nombre>codejercicio</nombre>
        <tipo>character varying(4)</tipo>
        <nulo>NO</nulo>
    </columna>
</tabla>
```

### Important Notes
- This is a **fork** of FacturaScripts with some components removed
- Not 100% compatible with base FacturaScripts
- Plugins in `/plugins/*/` (except adminlte, business_data) are gitignored
- Never commit `config.php`, `package-lock.json`, or `node_modules/`
- The framework uses `$GLOBALS['plugins']` for plugin discovery

### Common Patterns
1. Model instantiation: `new model_name()` uses optional `$data` array
2. Controller patterns: Use `filter_input()` for form data
3. URL generation: Use `$this->url()` or direct `index.php?page=...`
4. Default items: Use `$this->default_items` for series, warehouses, etc.

### Internationalization (i18n)

FSFramework includes a translation system based on Symfony Translation Component.

#### Using Translations in Templates (Twig)
```twig
{# As function #}
{{ trans('login-text') }}
{{ trans('hello', {'%name%': 'Juan'}) }}

{# As filter #}
{{ 'save'|trans }}
```

#### Using Translations in PHP
```php
use FSFramework\Translation\FSTranslator;

// Simple translation
echo FSTranslator::trans('login-text');

// With parameters
echo FSTranslator::trans('hello', ['%name%' => 'Juan']);

// Change locale
FSTranslator::setLocale('en_EN');
```

#### Creating Translations for Plugins

**New format (recommended):** `plugins/MyPlugin/translations/messages.{locale}.yaml`
```yaml
# plugins/MyPlugin/translations/messages.es.yaml
my-plugin-title: "Mi Plugin"
my-button: "Ejecutar"
```

**FS2025 format (compatibility):** `plugins/MyPlugin/Translation/{locale}.json`
```json
{
    "my-plugin-title": "Mi Plugin",
    "my-button": "Ejecutar"
}
```

See `docs/TRANSLATION.md` for complete documentation.

### Security: CSRF Protection

FSFramework includes CSRF (Cross-Site Request Forgery) protection using Symfony Security CSRF.

#### Using CSRF in Templates

```twig
{# Add to any form with method="post" #}
<form method="post" action="{{ fsc.url() }}">
    {{ csrf_field() }}
    <input type="text" name="campo" />
    <button type="submit">Guardar</button>
</form>

{# For AJAX requests, include meta tag in head #}
{{ csrf_meta() }}
```

#### Configuration

Add to `config.php`:
```php
// CSRF mode: false = soft (warnings only), true = strict (blocks invalid requests)
define('FS_CSRF_STRICT', false);
```

#### Checking CSRF in Controllers

```php
// CSRF is automatically validated in pre_private_core()
// Check if validation passed:
if (!$this->isCsrfValid()) {
    // Log for auditing during transition period
    error_log("Form submitted without valid CSRF token");
}
```

### Event System

FSFramework includes an event dispatcher compatible with legacy extensions.

#### Dispatching Events

```php
use FSFramework\Event\FSEventDispatcher;
use FSFramework\Event\ModelEvent;

// Get dispatcher instance
$dispatcher = FSEventDispatcher::getInstance();

// Dispatch model events
$event = $dispatcher->dispatchModelEvent('before_save', $myModel);
if ($event->isCancelled()) {
    return false;
}
```

#### Listening to Events

```php
use FSFramework\Event\FSEventDispatcher;
use FSFramework\Event\ModelEvent;

$dispatcher = FSEventDispatcher::getInstance();

$dispatcher->addListener(ModelEvent::BEFORE_SAVE, function(ModelEvent $event) {
    $model = $event->getModel();
    // Validate or modify before save
    if (!$model->isValid()) {
        $event->cancel('Invalid data');
    }
});
```

#### Available Events

- `controller.before_action`: Before private_core() executes
- `controller.after_action`: After private_core() completes
- `model.before_save`: Before a model is saved
- `model.after_save`: After a model is saved successfully
- `model.before_delete`: Before a model is deleted
- `model.after_delete`: After a model is deleted successfully

### Model Validation (Symfony Validator)

FSFramework supports Symfony Validator constraints via the `ValidatorTrait`.

#### Using Validation in Models

```php
use FSFramework\Traits\ValidatorTrait;
use Symfony\Component\Validator\Constraints as Assert;

class MiModelo extends fs_model {
    use ValidatorTrait;
    
    #[Assert\NotBlank(message: 'El código es obligatorio')]
    #[Assert\Length(max: 10, maxMessage: 'Máximo 10 caracteres')]
    public $codigo;
    
    #[Assert\Email(message: 'Email inválido')]
    public $email;
    
    #[Assert\PositiveOrZero]
    public $cantidad;
    
    public function test() {
        // Use Symfony validation
        if (!$this->validate()) {
            return false;
        }
        // Additional legacy validations if needed
        return parent::test();
    }
}
```

#### Dynamic Validation (without attributes)

```php
// Validate a single value
$isValid = $this->validateValue($email, [
    new Assert\NotBlank(),
    new Assert\Email()
]);

// Using the fluent builder
$constraints = self::constraints()
    ->notBlank()
    ->length(min: 1, max: 100)
    ->get();

$isValid = $this->validateValue($value, $constraints);
```

### Dependency Injection Container

FSFramework includes a Service Container compatible with legacy code.

#### Using the Container (Service Locator pattern)

```php
use FSFramework\DependencyInjection\Container;

// Get services
$db = Container::get('db');
$request = Container::get('request');
$events = Container::get('event_dispatcher');

// Shortcuts for common services
$db = Container::db();
$request = Container::request();
$hasher = Container::passwordHasher();

// Get any class (auto-wired)
$empresa = Container::get(Empresa::class);
```

#### Registering Custom Services

```php
// In a plugin's config/services.php
return function(\Symfony\Component\DependencyInjection\ContainerBuilder $container) {
    $container->register('my_service', MyService::class)
        ->setPublic(true)
        ->setAutowired(true);
};
```

### Password Hashing (Secure)

FSFramework provides secure password hashing with automatic legacy migration.

```php
use FSFramework\Security\PasswordHasherService;

$hasher = new PasswordHasherService();

// Hash a new password (uses bcrypt by default)
$hash = $hasher->hash('my_password');

// Verify password
if ($hasher->verify($hash, 'my_password')) {
    // Password correct
}

// Verify with automatic migration from SHA1/MD5 legacy
$storedHash = $user->password;
if ($hasher->verifyAndMigrate($storedHash, $plainPassword, $legacySalt, function($newHash) use ($user) {
    $user->password = $newHash;
    $user->save();
})) {
    // Password correct, migrated to bcrypt if it was legacy
}
```

### User Security Adapter

Wrap `fs_user` for Symfony Security compatibility:

```php
use FSFramework\Security\UserAdapter;

// Create adapter from existing fs_user
$adapter = new UserAdapter($fsUser);

// Or load by nick
$adapter = UserAdapter::fromNick('admin');

// Use Symfony Security methods
$roles = $adapter->getRoles();           // ['ROLE_USER', 'ROLE_ADMIN', ...]
$identifier = $adapter->getUserIdentifier(); // 'admin'

// Check permissions
if ($adapter->hasAccessTo('admin_users')) { ... }
if ($adapter->canDeleteIn('ventas')) { ... }
```

### Form Helper

Create Symfony Forms with CSRF protection:

```php
use FSFramework\Form\FormHelper;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

// Create form
$form = FormHelper::create()
    ->add('nombre', TextType::class, ['label' => 'Nombre'])
    ->add('email', EmailType::class)
    ->add('guardar', SubmitType::class, ['label' => 'Guardar'])
    ->getForm();

// Handle request
if (FormHelper::handleRequest($form)) {
    $data = $form->getData();
    // Process form...
}

// Or create from model
$form = FormHelper::createForModel($cliente, [
    'nombre' => FormHelper::TEXT,
    'email' => FormHelper::EMAIL,
    'activo' => FormHelper::CHECKBOX,
]);
```

### Cache Management (Symfony Cache)

FSFramework includes a unified cache system using Symfony Cache, optimized for administrative systems where changes must be reflected quickly.

#### Features

- **Short default TTL (180s)**: Ideal for admin systems where data changes frequently
- **Multiple adapters**: ArrayAdapter (per-request) + FilesystemAdapter + Memcached (if available)
- **Legacy support**: Integrates with existing fs_cache, RainTPL, and Twig cache
- **Unified clearing**: One method to clear all cache types

#### Using CacheManager

```php
use FSFramework\Cache\CacheManager;
use FSFramework\DependencyInjection\Container;

// Get instance via Container
$cache = Container::cache();

// Or directly
$cache = CacheManager::getInstance();

// Get with callback (recommended - auto-generates if missing)
$users = $cache->get('all_users', function() {
    return $this->db->select("SELECT * FROM fs_users");
});

// Get with custom TTL (60 seconds)
$stats = $cache->get('dashboard_stats', fn() => $this->calculateStats(), 60);

// Simple get/set
$cache->set('my_key', $value, CacheManager::SHORT_TTL);
$value = $cache->getItem('my_key', $default);

// Check existence
if ($cache->has('my_key')) { ... }

// Delete
$cache->delete('my_key');
$cache->deleteMultiple(['key1', 'key2']);
```

#### TTL Constants

| Constant | Value | Use Case |
|----------|-------|----------|
| `SHORT_TTL` | 30s | Very dynamic data (counters, status) |
| `DEFAULT_TTL` | 180s | Standard cache for admin systems |
| `MEDIUM_TTL` | 600s | Semi-static data (config, menus) |
| `LONG_TTL` | 3600s | Rarely changing data |

#### Clearing Cache

```php
// Clear application cache only
$cache->clear();

// Clear Twig template cache only
$cache->clearTwigCache();

// Clear legacy RainTPL templates
$cache->clearLegacyTemplateCache();

// Clear ALL caches (Symfony + Twig + RainTPL + php_file_cache + Memcache)
$results = $cache->clearAll();
// Returns: ['symfony' => true, 'twig' => true, 'legacy_templates' => true, ...]

// Check if all cleared successfully
if ($cache->clearAllSuccessful()) {
    echo "All caches cleared!";
}
```

#### Cache Information

```php
$info = $cache->getInfo();
// Returns: ['type' => 'Symfony Cache', 'adapters' => [...], 'memcached_available' => bool, ...]

$version = $cache->version();
// Returns: 'Symfony Cache + Memcached' or 'Symfony Cache (Filesystem)'
```

#### Admin Interface

The "Limpiar caché" button in admin_info (`index.php?page=admin_info&clean_cache=TRUE`) now clears all cache types and shows detailed results.

### Symfony Components Used

| Component | Version | Purpose |
|-----------|---------|---------|
| `symfony/http-foundation` | ^7.4 | Request/Response handling |
| `symfony/routing` | ^7.4 | Modern routing with attributes |
| `symfony/security-csrf` | ^7.4 | CSRF token protection |
| `symfony/security-core` | ^7.4 | User authentication, password hashing |
| `symfony/event-dispatcher` | ^7.4 | Event system |
| `symfony/validator` | ^7.4 | Model validation |
| `symfony/dependency-injection` | ^7.4 | Service container |
| `symfony/form` | ^7.4 | Form handling |
| `symfony/translation` | ^7.4 | Internationalization |
| `symfony/cache` | ^7.4 | Caching |
| `symfony/console` | ^7.4 | CLI commands |
| `twig/twig` | ^3.0 | Template engine |

## PHP 8.2+ Features

FSFramework leverages modern PHP 8.2+ features throughout the codebase:

### Attributes (PHP 8)
```php
#[FSRoute('/api/users', methods: ['GET'])]
class api_users extends fs_controller { }

#[ApiResource(operations: [Operation::LIST, Operation::GET])]
class cliente extends fs_model {
    #[Assert\NotBlank(message: 'Name is required')]
    public $nombre;
}
```

### Typed Properties
```php
class MyController extends fs_controller
{
    protected \Symfony\Component\HttpFoundation\Request $request;
    protected bool $is_ajax = false;
    protected array $items = [];
    protected ?Empresa $empresa = null;
}
```

### Union Types
```php
public function processData(string|int $id): array|object
{
    // Handle both string and int IDs
}
```

### Match Expressions
```php
$inputType = match ($type) {
    'email' => 'email',
    'password' => 'password',
    'number', 'integer', 'money' => 'number',
    'textarea' => 'textarea',
    default => 'text',
};
```

### Named Arguments
```php
$constraints = self::constraints()
    ->length(min: 1, max: 100)
    ->email(message: 'Invalid email format')
    ->get();
```

### Constructor Property Promotion
```php
class UserAdapter implements UserInterface
{
    public function __construct(
        private object $legacyUser
    ) {}
}
```

## REST API System

Located in `src/Api/` directory:

### API Attributes

```php
use FSFramework\Api\Attribute\ApiResource;
use FSFramework\Api\Attribute\ApiField;
use FSFramework\Api\Attribute\ApiHidden;
use FSFramework\Api\Attribute\Operation;

#[ApiResource(
    operations: [Operation::LIST, Operation::GET, Operation::CREATE],
    version: 'v1',
    searchable: ['nombre', 'email'],
    sortable: ['nombre', 'fechaalta'],
    filterable: ['activo'],
    perPage: 50,
    requiresAuth: true
)]
class cliente extends fs_model {
    #[ApiField(readable: true, writable: false)]
    public $id;
    
    #[ApiField(readable: true, writable: true)]
    public $nombre;
    
    #[ApiHidden(reason: 'Internal use only')]
    public $internal_data;
}
```

**Available Operations:**
- `Operation::LIST` - GET collection
- `Operation::GET` - GET single item
- `Operation::CREATE` - POST
- `Operation::UPDATE` - PUT/PATCH
- `Operation::DELETE` - DELETE

### API Registration via Container

```php
use FSFramework\DependencyInjection\Container;

// Register API authentication implementation
Container::registerApiAuth(
    MyApiAuth::class,           // Implements ApiAuthInterface
    MyAllowedUser::class,       // Implements AllowedUserInterface
    MyApiLogger::class          // Optional: Implements ApiLogInterface
);
```

## Response Helpers

Via `ResponseTrait` in controllers (`fs_controller` already includes this trait):

```php
// JSON response
return $this->json(['status' => 'success', 'data' => $users]);

// Redirect
return $this->redirect('index.php?page=admin_home');
return $this->redirectToPage('admin_home', ['param' => 'value']);

// HTML response
return $this->html('<h1>Hello</h1>');

// Error responses
return $this->notFound('User not found');
return $this->forbidden('Access denied');
return $this->badRequest('Invalid parameters');

// File download
return $this->file($content, 'report.pdf', 'application/pdf');

// Empty response
return $this->noContent();
```

## Template System (Twig)

### Directory Structure
```
themes/
└── AdminLTE/
    └── view/
        ├── master/
        │   ├── Base.html.twig           # Base template with blocks
        │   ├── MenuTemplate.html.twig   # Layout with AdminLTE menu
        │   └── MenuBghTemplate.html.twig # Alternative layout
        ├── login/
        │   └── default.html.twig        # Login page
        ├── Macro/
        │   ├── Menu.html.twig           # Menu macros
        │   └── Utils.html.twig          # Utility macros
        ├── block/                       # Block templates
        ├── tab/                         # Tab templates
        ├── header.html.twig             # Header template
        └── footer.html.twig             # Footer template
```

**Note:** Themes are located in `/themes/` directory. The default theme is **AdminLTE**.
Templates use Twig syntax and are organized within each theme's `view/` folder.

### Available Variables in Templates
- `fsc` - Controller instance
- `app` - Application info
- `user` - Current user
- `menu` - Menu items
- `path` - Current path

### Twig Functions
- `trans(key, params)` - Translate text
- `csrf_field()` - CSRF token field
- `csrf_meta()` - CSRF meta tag
- `path(route, params)` - Generate URL
- `getLocale()` - Get current locale
- `getAvailableLanguages()` - List available languages

## Modern Controller Patterns

### Controller with Request Access
```php
class mi_modulo extends fs_controller
{
    public $items = [];
    
    public function __construct()
    {
        parent::__construct(__CLASS__, 'Mi Módulo', 'ventas', FALSE, TRUE);
    }
    
    protected function private_core()
    {
        // Access Symfony Request
        $request = $this->getRequest();
        
        // Handle POST
        if ($request->request->has('save')) {
            $this->save_data();
        }
        
        // Load data with GET parameter
        $page = $request->query->getInt('page', 1);
        
        // Check AJAX
        if ($this->isAjax()) {
            return $this->json(['data' => $this->items]);
        }
        
        $model = new mi_modelo();
        $this->items = $model->all($page);
    }
    
    private function save_data()
    {
        // Validate CSRF (automatic)
        if (!$this->isCsrfValid()) {
            $this->new_error_msg('Token inválido');
            return;
        }
        
        $data = $this->getRequest()->request->all('data');
        // Process...
    }
}
```

### Model with Full Validation
```php
class mi_modelo extends \fs_model
{
    use \FSFramework\Traits\ValidatorTrait;
    
    #[Assert\NotBlank(message: 'El código es obligatorio')]
    #[Assert\Length(max: 10)]
    public $codigo;
    
    #[Assert\Email(message: 'Email inválido')]
    public $email;
    
    #[Assert\PositiveOrZero]
    public $cantidad;
    
    public function __construct($data = FALSE)
    {
        parent::__construct('mi_tabla');
        if ($data) {
            $this->codigo = $data['codigo'] ?? null;
            $this->email = $data['email'] ?? null;
            $this->cantidad = $data['cantidad'] ?? 0;
        }
    }
    
    public function test(): bool
    {
        // Validate with Symfony
        if (!$this->validate()) {
            return false;
        }
        // Additional custom validation
        if ($this->codigo === 'ADMIN' && !$this->isValidAdminCode()) {
            $this->new_error_msg('Invalid admin code');
            return false;
        }
        return true;
    }
    
    public function save(): bool
    {
        if (!$this->test()) {
            return false;
        }
        
        // Dispatch events
        $dispatcher = \FSFramework\Event\FSEventDispatcher::getInstance();
        $event = $dispatcher->dispatchModelEvent('before_save', $this);
        if ($event->isCancelled()) {
            $this->new_error_msg($event->getCancellationReason());
            return false;
        }
        
        // Save logic...
        $result = parent::save();
        
        if ($result) {
            $dispatcher->dispatchModelEvent('after_save', $this);
        }
        
        return $result;
    }
    
    public function delete(): bool { /* ... */ }
    public function exists(): bool { /* ... */ }
}
```

## Important Notes

1. **This is a fork** of FacturaScripts with significant modifications and modern architecture
2. **Not 100% compatible** with base FacturaScripts plugins
3. **Plugins** in `/plugins/*/` (except adminlte, business_data) are gitignored
4. **Never commit**: `config.php`, `package-lock.json`, `node_modules/`
5. **PHP 8.2+ features**: Attributes, typed properties, union types, match expressions, named arguments
6. **Twig is primary** template engine located in `/themes/AdminLTE/view/`, RainTPL supported for compatibility
7. **Lazy loading** for models available via `FS_LAZY_MODELS` constant
8. **Symfony 7.4** components are fully integrated and available
9. **API system** available via attributes - no manual route registration needed
10. **CSRF protection** is automatic but can be checked via `isCsrfValid()`

## Routing with PHP 8 Attributes

Located in `src/Attribute/FSRoute.php`:

```php
use FSFramework\Attribute\FSRoute;

#[FSRoute('/api/users', methods: ['GET'], name: 'api_users_list')]
class api_users extends fs_controller
{
    protected function private_core()
    {
        // Handle GET /api/users
        // Access route parameters via $this->request
    }
}
```

**FSRoute Parameters:**
- `path` - URL path pattern
- `methods` - HTTP methods array (GET, POST, PUT, DELETE, etc.)
- `name` - Route name for URL generation
- `requirements` - Parameter constraints (regex patterns)
- `defaults` - Default parameter values

## Database Operations

### Legacy Database Layer (fs_db2)

```php
// In controllers/models:
$result = $this->db->select("SELECT * FROM users WHERE active = " . $this->var2str(TRUE));

// Prepared statements via fs_prepared_db
$stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
$result = $this->db->execute($stmt, [$userId]);

// Get via Container
$db = \FSFramework\DependencyInjection\Container::db();
```

### Schema Definition (XML)

Located in `model/table/*.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<tabla>
    <columna>
        <nombre>id</nombre>
        <tipo>serial</tipo>
        <nulo>NO</nulo>
    </columna>
    <columna>
        <nombre>codejercicio</nombre>
        <tipo>character varying(4)</tipo>
        <nulo>NO</nulo>
    </columna>
    <restriccion>
        <nombre>ejercicios_pkey</nombre>
        <consulta>PRIMARY KEY (id)</consulta>
    </restriccion>
</tabla>
```

## Plugin System

### Plugin Structure
```
plugins/MyPlugin/
├── controller/              # Controllers (legacy)
├── Controller/              # Controllers (FS2025 style)
├── model/                   # Models (legacy)
├── Model/                   # Models (FS2025 style)
├── view/                    # Views (legacy - lowercase)
├── View/                    # Views (FS2025 - PascalCase)
├── translations/            # Translations (YAML format)
├── Translation/             # Translations (FS2025 - JSON format)
├── config/
│   └── services.php        # DI container services
├── facturascripts.ini      # Plugin metadata
└── description             # Plugin description
```

### Plugin Configuration
```ini
; facturascripts.ini
name = MyPlugin
description = My awesome plugin
version = 1.0
min_version = 2017.000
```

### Registering Plugin Services
```php
// plugins/MyPlugin/config/services.php
return function(\Symfony\Component\DependencyInjection\ContainerBuilder $container) {
    $container->register('my_service', MyService::class)
        ->setPublic(true)
        ->setAutowired(true);
};
```

## Documentation References

- [Translation System](docs/TRANSLATION.md) - Complete i18n documentation
- [Theme System](THEME_SYSTEM.md) - Template and theming guide
- Symfony 7.4 Documentation: https://symfony.com/doc/current/
- Twig Documentation: https://twig.symfony.com/doc/

---

**Last Updated**: February 2025  
**Framework Version**: FSFramework with Symfony 7.4  
**PHP Version**: 8.2+
