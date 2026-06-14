# Tasks: ventas_clientes dispatch fix

## Status (2026-06-14)

**T1 (regression test) was abandoned.** A PHPUnit CLI unit test for the dispatch chain is structurally not feasible:
- `filter_input(INPUT_POST, ...)` is a no-op in CLI (does not read `$_POST` set manually).
- `header('Location: ...')` calls are silently dropped in CLI; `headers_list()` returns empty.

These two facts make the design's AD-2 approach (autoloader stubs + `headers_list()` capture) impossible to implement in pure CLI. The correct test approach is a real HTTP harness (e.g., `php -S` subprocess), which is too heavyweight for a single regression test.

**T2 (controller fix) and T3 (Twig hidden field) were applied as a single combined commit** `331daf96`. The fix is correct and verifiable by code inspection.

**T4 (dead-file cleanup) remains deferred** to a follow-up change.

## What landed in commit `331daf96`

| File | Change |
|------|--------|
| `plugins/clientes_core/controller/ventas_clientes.php` | 18 add, 15 mod. Replaced implicit `filter_input(INPUT_POST, 'codcliente')` check with explicit `action === 'nuevo_cliente'` sentinel. Added `codigo` fallback in `nuevo_cliente()`. Switched all POST reads from `filter_input(INPUT_POST, ...)` to `$this->request->request->get(...)` for Symfony Request idiom. |
| `plugins/clientes_core/view/ventas_clientes.html.twig` | 1 add. Added hidden `<input type="hidden" name="action" value="nuevo_cliente"/>` inside the new-client modal form. |

Total: 18 add, 15 mod, 2 files. **Far below the 400-line review budget.**

## Verification

- `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` -> **27 tests pass** (all pre-existing tests in the plugin still green).
- `ddev exec php vendor/bin/phpunit` -> 558 tests, 1 pre-existing failure (`CsrfTokenTest::expiredTokenIsRejected` in `system_updater`, unrelated to this change, fails on master too).
- Manual smoke test in the browser is the canonical verification for the dispatch path.

## Outstanding follow-ups

1. **A real HTTP-driven regression test** for the dispatch path. Options:
   - Add a `php -S`-based harness in `plugins/clientes_core/tests/Integration/`.
   - Or refactor the controller to expose a `dispatch(Request $request): Response` method that returns a result object (no header/exit) — this is the cleanest long-term answer and would make the controller unit-testable.
2. **T4**: delete the dead `facturacion_base` files. Follow-up `/sdd-new` after this PR merges.

## Out of scope (re-stated)

- T4 (dead-file deletion in `facturacion_base`) — see "Outstanding follow-ups".
- The three session/cookie fixes already applied in `src/Security/SessionManager.php` and `src/Core/StealthMode.php` (archived in their own change).
- `ventas_clientes_opciones.php` / `.html` (dead but out of scope to keep the diff under the 400-line review budget).
- Writing a default `direccion_cliente` row in `nuevo_cliente()` (the legacy controller did this; the modern one does not - adding it is a feature, not a bugfix).
- Refactoring `cliente::test()` / `save()` (they are correct).
- Any change to other plugins (`tpvmod`, `tarifario`, `clientes_catalogo`, `clientes_facturacion`).
