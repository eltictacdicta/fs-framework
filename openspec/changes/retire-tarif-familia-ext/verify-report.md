```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:fb510541ddb7dac04732bdd8dbb02e13df18a9d8210495a057d542250fb56bfe
verdict: fail
blockers: 0
critical_findings: 0
requirements: 6/9
scenarios: 15/18
test_command: 'ddev exec php vendor/bin/phpunit --filter "TarifArticulosFamiliaImportTest\|TarifFamiliaWriteRetirementTest\|ExcelHierarchy"'
test_exit_code: 0
test_output_hash: sha256:0212d2ec3d4f04fcc1500f27dc260972171d831069c3067ec3a45f7defcb4a4b
build_command: ddev exec php -l (4 remediation files, sequential)
build_exit_code: 0
build_output_hash: sha256:429e33cea9f247a843de6d54d265fac1832ce23529fe817e7b19e89d4b944d98
```

## Verification Report

**Change**: `retire-tarif-familia-ext`
**Version**: N/A (delta specs carry no version field)
**Mode**: Strict TDD
**Report kind**: **Re-verification** after the remediation slice. The previous verdict on this change was **FAIL** (evidence_revision `sha256:87f856e0efb76a80b04ea53a25de5a00968a6bff695508e5d0472cae4ac9d4d5`, 3 CRITICALs C1/C2/C3 + W1/W2, parent commit `53f23862b162ab7fc0f4aaa18a6683fed245dc5b`). This re-verification supersedes it; the original findings are preserved condensed at the bottom of this file, and the full original report bytes remain in git at `53f23862b162ab7fc0f4aaa18a6683fed245dc5b:openspec/changes/retire-tarif-familia-ext/verify-report.md`.
**Why the verdict is still `fail` despite full closure**: all six remediation findings are CLOSED and re-proven (see Remediation Closure Audit). The verdict remains `fail` under the pipeline's runtime-evidence gate ("a spec scenario is compliant only when a covering test passed at runtime") because 3 scenarios — the same 3 PARTIALs documented in the original FAIL run, explicitly out of the remediation slice's scope — still lack a fully-passing covering test (15/18). A passing verdict (`pass`/`pass_with_warnings`) requires complete counts (validated: the gate refuses any pass* with incomplete counts). Nothing from the original findings remains open; the residual gap is bounded (≤3 small tests) and listed under Issues Found for an orchestrator decision: close the 3 partials in a micro-slice, or accept them explicitly at archive.
**Evidence revision**: parent HEAD `53f23862b162ab7fc0f4aaa18a6683fed245dc5b` (tarifario `f48702d`, catalogo_core `a93926f`). `evidence_revision` above = sha256 of the concatenated re-verification evidence outputs (root filtered run + tarifario suite + catalogo_core suite + drift probe + php -l build), captured 2026-09-07.

### Remediation Closure Audit

| Finding | Claimed closure | Re-verification evidence | Status |
|---|---|---|---|
| **C1** — writer #1 read an undefined `$codtarifa_import`; tarifa-row write silently skipped | tarifario `f48702d` | Source verified: `import_tarifa_json()` assigns `$codtarifa_import = $this->resolve_import_codtarifa()` **before** the familias loop (tarif_articulos.php:1181); persists the per-tariff row via `upsert_tarifa_familia_row($tarifa_familia_model, $codtarifa_import, ...)` (1217); a failed row save increments `errores`, records `Error al guardar la fila de tarifa para familia ...` (1223–1226) and forces `ROLLBACK` (1375–1379). Helpers verified: `resolve_import_codtarifa()` (1415–1432: `$_POST['codtarifa']` → `tarif_tarifa::get_default()` → `'DEF'`, mirrors `$_SESSION['import_codtarifa']`), protected factory seam `new_tarifa_familia_row()` (1440), `upsert_tarifa_familia_row()` (1458–1476: get-or-create, madre/capitulo on the per-tariff row, AD-6 flags, returns `(bool) save()`). Writer #2 checked save confirmed (1725–1734). Runtime: behavioral tests prove the row is persisted for the resolved codtarifa and that a save failure returns FALSE. | ✅ CLOSED |
| **C2** — writer #1 covering tests were whole-file greps that could never fail and masked C1 | `f48702d` | All three tests rewritten **region-scoped** via `getMethodRegion()` (visibility-keyword regex; bare `function (` closures do not terminate the region): `test_writer1_creates_tarifa_familia_row` asserts the `upsert_tarifa_familia_row(` call, the absence of unchecked `$tarifa_fam->save();`, and the error-reporting needle inside the `import_tarifa_json()` region + codtarifa/codfamilia binding in the helper region; `test_writer1_resolves_codtarifa_via_post` asserts the resolver call in the writer region + the full AD-7 chain in the resolver region; `test_writer1_sets_ad6_flags_on_tarifa_row` asserts the four AD-6 expressions in the helper region. Plus 5 behavioral tests through the factory seam. **RED re-verified independently**: pre-fix `a79c713` source contains no `upsert_tarifa_familia_row` / `resolve_import_codtarifa` / error-reporting needle anywhere, and unchecked `$tarifa_fam->save();` sits at lines 1222 (writer #1) and 1653 (writer #2) — the new assertions necessarily fire (apply-progress additionally records the live RED run: exit 2, 5 errors + 4 failures). **GREEN re-proven**: 16/16, exit 0. | ✅ CLOSED |
| **C3** — no apply-phase TDD evidence under Strict TDD | parent `53f23862` | `apply-progress.md` exists with the TDD Cycle Evidence table covering the original 6 work-unit commits (backfill WU1–WU3 + parent artifacts) and remediation cycles R1–R3 (RED/GREEN/TRIANGULATE/REFACTOR columns), plus work-unit evidence with focused commands, harness description and rollback boundaries. | ✅ CLOSED |
| **W1** — TFH-7 S1 scenario wording literally unfulfillable (inherited save/delete) | parent `53f23862` | Delta spec reworded (specs/tarifa-familia-hierarchy/spec.md:114–124): the class declares NO write methods of its own targeting `tarif_familia_ext`; removed overrides/helpers fail loudly; inherited `familia` `save()`/`delete()` remain callable but write only `familias`; ext is unreachable as a write target through the class. Wording now matches implemented, tested reality (8 runtime-negative source tests + writer-site sweep). | ✅ CLOSED |
| **W2** — zero-ext-write pin did not cover the writer sites | `f48702d` | `TarifFamiliaWriteRetirementTest::test_writer_sites_have_no_ext_writes` sweeps writers #1/#2 (`tarif_articulos.php`), #3 (`tarif_catalogo_view.php`) and #4 (`ExcelHierarchyService.php`) for `INSERT INTO`/`UPDATE`/`DELETE FROM` against `tarif_familia_ext`, and pins the single documented AD-8 exception: exactly ONE ext DELETE, region-contained inside `limpiar_todo()`. Can-fail mechanism proven live during authoring (the unscoped version failed on the `limpiar_todo` DELETE). | ✅ CLOSED |
| **SUGGESTION 1** — stale ExcelHierarchyService docblocks | `f48702d` | Class docblock (lines 37–43) now states the constructor wires base `\FSFramework\model\familia()` and that ext is never written; `createFamiliaRow()` docblock (452–465) states base `familia` + `tarif_tarifa_familia`, no ext writes. Both match implementation. | ✅ CLOSED |

**Closure summary**: 6/6 findings closed. No finding remains open.

### Completeness
| Metric | Value |
|--------|-------|
| Tasks total | 17 |
| Tasks complete (checkbox) | 17 |
| Tasks incomplete (checkbox) | 0 |
| Remediation findings closed | 6/6 |

### Build & Tests Execution (re-verification)

**Build**: ✅ Passed — `ddev exec php -l` clean on the 4 remediation-touched files, exit 0.
```text
plugins/tarifario/controller/tarif_articulos.php
plugins/tarifario/Services/ExcelHierarchyService.php
plugins/tarifario/tests/Controller/TarifArticulosFamiliaImportTest.php
plugins/tarifario/tests/Integration/TarifFamiliaWriteRetirementTest.php
build_output_hash: sha256:429e33cea9f247a843de6d54d265fac1832ce23529fe817e7b19e89d4b944d98
```

**Tests**: ✅ change-scope green; suites match documented baselines.
```text
1) ddev exec php vendor/bin/phpunit --filter "TarifArticulosFamiliaImportTest\|TarifFamiliaWriteRetirementTest\|ExcelHierarchy"
   OK — 63 tests, 188 assertions, exit 0 (envelope evidence; 6 PHPUnit deprecation notices, no failures)
   test_output_hash: sha256:0212d2ec3d4f04fcc1500f27dc260972171d831069c3067ec3a45f7defcb4a4b
2) ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
   Tests: 173, Assertions: 604, Failures: 1, Skipped: 3 (exit 1) — the single failure IS the
   documented pre-existing VentasArticulosQuickCreateGateCompositionTest
   ::testUnassignedEditorQuickCreateIsDeniedByRealListener (suite RED-committed at 87b8a4a,
   unrelated ventas_articulos permission-gate subsystem). output hash sha256:c4a1121cd163590f363b4c6b23b9d467eec5ebaca0742965966795f323237526
3) ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
   OK — 267 tests, 571 assertions, 25 pre-existing warnings (untouched model/core/articulo.php
   "Undefined array key"), 1 skipped, exit 0. output hash sha256:11aaa255716c035d30e81dbbbfac6a3be53946002d06b1cc6cefed7c285157c0
```

**Coverage**: ➖ Not available — no coverage tool configured (phpdbg/pcov absent). Coverage analysis skipped.

**Drift probe (read-only)**:
```text
ddev mysql -e "SELECT (SELECT COUNT(*) FROM tarif_familia_ext) ext, (SELECT COUNT(*) FROM tarif_tarifa_familia) tf, (SELECT COUNT(*) FROM familias) fam;"
  ext=233, tf=238, fam=239   (hash sha256:33d15e11416a8f7a4a5ac5375ddd2007f34e0544f2e992f299bb8dcdc5d08dd0)
```
ext = 233 = apply-time value = original-verify value → the zero-ext-write invariant still holds post-remediation. familias/tarifa counts unchanged since the remediation snapshot in `apply-progress.md` §4.

### Spec Compliance Matrix (post-remediation)

Counts from the two delta specs: 9 requirements / 18 scenarios (tarifa-familia-hierarchy: 7 req / 14 scenarios; catalog-domain-models: 2 req / 4 scenarios). Same completeness standard as the original run: a requirement is complete when ALL its scenarios are compliant; ⚠️ PARTIAL keeps the requirement incomplete.

| Requirement | Scenario | Test / evidence | Result |
|-------------|----------|-----------------|--------|
| TFH: Two-write contract | S1 Legacy-only writer persists both rows | `TarifArticulosFamiliaImportTest::test_writer1_persists_tarifa_row_for_resolved_codtarifa` (behavioral, runtime) + `::test_writer1_creates_tarifa_familia_row` (region call-site pin) | ✅ COMPLIANT (was ❌) |
| TFH: Two-write contract | S2 Dual writer drops the legacy write | `TarifFamiliaWriteRetirementTest` (13) + `ExcelHierarchyServiceCreateFamiliaRowTest` | ✅ COMPLIANT |
| TFH: Zero new ext writes | S1 Imports leave ext row counts untouched | drift probe (ext 233→233, re-proven this session) + tier SQL assertions | ✅ COMPLIANT |
| TFH: Zero new ext writes | S2 Historical ext data remains readable | `test_read_methods_preserved` + ext LEFT JOINs (source, diff purity) | ⚠️ PARTIAL — no runtime test asserts returned capitulo/nivel values (unchanged from original run; out of remediation scope) |
| TFH: Page visibility | S1 Imported familia listed for the imported tarifa | tarifa row persisted for the resolved codtarifa (behavioral, runtime green) + page lists via `all_from_tarifa()` (`tarif_familias.php:874`, source-verified in original run) | ✅ COMPLIANT (was ❌) |
| TFH: Page visibility | S2 Unlinked familias remain available | `TarifFamiliasAddFamiliaTest` (green) + `load_familias_disponibles()` + probe (VARI unlinked; fam=239) | ✅ COMPLIANT |
| TFH: Tier matching | S1 Per-tariff capitulo match avoids duplicates | `ExcelHierarchyServiceUpsertFamiliaTest` tier-1/2 + R10.2 canary (in this session's 34 green Excel tests) | ✅ COMPLIANT |
| TFH: Tier matching | S2 Fallback leg matches an unlinked familia | `test_tier2_fallback_matches_madre_descripcion_for_unlinked_familia` | ✅ COMPLIANT |
| TFH: Flag defaults | S1 JSON defaults apply to legacy-only writers | behavioral `test_writer1_persists_tarifa_row_for_resolved_codtarifa` (create-branch defaults activa/en_tarifa/en_catalogo=TRUE, orden=0) + `test_writer1_reuses_existing_tarifa_row_and_honors_json_flags` (JSON overrides) + region `test_writer1_sets_ad6_flags_on_tarifa_row` | ✅ COMPLIANT (was ⚠️) |
| TFH: Flag defaults | S2 Excel divergence preserved | `test_en_tarifa_flag_set_to_true_diverging_from_csv_importer` | ✅ COMPLIANT |
| TFH: codtarifa resolution | S1 Posted codtarifa honored | behavioral `test_writer1_resolves_codtarifa_from_post_and_mirrors_session` (POST wins + session mirror, runtime) + region `test_writer1_resolves_codtarifa_via_post` | ✅ COMPLIANT (was ❌) |
| TFH: codtarifa resolution | S2 Missing codtarifa falls back | behavioral `test_writer1_codtarifa_fallback_uses_default_tarifa_or_def` + `test_writer2_uses_session_codtarifa` (session → get_default() → 'DEF', lines 1678–1683) | ✅ COMPLIANT |
| TFH: read-only API | S1 No own write surface, ext unreachable | reworded scenario; `TarifFamiliaWriteRetirementTest` 8 negative source tests + writer-site sweep (runtime green); inherited save/delete write only `familias` (base model property, unchanged; catalogo_core suite green) | ✅ COMPLIANT (was ⚠️) |
| TFH: read-only API | S2 Read surface unchanged | `test_read_methods_preserved` (9 methods, runtime) + commit `a93926f` diff purity | ✅ COMPLIANT |
| CDM: CDM-06 | S1 familia resolves deterministically to base | `FamiliaOverrideRemovalTest` canary + cold-cache resolution (green in this session's suites) | ✅ COMPLIANT |
| CDM: CDM-06 | S2 Stale caches cannot resurrect the override | `FamiliaOverrideRemovalTest` clearCache legs | ⚠️ PARTIAL — stale-cache leg not simulated (unchanged; out of remediation scope) |
| CDM: CDM-12 | S1 Read-only consumers unaffected | suite-level regression (tarifario 173 + catalogo_core 267, this session) | ⚠️ PARTIAL — no direct consumer-level runtime test (unchanged; out of remediation scope) |
| CDM: CDM-12 | S2 Model carries no write surface | retirement source negatives + `@deprecated` reflection | ✅ COMPLIANT |

**Compliance summary**: 15/18 scenarios compliant, 3 partial, 0 failing. Requirement totals: 6/9 complete (Req 2, CDM-06, CDM-12 incomplete solely because one scenario each is PARTIAL — same standard as the original run).

### Correctness (Static Evidence — deltas vs original run)
| Item | Status | Notes |
|------|--------|-------|
| Two-write contract (D-1) | ✅ Implemented | All four writers verified: #1 via helpers + call site + checked stats/rollback; #2 checked save (1725–1734); #3 unchanged per D-3 (tarifa write + etiquetas untouched); #4 `createFamiliaRow` checks both saves |
| codtarifa resolution (AD-7) | ✅ Implemented | `resolve_import_codtarifa()` chain + session mirror (1429); batch consumes session (1678–1683); behavioral coverage of both legs |
| Tarifa-row failure reporting (W3) | ✅ Implemented | Writers #1 (1223–1226 + ROLLBACK) and #2 (1731–1734) report row-save failures into `errores`/`error_details` instead of success stats |
| `nivel` semantics | ✅ | `tarif_tarifa_familia::save()` recomputes `$this->nivel = $this->calcular_nivel()` (model line 249); any payload-level assignment (writer #3, pre-existing and untouched per D-3) is overwritten at persistence time |
| TDD evidence (C3) | ✅ | `apply-progress.md` present; RED capability independently re-verified against pre-fix `a79c713` source |

### Coherence (Design — deltas)
| Decision | Followed? | Notes |
|----------|-----------|-------|
| AD-6 flag preservation | ✅ Yes (upgraded) | Now behaviorally tested: create-branch defaults + JSON overrides through the seam |
| AD-7 codtarifa chain | ✅ Yes (was partial) | Writer #1 follows the chain; session mirror; batch consumes session |
| D-1 two-write contract | ✅ Yes (was partial) | Holds for all four writers |
| AD-1/AD-2/AD-3/AD-4/AD-5/AD-8/D-2/D-3/D-4 | ✅ Yes | Unchanged from original run; re-confirmed by suite greens + drift probe |

### Task Audit (deltas)
| Task | Verdict | Evidence |
|------|---------|----------|
| 1.1 | ✅ Done (was deviation) | The documented substitution (region-scoped negatives instead of the unsatisfiable `!method_exists('save')`) is now explicit in the test file docblock; 8 negatives + writer-site sweep are genuine and runtime-green |
| 3.1 | ✅ Done (was deviation) | Writer #1 tests re-scoped to method regions; RED capability proven (live RED in apply-progress R1 + independently re-verified against `a79c713`) |
| 3.3 | ✅ Done (was claim false) | Writer #1 codtarifa chain implemented via `resolve_import_codtarifa()` and behaviorally green |
| 3.5 | ✅ Done (was narrower) | `test_writer_sites_have_no_ext_writes` now pins all writer files incl. the region-scoped AD-8 exception |

### TDD Compliance (post-remediation)
| Check | Result | Details |
|-------|--------|---------|
| TDD Evidence reported | ✅ | `apply-progress.md` with TDD Cycle Evidence (C3 closed) |
| All tasks have tests | ✅ | 26 original + 10 remediation tests present |
| RED confirmed | ✅ | Original ~23/26 RED-capable (original run) + remediation RED proven live (9/16) and independently re-verified against pre-fix source |
| GREEN confirmed | ✅ | 63/63 change-scope tests green this session (16 import + 13 retirement + 34 Excel) |
| Triangulation adequate | ✅ | Resolution chain both legs; row persistence create/update/failure branches; flags defaults + overrides; sweep 3 files + exception pin |
| Safety Net for modified files | ✅ | Both plugin suites re-run green (documented pre-existing exceptions only) |

**TDD Compliance**: 6/6 checks passed (was 4/6).

### Issues Found (post-remediation)

**CRITICAL**: none.

**WARNING** (residual partial coverage — the ONLY gaps between this change and a passing verdict; all three pre-date the remediation, were documented in the original FAIL run, and were explicitly out of the remediation slice's scope; none was introduced by remediation):
1. TFH Req 2 S2 — historical `capitulo`/`nivel` reads preserved by source + method-existence evidence, but no runtime test asserts the returned values (repo-wide grep: no test executes the `tarif_familia` read methods).
2. CDM-06 S2 — stale-cache leg not simulated at runtime (`FamiliaOverrideRemovalTest` / `FamiliaTarifaResolutionTest` clear the cache and rebuild registration in both activation orders — cold leg only; no poisoned-cache test).
3. CDM-12 S1 — read-only consumers covered by suite-level regression only; no direct consumer-level runtime test (`LegacyImportRegressionTest` is session-gated and skipped in this session's run).

**SUGGESTION**:
1. Delta spec TFH Req 1 (line 14): "hierarchy values (`madre`, `capitulo`) … never on the base familia object" is ambiguous for `madre` — all three writers (pre-change included) set `$familia->madre` on the base model because `madre` is a native `familias` column (design §1); the enforced prohibition targets `capitulo` (design D-2/D-3 and the negative tests). Consider tightening the wording at archive time.
2. Optional VARI backfill SQL (AD-4) still unapplied (probe: VARI unlinked — expected, AD-4).
3. DB-backed integration test for a writer flow (original suggestion 4) still open.
4. `limpiar_todo()` re-index on `tarif_tarifa_familia` (AD-8 future work) still open.
5. Housekeeping: this updated `verify-report.md` is an uncommitted parent-repo modification (commit it to keep the tracked verification chain); `plugins/tarifario` has two uncommitted files unrelated to this change (`model/tarif_opcional_precio_historial.php`, `model/tarif_precio_historial.php`) — commit separately.

### Commits verified (re-verification)
- tarifario: `f48702d` (remediation: writer #1 fix + helpers + re-scoped tests + writer #2 checked save + sweep test + docblocks) on top of `a79c713`, `1f8a702`, `5e6851c` ✓
- catalogo_core: `a93926f` (model retirement, unchanged since original verify) ✓
- parent: `53f23862` (apply-progress + TFH-7 wording + tracked FAIL report), `a807018c`, `d9962079` ✓

### Original verification (FAIL) — preserved for traceability
Verdict **FAIL**; envelope: evidence_revision `sha256:87f856e0efb76a80b04ea53a25de5a00968a6bff695508e5d0472cae4ac9d4d5`, blockers 3, critical_findings 3, requirements 1/9, scenarios 10/18, `test_command: ddev exec php vendor/bin/phpunit --testsuite Plugins` exit 2 (1 documented pre-existing failure + 47 pre-existing `FacturaPdf1\*` errors). Findings: **C1** writer #1 used undefined `$codtarifa_import` and silently skipped the tarifa-row write (violating TFH Req 1 S1, Req 3 S1, Req 6 S1); **C2** three writer #1 whole-file-grep tests could never fail and masked C1; **C3** no apply-phase TDD evidence artifact; **W1** TFH-7 S1 wording unfulfillable under inheritance; **W2** ext-write pin covered only the model file; **W3** task 3.3 checkbox false + unchecked tarifa saves in writers #1/#2; suggestions on stale docblocks, VARI backfill, probe provenance, and a DB-backed writer integration test. All closures audited in the Remediation Closure Audit above; full original bytes: `53f23862b162ab7fc0f4aaa18a6683fed245dc5b:openspec/changes/retire-tarif-familia-ext/verify-report.md`.

### Verdict
**FAIL** — but with a fundamentally different failure surface than the original run: **every remediation finding is closed** (C1, C2, C3, W1, W2 and the docblock suggestion — 6/6, re-proven with fresh source and runtime evidence: 63/63 change tests green with independently re-verified RED capability, both plugin suites at their documented baselines, zero-ext-write invariant held at ext=233). The `fail` verdict is driven **solely** by the 3 pre-documented PARTIAL scenarios (TFH Req 2 S2, CDM-06 S2, CDM-12 S1) that still lack a fully-passing covering test; the pipeline's runtime-evidence gate requires 18/18 scenario compliance for any passing verdict and refuses a pass* with incomplete counts (validator-confirmed this session). No CRITICAL or blocker remains from the original findings. **Recommended next (orchestrator decision)**: (a) one bounded micro-slice closing the 3 partials (≈3 small tests: a runtime read-value assertion for the ext reads, a poisoned-cache resolution test, a consumer-level read test), then a short re-verify; or (b) an explicit decision to accept the partials and proceed to archive. Suggestion 1 (spec line-14 `madre` wording) can be folded into archive-time spec touch-up.
