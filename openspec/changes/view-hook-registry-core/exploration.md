# Exploration: Promote ViewHookRegistry to Core

## Current State

### ViewHookRegistry ( clientes_core/src/ViewHookRegistry.php)

A simple static class with three methods:

- `register(string $hook, string $template)` — registers a Twig template path for a named hook point
- `has(string $hook)` — checks if any templates are registered for a hook
- `render(Environment $twig, string $hook, array $context = [])` — renders all registered templates for a hook, passing context, swallowing exceptions with error_log

The class is in `FSFramework\Plugins\clientes_core` namespace. It is loaded via `require_once` (not Composer autoload) from `plugins/clientes_core/Init.php`.

### Current Registration Flow

1. `clientes_core/Init.php` requires the file, registers a Twig function `clientes_render_hook` via TwigInitEvent
2. `clientes_catalogo/Init.php` requires the same file via `require_once __DIR__ . '/../clientes_core/src/ViewHookRegistry.php'` and calls `ViewHookRegistry::register()` with two hook points
3. Templates in `clientes_core` call `{{ clientes_render_hook('hook_name', {context})|raw }}`

### Two Hook Points

| Hook Name | Template | Purpose |
|-----------|----------|---------|
| `cliente_form_after_main` | `@clientes_catalogo/Hooks/cliente_form_catalogo.html.twig` | Adds divisa selector to client form |
| `cliente_direccion_form_after_codpais` | `@clientes_catalogo/Hooks/direccion_form_catalogo.html.twig` | Adds country datalist to address form |

### Existing Core Extension Mechanism (Html::getPluginIncludeViews)

There is already an implicit, convention-based mechanism in `src/Core/Html.php`:

- Plugins place templates in `Extension/View/{ParentTemplate}_{position}_{order}.html.twig`
- `getPluginIncludeViews(template, position)` scans all plugin directories, finds matching files, renders them in order
- Registered as Twig function `getIncludeViews` but **never actually called from any Twig template** (only in Html.php's own docs/comments)
- No context passing — templates get an empty `[]` context
- Uses glob-based filesystem scanning at render time

**This pattern is NOT being used** — it's dead code that was built for the Extension/View convention but never adopted by any template.

### admin_empresa Use Case (Hardcoded Plugin Integration)

`plugins/business_data/controller/admin_empresa.php` has hardcoded factura_pdf1 integration:

- `isPdfPluginActive()` checks `$GLOBALS['plugins']` and class existence
- `loadPdfPluginSettings()` instantiates `factura_pdf1\Services\SettingsService` directly
- `savePdfPluginSettings()` saves settings back via the same service
- Template `admin_empresa_impresion.html.twig` has `{% if fsc.pdf_settings is not null %}` conditional

This is the anti-pattern that ViewHookRegistry should replace: hardcoded plugin dependencies in core controllers.

## Affected Areas

| File | Status | Why |
|------|--------|-----|
| `plugins/clientes_core/src/ViewHookRegistry.php` | **move to core** | Becomes `src/View/ViewHookRegistry.php` |
| `plugins/clientes_core/Init.php` | **modify** | Remove require_once, change to use core namespace |
| `plugins/clientes_catalogo/Init.php` | **modify** | Remove require_once of clientes_core file, use core namespace |
| `src/Core/Html.php` | **modify** | Register `render_hook` Twig function in `registerUtilityFunctions()` |
| `plugins/clientes_core/view/ventas_cliente.html.twig` | **modify** | Change `clientes_render_hook` to `render_hook` |
| `plugins/business_data/controller/admin_empresa.php` | **future consumer** | Replace hardcoded factura_pdf1 with hook-based approach |
| `plugins/business_data/view/block/admin_empresa_impresion.html.twig` | **future consumer** | Replace `{% if fsc.pdf_settings %}` with `render_hook` call |
| `src/View/` | **new directory** | Home for core View utilities |
| `tests/View/ViewHookRegistryTest.php` | **new file** | Unit tests for the registry |

## Approaches

### Approach 1: Move ViewHookRegistry to src/View/ + Register Twig Function in Html.php

Move the class to `FSFramework\View\ViewHookRegistry`, register `render_hook` (and alias `clientes_render_hook`) in `Html::registerUtilityFunctions()`.

**Pros:**
- Clean namespace: `FSFramework\View\ViewHookRegistry`
- Twig function available globally without plugin dependency
- Minimal change surface — the class is already well-designed
- Can deprecate `clientes_render_hook` alias

**Cons:**
- Requires updating clientes_core and clientes_catalogo to use new namespace
- The `require_once` pattern in consumers needs to change to Composer autoload

**Effort:** Low

### Approach 2: Unify with Extension/View Pattern

Merge ViewHookRegistry into `Html::getPluginIncludeViews` — make the Extension/View convention support context passing.

**Pros:**
- Single mechanism instead of two
- Convention-over-configuration (no registration needed)

**Cons:**
- Extension/View is implicit and can't pass context to templates
- Fundamentally different design: one is imperative registration, the other is filesystem scanning
- Would require major refactor of both systems
- The Extension/View pattern is unused dead code — not worth unifying with

**Effort:** High

### Approach 3: Move + Add Interface for Type-Safe Hooks

Move to core and add a `ViewHookInterface` that models can implement to declare available hooks.

**Pros:**
- Type safety, discoverability
- Models can declare their hook points

**Cons:**
- Over-engineered for the current use case (2 hooks, 1 consumer)
- Adds complexity without clear benefit
- Hooks are view-layer concerns, not model concerns

**Effort:** Medium

## Recommendation

**Approach 1: Move to src/View/ + Register in Html.php**

This is the right level of intervention. The class is already well-designed — it just needs to live in the right namespace and have its Twig function registered by the core instead of by a plugin.

Key decisions:
1. **Namespace:** `FSFramework\View\ViewHookRegistry`
2. **Twig function name:** `render_hook` (primary), `clientes_render_hook` (deprecated alias)
3. **Autoloading:** PSR-4 via `src/View/` (Composer autoload, no more require_once)
4. **Location in Html.php:** Register in `registerUtilityFunctions()` alongside existing functions

## Backward Compatibility Strategy

1. **Phase 1 (this change):**
   - Move class to `src/View/ViewHookRegistry.php` under `FSFramework\View` namespace
   - Register `render_hook` Twig function in `Html::registerUtilityFunctions()`
   - Add `clientes_render_hook` as a deprecated alias that delegates to `render_hook`
   - Update `clientes_core/Init.php` to use new namespace (remove require_once)
   - Update `clientes_catalogo/Init.php` to use new namespace (remove require_once)
   - Update `ventas_cliente.html.twig` to use `render_hook` instead of `clientes_render_hook`

2. **Phase 2 (future):**
   - Remove `clientes_render_hook` alias after all consumers migrated
   - Consider adding `has_hook()` to Twig for conditional rendering

## Risks

1. **Merge conflict in Html.php:** Lines 520-566 have unresolved `<<<<<<< HEAD` markers in the `getPluginIncludeViews` docblock. Must be resolved before or during this change.
2. **Plugin consumers of `require_once`:** Any third-party plugin that does `require_once __DIR__ . '/../clientes_core/src/ViewHookRegistry.php'` will break. Mitigation: keep a thin deprecated stub at the old path during transition.
3. **Static state:** ViewHookRegistry uses static properties — hooks persist for the entire request lifecycle. This is fine for CLI but could leak between requests in long-running processes. Not a blocker but worth noting.
4. **No tests exist:** The registry has zero test coverage. This change should add tests.

## Ready for Proposal

Yes. The exploration is complete. The approach is clear: move ViewHookRegistry to `src/View/`, register `render_hook` in Html.php, add backward-compatible alias, update consumers, add tests.
