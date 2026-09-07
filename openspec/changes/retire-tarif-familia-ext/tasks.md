# Tasks: Retire `tarif_familia_ext` Write Path

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~250–320 (4 source, 4 test, 1 spec) |
| 400-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Suggested split | PR 1: model retirement + writer #3 + tier SQL · PR 2: writers #1/#2 + tests |
| Delivery strategy | ask-on-risk |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Retire tarif_familia writes + writer #3 | PR 1 | `phpunit -c plugins/catalogo_core/phpunit.xml` | catalogo_core suite | Revert tarif_familia.php + tarif_catalogo_view.php |
| 2 | ExcelHierarchyService tier SQL + write contract | PR 1 | `phpunit -c plugins/tarifario/phpunit.xml --filter ExcelHierarchyService` | Excel tests | Revert ExcelHierarchyService.php |
| 3 | Writers #1/#2 (AD-6/AD-7) + final tests + spec | PR 2 | `phpunit --testsuite Plugins` | Full suite | Revert tarif_articulos.php + test/spec files |

## Phase 1: Model Retirement + Writer #3 — Commit 1

- [x] 1.1 RED: `TarifFamiliaWriteRetirementTest` — assert `!method_exists(tarif_familia::class, 'save')` — must FAIL.
- [x] 1.2 GREEN: `tarif_familia.php` — add `@deprecated`; remove `save/delete/save_extension/delete_extension/load_extension/ext_exists/calcular_nivel/actualizar_niveles_hijas`. Keep reads + ext LEFT JOINs.
- [x] 1.3 GREEN: `tarif_catalogo_view.php` `process_familias_batch` — swap `new tarif_familia()` → `new \FSFramework\model\familia()` FQCN; drop `$familia->capitulo`; keep tarifa write + etiquetas.
- [x] 1.4 Verify: retirement test green; catalogo_core + FamiliaOverrideRemoval green.

## Phase 2: ExcelHierarchyService (AD-5 + D-2) — Commit 2

- [x] 2.1 RED: `UpsertFamiliaTest` — assert tier-2 SQL references `tarif_tarifa_familia` not ext — must FAIL.
- [x] 2.2 RED: Assert fallback leg matches `(madre, descripcion)` for unlinked familias — must FAIL.
- [x] 2.3 RED: `CreateFamiliaRowTest` — assert no `capitulo` on familia model — must FAIL.
- [x] 2.4 GREEN: `ExcelHierarchyService.php` — retarget tier-1/2 SQL to tarifa table; add fallback leg; constructor → `new \FSFramework\model\familia()`; widen setter type; drop `$familia->capitulo`. Tarifa write (465–475) unchanged.
- [x] 2.5 `ExcelImportWizardService.php` — FIELD_CATALOG `table` entries → `'tarif_tarifa_familia'` (AD-8.3).
- [x] 2.6 Verify: all 3 ExcelHierarchyService test files green.

## Phase 3: Writers #1/#2 + Tests + Spec — Commit 3

- [ ] 3.1 RED: `TarifArticulosFamiliaImportTest` — assert writer #1 creates `tarif_tarifa_familia` row with AD-6 flags and AD-7 codtarifa — must FAIL.
- [ ] 3.2 RED: Assert writer #2 batch step creates tarifa row using session `codtarifa` — must FAIL.
- [ ] 3.3 GREEN: `tarif_articulos.php` writer #1 `import_tarifa_json()` (~1170–1216) — after familia upsert, save tarifa row with `codtarifa` via `$_POST['codtarifa']` → `get_default()` → `'DEF'`; AD-6 flags (activa/en_tarifa/en_catalogo=true, orden=0).
- [ ] 3.4 GREEN: writer #2 `process_familias_batch()` (~1583–1628) — session `$_SESSION['import_codtarifa']`; save tarifa row with AD-6 flags.
- [ ] 3.5 `TarifFamiliaWriteRetirementTest` — add `test_class_deprecated` and `test_read_methods_unchanged`; source-inspect zero ext writes.
- [ ] 3.6 Amend `openspec/specs/catalog-domain-models/spec.md` lines 44–49 with delta scenario.
- [ ] 3.7 Verify: `phpunit --testsuite Plugins` green; ext row count probe unchanged.
