# Proposal: Retire `tarif_familia_ext` Write Path — Unify Familia Hierarchy per Tariff

## Intent

The "familia" entity currently has two competing hierarchy sources: the legacy global hierarchy (`familias.madre` + `tarif_familia_ext` capitulo/nivel, written through `tarif_familia`) and the per-tariff hierarchy (`tarif_tarifa_familia`). Three legacy write paths still create familias through `tarif_familia`, so familias created by those flows never appear on the tarifa families page (`tarif_familias.php`), which lists exclusively from `tarif_tarifa_familia`. This change retires `tarif_familia_ext` as a write path and converges every familia-creating flow on base `familia` + `tarif_tarifa_familia` (user-approved option 2). The legacy table remains as historical migration data — no data deletion.

## Problem Statement (verified evidence)

| # | Evidence | Location | Notes |
|---|----------|----------|-------|
| 1 | Legacy-only writer (SAP/Excel import) | `plugins/tarifario/controller/tarif_articulos.php:1170,1198` (save at 1206) | writes `familias` + `tarif_familia_ext` only |
| 2 | Legacy-only writer (batch import) | `tarif_articulos.php:1586,1606` — `process_familias_batch()` (save at 1614) | same |
| 3 | Dual writer (batch import) | `tarif_catalogo_view.php:2525,2551` (`tarif_familia` save at 2561) + `tarif_tarifa_familia` save at 2578 | **Correction to preflight**: this site DOES write the new table; it writes both |
| 4 | Dual writer — **not in original list, found during verification** | `plugins/tarifario/Services/ExcelHierarchyService.php` — `createFamiliaRow()`: `$familia->save()` at 461 (ext write) + `$fam_tarifa->save()` at 473 | docblock (435-437) explicitly states it writes all three tables |
| 5 | Target flow (reference pattern) | `plugins/catalogo_core/controller/tarif_familias.php:654` (`add_familia_to_tarifa()`) + `tarif_tarifa_familia::add_familia_to_tarifa()` (model line 636) | base familia + per-tariff row |
| 6 | One-time migration | `tarif_tarifa_familia.php:155-176` (`migrate_existing_familias()`, called from `install()` line 126) | the only historic copy path ext → tarifa table |
| 7 | DB drift (preflight, live MySQL via ddev) | familias=238, ext=233, tarifa=237 (all `codtarifa='DEF'`); HOLA/HOLA2/WWW/FAMILIA2 in tarifa table but NOT in ext; VARI in `familias` with no tarifa row | drift is already real |
| 8 | Stale spec scenario | `openspec/specs/catalog-domain-models/spec.md:44-49` references `plugins/tarifario/model/familia.php` — file confirmed deleted (archived change `2026-09-03-tarifario-catalogo-hook-integration`) | documentation rot |
| 9 | Catalog page (intended, not a bug) | `plugins/catalogo_core/Controller/VentasFamilia.php:59,114` writes only `familias` | by design; such familias appear in the "Añadir familia" datalist until linked |

## Scope

### In Scope
- Migrate the two legacy-only writers in `tarif_articulos.php` (#1, #2) to base `familia` + `tarif_tarifa_familia`, preserving the imported hierarchy values (madre/capitulo).
- Convert the two dual-writers (#3 `tarif_catalogo_view.php::process_familias_batch`, #4 `ExcelHierarchyService::createFamiliaRow`) to base `familia` save + `tarif_tarifa_familia` save, dropping the ext write.
- Retire `tarif_familia` as a write path (`save()`/`save_extension()`/`delete_extension()` removed or neutralized) — exact class fate per Open Question 1.
- Amend the stale `catalog-domain-models` spec scenario (evidence #8).
- Update/add tests for all migrated writers (placement per Open Question 4).

### Out of Scope
- Dropping or truncating `tarif_familia_ext`, or any data deletion (historical data preserved).
- Migrating read-only `tarif_familia` consumers to per-tariff reads (separate future change).
- Changing `VentasFamilia` catalog-page behavior (writes only `familias` — intended).
- Removing the `migrate_existing_familias()` install hook.

## Capabilities

### New Capabilities
- `tarifa-familia-hierarchy`: the per-tariff `tarif_tarifa_familia` hierarchy is the single write target for every familia-creating flow; `tarif_familia_ext` is read-only historical data.

### Modified Capabilities
- `catalog-domain-models`: replace the stale scenario "dependent plugin can override catalogo_core model" (references deleted alias file); document the disposition of `FSFramework\model\tarif_familia`.

## Approach

Apply the pattern already proven in `tarif_familias.php::add_familia_to_tarifa()`: upsert the base catalog row via `\FSFramework\model\familia`, then persist the per-tariff hierarchy (madre/capitulo/flags) via `tarif_tarifa_familia::add_familia_to_tarifa()` or a direct save. `ExcelHierarchyService` needs the minimal change: point its `familiaModel` at base `familia` in `createFamiliaRow()` — the tarifa-side write (lines 465-475) already exists. `tarif_familia` continues to serve read-only consumers (`get()/all()/hijas()` still LEFT JOIN `tarif_familia_ext`, which persists as historical data), pending Open Question 1.

## Alternatives Considered

- **Option 1 (rejected): make `tarif_familia::save()` dual-write `tarif_tarifa_familia`.** Perpetuates the dual hierarchy forever and leaves the new table's flags/hierarchy derived rather than authored. User rejected.
- **Delete `tarif_familia` outright (deferred).** Breaks the verified read-only consumers (Open Question 1) and the model wrappers that hydrate from plain `familias` rows.
- **Do nothing.** Drift continues; imported familias stay invisible on the tarifa families page.

## Affected Areas

| Area | Impact |
|------|--------|
| `plugins/tarifario/controller/tarif_articulos.php` | Modified — writers #1 and #2 migrated |
| `plugins/tarifario/controller/tarif_catalogo_view.php` | Modified — dual-writer #3 drops ext write |
| `plugins/tarifario/Services/ExcelHierarchyService.php` | Modified — dual-writer #4 uses base `familia` |
| `plugins/catalogo_core/model/tarif_familia.php` | Modified or Removed — write path retired (OQ1) |
| `openspec/changes/retire-tarif-familia-ext/specs/` | New — delta specs (tarifa-familia-hierarchy, catalog-domain-models) |
| `plugins/tarifario/tests/**` | Modified/New — coverage for migrated writers |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Data drift already present (4 familias missing from ext; VARI orphan) | Certain (pre-existing) | Not worsened by this change; VARI handled via OQ2 |
| FK CASCADE between `tarif_familia_ext` and `familias` in delete flows (`tarif_familia::delete()` touches both, model lines 251-269) | Medium | Keep table + FK intact during transition; cleanup decision in OQ3 |
| Read-only consumers regress if class deleted | High (if deleted) | Prefer deprecated read-only class (OQ1); all consumers verified read-only |
| Import flag semantics divergence (`en_tarifa`: Excel importer sets true, CSV importer false — documented in service docblock) | Low | Preserve each flow's current defaults; no silent normalization |

## Rollback Plan

Revert the PHP changes; writers return to `tarif_familia::save()` and the ext table + FK are untouched, so legacy writes keep working. No schema changes are planned, so rollback requires no migration. The spec delta is reverted by restoring the original scenario text from the archived spec.

## Open Questions (decisions for design phase)

1. **Fate of `FSFramework\model\tarif_familia`:** delete vs keep `@deprecated` read-only. **Recommendation:** keep deprecated with write methods removed/neutered. Verified read-only consumers (methods used): `tarif_articulo_edit.php:55` (get:227); `tarif_opcionales.php:66,332` (all:333); `tarif_opcional_precios.php:54` (instantiated, exposed to view, no controller-level calls); `tarif_opcional_edit.php:56` (all:210); `tarif_actualizar_precios.php:130` (hijas:326, get:482); `tarif_catalogo_view.php:1220,2525` (get); `tarif_articulos.php:96` (all:561, get:831); `ExcelHierarchyService.php:55` (get:239,317,491); wrappers `tarif_opcional_familia.php:25`, `tarif_catalogo_def_familia.php:214,243,272`, `tarif_tarifa_opcional_familia.php:231` (hydrate from plain `familias` rows — no ext join of their own).
2. **VARI orphan** (in `familias`, no tarifa row): **recommendation:** backfill a `DEF` row reusing the `migrate_existing_familias()` SQL shape; leaving it as-is is acceptable.
3. **FK/CASCADE implications:** confirm whether a DB-level FK `tarif_familia_ext` → `familias` constrains deletion flows during transition; keep the explicit delete-then-cleanup order as today.
4. **Test placement:** writer sites are `plugins/tarifario/` code, so tests belong in `plugins/tarifario/tests/` (existing `Services/ExcelHierarchyService*Test.php`, `Integration/LegacyImportRegressionTest.php`); catalogo_core's `TarifFamiliasAddFamiliaTest` already covers the target flow.

## Dependencies

- None external. `tarifario` already declares `catalogo_core` as a plugin dependency; the models it needs stay in `plugins/catalogo_core/model/`.

## Success Criteria

- [ ] All four verified familia write paths write `familias` (base) + `tarif_tarifa_familia` only; zero new writes to `tarif_familia_ext`
- [ ] Familias created by the `tarif_articulos.php` import flows appear on the tarifa families page for the imported tarifa
- [ ] `tarif_familia_ext` row counts unchanged by this change (historical data preserved)
- [ ] All read-only consumers keep working (`Plugins` suite green)
- [ ] Stale `catalog-domain-models` scenario amended (no references to the deleted alias file)
