# Archive Report: retire-tarif-familia-ext

**Archived**: 2026-09-08
**Destination**: `openspec/changes/archive/2026-09-08-retire-tarif-familia-ext/`
**Mode**: openspec (core `openspec/` — change spans two plugin repos, `tarifario` and `catalogo_core`; core tree was the neutral home)
**Verdict at close**: **PASS** (`verify-report.md` Final Verification: 0 blockers, 0 CRITICAL, 9/9 requirements, 18/18 scenarios, validator-admitted evidence revision `sha256:e342738a65669fccbd74d89358ffbcbb839e78de3af2910fe8e2e656a3d13530`, captured 2026-09-08)
**Task Completion Gate**: tasks.md 17/17 checked — passed with no reconciliation needed.

## What Shipped

`tarif_familia_ext` is retired as a write path: every familia-creating flow now writes exactly two rows (base `familias` via `\FSFramework\model\familia` + per-tariff hierarchy via `tarif_tarifa_familia::save()`). `FSFramework\model\tarif_familia` remains as a `@deprecated` read-only wrapper; the ext table and its FK are untouched as historical data. No schema changes; no data deletion.

### Commits (3 repos)

| Repo | Commit | Content |
|------|--------|---------|
| `plugins/catalogo_core` | `a93926f` | WU1: `tarif_familia` write surface removed (134−/5+), `@deprecated`; reads + ext LEFT JOINs preserved |
| `plugins/tarifario` | `5e6851c` | WU1: writer #3 (`tarif_catalogo_view.php::process_familias_batch`) → base `familia` (FQCN), ext write dropped |
| `plugins/tarifario` | `1f8a702` | WU2: `ExcelHierarchyService` tier-1/2 SQL retargeted to `tarif_tarifa_familia` + fallback leg (AD-5); constructor → base `familia`; 2 FIELD_CATALOG `table` entries (AD-8.3) |
| `plugins/tarifario` | `a79c713` | WU3: writers #1/#2 (`tarif_articulos.php` JSON import + batch) → base `familia` + tarifa row (AD-6/AD-7) |
| `plugins/tarifario` | `f48702d` | Remediation: writer #1 codtarifa chain + helpers (`resolve_import_codtarifa()`, `new_tarifa_familia_row()`, `upsert_tarifa_familia_row()`), re-scoped region tests, writer #2 checked save, writer-site zero-ext-write sweep test, docblock refresh (closes verify CRITICALs C1/C2, W1/W2, W3) |
| `plugins/tarifario` | `cd0b2da` | Micro-slice: `TarifFamiliaHistoricalExtReadTest` (M1) + `TarifOpcionalFamiliaConsumerReadTest` (M3) |
| `plugins/catalogo_core` | `3bae543` | Micro-slice: `FamiliaStaleCacheResolutionTest` (M2) |
| parent | `d9962079` | proposal/design/tasks/specs |
| parent | `a807018c` | tasks marked complete; base CDM spec S1 amendment (apply-time) |
| parent | `53f23862` | remediation apply-progress + TFH-7 wording fix + tracked FAIL verify report |
| parent | `d4be7334` | micro-slice apply-progress §7 + re-verification report |
| parent | `5993ac4d` | final verification PASS 18/18 |
| parent | *(this archive commit)* | spec merges (TFH new spec + CDM delta applied), archive move, this report |

### Verification history (evidence revision trail)

1. Original verify: **FAIL** — 3 CRITICALs (C1 writer #1 undefined `$codtarifa_import` / silently skipped tarifa write; C2 false-positive whole-file-grep tests; C3 missing apply-progress TDD evidence) + W1/W2/W3. Full bytes: `53f23862…:openspec/changes/retire-tarif-familia-ext/verify-report.md`.
2. Re-verification after remediation: **FAIL solely on 3 PARTIAL coverage gaps** (0 criticals; 15/18). All 6 remediation findings closed.
3. Micro-slice closed the 3 partials with 3 test files (tarifario `cd0b2da`, catalogo_core `3bae543`, parent `d4be7334`).
4. Final verification: **PASS — 18/18 scenarios, 9/9 requirements, 0 CRITICAL, 0 blockers**, committed at parent `5993ac4d`. Evidence revision `sha256:e342738a…`.

### Test baselines at close (final state — supersedes intermediate snapshots)

Per the Final-State Authority hierarchy, these numbers come from the orchestrator's final-state handoff and the final verify run, not from earlier snapshots:

- `plugins/tarifario`: 179 tests / 649 assertions, 1 failure / 3 skipped — the 1 failure is the **documented PRE-EXISTING** `VentasArticulosQuickCreateGateCompositionTest::testUnassignedEditorQuickCreateIsDeniedByRealListener` (RED-committed at `87b8a4a` in a prior ventas_articulos permission-gate work stream — **NOT this change**).
- `plugins/catalogo_core`: 269 tests / 584 assertions, OK, 25 pre-existing warnings (untouched `model/core/articulo.php`), 1 skipped.
- Change-scope combined run: 71/71 green (63 prior + 8 micro-slice), exit 0.
- Drift invariant: `tarif_familia_ext` frozen at **233 rows** across every probe (apply, remediation, both verify runs, final). `tarif_tarifa_familia` reads 239 (data-dependent, the change's legitimate write target; verify-report SUGGESTION 6 notes a 238→239 move between 2026-09-07 and 2026-09-08 consistent with out-of-band manual activity — benign for this change).

## Spec Merge Summary (this archive)

| Domain | Action | Details |
|--------|--------|---------|
| `tarifa-familia-hierarchy` | **Created** `openspec/specs/tarifa-familia-hierarchy/spec.md` | New capability spec (7 requirements / 14 scenarios). Mechanical `cp` of the delta (pre-move `diff -r` empty, exit 0). House header adjustments: removed the change-preamble lines (Change:/Design refs:) that would dangle post-archive. Folded the archive-time spec suggestion (verify SUGGESTION 1 / orchestrator task 3): TFH Req 1 wording tightened — the prohibition on base-object writes targets `capitulo` only; `madre` is legitimately set as the native `familias.madre` column by every writer (and carried on the per-tariff row). Body kept in English, consistent with house precedent for delta-born specs (`htmx-crud`, `htmx-core-support` are English). |
| `catalog-domain-models` | **Updated** `openspec/specs/catalog-domain-models/spec.md` | CDM-06 table row replaced: stale "plugins dependientes sobrescriban su lógica" override semantics → deterministic base resolution for `familia` (per the delta MODIFIED text). Scenario "familia resolves deterministically to the catalogo_core base" normalized English → Spanish house style + an **AND** leg for load-order/aliasing independence; **added** scenario "Stale caches cannot resurrect the override". **Added CDM-12** (`tarif_familia` deprecated read-only model) to the requirements table + its 2 scenarios ("Read-only consumers unaffected", "Model carries no write surface"), normalized to Spanish. Requirement count 11 → 12. All other requirements/scenarios preserved untouched. |

**Language normalization flag**: the main `catalog-domain-models` spec is Spanish while the delta was English — the merged CDM-06/CDM-12 sections were normalized to the main spec's Spanish house style (RFC 2119 keywords and technical identifiers kept as-is), per the archive instructions. The TFH main spec remains in English (new-spec copy per instruction); the two specs therefore differ in language, matching the pre-existing tree (Spanish `catalog-*` specs, English `htmx-*` specs). TFH also keeps the delta's named-requirement format (no ID requirements table), unlike the HCS/CRD specs — a format divergence recorded here for future spec curators.

## Final-State Notes (known repo state at archive)

1. `plugins/catalogo_core` is on branch `feat/htmx-crud-4-reorder` with **PRE-EXISTING uncommitted WIP** (`View/tarif_familias.html.twig`, `controller/tarif_familias.php`, `model/tarif_tarifa_familia.php`) and an untracked `tests/TarifFamiliasAddFamiliaTest.php` — not this change's files; left untouched.
2. `plugins/tarifario` has 2 unrelated uncommitted model files (`model/tarif_opcional_precio_historial.php`, `model/tarif_precio_historial.php`) — left untouched (verify SUGGESTION 5 asks for a separate commit by their owner).
3. Parent repo is ahead of `origin/master` and carries unrelated modifications (`README.md`, `opencode.json`) — left untouched by the archive commit.
4. The `import_json` action (writer #1, `tarif_articulos.php`) is dispatch-reachable but **UI-orphaned**; dead-code removal is future work (below).

## Delivery Decision (recorded, NOT executed)

- `delivery_strategy`: **ask-on-risk**
- `chain_strategy`: **stacked-to-main**
- Suggested split (from tasks.md Review Workload Forecast, ~250–320 lines, Medium 400-line budget risk): **PR 1** = model retirement (`tarif_familia.php`) + writer #3 + tier SQL; **PR 2** = writers #1/#2 + tests.
- **No PRs were created by the archive phase.** Delivery is a separate human decision after archive.

## Deployment Notes

None required:
- No schema changes, no DDL, no data migration in code (AD-5 / design §7); `tarif_familia_ext` and its FK remain as historical data.
- Stale/poisoned `tmp/*model_class_map.php` caches self-validate: the autoloader evicts dead entries and re-resolves `familia` deterministically to the base implementation (runtime-proven by `FamiliaStaleCacheResolutionTest`) — no cache-flush step needed on deploy.

## Future Work (open follow-ups at close)

1. **VARI optional backfill SQL** (design §7 / AD-4): idempotent one-shot `INSERT INTO tarif_tarifa_familia … WHERE codfamilia = 'VARI'` on operator consent only; two self-heal paths already cover it at runtime.
2. **`limpiar_todo()` re-index on `tarif_tarifa_familia`** (AD-8): the destructive maintenance flow still indexes "which familias belong to tarifario" via ext and bulk-deletes ext; post-change familias are invisible to that index (fewer-not-more deletions, accepted).
3. **DB-backed writer integration test** for a writer flow (verify suggestion, both runs).
4. **Possible removal of the UI-orphaned `import_json` action** (writer #1) — dispatch-reachable, no UI entry point.
5. Housekeeping (from verify SUGGESTION 5): commit the 2 unrelated `tarifario` model files separately; confirm the extra `tarif_tarifa_familia` row (238→239) is expected operator activity.

## Archive Contents

- `proposal.md` — intent, problem statement (9 evidence items), scope, risks, rollback, open questions
- `design.md` — AD-1..AD-8, D-1..D-4, file changes, testing strategy, VARI backfill SQL, risks
- `tasks.md` — 17/17 complete
- `apply-progress.md` — original apply backfill (WU1–WU3), remediation slice (R1–R3), micro-slice (M1–M3)
- `verify-report.md` — full verification trail (original FAIL preserved; re-verification; final PASS 18/18)
- `specs/tarifa-familia-hierarchy/spec.md`, `specs/catalog-domain-models/spec.md` — delta specs (source of the merges above)

## Mechanical Archive Verification

- Delta → main-spec copy: `diff -r` empty (exit 0).
- Change-folder move (`git mv` to `openspec/changes/archive/2026-09-08-retire-tarif-familia-ext/`): pre-move recursive snapshot vs destination `diff -r` **empty (exit 0)** — byte-identical; this report is the only additive file and is excluded from the comparison.
