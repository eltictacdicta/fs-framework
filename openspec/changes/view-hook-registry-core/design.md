# Design: Promote ViewHookRegistry to Core

## Technical Approach

Relocate `ViewHookRegistry` from `plugins/clientes_core/src/` to `src/View/` under the `FSFramework\View` namespace. Register `render_hook` as a Twig function in `Html::registerUtilityFunctions()` alongside existing utility functions. Maintain a deprecated `clientes_render_hook` alias and a stub at the old path for backward compatibility. PSR-4 autoloading via `composer.json` (`"FSFramework\\": "src/"`) resolves the new class automatically.

## Architecture Decisions

### Decision 1: Registration Location in Html.php

| Option | Tradeoff | Decision |
|--------|----------|----------|
| `registerUtilityFunctions()` | Consistent with existing pattern (csrf_field, asset, trans all live here). Simple, one method to maintain. | **Chosen** |
| New `registerHookFunctions()` | Separates concerns but adds a new method for one function. Overhead not justified. | Rejected |

**Rationale**: `render_hook` is a utility function like `csrf_field` or `asset`. The existing `registerUtilityFunctions()` is the canonical home for Twig functions that don't warrant their own registration method.

### Decision 2: Deprecated Stub Strategy

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Option A: Thin wrapper at old path | Simplest. No composer changes. Third-party `require_once` still works. | **Chosen** |
| Option B: Composer autoload alias | Clean but requires composer.json modification and `dump-autoload`. | Rejected |
| Option C: `class_alias()` in old file | Works but the stub file still needs to be loaded somehow. Same as A but less explicit. | Rejected |

**Rationale**: The old path `plugins/clientes_core/src/ViewHookRegistry.php` is a `require_once` target for third-party plugins. A thin wrapper that `require_once`s the core file and emits a deprecation notice is the safest transition path.

### Decision 3: admin_empresa Hook Points

| Option | Tradeoff | Decision |
|--------|----------|----------|
| `{view}_{section}_before` / `_after` | Standard pattern. Easy to discover. Clear insertion points. | **Chosen** |
| `{view}_{section}_{plugin}` | Too granular. Requires each plugin to know about others. | Rejected |

**Hook names**: `admin_empresa_impresion_before`, `admin_empresa_impresion_after`. The template passes `fsc` context so plugins can access `pdf_settings` if available. This is a **future consumer** — out of scope for this change but documented here for design completeness.

### Decision 4: Error Handling

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Keep current behavior (error_log) | Simple. Works in production. No API surface change. | **Chosen** |
| Add error collector for dev mode | More debugging info but adds complexity and state management. | Deferred |

**Rationale**: The current `error_log` approach is battle-tested in production. An error collector is a nice-to-have but not required for the initial promotion. Can be added later via a `ViewHookRegistry::getErrors()` static method.

## Data Flow

```
Plugin Init.php                    Core Html.php
       │                                │
       ▼                                ▼
 ViewHookRegistry::register()    registerUtilityFunctions()
       │                                │
       │ stores {hook => [templates]}   │ registers render_hook() Twig function
       │                                │
       └────────────┬───────────────────┘
                    │
                    ▼
            Twig Template
       {{ render_hook('hook_name', {context}) }}
                    │
                    ▼
       ViewHookRegistry::render($twig, 'hook', $context)
                    │
        ┌───────────┼───────────┐
        ▼           ▼           ▼
   $twig->render  $twig->render  $twig->render
   (template A)   (template B)   (template C)
        │           │           │
        └───────────┼───────────┘
                    ▼
            Concatenated HTML
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/View/ViewHookRegistry.php` | **Create** | Core class in `FSFramework\View` namespace. Identical logic to current `plugins/clientes_core/src/ViewHookRegistry.php` but with new namespace. |
| `src/Core/Html.php` | **Modify** | (1) Add `use FSFramework\View\ViewHookRegistry;` import. (2) Register `render_hook` function in `registerUtilityFunctions()`. (3) Register deprecated `clientes_render_hook` alias. (4) Resolve merge conflict in `getPluginIncludeViews` docblock (lines 520-566). |
| `plugins/clientes_core/src/ViewHookRegistry.php` | **Replace** | Thin deprecated stub: `require_once` core file, `class_alias` or `use` + delegation. Emits `E_USER_DEPRECATED`. |
| `plugins/clientes_core/Init.php` | **Modify** | Remove `require_once __DIR__ . '/src/ViewHookRegistry.php';`. Change `registerClientesRenderHook()` to use core `ViewHookRegistry` namespace (or remove entirely since Html.php now registers it). |
| `plugins/clientes_catalogo/Init.php` | **Modify** | Remove `require_once __DIR__ . '/../clientes_core/src/ViewHookRegistry.php';`. Change `use` statement from `FSFramework\Plugins\clientes_core\ViewHookRegistry` to `FSFramework\View\ViewHookRegistry`. |
| `plugins/clientes_core/view/ventas_cliente.html.twig` | **Modify** | Replace `clientes_render_hook` with `render_hook` (lines 204, 438). |
| `tests/View/ViewHookRegistryTest.php` | **Create** | Unit tests: register, has, render, error handling, static state isolation. Mock Twig environment. |

## Interfaces / Contracts

```php
// src/View/ViewHookRegistry.php
namespace FSFramework\View;

final class ViewHookRegistry
{
    /** @var array<string, array<int, string>> */
    private static array $hooks = [];

    public static function register(string $hook, string $template): void;
    public static function has(string $hook): bool;
    public static function render(\Twig\Environment $twig, string $hook, array $context = []): string;
}
```

```php
// Twig function signature (registered in Html.php)
render_hook(string $name, array $context = []): string
// Deprecated alias:
clientes_render_hook(string $name, array $context = []): string  // triggers E_USER_DEPRECATED
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | ViewHookRegistry: register, has, render, error swallowing, duplicate registration | Mock `\Twig\Environment`, use Reflection to reset static `$hooks` between tests |
| Unit | Twig function registration: `render_hook` and `clientes_render_hook` delegate correctly | Create real Twig environment, register functions, render test templates |
| Unit | Deprecated stub: old namespace resolves to core class | `require_once` the stub, verify class exists and methods work |
| Integration | Plugin registers hook, template renders it | (Future — out of scope for this change) |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

No data migration required. Pure PHP class relocation with backward-compatible aliases.

**Rollout order:**
1. Create `src/View/ViewHookRegistry.php` (core class)
2. Register `render_hook` in `Html.php::registerUtilityFunctions()`
3. Replace `plugins/clientes_core/src/ViewHookRegistry.php` with deprecated stub
4. Update `clientes_core/Init.php` — remove `require_once`, update namespace
5. Update `clientes_catalogo/Init.php` — remove `require_once`, update namespace
6. Update `ventas_cliente.html.twig` — `render_hook` instead of `clientes_render_hook`
7. Resolve merge conflict in `Html.php::getPluginIncludeViews` docblock
8. Add unit tests
9. Run full test suite

## Open Questions

- None — all architecture decisions have been resolved with rationale.
