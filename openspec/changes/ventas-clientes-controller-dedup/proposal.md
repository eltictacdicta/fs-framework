# Proposal: Fix ventas_clientes silent create failure and remove dead legacy duplicate

## Why

The user reported that submitting the "Nuevo cliente" modal on `ventas_clientes` produces no error, no success message, and no new row in the `clientes` table. The page simply re-renders the listing. After end-to-end dispatch tracing, the orchestrator's working hypothesis (a "legacy duplicate controller receiving the POST with `name="codigo"`") is **refuted**. The actual root cause is a dispatch-condition bug in the modern controller, and the legacy duplicate is a separate architectural rot.

### Dispatch trace (evidence)

1. `index.php?page=ventas_clientes` -> `index.php:306` calls `find_controller('ventas_clientes')`.
2. `base/fs_functions.php:86-110` iterates `$GLOBALS['plugins']` in order and returns the first match. Active order in `tmp/7e0665add8c25a728458/enabled_plugins.list`: `catalogo_core, legacy_support, business_data, clientes_core, clientes_facturacion, facturacion_base, ...`. `clientes_core` is at index 3, `facturacion_base` at index 5.
3. `find_controller` returns `plugins/clientes_core/controller/ventas_clientes.php` first. The `facturacion_base` controller at `plugins/facturacion_base/controller/ventas_clientes.php` is **never loaded** in the current plugin order. It is dead code.
4. Template resolution: `src/Core/Html.php:493-512` (`resolveTemplate`) looks for `ventas_clientes.html.twig` first. `src/Core/Html.php:189-216` (`addPluginViewPaths`) reverses `$GLOBALS['plugins']` and `prependPath`s each plugin's `view/` and `View/` directories. With the active plugin order, `clientes_core/view/` ends up before `facturacion_base/view/` in the Twig `FilesystemLoader`. The Twig template at `plugins/clientes_core/view/ventas_clientes.html.twig` is the one rendered. The legacy `.html` at `plugins/facturacion_base/view/ventas_clientes.html` is **never rendered**.
5. The Twig template's modal form (line 246) uses `name="codcliente"` (line 257), `name="nombre"` (line 264), `name="razonsocial"` (line 272), `name="cifnif"` (line 278), `name="telefono1"` (line 286), `name="email"` (line 292), `name="codgrupo"` (line 298). The legacy form's `name="codigo"` and `name="scodgrupo"` are **never submitted**.

### Actual root cause

`plugins/clientes_core/controller/ventas_clientes.php:82`:

```php
} else if (filter_input(INPUT_POST, 'codcliente')) {
    if ($this->requireMutationCsrf(fn() => $this->load_clientes())) {
        $this->nuevo_cliente();
    }
}
```

`filter_input(INPUT_POST, 'codcliente')` returns the empty string when the field is posted empty, and `''` is falsy. The Twig form's `codcliente` input has `placeholder="Auto"` and no `required` attribute, so users legitimately submit with an empty value to request auto-generated code. The dispatch condition is not met, the request falls through to the `else { $this->load_clientes(); }` branch on line 90-91, and the page silently re-renders the listing. No error is reported because no error condition occurred from the controller's perspective.

The `nuevo_cliente()` method itself (line 174) **already handles** an empty `codcliente` correctly: line 177 assigns `null` when `filter_input(INPUT_POST, 'codcliente')` is falsy, and `cliente::test()` at `plugins/clientes_core/model/core/cliente.php:404-406` calls `$this->get_new_codigo()` when `codcliente` is null. The save logic is correct; only the dispatch gate is broken.

### Why the orchestrator's hypothesis was wrong

The hypothesis assumed the legacy view was being rendered and posting `name="codigo"`. The dispatch trace above shows the legacy view and controller are both unreachable under the current plugin order. A previous orchestrator session edited `plugins/facturacion_base/controller/ventas_clientes.php` thinking it was the running controller; those edits had no effect on user-visible behavior. The dead file is misleading.

## What changes

- **Primary fix**: change the dispatch condition in `plugins/clientes_core/controller/ventas_clientes.php` so that submitting the new-client form with an empty `codcliente` is detected and routed to `nuevo_cliente()`.
- **Detection mechanism**: prefer an explicit `action` sentinel. The Twig form will add a hidden `name="action" value="nuevo_cliente"` field; the controller's dispatch condition changes to `else if (filter_input(INPUT_POST, 'action') === 'nuevo_cliente')`. This makes the contract explicit and immune to empty-form-field ambiguity.
- **Backwards compat**: also accept legacy submissions that post `codcliente` (truthy) OR `codigo` (truthy) as a transitional safety net. After the legacy view is removed, these branches can be dropped.
- **Cleanup**: delete the dead legacy files that are never reached under the current plugin order: `plugins/facturacion_base/controller/ventas_clientes.php`, `plugins/facturacion_base/view/ventas_clientes.html`, and `plugins/facturacion_base/view/block/ventas_clientes_nuevo.html`. **This cleanup is deferred to a follow-up change** because the 3 files total ~700 lines and would push this PR over the 400-line review budget. See `tasks.md` §T4 (DEFERRED) for the follow-up plan. Keep `plugins/facturacion_base/controller/ventas_clientes_opciones.php` and its view — they are still referenced by `index.php?page=ventas_clientes_opciones` (the legacy_options controller is also dead but is out of scope).
- **Regression test**: add a PHPUnit test that simulates a POST with `nombre` and empty `codcliente`, asserts the dispatch hits `nuevo_cliente()`, and asserts a row is created. The test fails on master (because the dispatch falls through) and passes after the fix.

## Scope

**In scope:**
- `plugins/clientes_core/controller/ventas_clientes.php` dispatch condition (lines 72-92) and `nuevo_cliente()` method (lines 174-197).
- `plugins/clientes_core/view/ventas_clientes.html.twig` modal form (line 246-314) to add the `action` hidden field.
- Deletion of the three dead legacy files in `facturacion_base`.
- New PHPUnit test in `plugins/clientes_core/tests/Controller/VentasClientesTest.php` (or similar location consistent with existing conventions).
- The single-cliente page `ventas_cliente.php` / `ventas_cliente.html.twig` is verified to be untouched and still working.

**Out of scope:**
- The three session/cookie fixes already applied in `src/Security/SessionManager.php` and `src/Core/StealthMode.php` (archived in their own change).
- `ventas_clientes_opciones.php` / `.html` (dead but out of scope to keep the diff under budget).
- The `tpvmod`, `tarifario`, `clientes_catalogo` plugins — they do not include the legacy modal and are not affected.
- Refactoring the modern `nuevo_cliente()` to write a default `direccion_cliente` row (the legacy controller did this; the modern one does not, and adding it is a feature not a bugfix).
- The `cliente` model `test()` / `save()` / `exists()` methods — they are correct.

## Risk

- **Low risk**: the dispatch change is local, the Twig form change is additive (a hidden field). Legacy file deletion is deferred to a follow-up change to stay under the 400-line review budget.
- **Risk if legacy modal is being rendered somewhere we missed**: the only known include of `facturacion_base/view/block/ventas_clientes_nuevo.html` is `facturacion_base/view/ventas_clientes.html:334`, which is itself never rendered. Confirmed by exhaustive grep across `.html`, `.twig`, and `.js` files.
- **Risk if another plugin's Twig template extends the legacy view**: checked — no `Extension/View/ventas_clientes*` files exist.
- **Risk if the dispatch change breaks an existing consumer that posts `codcliente` empty but expects listing reload**: the new behavior is the documented one ("create a new client") and matches user intent.
- **Review budget**: 400 lines. Estimated change size for this PR: **~125 lines** (5 production add + 120 test add). Well under budget. The follow-up cleanup change is ~700 lines (deletions only, mechanical).

## Success criteria

- A POST to `index.php?page=ventas_clientes` with `action=nuevo_cliente&nombre=Test%20User&cifnif=B12345678` (and optionally `codcliente=...` or `codigo=...`) creates a row in `clientes` and redirects to `index.php?page=ventas_cliente&cod=<auto>`.
- A POST with `action=nuevo_cliente` and an empty `codcliente` creates a row with an auto-generated `codcliente` (via `get_new_codigo()`).
- A POST without a valid CSRF token is rejected with "Token de seguridad inválido".
- The dead legacy files `facturacion_base/controller/ventas_clientes.php`, `facturacion_base/view/ventas_clientes.html`, and `facturacion_base/view/block/ventas_clientes_nuevo.html` are deleted.
- A new PHPUnit test in `plugins/clientes_core/tests/` exercises the empty-codcliente path and asserts a row is created. The test fails on master (dispatch falls through) and passes after the fix.
- No regression in `index.php?page=ventas_cliente&cod=...` (single-client detail).
- No regression in `index.php?page=ventas_clientes_opciones` (untouched).
- `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` passes after the change.
