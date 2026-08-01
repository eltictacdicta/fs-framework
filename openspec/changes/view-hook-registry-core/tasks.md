# Tasks: Promote ViewHookRegistry to Core

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~300–350 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | auto-chain |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Core class + Twig integration + plugin migration + tests | Single PR | `ddev exec php vendor/bin/phpunit tests/View/ViewHookRegistryTest.php` | N/A — pure unit tests | Revert entire PR; no partial rollback needed |

## Phase 1: Core Class (TDD)

- [x] 1.1 RED — Create `tests/View/ViewHookRegistryTest.php` with tests: register + has, duplicate template dedup, render with templates, render unknown hook returns empty, error swallowing (exception logged, rendering continues), multiple templates concatenated, static state isolation between tests. Use Reflection to reset `$hooks` in `setUp()`. (~150 lines)
- [x] 1.2 GREEN — Create `src/View/ViewHookRegistry.php` under `FSFramework\View` namespace. Copy logic from `plugins/clientes_core/src/ViewHookRegistry.php`. Add typed property `private static array $hooks = []`. No other logic changes. (~55 lines)
- [x] 1.3 REFACTOR — Run tests, confirm all pass. Verify class resolves via Composer PSR-4 without `require_once`. (~0 new lines)

## Phase 2: Twig Integration (TDD)

- [x] 2.1 RED — Add test to `ViewHookRegistryTest.php`: create real Twig\Environment, register `render_hook` via `Html::registerUtilityFunctions()` (or inline registration), render `{{ render_hook('test') }}`, assert output matches `ViewHookRegistry::render()`. Test deprecated `clientes_render_hook` triggers `E_USER_DEPRECATED`. Test invalid (non-string) hook name returns empty. (~30 lines)
- [x] 2.2 GREEN — Modify `src/Core/Html.php`: add `use FSFramework\View\ViewHookRegistry;`, register `render_hook` function in `registerUtilityFunctions()` that delegates to `ViewHookRegistry::render($twig, $name, $context)`. Wrap duplicate registration in try/catch for idempotency. (~15 lines)
- [x] 2.3 GREEN — Register deprecated `clientes_render_hook` alias in same method: closure that triggers `E_USER_DEPRECATED` then delegates to `ViewHookRegistry::render()`. (~10 lines)
- [x] 2.4 REFACTOR — Run `tests/View/ViewHookRegistryTest.php`, confirm all tests pass including deprecated alias test. (~0 new lines)

## Phase 3: Plugin Migration

- [x] 3.1 Replace `plugins/clientes_core/src/ViewHookRegistry.php` content with deprecated stub: `require_once` core file, `use` core class, `class_alias` or direct delegation. Emit `E_USER_DEPRECATED` on class load. (~25 lines)
- [x] 3.2 Modify `plugins/clientes_core/Init.php`: remove `require_once __DIR__ . '/src/ViewHookRegistry.php';`, update `use` from `FSFramework\Plugins\clientes_core\ViewHookRegistry` to `FSFramework\View\ViewHookRegistry`. (~5 lines changed)
- [x] 3.3 Modify `plugins/clientes_catalogo/Init.php`: remove `require_once __DIR__ . '/../clientes_core/src/ViewHookRegistry.php';`, update `use` statement to `FSFramework\View\ViewHookRegistry`. (~5 lines changed)
- [x] 3.4 Modify `plugins/clientes_core/view/ventas_cliente.html.twig`: replace `clientes_render_hook` with `render_hook` at lines 204 and 438. (~4 lines changed)

## Phase 4: Cleanup

- [x] 4.1 Resolve merge conflict in `src/Core/Html.php::getPluginIncludeViews` docblock (lines 520–566) — clean up any conflict markers or stale content. (~10 lines)
- [x] 4.2 Run full test suite: `ddev exec php vendor/bin/phpunit`. Confirm no regressions. (~0 new lines)
- [x] 4.3 Verify backward compatibility: third-party `require_once` of old path loads stub without fatal error. (~0 new lines)
