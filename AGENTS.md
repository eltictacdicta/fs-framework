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

### Symfony Components Used

| Component | Version | Purpose |
|-----------|---------|---------|
| `symfony/http-foundation` | ^7.4 | Request/Response handling |
| `symfony/routing` | ^7.4 | Modern routing with attributes |
| `symfony/security-csrf` | ^7.4 | CSRF token protection |
| `symfony/event-dispatcher` | ^7.4 | Event system |
| `symfony/validator` | ^7.4 | Model validation |
| `symfony/translation` | ^7.4 | Internationalization |
| `symfony/cache` | ^7.4 | Caching |
| `symfony/console` | ^7.4 | CLI commands |
| `twig/twig` | ^3.0 | Template engine |
