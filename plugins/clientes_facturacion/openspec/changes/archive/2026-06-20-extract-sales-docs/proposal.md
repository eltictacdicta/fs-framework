# Proposal: Extract sales-document domain to clientes_facturacion

## Intent

`facturacion_base` bundles four concerns (client sales, supplier purchases, accounting, TPV). Any consumer (e.g. `tpvmod`) needing only the **sales-document models** still `require`s all of `facturacion_base`. This change moves the client-side sales domain (10 models + 10 core classes + 3 traits, 10 XMLs, 17 controllers, 15 views) into the existing-but-minimal `clientes_facturacion` and bootstraps it as a **functionally standalone, SDD-aware** plugin. `facturacion_base` becomes the **optional** add-on for extended capabilities (supplier management, accounting, TPV-specific). `tpvmod`'s own `require` update is a separate SDD.

## Scope

### In Scope

- Move 10 shims + their 10 real classes in `model/core/` (`FSFramework\model` namespace) from `facturacion_base/model/` to `clientes_facturacion/model/`. **R1 critical** — shim/core split is not in S1.
- Move 10 XMLs from `facturacion_base/model/table/` to `clientes_facturacion/model/table/`. **Zero schema changes** (S7).
- Move **3 trait files** (`documento_venta.php`, `linea_documento_venta.php`, `factura.php`) from `facturacion_base/extras/` to `clientes_facturacion/extras/`. The `factura.php` trait is shared with `factura_proveedor` (staying in `facturacion_base`); `factura_proveedor` gets an updated cross-plugin `require_once` pointing into `clientes_facturacion/extras/`. Safe because `facturacion_base` already `require`s `clientes_facturacion`, so the trait is always reachable when `factura_proveedor` is loaded.
- Move 17 admin controllers (14 `ventas_*` + `informe_facturas` + `informe_albaranes` + `nueva_venta`) and 15 matching `view/*.html`. 2 views don't exist (reused); not blockers.
- Update all `require_once` paths in moved files (shim paths and 2 `__DIR__`-relative paths in core files).
- Bootstrap `clientes_facturacion`: `Init.php`, `description`, `translations/messages.es.yaml`, `phpunit.xml`, `tests/ModelLoadingTest.php` (10 tests). Bump `fsframework.ini` 1→2 and `facturascripts.ini` 1→2.
- Add `clientes_facturacion` to `plugins/catalogo_core/fsframework.ini` `require` (currently absent) because `catalogo_core/extras/fbase_controller.php:688` instantiates `regularizacion_iva` at RUNTIME inside private `validateFacturaEjercicio()`. Verified runtime-only.
- Bump `facturacion_base/facturascripts.ini` 158→159; update `facturacion_base/description` to reduced scope.

### Out of Scope

All `compras_*`, `contabilidad_*`, `admin_agente*`, `admin_empresa`, `admin_transportes` controllers; all accounting models (`asiento`, `balance*`, `epigrafe`, `cuenta*`, `partida`, `subcuenta*`, `secuencia*`); `tpv_caja`/`tpv_recambios`; `asiento_factura`; `cuenta_banco_cliente`/`_proveedor`. `tpvmod`'s own `require` update is a separate SDD. No deprecation cycle on `facturacion_base` (S6).

## Capabilities

- **New Capabilities**: None.
- **Modified Capabilities**: None.

File-move refactor, zero behavior change. The `sdd-spec` phase writes one delta spec at `plugins/clientes_facturacion/openspec/changes/extract-sales-docs/specs/sales-documents/spec.md` making the moved domain's contract explicit.

## Approach

Move files in atomic groups (shim + core + trait + paths in one commit per document). Load chain after this change:
- `catalogo_core → clientes_facturacion → clientes_core` (catalogo_core gains a new dep on clientes_facturacion for the `regularizacion_iva` runtime use in `fbase_controller.php:688`)
- `facturacion_base → clientes_facturacion → clientes_core` (was already the case; now also required because `factura_proveedor` `require_once`s the `factura` trait from `clientes_facturacion/extras/`)
- `tpvmod → clientes_facturacion → clientes_core` (the goal; future SDD)

No cycles. `fs_model_autoloader` resolves by file path, so XMLs and PHP class files move transparently. `ModelLoadingTest` is auto-discovered by the root **Plugins** suite. `clientes_facturacion` keeps `require = "clientes_core"` (unchanged). Consumers needing `articulo`/`familia` add `catalogo_core` to their own require (documented in the plugin's `description`).

## Affected Areas

| Area | Impact |
|------|--------|
| `facturacion_base/model/{name}.php` (10) + `model/core/{name}.php` (10) | Removed → `clientes_facturacion/` |
| `facturacion_base/model/table/*.xml` (10) | Removed → `clientes_facturacion/model/table/` (byte-identical) |
| `facturacion_base/controller/{ventas_*,informe_facturas,informe_albaranes,nueva_venta}.php` (17) | Removed → `clientes_facturacion/controller/` |
| `facturacion_base/view/{ventas_*,informe_*,nueva_venta}.html` (15) | Removed → `clientes_facturacion/view/` |
| `facturacion_base/extras/{documento_venta,linea_documento_venta,factura}.php` (3) | Removed → `clientes_facturacion/extras/` |
| `facturacion_base/model/core/factura_cliente.php` (line 23) + `model/core/factura_proveedor.php` (line 22) | `require_once __DIR__ . '/../../extras/factura.php'` updated to cross-plugin path `plugins/clientes_facturacion/extras/factura.php` |
| `clientes_facturacion/` | New: `Init.php`, `description`, `translations/`, `phpunit.xml`, `tests/ModelLoadingTest.php`, inis bumped |
| `facturacion_base/facturascripts.ini` + `description` | `version` 158→159; description reflects reduced scope |
| `catalogo_core/fsframework.ini` | Add `require = "clientes_facturacion"` |

## Risks

| ID | Risk | Severity | Mitigation |
|----|------|----------|------------|
| **R1** | S1 lists 10 shims but real classes are in `model/core/`; 3 trait files in `extras/` (`documento_venta`, `linea_documento_venta`, `factura`) are also required. `factura.php` is shared with `factura_proveedor` (staying); per user direction, all 3 traits move to `clientes_facturacion/extras/`. `factura_proveedor` retains a `require_once` to the moved trait. | **CRITICAL** | Apply corrected scope: 10 shims + 10 core + 3 traits moved to `clientes_facturacion/`. Update 2 cross-plugin `require_once` paths in `model/core/factura_cliente.php` and `model/core/factura_proveedor.php`. |
| **R2** | S3 says 18 controllers; actual is **17** (14 `ventas_*` + 2 `informe_*` + 1 `nueva_venta`). | WARNING | Adopt 17. |
| **R3** | Cross-domain runtime couplings: `factura_cliente` (core) `new \asiento()`; `regularizacion_iva` (core) `new \partida()`/`\asiento()`; `ventas_factura` + `ventas_factura_devolucion` use `asiento_factura`; `ventas_cliente` + `ventas_imprimir` use `cuenta_banco_cliente`; `ventas_clientes` + `nueva_venta` call `$cliente->get_subcuenta()` (body references `\subcuenta`); `informe_facturas extends informe_albaranes` and the latter uses `factura_proveedor`/`albaran_proveedor`/`proveedor`. **`tpvmod` does NOT trigger any of these paths** (verified: no accounting-class refs in `plugins/tpvmod/`). | **CRITICAL (architectural)** | The change is **split by location, not full independence**. Goal met for **model** layer; moved **controllers** stay runtime-coupled to `facturacion_base`. Refactoring the coupling is a future SDD. |
| **R4** | `catalogo_core` runtime use of `regularizacion_iva` at `fbase_controller.php:688`. Verified: RUNTIME-only (private method called via `finalizeFacturaClienteTotales`). | CRITICAL (mitigated) | Add `clientes_facturacion` to `catalogo_core` `require`. Autoloader resolves on `new regularizacion_iva()`. |
| **R5** | Size: ~57–62 files / ~2000–3500 lines. Exceeds 400-line review budget (D1). | SUGGESTION | `sdd-tasks` MUST ask chained-PR question and split: (1) bootstrap+test; (2) models+traits+XMLs; (3) controllers+views; (4) ini/description. |
| **R6** | `informe_facturas extends informe_albaranes`; both reference compras models; must move atomically. | WARNING | Atomic move of the pair. |

## Rollback Plan

`git revert <merge-commit>` restores all files. XMLs byte-identical (no DB migration). Revert `facturacion_base/facturascripts.ini` version and `catalogo_core/fsframework.ini` `require`. Verify with `ddev exec php vendor/bin/phpunit`.

## Dependencies

- `clientes_facturacion` `require = "clientes_core"` (unchanged). Standalone status: `clientes_facturacion` is functionally complete on its own (models + controllers + traits + i18n) for the client-sales domain. `facturacion_base` becomes optional, providing extended capabilities (suppliers, accounting, TPV-specific).
- `catalogo_core/fsframework.ini` adds `require = "clientes_facturacion"`. Chain: `catalogo_core → clientes_facturacion → clientes_core`. No cycle.
- `facturacion_base` `require` unchanged: `"catalogo_core,business_data,clientes_core,clientes_facturacion"`. New cross-plugin path: `facturacion_base/model/core/factura_proveedor.php:22` `require_once`s the `factura` trait from `clientes_facturacion/extras/`.
- Zero core code changes (`base/`, `src/`, `controller/` root, `model/` root untouched).
- `tpvmod` update is a separate SDD.

## Success Criteria

- [ ] `ddev exec php vendor/bin/phpunit --testsuite Plugins` passes; 10 green tests in `ModelLoadingTest`.
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Base` unchanged (160/160).
- [ ] 10 XMLs resolve to same DB tables (`facturascli`, `albaranescli`, `pedidoscli`, `presupuestoscli`, `lineas*`, `lineasivafactcli`, `co_regiva`).
- [ ] Moved controllers still render via AdminLTE menu with `facturacion_base` active.
- [ ] `tpvmod` controllers can `require_model('factura_cliente.php')` etc. without `facturacion_base` in their require chain.
- [ ] `php -l` clean; `ddev exec composer phpstan` shows no new errors.
- [ ] Zero refs from `clientes_facturacion/` to `facturacion_base/`. (`facturacion_base/model/core/factura_proveedor.php` retains a `require_once` into `clientes_facturacion/extras/factura.php` — that direction is the inverse, expected.)
- [ ] No artifacts in core `openspec/changes/extract-sales-docs/`.
