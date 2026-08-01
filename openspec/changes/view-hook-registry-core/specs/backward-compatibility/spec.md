# Backward Compatibility Specification

## Purpose

Ensure existing consumers of `ViewHookRegistry` and `clientes_render_hook` continue to work during and after the migration to core.

## Requirements

### Requirement: Deprecated Stub at Old Path

A deprecated stub file SHALL remain at `plugins/clientes_core/src/ViewHookRegistry.php`. The stub MUST extend or delegate to the core `FSFramework\View\ViewHookRegistry` class. The stub MUST emit a deprecation notice when loaded.

#### Scenario: Third-party plugin using require_once

- GIVEN a third-party plugin does `require_once __DIR__ . '/../clientes_core/src/ViewHookRegistry.php'`
- WHEN the stub is loaded
- THEN a PHP `E_USER_DEPRECATED` notice is emitted
- AND the stub delegates to the core class
- AND all static methods remain functional

#### Scenario: Stub not autoloaded

- GIVEN a plugin uses `use FSFramework\Plugins\clientes_core\ViewHookRegistry;`
- WHEN the class is resolved
- THEN the stub file is loaded via the old namespace
- AND the core class is used internally

### Requirement: clientes_render_hook Alias Preserved

The `clientes_render_hook` Twig function MUST continue to work after the migration. It SHALL delegate to `ViewHookRegistry::render()` with identical behavior. The alias MUST emit a deprecation notice.

#### Scenario: Existing template using clientes_render_hook

- GIVEN a Twig template calls `{{ clientes_render_hook('hook_name') }}`
- WHEN the template is rendered
- THEN the hook is rendered correctly
- AND a deprecation notice is logged

### Requirement: Migration Path Documentation

The change documentation SHALL include a migration guide for plugin developers: (1) replace `require_once` with PSR-4 `use`, (2) replace `clientes_render_hook` with `render_hook`, (3) update namespace from `FSFramework\Plugins\clientes_core` to `FSFramework\View`.

#### Scenario: Developer reads migration guide

- GIVEN a plugin developer reviewing the changelog
- WHEN they follow the migration steps
- THEN their plugin uses the core namespace without deprecation notices
- AND no functional changes are required
