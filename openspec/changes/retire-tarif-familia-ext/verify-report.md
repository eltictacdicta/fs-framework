```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:87f856e0efb76a80b04ea53a25de5a00968a6bff695508e5d0472cae4ac9d4d5
verdict: fail
blockers: 3
critical_findings: 3
requirements: 1/9
scenarios: 10/18
test_command: ddev exec php vendor/bin/phpunit --testsuite Plugins
test_exit_code: 2
test_output_hash: sha256:42d596a56fe34cfaf4cdfbf2976426133f6ad91f0244309988353b642edf4d63
build_command: ddev exec php -l (7 changed files, sequential)
build_exit_code: 0
build_output_hash: sha256:541c82d33d68259d029a07d553ca5436073b12f2f9f84f0068343081ba501128
```

## Verification Report

**Change**: `retire-tarif-familia-ext`
**Version**: N/A (delta specs carry no version field)
**Mode**: Strict TDD

### Completeness
| Metric | Value |
|--------|-------|
| Tasks total | 17 |
| Tasks complete (checkbox) | 17 |
| Tasks incomplete (checkbox) | 0 |
| Tasks verified as described | 13 |
| Tasks with deviation | 3 (1.1, 3.1, 3.5 — see Task Audit) |
| Task claims false | 1 (3.3 — see C1) |

### Build & Tests Execution
**Build**: ✅ Passed — `ddev exec php -l` clean on all 7 changed files, exit 0.

**Tests**: ✅ 727 passed / ❌ 1 failure + 47 errors — all pre-existing (see "Pre-existing baseline")
```text
# Root Plugins suite (auto-discovery)
Tests: 776, Assertions: 1876, Errors: 47, Failures: 1, Warnings: 1, PHPUnit Deprecations: 12, Skipped: 24. (exit 2)
Error census: 47/47 in FacturaPdf1\* ; single failure =
  Tests\Tarifario\Integration\VentasArticulosQuickCreateGateCompositionTest::testUnassignedEditorQuickCreateIsDeniedByRealListener
  (RED-committed suite 87b8a4a predates this change; unrelated subsystem)

# Plugin suites
ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
  Tests: 166, Assertions: 549, Failures: 1 (the same pre-existing quick-create test), Skipped: 3.
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
  Tests: 267, Assertions: 571, Warnings: 25 (pre-existing "Undefined array key" notices in the
  untouched model/core/articulo.php), Skipped: 1. Green.

# Targeted runs (all green)
--filter TarifFamiliaWriteRetirementTest  -> OK (12 tests, 22 assertions)
--filter ExcelHierarchy                   -> OK (34 tests, 91 assertions)
--filter TarifArticulosFamiliaImportTest  -> OK (10 tests, 20 assertions)
```
New change tests: 12 retirement + 10 writers + 3 tier SQL + 1 capitulo = 26, all passing (matches apply claim "58 change tests / 141 assertions" when combined with pre-existing coverage in those files).

**Coverage**: ➖ Not available — no coverage tool configured (phpdbg/pcov absent). Coverage analysis skipped — no coverage tool detected.

**Drift probe (read-only)**:
```sql
ddev mysql -e "SELECT (SELECT COUNT(*) FROM tarif_familia_ext) ext, (SELECT COUNT(*) FROM tarif_tarifa_familia) tf;"
  ext=233, tf=238 (all DEF)
```
ext = 233 = apply-time value → the zero-ext-write invariant held ✓. Observation: familias 238→239 and tf 237→238 vs design preflight; the new row is familia `HOLA3` ("hola3") with a DEF tarifa row and **no ext row** — consistent with a post-preflight import through the migrated flow (live evidence FOR the contract); VARI remains unlinked (expected, AD-4). Provenance unconfirmed (manual smoke assumed).

### Spec Compliance Matrix
Counts from the two delta specs: 9 requirements / 18 scenarios.

| Requirement | Scenario | Test | Result |
|-------------|----------|------|--------|
| TFH: Two-write contract | S1 Legacy-only writer persists both rows | `TarifArticulosFamiliaImportTest::test_writer1_creates_tarifa_familia_row` | ❌ FAILING — test passes but is a false positive (whole-file grep; see C1/C2); runtime inspection shows the tarifa write is silently skipped |
| TFH: Two-write contract | S2 Dual writer drops the legacy write | `TarifFamiliaWriteRetirementTest` (12) + `ExcelHierarchyServiceCreateFamiliaRowTest` | ✅ COMPLIANT |
| TFH: Zero new ext writes | S1 Imports leave ext row counts untouched | drift probe (233→233) + `ExcelHierarchyServiceUpsertFamiliaTest` tier SQL assertions | ✅ COMPLIANT |
| TFH: Zero new ext writes | S2 Historical ext data remains readable | `TarifFamiliaWriteRetirementTest::test_read_methods_preserved` | ⚠️ PARTIAL — reads + LEFT JOINs preserved (source, diff purity), but no runtime test asserts returned capitulo/nivel values |
| TFH: Page visibility | S1 Imported familia listed for imported tarifa | (writer #1 flow broken) | ❌ FAILING — C1: direct JSON import yields no tarifa row → familia invisible on the tarifa page |
| TFH: Page visibility | S2 Unlinked familias remain available | `TarifFamiliasAddFamiliaTest` (catalogo_core, green) + `load_familias_disponibles()` source + probe (VARI unlinked, offered) | ✅ COMPLIANT |
| TFH: Tier matching | S1 Per-tariff capitulo match avoids duplicates | `ExcelHierarchyServiceUpsertFamiliaTest` tier-1/2 tests + R10.2 canary | ✅ COMPLIANT |
| TFH: Tier matching | S2 Fallback leg matches an unlinked familia | `test_tier2_fallback_matches_madre_descripcion_for_unlinked_familia` | ✅ COMPLIANT |
| TFH: Flag defaults | S1 JSON defaults apply to legacy-only writers | `TarifArticulosFamiliaImportTest` flag tests | ⚠️ PARTIAL — code correct (verified at 1218–1221, 1649–1652) but writer #1's covering test is a weak whole-file grep |
| TFH: Flag defaults | S2 Excel divergence preserved | `test_en_tarifa_flag_set_to_true_diverging_from_csv_importer` | ✅ COMPLIANT |
| TFH: codtarifa resolution | S1 Posted codtarifa honored | `test_writer1_resolves_codtarifa_via_post` | ❌ FAILING — false positive (needle matches the upload function at 1448, not writer #1); writer #1 reads an undefined variable (C1) |
| TFH: codtarifa resolution | S2 Missing codtarifa falls back | `test_writer2_uses_session_codtarifa` | ✅ COMPLIANT — session → get_default() → 'DEF' chain verified at 1608–1613 |
| TFH: read-only API | S1 Write surface removed, fails loudly | `TarifFamiliaWriteRetirementTest` (8 negative source tests) | ⚠️ PARTIAL — all 8 extension helpers removed (fail loudly ✓); save/delete remain *inherited* from base `familia` (callable; write only `familias`, ext unreachable) — scenario wording "no write method exists / stale caller fails loudly" is literally unfulfillable under inheritance (W1) |
| TFH: read-only API | S2 Read surface unchanged | `test_read_methods_preserved` + commit `a93926f` diff purity | ✅ COMPLIANT |
| CDM: CDM-06 | S1 familia resolves deterministically to base | `FamiliaOverrideRemovalTest::test_no_global_familia_declaration_in_plugin_tree` + cold-cache resolution | ✅ COMPLIANT |
| CDM: CDM-06 | S2 Stale caches cannot resurrect the override | `FamiliaOverrideRemovalTest` (clearCache setUp/tearDown) | ⚠️ PARTIAL — cleared-cache leg exercised at runtime; stale-cache leg not simulated |
| CDM: CDM-12 | S1 Read-only consumers unaffected | suite-level regression only (tarifario 166 + catalogo_core 267 green) | ⚠️ PARTIAL — no direct consumer-level runtime test; static verification of all 13 consumer sites |
| CDM: CDM-12 | S2 Model carries no write surface | `TarifFamiliaWriteRetirementTest` (source negatives + @deprecated reflection) | ✅ COMPLIANT |

**Compliance summary**: 10/18 scenarios compliant, 5 partial, 3 failing.

### Correctness (Static Evidence)
| Requirement | Status | Notes |
|------------|--------|-------|
| Two-write contract | ⚠️ Implemented with defect | Writers #2/#3/#4 correct (source verified); writer #1 broken (C1) |
| Zero new ext writes | ✅ Implemented | All 4 writer sites + service: no ext INSERT/UPDATE/DELETE; only SELECTs + the AD-8-documented `limpiar_todo` bulk DELETE (tarif_articulos.php:2124, inside limpiar_todo 1854–2176) |
| Page visibility | ⚠️ Implemented with defect | tarifa page lists via `all_from_tarifa()` (tarif_familias.php:874); writer #1 flow drops the row |
| Tier matching (AD-5) | ✅ Implemented | Tarifa-scoped legs 331–336/360–364, fallback 372–377, single-match + `codfamilia !=` preserved |
| Flag defaults (AD-6) | ✅ Implemented | 1218–1221, 1649–1652, 2573–2576, createFamiliaRow 483–485; `nivel` recomputed by `calcular_nivel()` inside save() (model 249) |
| codtarifa resolution (AD-7) | ⚠️ Implemented with defect | Writer #2 correct; upload step 1448–1460 correct; writer #1 missing (C1) |
| tarif_familia read-only API (AD-1/AD-2) | ✅ Implemented | 134 lines removed, 5 added (@deprecated docblock); 9 read methods + LEFT JOINs intact |
| CDM-06 / CDM-12 | ✅ Implemented | Base spec 44–49 amended; CDM-12 in delta |

### Coherence (Design)
| Decision | Followed? | Notes |
|----------|-----------|-------|
| AD-1 keep deprecated read-only | ✅ Yes | Diff `a93926f` pure: removals + docblock only |
| AD-2 keep ext reads as historical | ✅ Yes | All read SQL unchanged |
| AD-3 no autoloader action | ✅ Yes | `FamiliaOverrideRemovalTest` canary green |
| AD-4 VARI leave as-is | ✅ Yes | Probe: VARI still unlinked; backfill SQL remains optional |
| AD-5 tier retarget + fallback | ✅ Yes | Exact implementation incl. dual NOT EXISTS fallback |
| AD-6 flag preservation | ✅ Yes | Per-flow defaults verified in source |
| AD-7 codtarifa chain | ⚠️ Partial | Writer #2 + upload step follow it; writer #1 does not (C1) |
| AD-8 limpiar_todo + FIELD_CATALOG | ✅ Yes | Ext-index DELETE kept (documented exception); both `table` entries → `tarif_tarifa_familia` (lines 196, 220) |
| D-1 two-write contract | ⚠️ Partial | Holds for #2/#3/#4; broken for #1 |
| D-2 service retarget | ✅ Yes | Constructor/setter/`createFamiliaRow` all per design |
| D-3 writer #3 detail | ✅ Yes | FQCN form; etiquetas + error path untouched; canary count-of-2 intact |
| D-4 rollback revert-only | ✅ Yes | No DDL, no data writes |

### Task Audit (spot-checked against code, not checkboxes)
| Task | Verdict | Evidence |
|------|---------|----------|
| 1.1 | ⚠️ Deviation | Test exists and is green, but ships source-grep negatives instead of the stated `!method_exists(...'save')` — the stated form is unsatisfiable because `save()` is inherited from base `familia`; substitution is technically necessary but undocumented |
| 1.2 | ✅ Done | Commit `a93926f`; source verified |
| 1.3 | ✅ Done | tarif_catalogo_view.php 2525–2618 |
| 1.4 | ✅ Done | Both suites green |
| 2.1–2.3 | ✅ Done | 3 tier tests + capitulo test exist, pass, and were RED-capable pre-change |
| 2.4 | ✅ Done | Service source verified (constructor 55, setter 528, tiers 331–441, createFamiliaRow 461–491) |
| 2.5 | ✅ Done | FIELD_CATALOG lines 196, 220 |
| 2.6 | ✅ Done | 34 Excel tests green |
| 3.1 | ⚠️ Deviation | Test file exists, but writer #1 assertions are false-positive greps (C2) — the claimed RED could never fail |
| 3.2 | ✅ Done | Writer #2 tests region-scoped and genuine |
| 3.3 | ❌ Claim false | Writer #1 does NOT resolve codtarifa via `$_POST`→`get_default()`→`'DEF'`; it reads an undefined local (C1) |
| 3.4 | ✅ Done | 1608–1613, 1641–1653 |
| 3.5 | ⚠️ Narrower | `test_source_has_no_ext_insert_update_outside_class` greps only the model file — writer sites unpinned (W2) |
| 3.6 | ✅ Done | Base spec 44–49 replaced, stale alias-file reference gone |
| 3.7 | ✅ Done | Suite green (documented pre-existing exceptions); ext probe 233 unchanged |

### TDD Compliance
| Check | Result | Details |
|-------|--------|---------|
| TDD Evidence reported | ❌ | No `apply-progress.md` for this change (openspec dir has only proposal/design/tasks/specs; Engram: no record) — no "TDD Cycle Evidence" table exists |
| All tasks have tests | ✅ | 26 new tests verified present across 4 files |
| RED confirmed (tests exist) | ⚠️ | 26/26 exist; ~23/26 verified RED-capable against pre-change source (git 9321af5); 3 writer #1 greps could never fail (C2); RED-before-GREEN ordering not independently verifiable — tests and implementation were committed in the same work-unit commits |
| GREEN confirmed (tests pass) | ✅ | 26/26 pass at runtime (targeted runs) |
| Triangulation adequate | ✅ | Tier matching 3 cases + fallback; two-write covered per writer; capitulo negative + positive |
| Safety Net for modified files | ✅ | Existing suites executed and green (UpsertFamilia harness mocks `select()` only — root-suite compatible, R-7 respected) |

**TDD Compliance**: 4/6 checks passed.

### Test Layer Distribution
| Layer | Tests | Files | Tools |
|-------|-------|-------|-------|
| Unit | 26 | 4 | PHPUnit 11 (mocked db/models) |
| Integration | 0 new (pre-existing suites ran) | — | PHPUnit plugin suites |
| E2E | 0 | — | not installed |
| **Total** | **26** | **4** | |

Note: all new coverage is source-inspection + mocked-unit; no runtime test executes the migrated writer flows end-to-end against a database.

### Changed File Coverage
Coverage analysis skipped — no coverage tool detected.

### Assertion Quality
| File | Line | Assertion | Issue | Severity |
|------|------|-----------|-------|----------|
| `TarifArticulosFamiliaImportTest.php` | 79–88 | `assertStringContainsString('tarif_tarifa_familia', $source)` on the whole 2429-line file | Cannot fail — needle pre-existed ×3 pre-change (limpiar/wizard flows); proves nothing about writer #1; masks C1 | CRITICAL |
| `TarifArticulosFamiliaImportTest.php` | 90–99 | `assertStringContainsString('$_POST['codtarifa']', $source)` whole-file | Cannot fail — matches the upload function (1448), not writer #1; masks C1 | CRITICAL |
| `TarifArticulosFamiliaImportTest.php` | 101–121 | whole-file greps for `'activa'`, `'en_tarifa'`, `'en_catalogo'` | Cannot fail — `'activa'` pre-existed pre-change | CRITICAL |
| `TarifFamiliaWriteRetirementTest.php` | 123–131 | `test_source_has_no_ext_insert_update_outside_class` greps only the model file | Scope narrower than the name suggests — writer sites not pinned (W2) | WARNING |

**Assertion quality**: 3 CRITICAL, 1 WARNING.

### Quality Metrics
**Linter**: ➖ Not available (no PHP linter config in repo; `php -l` clean on all 7 changed files)
**Type Checker**: ➖ Not available (PHP project; no static analysis tool configured)

### Issues Found

**CRITICAL**:
1. **C1 — Writer #1 (`import_tarifa_json()`) uses an undefined `$codtarifa_import`; the tarifa-row write is silently skipped.** `plugins/tarifario/controller/tarif_articulos.php:1210,1213` read a local that is never assigned inside the function (span 1116–1406; the assignments at 1448–1460 belong to `import_json_upload()`, those at 1608+ to `process_familias_batch()`). PHP 8.2 yields E_WARNING + `null`; `tarif_tarifa_familia::test()` then fails the empty-codtarifa guard (model line 238) and `save()` returns false, which is unchecked at call site (line 1222) while stats still count the familia as created. Result: the `action=import_json` flow (dispatched at line 111–113) writes `familias` but never `tarif_tarifa_familia` → violates TFH Req 1 S1, Req 3 S1, Req 6 S1 and silently violates the "imported familias appear on the tarifa families page" success criterion. Mitigating context: no view/JS currently posts `action=import_json` (the wizard uses `import_json_chunk`/`import_json_process`), so the broken path is dispatch-reachable but UI-orphaned. Fix is small: resolve the codtarifa at the top of `import_tarifa_json()` mirroring 1448–1460 (and check `$tarifa_fam->save()`'s return).
2. **C2 — Writer #1's covering tests are whole-file greps that cannot fail and masked C1.** `TarifArticulosFamiliaImportTest::test_writer1_creates_tarifa_familia_row`, `::test_writer1_resolves_codtarifa_via_post`, and `::test_writer1_sets_ad6_flags_on_tarifa_row` assert needles that pre-existed in `tarif_articulos.php` before this change (git census at 9321af5: `tarif_tarifa_familia` ×3, `$_POST['codtarifa']` ×1, `activa` ×1). They were green before implementation (no RED possible) and stay green despite the runtime defect. They must be re-scoped to the `import_tarifa_json()` function region with assertions tied to actual resolution/assignment code.
3. **C3 — No apply-phase TDD evidence artifact under Strict TDD.** No `apply-progress.md` (or any TDD Cycle Evidence table) exists in `openspec/changes/retire-tarif-familia-ext/` or in Engram; RED-before-GREEN is not independently verifiable (tests and implementation share work-unit commits). Runtime GREEN was fully re-proven here, but the Strict TDD protocol requires the reported evidence.

**WARNING**:
1. **W1 — TFH-7 S1 scenario wording cannot be satisfied literally.** `save()`/`delete()` remain callable on `tarif_familia` via inheritance from base `familia` (they write `familias` only; ext is unreachable from PHP). The archived spec would enshrine "no write method exists and a stale caller fails loudly" — untrue for those two methods. Amend the scenario wording (e.g., "no write override and no extension helper exists; removed helpers fail loudly; inherited save/delete cannot touch `tarif_familia_ext`") before archive.
2. **W2 — Zero-ext-write source pin does not cover the writer sites.** `test_source_has_no_ext_insert_update_outside_class` greps only `catalogo_core/model/tarif_familia.php`. Manual sweep confirms the writer sites are clean today (only SELECTs + the AD-8-documented `limpiar_todo` DELETE at line 2124), but nothing automated pins it. Extend the retirement test to sweep the 4 writer files (excluding the documented `limpiar_todo` exception).
3. **W3 — Task 3.3 checkbox is inaccurate** (writer #1 codtarifa chain claimed GREEN but absent) — fold into the C1 remediation; also unchecked `$tarifa_fam->save()` returns in writers #1/#2 (lines 1222, 1653) swallow failures into success stats.

**SUGGESTION**:
1. Stale docblocks in `ExcelHierarchyService.php`: line 449–450 still says `createFamiliaRow` writes `tarif_familia_ext`, and line 37 says the constructor wires `new tarif_familia()`. Both are now false — update to base `familia` and drop the ext mention.
2. Optional VARI backfill (AD-4 §7 SQL) still available; probe confirms VARI unlinked (expected).
3. Confirm provenance of the post-preflight data growth (familias 238→239, tf 237→238, new `HOLA3` with DEF row and no ext row). Contract held (ext stayed 233); expected if an operator smoke-ran the batch import.
4. Consider a small DB-backed integration test executing writer #2's familia step against a test database so the two-write contract gains a runtime (not only source-level) pin.

### Pre-existing baseline (NOT caused by this change — verified, not counted)
- `VentasArticulosQuickCreateGateCompositionTest::testUnassignedEditorQuickCreateIsDeniedByRealListener` — 1 failure in both root Plugins suite and tarifario suite. The suite was RED-committed at `87b8a4a` (before this change's commits) and belongs to the ventas_articulos permission-gate work stream, untouched by this change.
- 47 errors, all in `FacturaPdf1\*` (AdapterGettersTest ×4, TextBlockPositionTest ×21, CezpdfRenderFeatureTest ×14, CezpdfRenderServiceTest ×1, PrintableDocumentInterfaceGettersTest ×5, GoldenPdfTest ×1, DocumentPrintViewFixture ×1) — PDF plugin, untouched by this change.
- 25 warnings in the catalogo_core suite: pre-existing "Undefined array key" notices in the untouched `model/core/articulo.php` (lines 215/226/241).

### Commits verified
- `a93926f` (catalogo_core) — model retirement, diff pure (134−/5+, docblock + removals only) ✓
- `5e6851c`, `1f8a702`, `a79c713` (tarifario) — writer #3 + retirement test; tier SQL + FIELD_CATALOG + Excel tests; writers #1/#2 + import test ✓
- `d9962079`, `a807018c` (parent) — SDD artifacts; tasks + base-spec amendment ✓

### Verdict
**FAIL** — 3 failing spec scenarios trace to one runtime defect (writer #1's undefined `$codtarifa_import`, silently dropping the tarifa write) that its own covering tests cannot detect; the remaining 15 scenarios are compliant or partial with green runtime evidence. Remediation (small, bounded): fix writer #1's codtarifa resolution + check the tarifa save return, re-scope the three writer #1 tests to the function region, add the writer-site ext-write sweep, amend the TFH-7 S1 wording, and backfill the apply-phase TDD evidence artifact.
