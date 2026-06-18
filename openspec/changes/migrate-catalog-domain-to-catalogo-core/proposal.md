# Proposal: migrate-catalog-domain-to-catalogo-core

## Intent

`plugins/catalogo_core/` already owns `articulo`/`familia`/`fabricante`/`impuesto`/`almacen`/`divisa`/`pais` and 13 adjacent models. But `plugins/facturacion_base/` still owns catalog controllers, RainTPL views, 13 XML schemas, and 30-line stub shims. Move ownership into `catalogo_core/`; migrate to PSR-4 + Twig; keep URLs, page names, and class-name strings intact for `tpvmod`/`tarifario`/`clientes_catalogo`.

## Scope

**In**: `git mv` 13 adjacent PHP + 13 XML from `facturacion_base/{model/core,model/table}/` → `catalogo_core/{model/core,model/table}/`. New `catalogo_core/compat/` (`class_alias` + `@deprecated`) preserving `new articulo()`. Migrate 10 catalog controllers to PSR-4 + Twig (legacy wrappers preserve routing). Port 16 RainTPL views to Twig. Add `Init.php`.

**Out**: Page renames, adjacent-model PSR-4 migration, `fbase_controller` refactor, `ValidatorTrait` on wrappers, README typo fix, `fs_divisa_tools` dedup, TPV models/flow, internal `Articulo` wrapper.

**Dependency**: `facturacion_base` deletes stubs + 13 model/core + 13 XML + 10 controllers + 16 views in one PR before PR1. `catalogo_core` receives 13 imports via `git mv --`. `tpvmod`/`tarifario`/`business_data`/`clientes_catalogo` unchanged.

## Capabilities

> No existing spec covers catalog — all NEW.

- **NEW `catalog-domain-models`**: 7 core entities + `compat/` shim.
- **NEW `catalog-adjacent-models`**: 13 adjacent models + XML (stock, attributes, suppliers, traceability, tariffs, transfers).
- **NEW `catalog-page-views`**: PSR-4 controllers + Twig for 10 catalog pages; legacy wrappers preserve routing.
- **MODIFIED**: none.

## Approach

Chained, vertical, TDD-first PRs (600 lines/PR). **PR1 — Plumbing**: `git mv` 13+13 files; `Init.php` + autoload; remove stubs; add `compat/` shim. Zero behavior change — `phpunit Plugins` green. **PR2..N — One per entity** (articulo → familia → fabricante → impuesto → almacen → divisa → pais → atributo → stock → tarifa → ...): TDD PSR-4 controller + Twig view; thin legacy wrapper.

## Affected Areas

| Area | Status |
|------|--------|
| `catalogo_core/model/{core,table}/` | NEW (13 PHP + 13 XML) |
| `catalogo_core/compat/`, `{Controller,View}/`, `controller/`, `Init.php` | NEW |
| `facturacion_base` stubs + 13 model/core + 13 XML + 10 controllers + 16 views | REMOVED |
| `plugins/tpvmod/`, `tarifario/`, `business_data/`, `clientes_catalogo/` | UNCHANGED |

## Risks

- **Class identity (M)** — shim preserves it; PHPUnit covers.
- **Cross-repo `git mv` (M)** — facturacion_base deletion first.
- **600-line budget (H)** — Twig views 100–450 lines; `ask-always` sub-slices.
- **Static state leaks (M)** — reuse `ArticuloModelEncodingTest` reset pattern.
- **`tpvmod` shares at `ventas_articulo` (M)** — page name preserved; legacy wrapper keeps routing.

## Rollback Plan

PR1 reverses via `git mv` revert in both repos + stub restoration (no DB change). Each per-entity PR reverts independently. The `class_alias` shim is the rollback anchor.

## Success Criteria

- 76 catalog call sites resolve after PR1; `phpunit Plugins` zero regressions.
- Stubs `facturacion_base/model/{articulo,familia,fabricante,impuesto}.php` gone; `catalogo_core/compat/` re-exports them.
- 13 PHP + 13 XML under `catalogo_core/{model/core,model/table}/`; 10 catalog pages render via Twig, same URLs.
- `tpvmod` smoke test passes.
- No PR exceeds 600 changed lines.
