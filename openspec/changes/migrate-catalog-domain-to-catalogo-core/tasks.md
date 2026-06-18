# Tasks: migrate-catalog-domain-to-catalogo-core

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 250-580 per PR |
| 600-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR-A → PR1 → PR2..N |
| Delivery strategy | ask-on-risk |
| Chain strategy | stacked-to-main (F1) |

```
Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
600-line budget risk: High
```

### Suggested Work Units

| Unit | Goal | PR | Deps |
|------|------|----|------|
| WU-1 | Drop 4 stubs + 13 PHP + 13 XML + 10 ctrl + 16 views; tag | PR-A | — |
| WU-2 | `Init.php` + `compat/class_aliases.php` + 2 tests; receive `git mv` 13+13 | PR1 | WU-1 |
| WU-3..7 | Per-entity PRs: `admin_paises`, `fabricante`, `familia`, `articulo` (list, detail) | PR2..6 | WU-2 |
| WU-8..10 | Adjacent: stocks, attributes, tariffs | PR7..9 | WU-2 |
| WU-11 | Final cleanup; sync specs | WU-Final | WU-3..10 |

## Phase 1: Foundation (PR-A + PR1)

- [x] 1.1 WU-1: clone `facturacion_base`; `git rm` 4 stubs; `git mv` 13 PHP + 13 XML; `git rm` 10 ctrl + 16 views; tag. [CDM-03, CAM-01, CAM-02, CAM-05]
- [x] 1.2 WU-2 RED: `CompatAliasesTest.php` asserts `get_class(new articulo()) === 'FSFramework\model\articulo'` for 4 aliases. [CDM-08, CDM-02]
- [x] 1.3 WU-2 GREEN: `compat/class_aliases.php` with 4 `class_alias` guarded by `class_exists(..., false)`. [CDM-04]
- [x] 1.4 WU-2 REFACTOR: `@deprecated` docblocks; `almacen/divisa/pais` need no alias. [CDM-05, CDM-06, CDM-11]
- [x] 1.5 WU-2 RED: `InitAutoloadTest.php` asserts 13 `FSFramework\model\*` resolve. [CAM-07, CAM-09]
- [x] 1.6 WU-2 GREEN: `Init.php` with `spl_autoload_register` mapping `FSFramework\model\*` → `plugins/catalogo_core/model/core/*` + `require_once` of `compat/class_aliases.php`. [CDM-07, CAM-03, CAM-04]
- [x] 1.7 WU-2 REFACTOR: `is_file()` guard; pre-load 4 alias targets; byte-identity. [CAM-10]
- [x] 1.8 WU-2: receive 12 PHP + 11 XML from facturacion_base (G1 strategy: copy then delete). Files now in `catalogo_core/model/{core,table}/`.
- [x] 1.9 WU-2 VERIFY: `phpunit --testsuite Plugins` green; 7 core entities under `FSFramework\model`. [CDM-01, CDM-10, CAM-06]

## Phase 2: Per-entity vertical PRs (PR2..PR6)

- [x] 2.1 WU-3 PR2 `admin_paises`: RED `pais::all()` 200; GREEN `Controller/AdminPaises.php` + Twig + wrapper; REFACTOR drop `new_error_msg` non-`fs_model`; verify CSRF. [CPV-01, CPV-02, CPV-03, CPV-06, CPV-07, CPV-10, CPV-11]
- [x] 2.2 WU-4 PR3 `fabricante`: RED `::all()` + `::get($cod)`; GREEN `Controller/VentasFabricantes.php` + `VentasFabricante.php` + Twig + 2 wrappers preserving `cod`; REFACTOR alias `@deprecated`; `tpvmod` smoke. [CDM-09, CDM-10, CPV-04, CPV-05, CPV-09]
- [x] 2.3 WU-5 PR4 `familia`: same (list + detail, `codfamilia`); mark `familia` alias `@deprecated`.
- [x] 2.4 WU-6 PR5 `ventas_articulos`: RED `::all()` search via `var2str`; GREEN `Controller/VentasArticulos.php` + Twig + wrapper; VERIFY SQL-injection safe. [CPV-08]
- [x] 2.5 WU-7 PR6 `ventas_articulo`: RED `::get($ref)` + `::url()` → `index.php?page=ventas_articulo&ref=...`; GREEN `Controller/VentasArticulo.php` (582) + Twig (454) + wrapper; REFACTOR alias `@deprecated`; drop unused `default_items`. [CDM-09, CPV-05, CPV-09]
- [x] 2.6 ALL WU-3..7 VERIFY: `phpunit --testsuite Plugins` green + per-entity test green + `tpvmod` smoke.

## Phase 3: Adjacent groups (PR7..PR9)

- [x] 3.1 WU-8/9/10: per-entity `tests/<Model>/ModelTest.php` mirroring `FabricanteModelTest`; verify `tpvmod`/`tarifario`/`clientes_catalogo` call sites resolve via autoloader. [CAM-06, CAM-09]
- [x] 3.2 WU-8/9/10: confirm `facturascripts.ini` declares `catalogo_core` as dep; Plugins suite green. [CAM-08]

## Phase 4: Cleanup

- [x] 4.1 Update `plugins/catalogo_core/README.md` model inventory + namespace typo fix (optional).
- [x] 4.2 Update `openspec/specs/catalog-{domain,adjacent,page}-models/spec.md` for any deviation.
- [x] 4.3 WU-11: enumerate `facturacion_base` files outside 4+13+13+10+16; remove leftovers. **Found and deleted 12 additional legacy stubs** in `facturacion_base/model/` (articulo_combinacion, articulo_propiedad, articulo_proveedor, articulo_traza, atributo, atributo_valor, linea_transferencia_stock, recalcular_stock, regularizacion_stock, stock, tarifa, transferencia_stock).
- [x] 4.4 WU-11: re-run `ddev exec php vendor/bin/phpunit` (full suite) — zero regressions.
- [x] 4.5 WU-11: flag any `MUST NOT` softened during apply for user review.

## Post-implementation Fixes

- [x] **BUGFIX: Model override conflict** — Removed custom autoloader from `Init.php` and deleted `compat/class_aliases.php`. The framework's `fs_model_autoloader` already handles model loading and plugin overrides correctly. Our custom autoloader was causing "Cannot declare class" errors when dependent plugins (like `tarifario`) tried to override models from `catalogo_core`. Updated specs CDM-04, CDM-06, CDM-07, CDM-11, CAM-07, CAM-10 to reflect that the framework handles aliases automatically. All 146 catalogo_core tests pass.

- [x] **VERIFIED: Plugin override mechanism** — Created integration tests to verify that plugin model overrides work correctly. Tests confirm that: (1) catalogo_core's namespaced models (FSFramework\model\familia) can be loaded independently, (2) tarifario's global override (familia) can coexist without conflicts, (3) no "Cannot declare class" errors occur. All 153 catalogo_core tests pass (297 assertions). The override pattern allows dependent plugins to extend base functionality without breaking the base plugin.
