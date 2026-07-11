# Archive Report: migrate-catalog-domain-to-catalogo-core

## Summary

Migrated the entire catalog domain (7 core entities + 12 adjacent models + 18 XML schemas + 9 PSR-4 controllers + 9 Twig views + 9 legacy wrappers + 7 PSR-4 model wrappers) from `plugins/facturacion_base/` to `plugins/catalogo_core/`. The framework's `fs_model_autoloader` handles model loading and global alias creation automatically, eliminating the need for a custom autoloader or manual `class_alias` shim.

## What Was Accomplished

### Phase 1: Foundation (PR-A + PR1)
- **facturacion_base cleanup**: Removed 4 legacy stubs, 13 model/core PHP files, 13 XML schemas, 10 controllers, 16 RainTPL views, plus 12 additional legacy stubs discovered during cleanup
- **catalogo_core reception**: 13 PHP + 13 XML now live under `catalogo_core/model/{core,table}/`
- **Init.php**: Exists as plugin entry point; framework autoloader handles model resolution
- **Compat layer**: Removed after discovering `fs_model_autoloader` already provides global aliases and plugin override support

### Phase 2: Per-entity Vertical PRs (PR2-PR6)
- Migrated 9 catalog pages to PSR-4 controllers + Twig views + legacy wrappers
- Pages: `ventas_articulo`, `ventas_familia`, `ventas_fabricante`, `admin_paises`, `admin_almacenes`, `admin_divisas`, `ventas_articulos`, `ventas_familias`, `ventas_fabricantes`
- All preserve original URLs, page names, and query parameters

### Phase 3: Adjacent Groups (PR7-PR9)
- 12 adjacent models verified: stock, transferencia_stock, linea_transferencia_stock, articulo_combinacion, articulo_propiedad, articulo_proveedor, articulo_traza, atributo, atributo_valor, regularizacion_stock, recalcular_stock, tarifa

### Phase 4: Cleanup
- Updated main specs to reflect implementation reality (fs_model_autoloader instead of custom autoloader)
- Full test suite: zero regressions

## Post-implementation Bugfix

**Model override conflict**: The initial implementation included a custom `spl_autoload_register` in `Init.php` and a `compat/class_aliases.php` file. This caused "Cannot declare class" errors when dependent plugins (like `tarifario`) tried to override models from `catalogo_core`. The fix was to remove both the custom autoloader and the compat layer, relying entirely on the framework's `fs_model_autoloader` which handles model loading, global aliases, and plugin overrides correctly.

## Files Changed

| Category | Count | Details |
|----------|-------|---------|
| Models moved to catalogo_core | 19 PHP | 7 core + 12 adjacent |
| XML schemas moved | 18 | In catalogo_core/model/table/ |
| PSR-4 controllers created | 9 | catalogo_core/Controller/ |
| Legacy wrappers created | 9 | catalogo_core/controller/ |
| Twig views created | 9 | catalogo_core/View/ |
| PSR-4 model wrappers | 7 | catalogo_core/Model/ |
| Test files created | 22 | catalogo_core/tests/ |
| Files removed from facturacion_base | ~62 | Stubs + models + XML + controllers + views |

## Tests

| Metric | Value |
|--------|-------|
| Test files | 22 |
| Tests passing | 280 (catalog-related) |
| Total suite | 280 passed / 1 failed (pre-existing, unrelated) / 1 skipped |
| Assertions | 540 |
| Spec requirements covered | 30/30 |
| Scenarios covered | 23/23 |

## Verification Results

- **Verdict**: PASS WITH WARNINGS
- **CRITICAL issues**: None
- **Warnings**: 1 tautological assertion in conditional test branch; 1 cross-repo dependency unverifiable locally
- **TDD compliance**: 6/6 checks passed
- **Design decisions followed**: 7/7

## Deviations from Original Plan

1. **Compat layer removed**: Original design specified `class_alias()` in `compat/class_aliases.php` + custom `spl_autoload_register` in `Init.php`. Implementation discovered the framework's `fs_model_autoloader` already provides this functionality. The compat layer was removed to avoid conflicts with plugin overrides.
2. **12 additional legacy stubs found and removed**: The proposal listed 4 stubs; cleanup found and removed 12 more adjacent model stubs in `facturacion_base/model/`.
3. **9 pages instead of 10**: The proposal mentioned "10 catalog controllers" but the spec and implementation deliver 9 pages (the spec's CPV-05 lists 9 page names).

## Specs Synced

| Spec | Status | Notes |
|------|--------|-------|
| catalog-domain-models | Already synced | Updated during task 4.2 to reflect fs_model_autoloader |
| catalog-adjacent-models | Already synced | Updated during task 4.2 |
| catalog-page-views | Already synced | Updated during task 4.2 |

## Archive Contents

- proposal.md
- design.md
- tasks.md (22/22 tasks complete)
- verify-report.md
- PREFLIGHT.md

## Final Status

**ARCHIVED** - 2026-06-18
