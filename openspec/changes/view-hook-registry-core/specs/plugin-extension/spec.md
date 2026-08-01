# Plugin Extension Specification

## Purpose

Define how plugins register view hooks during initialization and the conventions for template namespaces.

## Requirements

### Requirement: Plugin Hook Registration in Init.php

Plugins SHALL register hooks in their `Init.php` class, typically in `init(\Twig\Environment $twig)`. Plugins MUST call `ViewHookRegistry::register()` with a hook name and template path. No `require_once` is needed — PSR-4 autoloading resolves the class.

#### Scenario: Plugin registers hooks during init

- GIVEN a plugin's `Init.php` has an `init($twig)` method
- WHEN `init()` calls `ViewHookRegistry::register('hook_name', '@plugin/template.html.twig')`
- THEN the hook is available to any controller that renders it

#### Scenario: Multiple plugins register same hook

- GIVEN plugin A registers `'my_hook'` with `@a/template.html.twig`
- AND plugin B registers `'my_hook'` with `@b/template.html.twig'
- WHEN a controller renders `'my_hook'`
- THEN both templates are rendered and concatenated

### Requirement: Template Namespace Convention

Plugin hook templates SHOULD use the `@plugin_name/` Twig namespace convention (e.g., `@clientes_catalogo/Hooks/cliente_form_catalogo.html.twig`). This convention enables automatic template resolution within the plugin's `view/` directory.

#### Scenario: Template resolved via plugin namespace

- GIVEN plugin `clientes_catalogo` has template `view/Hooks/cliente_form_catalogo.html.twig`
- WHEN `ViewHookRegistry::register('hook', '@clientes_catalogo/Hooks/cliente_form_catalogo.html.twig')` is called
- THEN Twig resolves the template path relative to the plugin's view directory

### Requirement: No Core Dependency for Hook Registration

Plugins registering hooks MUST NOT require core files or use `require_once` for `ViewHookRegistry`. The class is autoloaded from `src/View/` and available to all plugins after framework bootstrap.

#### Scenario: Plugin without require_once

- GIVEN a plugin that has previously used `require_once __DIR__ . '/../clientes_core/src/ViewHookRegistry.php'`
- WHEN the `require_once` line is removed
- AND the plugin uses `use FSFramework\View\ViewHookRegistry;`
- THEN the class resolves via Composer autoloading
- AND no changes to `composer.json` are needed
