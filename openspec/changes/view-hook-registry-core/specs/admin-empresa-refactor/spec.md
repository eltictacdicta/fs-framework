# Admin Empresa Refactor Specification

## Purpose

Define the hook points that replace hardcoded `factura_pdf1` integration in `admin_empresa`, enabling any plugin to inject content into the empresa editing view.

## Requirements

### Requirement: Hook Points for Impresion Section

The `admin_empresa` controller/template SHALL expose named hook points for the impresion section. Hook names MUST be stable, documented, and follow the `{view}_{section}` convention.

#### Scenario: Define impresion hook points

- GIVEN the admin_empresa template has an impresion section
- WHEN the template is rendered
- THEN `{{ render_hook('admin_empresa_impresion_before') }}` is available before the section
- AND `{{ render_hook('admin_empresa_impresion_after') }}` is available after the section

#### Scenario: Plugin injects content into impresion

- GIVEN `factura_pdf` plugin registers hook `'admin_empresa_impresion_after'`
- WHEN admin_empresa is rendered
- THEN the plugin's template appears in the impresion section

### Requirement: Replace Hardcoded Plugin Dependencies

The `admin_empresa` controller MUST NOT contain direct references to `factura_pdf1` or any specific plugin. All plugin-specific content SHALL be injected via view hooks.

#### Scenario: No hardcoded plugin references

- GIVEN the admin_empresa controller source code
- WHEN inspected for string literals
- THEN no references to `factura_pdf1` or specific plugin names exist in the controller logic
- AND all plugin content is rendered via `render_hook()` calls in the template

### Requirement: Template-Only Changes

The admin_empresa refactor SHALL only modify the Twig template, not the controller logic. The controller continues to load empresa data; the template decides what hooks are rendered.

#### Scenario: Controller unchanged

- GIVEN the admin_empresa controller before the refactor
- WHEN the refactor is applied
- THEN the controller's `private_core()` method has no new hook-related logic
- AND only the template gains `render_hook()` calls
