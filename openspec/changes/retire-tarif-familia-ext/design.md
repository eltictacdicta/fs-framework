# Design: retire-tarif-familia-ext

**Change**: `retire-tarif-familia-ext` — retire `tarif_familia_ext` as a write path; converge every familia-creating flow on base `familia` + `tarif_tarifa_familia` (proposal option 2).
**Phase**: `sdd-design` (post-proposal, pre-spec/tasks).
**Artifact store**: `openspec` (core `openspec/changes/retire-tarif-familia-ext/` — orchestrator-designated; the change spans two plugin repos, `tarifario` and `catalogo_core`, so a core-tree change entry is the neutral home).
**Style reference**: `plugins/tarifario/openspec/changes/archive/2026-09-03-tarifario-catalogo-hook-integration/design.md` (AD-xx / R-xx / D-xx).
**Capabilities targeted**: new `tarifa-familia-hierarchy`; modified `catalog-domain-models` (stale scenario, spec phase).

## 1. Context (verified against code + live DB)

- **Legacy model** `plugins/catalogo_core/model/tarif_familia.php` (429 lines, `class tarif_familia extends \FSFramework\model\familia`, table `familias`): write surface = `save()` (211–239: familias upsert → `save_extension()` 87–101 → recursive `actualizar_niveles_hijas()` 244–249), `delete()` (251–269: explicit `delete_extension()` 107–111 → NULL hijas' madre → orphan-ext `nivel` cleanup → DELETE familias), `load_extension()` 58–68 (never invoked), `ext_exists()` 74–81 (only used by `save_extension`). Read surface = `get()` 126, `get_madre()`, `get_hijas()` 154, `hijas()` 175, `all()` 275, `all_simple()` 316, `search()` 337, `all_by_capitulo()` 364, `suggest_capitulo()` 384 — all `LEFT JOIN tarif_familia_ext e` adding only `capitulo`/`nivel`.
- **Base model** `plugins/catalogo_core/model/core/familia.php`: table `familias`, persists only `codfamilia`/`descripcion`/`madre` (`save()` 167–187; `nivel` is a display property computed in `all()`/`aux_all()`, never persisted); `test()` caps `codfamilia` ≤8 chars, `descripcion` ≤100; `install()` seeds VARI (line 81).
- **Target pattern** `tarif_familias.php::add_familia_to_tarifa()` (654–745): `require_once` + `new \FSFramework\model\familia()` upsert → `tarif_tarifa_familia::add_familia_to_tarifa()` (model 636–651, capitulo via `suggest_capitulo`); the same flow's `delete_familia()` (813–851) deletes ONLY the per-tariff row, never the base familia. Lazy self-heal precedent: `tarif_articulo_edit.php::ensure_tarifa_family_available()` (207–241) adds the tarifa row on first need.
- **Four writer sites** (all `tarif_familia::save()`): #1 `tarif_articulos.php::import_tarifa_json()` (model 1170, get 1194, new 1198, save 1206; capitulo from JSON 1192); #2 `tarif_articulos.php::process_familias_batch()` (1583–1628, save 1614); #3 `tarif_catalogo_view.php::process_familias_batch()` (2522–2619: `tarif_familia` save 2561 **and** `tarif_tarifa_familia` save 2578 with flags `activa/en_tarifa/en_catalogo` = JSON ?: TRUE, orden JSON ?: 0); #4 `ExcelHierarchyService::createFamiliaRow()` (447–478: `$familia->save()` 461 incl. `$familia->capitulo` at 460 → ext, then tarifa row 465–475 with `en_tarifa=true` hard-coded 471). Writers #1/#2 have **no tarifa-side write and no `codtarifa` resolution** today; the batch flow's tarifa resolution precedent: `tarif_articulos.php:1431–1443` (`$_POST['codtarifa']` → `get_default()` → `'DEF'`, stored in `$_SESSION['import_codtarifa']`).
- **Read-only consumers verified** (proposal list re-checked, all consume only `codfamilia`/`descripcion`/`madre` — **none reads `capitulo`/`nivel`** off a `tarif_familia` object): `tarif_articulo_edit.php:55,227` (+ `ensure_tarifa_family_available`), `tarif_opcionales.php:66,332–363` (map: desc/madre only), `tarif_opcional_precios.php:54`, `tarif_opcional_edit.php:56,210`, `tarif_actualizar_precios.php:130,326` (`hijas()`→codfamilia), `,482` (get→descripcion), `tarif_catalogo_view.php:1220` (get→descripcion), `tarif_articulos.php:96,561` (template export: cod/desc), `,831` (exists-check). Wrapper models hydrate `tarif_familia` from **plain `familias` rows** (no ext join of their own; `capitulo` hydrates to `''`): `tarif_opcional_familia.php:25`, `tarif_catalogo_def_familia.php:214,243,272`, `tarif_tarifa_opcional_familia.php:231`. Additions found beyond the proposal's list (all non-writer, non-breaking): `tarif_configurador_opcionales.php:23,45` (vestigial `use`/`require`, reads the tarifa table at 170), `catalogo_core/Init.php:73–94` (schema-sync instantiation of `tarif_familia_ext`), `ExcelImportWizardService::FIELD_CATALOG` (196, 220: metadata-only — consumers at 267/292 use `aliases`/`labels` only).
- **Catalog views/exports already per-tariff**: every `familias_map` in `tarif_catalogo_view.php` is built from `tarif_tarifa_familia->all_from_tarifa($codtarifa)` (2023, 3178, 3443, 3617) — `capitulo` for exports comes from the tarifa table today.
- **Live schema (ddev, SHOW CREATE TABLE)**: `tarif_familia_ext` — PK(`codfamilia`), single FK `ca_tarif_familia_ext_familia` → `familias(codfamilia)` **ON DELETE CASCADE ON UPDATE CASCADE**; no reverse FK on `familias`. `tarif_tarifa_familia` — FKs `ca_tarif_tarifa_familia_familia` → `familias` (CASCADE) and `ca_tarif_tarifa_familia_tarifa` → `tarif_tarifas` (CASCADE). **No RESTRICT anywhere in the trio.**
- **Live drift (ddev)**: familias=238, ext=233, tarifa(DEF)=237; FAMILIA2/HOLA/HOLA2/WWW in tarifa table but not in ext; **VARI** in `familias` with no ext row and no tarifa row. VARI has no code dependency (only the `familia::install()` seed).
- **Autoloader (OQ1c)**: `base/fs_model_autoloader.php` `ensureGlobalAlias` (269–278) aliases model **short names** generically (`familia` → `FSFramework\model\familia`; `MODEL_NAMESPACES` at 34–37). `tarif_familia` is a distinct short name with a single declaration (repo-wide grep); **no `class_alias` targets it**; keeping or deleting the file cannot affect the `\familia` global alias (deterministic to base since `plugins/tarifario/model/familia.php` was deleted in the 2026-09-03 change). Zero callers of `tarif_familia::delete()` exist (grep, both plugins); `save()` callers are exactly the four writer sites + the internal recursion at 247.
- **Test infra**: `plugins/tarifario/phpunit.xml` (bootstrap `../../tests/bootstrap.php`, suite `tarifario`, `processIsolation="true"`); root `phpunit.xml` Plugins suite auto-discovers `plugins/*/tests/**` with a documented exclusion list (4 tarifario files excluded for `fs_db2` anonymous-mock `exec()` signature conflicts). Existing coverage to extend: `tests/Services/ExcelHierarchyService{UpsertFamilia,CreateFamiliaRow,ApplyRowHierarchy}Test.php`, `tests/Integration/{FamiliaOverrideRemovalTest,LegacyImportRegressionTest}.php`; target-flow helpers covered in `plugins/catalogo_core/tests/TarifFamiliasAddFamiliaTest.php`. `FamiliaOverrideRemovalTest:96` pins exactly **2** occurrences of `new familia()` in `tarif_catalogo_view.php` (sites 4724/4737) — migrated code must use the FQCN form `new \FSFramework\model\familia()` to leave that canary intact.
- **Proposal factual check**: consumer list and drift numbers verified accurate. Two enrichments (not errors): (a) `ExcelHierarchyService` tier-1/tier-2 matching SQL **reads** `tarif_familia_ext.capitulo` (331–336, 359–363) — a write-path dependency the proposal's consumer list does not surface (§ AD-5); (b) `limpiar_todo()` (`tarif_articulos.php:2068–2099`) uses ext as its "which familias belong to tarifario" index and bulk-`DELETE`s ext (AD-8).

## 2. Goals / Non-Goals

| In scope | Out of scope |
|---|---|
| Migrate writers #1/#2 (JSON import) to base `familia` + `tarif_tarifa_familia` | Dropping/truncating `tarif_familia_ext` or any data deletion |
| Drop the ext write in dual-writers #3/#4 | Migrating read-only `tarif_familia` consumers to per-tariff reads |
| Retire `tarif_familia` write surface (OQ1); keep reads | Changing `VentasFamilia` (writes only `familias` — intended) |
| Retarget `ExcelHierarchyService` capitulo-matching SQL (AD-5) | `limpiar_todo()` re-index (AD-8); `migrate_existing_familias()` hook removal |
| VARI decision (OQ2); FK confirmation (OQ3); test plan (OQ4) | PHP-coded data backfills / new schema objects |

## 3. Architecture decisions

| ID | Decision | Alternatives considered | Choice / rationale |
|---|---|---|---|
| **AD-1** (OQ1) | **Keep** `FSFramework\model\tarif_familia` as an `@deprecated` **read-only** class. Remove `save()`, `delete()`, and the write-only helpers (`save_extension`, `delete_extension`, `load_extension`, `ext_exists`, `calcular_nivel`, `actualizar_niveles_hijas`). Keep every read method, including the ext LEFT JOINs. | (a) Delete the class — breaks 13 verified read-only consumers incl. the three wrapper models that `new tarif_familia($d)` from plain rows; (b) keep write methods but make them throw — louder for callers, but leaves 100+ lines of dead SQL that still targets a retired table, and the post-migration call-graph has zero callers to protect. | Removal is the house retirement pattern (AGENTS.md: CookieSigner/SecretManager precedents — "update callers directly"); a removed method fails loudly (`undefined method`) exactly where a throw would. Class-level `@deprecated` docblock states: read-only historical API; write via `\FSFramework\model\familia` + `tarif_tarifa_familia`. |
| **AD-2** (OQ1b) | Keep `get()/all()/hijas()/…` reading the ext table **as historical data, unchanged**. | Drop the ext join and read plain `familias` — would silence the historical capitulo/nivel columns for any un-audited view and churn every read method for zero verified gain. | All consumers spot-checked consume only `codfamilia`/`descripcion`/`madre`; the LEFT JOIN keeps rows present even when ext rows are absent, so no consumer loses rows either way. Keeping the join = zero risk, zero diff. `suggest_capitulo()` stays (harmless read, no callers). |
| **AD-3** (OQ1c) | No autoloader/class-alias action required. The file stays where it is; `\familia` remains deterministically base. | Re-home `tarif_familia` into `tarifario` — pure churn for a class catalogo_core owns (its `Init.php` syncs the ext table). | Evidence in §1; single declaration, no alias dependency. |
| **AD-4** (OQ2) | **VARI orphan: leave as-is in code.** Two existing self-heal paths cover it: the "Añadir familia" datalist (`load_familias_disponibles()`, `tarif_familias.php:898–917` lists base familias missing from the tarifa) and `ensure_tarifa_family_available()` (article-edit auto-link). The tarifa families page degrades gracefully (VARI simply absent from the tree; no empty-state break). Document an **optional operator backfill** SQL (migrate-shape, idempotent) in §7 — not part of the PHP diff. | PHP-coded backfill reusing `migrate_existing_familias()` — `install()` (model 126–131) only runs at table creation, so there is no honest trigger point; inventing one adds code and a data write to a code-only change. | Verified: no code depends on VARI having a tarifa row (only the install seed); both heal paths are user-triggered and cheap. Operator can opt into the one-shot SQL during verify. |
| **AD-5** (OQ3 + discovered dependency) | **No schema/FK change.** FK evidence confirms CASCADE in the safe direction. Because ext stops receiving writes, retarget the two `ExcelHierarchyService` ext-join reads: tier-1 collision SQL (331–336) and tier-2 match SQL (359–363) match capitulo from `tarif_tarifa_familia` scoped to the import `codtarifa`, with a fallback leg matching (madre, descripcion) for familias that have **neither** a tarifa row in this tarifa nor an ext row (covers VARI-like orphans and cross-tarifa re-import without creating duplicates). Tier-3 (descripcion-only) untouched. | (a) Leave the ext joins — tier-2 goes blind to post-change familias and re-imports into the same tarifa would create duplicate familias (R10.2 collision guard also goes blind); (b) dual-write capitulo back to ext — violates the "zero new ext writes" contract; (c) tarifa-scoped match with no fallback — cross-tarifa re-import of an existing unlinked familia would fabricate a duplicate `familias` row. | The tier SQL is part of writer #4's contract (it decides create-vs-match); retiring the ext write without retargeting it silently degrades the import guard pinned by R10.2. Single-match semantics and the `codfamilia !=` self-exclusion clause are preserved, so the R10.2 canary stays green. Cross-tarifa semantics documented in §4. |
| **AD-6** (OQ4 flag semantics) | Preserve each flow's current defaults; writers #1/#2 (no prior tarifa-side write) adopt writer #3's JSON defaults for the **same import format**: `activa = JSON ?: true`, `en_tarifa = JSON ?: true`, `en_catalogo = JSON ?: true`, `orden = JSON ?: 0`; capitulo/madre copied from the JSON exactly as each writer today writes them to ext/tarifa; `nivel` left to `tarif_tarifa_familia::save()` (`calcular_nivel()` recomputes it at 249 — JSON `nivel` was display-only there too). | Force `en_tarifa=false` on #1/#2 (conservative) — the same JSON file would then import differently through `tarif_catalogo_view` vs `tarif_articulos`; normalizing #4 to false — explicitly rejected by the proposal (preserve, do not normalize). | #3 processes the identical `data['familias']` payload with these defaults; the historical `migrate_existing_familias()` SQL keeps `en_tarifa=FALSE` and is untouched. Net divergence table stays: Excel wizard = true; JSON imports (all three sites) = JSON-override/true; historical migration = false (frozen). |
| **AD-7** (OQ4 writer-codtarifa) | Writer #1 (`import_tarifa_json`, action `import_json`) resolves the target tarifa exactly like the batch start flow (`tarif_articulos.php:1431–1443`): `$_POST['codtarifa']` → `tarif_tarifa::get_default()` → `'DEF'`, mirrored into `$_SESSION['import_codtarifa']`. Writer #2 keeps using the session value it already resolves (1638–1643). | Hard-code `'DEF'` — silently re-parents non-default imports; leave #1 without a tarifa row — violates the success criterion ("imported familias appear on the tarifa families page"). | Same-file, same-flow consistency; the batch UI already posts `codtarifa`. |
| **AD-8** (discovered ext touchers) | Kept as-is, documented: (1) `limpiar_todo()` (`tarif_articulos.php:2068–2099`) keeps using ext as its familias index and bulk-deleting ext — operator-triggered destructive flow, out of scope. Consequence (accepted): post-change imported familias are invisible to that index (same class as `VentasFamilia`-created familias today); their tarifa rows are wiped by the tarifa-table step (2050), leaving linked-to-nothing familias the model already supports. Future work: re-index on `tarif_tarifa_familia`. (2) `tarif_familia_ext` model + `catalogo_core/Init.php:93–94` schema sync stay (they only ensure table existence). (3) `FIELD_CATALOG` entries `cap_familia`/`cap_subfamilia` (196, 220) update `'table'` to `'tarif_tarifa_familia'` — metadata-only (`suggestMapping`/`fieldOptions` consume aliases/labels; canary pins key names, not values). | Removing the limpiar ext-delete would orphan ext rows mid-wipe; changing FIELD_CATALOG table values was canary-risk — checked: canary asserts key names only. | Keeps the destructive flow's blast radius conservative (deletes *fewer* familias post-change) and the metadata truthful without runtime impact. |

## 4. Structure decisions (target write flow per site)

**D-1 — Contract (both capabilities)**: after this change, every familia-creating flow performs exactly two writes — `familias` (via base `familia::save()`) and `tarif_tarifa_familia` (via `save()`). Zero new `INSERT/UPDATE/DELETE` against `tarif_familia_ext` from any of the four sites. Families imported through `tarif_articulos.php` acquire a tarifa row (flags per AD-6) and therefore appear on the tarifa families page for the imported tarifa.

```
Site #1/#2 (tarif_articulos.php, JSON import)          Site #3 (tarif_catalogo_view.php)
  JSON fam_data {codfamilia,descripcion,madre,capitulo}   same payload, batched chunks
      │ get() on base familia (upsert check)                  │ (unchanged)
      ▼                                                       ▼
  new \FSFramework\model\familia()  ──save()──▶ familias   base familia save (ext write DROPPED)
      │                                               │       ▼
      ▼                                               │   tarif_tarifa_familia save (kept)
  new tarif_tarifa_familia() ──save()──▶ tarif_tarifa_familia
      (codtarifa per AD-7; madre/capitulo from JSON;
       flags per AD-6; nivel recomputed by model)
```

**D-2 — Site #4 (`ExcelHierarchyService`)**: constructor `familiaModel` → `new \FSFramework\model\familia()`; `setFameliaModel()` type widens `tarif_familia` → `familia` (existing `tarif_familia` mocks still typecheck — subtype); `createFamiliaRow()` drops `$familia->capitulo` (dynamic property on base is forbidden PHP 8.2+ — capitulo goes only to the tarifa row); tarifa-side write (465–475) unchanged (`en_tarifa=true` divergence preserved, canary-tested). Tier-1/tier-2 SQL per AD-5.

**D-3 — Site #3 detail**: `process_familias_batch` swaps `new tarif_familia()` (2525, 2551) → `new \FSFramework\model\familia()` (FQCN form — keeps `FamiliaOverrideRemovalTest:96`'s count-of-2 canary intact), drops `$familia->capitulo` (2557), keeps `$familia->clean_errors()` (2559), keeps everything from the tarifa write (2563–2578) and etiquetas (2581–2595) untouched. Error path (2602–2614) unchanged.

**D-4 — Rollback**: revert-only. No DDL, no data writes by the change; writers return to `tarif_familia::save()` on `git revert` and the ext table + FK are untouched, so legacy writes keep working. Optional VARI backfill SQL (if the operator ran it) is additive and harmless to keep.

**Threat matrix**: N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary (in-process PHP + existing PHPUnit runners).

## 5. File changes

| File | Action | Summary |
|---|---|---|
| `plugins/catalogo_core/model/tarif_familia.php` | Modify | AD-1: class `@deprecated`; remove `save/delete/save_extension/delete_extension/load_extension/ext_exists/calcular_nivel/actualizar_niveles_hijas`; keep all reads with ext LEFT JOIN. |
| `plugins/tarifario/controller/tarif_articulos.php` | Modify | Writers #1 (114–1216 region) and #2 (1583–1628): base familia upsert + tarifa row (AD-6/AD-7); no `capitulo` on the familia object. |
| `plugins/tarifario/controller/tarif_catalogo_view.php` | Modify | Writer #3: base familia (FQCN), drop ext write; tarifa write + etiquetas untouched (D-3). |
| `plugins/tarifario/Services/ExcelHierarchyService.php` | Modify | Writer #4: constructor/setter model swap; `createFamiliaRow` drops familia-capitulo; tier-1/tier-2 SQL retarget (AD-5). |
| `plugins/tarifario/Services/ExcelImportWizardService.php` | Modify | 2 FIELD_CATALOG `table` entries → `tarif_tarifa_familia` (AD-8.3). |
| `plugins/tarifario/tests/Services/ExcelHierarchyServiceUpsertFamiliaTest.php` | Modify | Updated tier SQL expectations; NEW: fallback-leg match, legacy-ext COALESCE match, R10.2 canary re-verified. |
| `plugins/tarifario/tests/Services/ExcelHierarchyServiceCreateFamiliaRowTest.php` | Modify | Mocks extend base `familia`; assert no dynamic `capitulo` on the familia model; `en_tarifa=true` pin stays. |
| `plugins/tarifario/tests/Integration/TarifFamiliaWriteRetirementTest.php` | New | `method_exists` negatives for `save/delete/save_extension/…` on `tarif_familia`; class loads; `@deprecated` marker; source-grep: no ext INSERT/UPDATE outside the ext model (source-inspection pattern of `FamiliaOverrideRemovalTest`). |
| `plugins/tarifario/tests/Controller/TarifArticulosFamiliaImportTest.php` | New | Structural tests for writers #1/#2: base familia + tarifa model used; codtarifa resolution chain (AD-7); flags per AD-6; `capitulo` assigned to the tarifa row only. |
| `openspec/changes/retire-tarif-familia-ext/specs/**` | New (spec phase) | Delta specs: `tarifa-familia-hierarchy` (new), `catalog-domain-models` (stale scenario → disposition of `tarif_familia`). |
| `openspec/specs/catalog-domain-models/spec.md` | Modify (at archive) | Scenario at lines 44–49 replaced (references deleted `plugins/tarifario/model/familia.php`). |

## 6. Testing strategy & verification approach (OQ4)

Strict TDD per `openspec/config.yaml` (`strict_tdd: true`, `apply.tdd: true`). All commands through ddev.

| Layer | What | How |
|---|---|---|
| Unit (RED→GREEN) | Tier-1/tier-2 retarget, fallback leg, `createFamiliaRow` contract, writer flag defaults | Extend the three `ExcelHierarchyService*Test` files; mocks extend base `familia`; db mock overrides `select()` only (UpsertFamilia pattern — keeps root-suite compatibility; the four excluded files show the `exec()`-signature trap). |
| Structural | Writers #1/#2/#3 source shape; write-surface retirement | `TarifArticulosFamiliaImportTest`, `TarifFamiliaWriteRetirementTest` (source-inspection + `method_exists`, established pattern). |
| Regression | Target flow, catalog views, opcional pages | Existing suites green, unchanged: `TarifFamiliasAddFamiliaTest`, `FamiliaOverrideRemovalTest`, `LegacyImportRegressionTest` (session-gated; often skips — not a coverage source). |

**Verify-phase commands**:
```bash
ddev exec php vendor/bin/phpunit --testsuite Plugins                              # root auto-discovery
ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml                 # plugin suite (process-isolated)
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml             # target-flow regression
ddev exec php vendor/bin/phpunit                                                  # full root suite
ddev mysql -e "SELECT (SELECT COUNT(*) FROM tarif_familia_ext) ext, (SELECT COUNT(*) FROM tarif_tarifa_familia) tf;"   # read-only drift probe, before/after
```

## 7. Migration / Rollout

No data migration in code; no schema change (AD-5). Historical ext data preserved verbatim; `migrate_existing_familias()` install hook untouched. **Optional operator backfill for VARI** (idempotent, migrate-SQL shape — run once via ddev, only on operator consent):
```sql
INSERT INTO tarif_tarifa_familia (codtarifa, codfamilia, madre, capitulo, nivel, en_catalogo, en_tarifa, activa)
SELECT 'DEF', f.codfamilia, f.madre, COALESCE(e.capitulo, ''), COALESCE(e.nivel, ''), TRUE, FALSE, TRUE
FROM familias f LEFT JOIN tarif_familia_ext e ON f.codfamilia = e.codfamilia
WHERE f.codfamilia = 'VARI'
  AND NOT EXISTS (SELECT 1 FROM tarif_tarifa_familia WHERE codtarifa = 'DEF' AND codfamilia = f.codfamilia);
```
Rollback: `git revert` only (D-4).

## 8. Risks

| Risk | Mitigation |
|---|---|
| **R-1** Tier-2 retarget changes create-vs-match semantics | AD-5 fallback leg + R10.2 canary (`codfamilia !=` clause preserved) + new tests for fallback and legacy-ext legs; single-match rejection semantics unchanged. |
| **R-2** Post-change familias have empty ext `capitulo/nivel` in ext-joined reads | Verified: no consumer reads those fields off `tarif_familia` objects (§1 list); catalog views/exports already read the tarifa table. Documented as the accepted cost of retirement. |
| **R-3** Unknown caller of removed `tarif_familia::save()/delete()` | Repo-wide grep (all plugins, base, src): only the four migrated sites; `delete()` zero callers; retirement test asserts the negative API. |
| **R-4** `en_tarifa` flag confusion across importers | AD-6 table: Excel=true (pinned), JSON imports=JSON-override/true, historical migration=false (frozen). No normalization. |
| **R-5** Drift worsens | Certain-direction improvement: ext stops receiving writes, so ext rows can only disappear via pre-existing cascade-driven deletes; row-count probe in verify. |
| **R-6** `limpiar_todo` misses post-change familias in its ext-index | AD-8: accepted, documented, fewer-not-more deletions; re-index listed as future work. |
| **R-7** New tests break the shared-process root suite | Mock pattern restricted to `select()` overrides (the four excluded files document the `exec()`-signature fatal); root-suite run in verify confirms. |

## 9. References

- Proposal: `openspec/changes/retire-tarif-familia-ext/proposal.md` (OQs 1–4 resolved here as AD-1/AD-4/AD-5/§6).
- Writers: `tarif_articulos.php:1170–1216,1583–1628,1431–1443`; `tarif_catalogo_view.php:2522–2619`; `ExcelHierarchyService.php:55,331–336,359–363,447–478`.
- Models: `catalogo_core/model/tarif_familia.php` (writes 87–111, 211–269; reads 126–428), `core/familia.php` (save 167–187, VARI seed 81), `tarif_tarifa_familia.php` (save 246–282, migrate 155–176, add_familia_to_tarifa 636–651).
- Target pattern: `tarif_familias.php:654–745,813–851,898–917`; `tarif_articulo_edit.php:207–241`.
- Live schema: `SHOW CREATE TABLE tarif_familia_ext / tarif_tarifa_familia` (ddev, 2026-09-07); drift probe counts in §1.
- House format: `plugins/tarifario/openspec/changes/archive/2026-09-03-tarifario-catalogo-hook-integration/design.md`.

**Status**: design frozen. All four proposal Open Questions resolved with evidence (AD-1/AD-2/AD-3 = OQ1; AD-4 = OQ2; AD-5 = OQ3 + discovered tier-SQL dependency; §6 = OQ4). Ready for `sdd-spec` (delta specs) then `sdd-tasks` (carry the AD-6/AD-7 flag/codtarifa tables, the AD-5 fallback-leg SQL, and the strict-TDD order per test file).
