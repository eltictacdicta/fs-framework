# AGENTS.md - FSFramework Development Guide

## Overview
FSFramework is a PHP-based ERP/accounting software fork of FacturaScripts. This guide provides instructions for agentic coding agents working in this repository.

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
- Requires a web server (Apache/Nginx/PHP built-in server)
- Requires MySQL or PostgreSQL database
- Configure database connection in `config.php`
- Access via `index.php` in browser

### No Test Suite
This codebase currently has **no automated tests**. Do not attempt to run test commands. If implementing tests, use PHPUnit and place test files in a `Test/` directory.

## Code Style Guidelines

### PHP Version Compatibility
- Minimum PHP 8.2 (required for Symfony 7.4)
- Uses modern PHP 8 features including attributes and typed properties

### File Header
All PHP files must include the license header:
```php
<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2013-2018 Carlos Garcia Gomez <neorazorx@gmail.com>
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
- **Classes**: PascalCase (e.g., `fs_controller`, `fs_model`, `admin_users`)
- **Methods**: camelCase (e.g., `private_core()`, `new_error_msg()`)
- **Variables**: camelCase (e.g., `$this->table_name`, `$codejercicio`)
- **Constants**: UPPER_SNAKE_CASE (e.g., `FS_DB_NAME`, `FS_COOKIES_EXPIRE`)
- **Table names**: Plural lowercase with underscores (e.g., `fs_users`, `ejercicios`)
- **XML model files**: Match table names (e.g., `model/table/fs_users.xml`)

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
/                    # Root - index.php, config.php, build.sh
/base/               # Core framework classes (fs_model, fs_controller, etc.)
/controller/         # Main application controllers
/model/              # Core models
  /table/           # XML schema definitions
/plugins/            # Plugins (business_data, adminlte, etc.)
  /*/model/         # Plugin-specific models
  /*/controller/    # Plugin-specific controllers
/view/               # HTML templates and assets
  /css/             # Stylesheets
  /js/              # JavaScript files
  /img/             # Images
/extras/             # Third-party libraries (PHPMailer, XLSXWriter)
/raintpl/            # Template engine
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
