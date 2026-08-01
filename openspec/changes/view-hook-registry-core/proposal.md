# Proposal: Promote ViewHookRegistry to Core

## Intent

ViewHookRegistry is a well-designed static registry that lets plugins inject Twig content into controller views — but it's locked inside `plugins/clientes_core`, usable only by `clientes_catalogo`. Meanwhile, `admin_empresa` has hardcoded `factura_pdf1` integration because no core hook mechanism exists. Promoting this to core eliminates hardcoded plugin dependencies and gives every plugin a standard way to extend any controller's view.

## Scope

### In Scope
- Move `ViewHookRegistry` from `plugins/clientes_core/src/` to `src/View/` (`FSFramework\View` namespace)
- Register `render_hook` Twig function in `src/Core/Html.php::registerUtilityFunctions()`
- Add deprecated `clientes_render_hook` alias for backward compatibility
- Resolve the merge conflict in `Html.php::getPluginIncludeViews` docblock (lines 520-566)
- Update `clientes_core/Init.php` and `clientes_catalogo/Init.php` to use core namespace (remove `require_once`)
- Update `ventas_cliente.html.twig` to call `render_hook` instead of `clientes_render_hook`
- Add unit tests for `ViewHookRegistry` (register, has, render, error handling)

### Out of Scope
- Refactoring `admin_empresa` to use hooks (future consumer — separate change)
- Adding `has_hook()` Twig function (future enhancement)
- Interface/type-safe hook declarations (over-engineered for current needs)
- Unifying with `Extension/View` pattern (dead code, different design philosophy)

## Capabilities

### New Capabilities
- `view-hook-registry`: Core view hook registry enabling plugins to inject Twig content into any controller's view via named hook points

### Modified Capabilities
None — this creates a new core capability without changing existing spec behavior.

## Approach

Move the existing class to `src/View/ViewHookRegistry.php`, register `render_hook` in Html.php alongside existing utility functions, and maintain a deprecated `clientes_render_hook` alias. PSR-4 autoload replaces `require_once`. The class is already well-designed — the change is a namespace relocation, not a rewrite.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `src/View/ViewHookRegistry.php` | New | Core namespace `FSFramework\View\ViewHookRegistry` |
| `src/Core/Html.php` | Modified | Register `render_hook` + deprecated alias; resolve merge conflict |
| `plugins/clientes_core/src/ViewHookRegistry.php` | Removed | Replaced by core version |
| `plugins/clientes_core/Init.php` | Modified | Remove require_once, use core namespace |
| `plugins/clientes_catalogo/Init.php` | Modified | Remove require_once, use core namespace |
| `plugins/clientes_core/view/ventas_cliente.html.twig` | Modified | `render_hook` instead of `clientes_render_hook` |
| `tests/View/ViewHookRegistryTest.php` | New | Unit tests |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Third-party plugins doing `require_once` of old path break | Medium | Keep deprecated stub at old path during transition |
| Merge conflict in Html.php blocks progress | Low | Resolve conflict first as a prerequisite step |
| Static state leaks in long-running processes | Low | Not a blocker for CLI; document the limitation |
| No existing test coverage | Certain | This change adds comprehensive unit tests |

## Rollback Plan

Revert all file changes. The deprecated `clientes_render_hook` alias can coexist with the old path. No database changes, no schema changes — pure PHP class relocation with backward-compatible aliases.

## Dependencies

- Resolve Html.php merge conflict (lines 520-566) before or during implementation

## Success Criteria

- [ ] `ViewHookRegistry` lives in `src/View/` under `FSFramework\View` namespace
- [ ] `render_hook` Twig function available globally without plugin dependency
- [ ] `clientes_render_hook` still works as deprecated alias
- [ ] `clientes_core` and `clientes_catalogo` use core version (no require_once)
- [ ] Unit tests cover register, has, render, and error-swallowing behavior
- [ ] No regressions in existing clientes_core views
