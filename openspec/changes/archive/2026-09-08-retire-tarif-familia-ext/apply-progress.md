# Apply Progress: retire-tarif-familia-ext

**Change**: `retire-tarif-familia-ext`
**Mode**: Strict TDD (openspec `config.yaml`: `strict_tdd: true`, `apply.tdd: true`)
**Artifact store**: openspec (core tree)
**Test runner**: `ddev exec php vendor/bin/phpunit`

This artifact documents (1) the original apply phase reconstructed from its
work-unit commits, and (2) the bounded **remediation slice** executed after
verify verdict **FAIL** (3 CRITICALs: C1, C2, C3 — see `verify-report.md`).
It was created as part of the remediation (this file closes finding C3).

---

## 1. Original apply phase (backfill from the 6 work-unit commits)

The original apply phase committed tests and implementation inside the same
work-unit commits, so RED-before-GREEN ordering is not independently provable
from git history. The backfill below is reconstructed from the commit
inventory; RED-capability and runtime GREEN were later proven by the verify
phase (26/26 change tests present, all green; ~23/26 RED-capable against
pre-change source `git 9321af5`).

| WU | Commit(s) | Content | Covering tests | Focused command | Evidence status at verify |
|----|-----------|---------|----------------|-----------------|---------------------------|
| WU1 — model retirement + writer #3 | `a93926f` (catalogo_core), `5e6851c` (tarifario) | `tarif_familia` write surface removed (134−/5+), `@deprecated`; writer #3 → base `familia` (FQCN) | `TarifFamiliaWriteRetirementTest` (12 tests: class loads, `@deprecated`, 8 negative source tests, reads preserved, model-file ext-write pin) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` + `--filter TarifFamiliaWriteRetirementTest` | 12/12 green; RED-capable vs `9321af5` (assertions reference removed methods) |
| WU2 — ExcelHierarchyService tier SQL (AD-5) + FIELD_CATALOG | `1f8a702` (tarifario) | tier-1/2 SQL retargeted to `tarif_tarifa_familia` + fallback leg; constructor → base `familia`; `createFamiliaRow` drops familia `capitulo`; 2 FIELD_CATALOG `table` entries | `ExcelHierarchyServiceUpsertFamiliaTest` (+4: tier-1, tier-2, fallback, capitulo), `ExcelHierarchyServiceCreateFamiliaRowTest` (+1: no capitulo on familia model) | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter ExcelHierarchy` | All green; RED-capable (assertions on the retargeted SQL, which did not exist pre-change) |
| WU3 — writers #1/#2 (AD-6/AD-7) | `a79c713` (tarifario) | writers #1/#2 → base `familia` + `tarif_tarifa_familia` row | `TarifArticulosFamiliaImportTest` (10 tests) | `--filter TarifArticulosFamiliaImportTest` | 10/10 green BUT **3 writer #1 tests were false-positive whole-file greps** (C2) and writer #1 had a runtime defect the tests could not detect (C1 — undefined `$codtarifa_import`) |
| Parent artifacts | `d9962079`, `a807018c` | proposal/design/tasks/specs; tasks marked complete; base-spec amendment | — | — | — |

**Backfill verdict (matches verify)**: WU1/WU2 honest; WU3's writer #1 evidence
was defective (tests could never fail) — remediated in §2 with live RED proof.

## 2. Remediation slice (this session)

Scope = the 5 orchestrator-mandated items only: C1 (writer #1 codtarifa +
checked tarifa-row save), C2 (re-scope writer #1 tests), C3 (this artifact),
W1 (TFH-7 S1 wording), W2 (writer-site ext-write sweep), plus the suggested
docblock refresh. Driver commits: tarifario `f48702d`; parent (this commit).

### Cycle R1 — C1 + C2 + W3: writer #1 fix, re-scoped tests, writer #2 checked save

**RED (tests written first, run against pre-fix code):**

```
ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter TarifArticulosFamiliaImportTest
Tests: 16, Assertions: 22, Errors: 5, Failures: 4   (exit 2)
```

- 5 ERRORS (behavioral — the helpers under test did not exist):
  `test_writer1_resolves_codtarifa_from_post_and_mirrors_session`,
  `test_writer1_codtarifa_fallback_uses_default_tarifa_or_def`,
  `test_writer1_persists_tarifa_row_for_resolved_codtarifa`,
  `test_writer1_reuses_existing_tarifa_row_and_honors_json_flags`,
  `test_writer1_tarifa_row_save_failure_is_reported`
  (all `ArgumentCountError`-free `ReflectionException`: `resolve_import_codtarifa()` /
  `upsert_tarifa_familia_row()` missing on `tarif_articulos`)
- 4 FAILURES (region-scoped source assertions):
  `test_writer1_creates_tarifa_familia_row` (unchecked `$tarifa_fam->save();`
  present in the writer region, no `upsert_tarifa_familia_row` call, no
  tarifa-failure error message), `test_writer1_resolves_codtarifa_via_post`
  (no `resolve_import_codtarifa()` call in the writer region), 
  `test_writer1_sets_ad6_flags_on_tarifa_row` (no AD-6 helper region),
  `test_writer2_checks_tarifa_save_return` (bare `$tarifa_fam->save();` in the
  batch region).

This run proves every re-scoped test CAN fail against the pre-fix shape —
the property the original whole-file greps lacked (C2).

**GREEN (implementation: `resolve_import_codtarifa()`, `new_tarifa_familia_row()`
factory seam, `upsert_tarifa_familia_row()`, loop wiring in writer #1, checked
save in writer #2):**

```
ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter TarifArticulosFamiliaImportTest
OK (16 tests, 63 assertions)
```

Commit: tarifario `f48702d` (code + tests + docblocks, one remediation commit
per repo as mandated).

### Cycle R2 — W2: writer-site zero-ext-write sweep (pinning test)

Pinning/approval-style test added to `TarifFamiliaWriteRetirementTest`
(`test_writer_sites_have_no_ext_writes`): sweeps the three writer files
(`tarif_articulos.php` hosts writers #1/#2, `tarif_catalogo_view.php` writer
#3, `ExcelHierarchyService.php` writer #4) for `INSERT INTO` / `UPDATE` /
`DELETE FROM` against `tarif_familia_ext`, and pins the single documented
AD-8 exception — exactly one ext DELETE, located inside the `limpiar_todo()`
region.

Can-fail proof obtained live during authoring: the first version asserted the
DELETE ban on all three files and **failed** on `tarif_articulos.php` (the
`limpiar_todo` DELETE), demonstrating the needle mechanism fires; the test
was then scoped to the AD-8 exception and went green.

```
ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter TarifFamiliaWriteRetirementTest
OK (13 tests, 34 assertions)
```

### Cycle R3 — SUGGESTION: ExcelHierarchyService docblocks (doc-only)

Class docblock (constructor wiring) and `createFamiliaRow()` docblock now
state the real contract: base `familia` + `tarif_tarifa_familia`; no ext
writes. No behavior change; no test required beyond the existing suite:

```
php -l Services/ExcelHierarchyService.php → clean
--filter "ExcelHierarchy|TarifFamiliaWriteRetirementTest|TarifArticulosFamiliaImportTest"
OK (63 tests, 188 assertions)
```

### W1 — TFH-7 S1 rewording (spec delta)

`specs/tarifa-familia-hierarchy/spec.md`, requirement "tarif_familia is a
deprecated read-only API": requirement text and scenario S1 reworded to the
truthful contract — the class adds NO write methods of its own targeting
`tarif_familia_ext` (removed overrides/helpers fail loudly); the inherited
base `familia` `save()`/`delete()` remain callable but write only `familias`;
ext is unreachable as a write target through this class.

## 3. TDD Cycle Evidence (Strict TDD hard gate)

| Task | Test file | Layer | Safety net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| R1a resolution chain (AD-7) | `TarifArticulosFamiliaImportTest` | Unit (behavioral + source) | ✅ 22/22 baseline (10 import + 12 retirement) | ✅ 2 behavioral (ReflectionException) + region RED | ✅ 16/16 | ✅ POST leg + default-tarifa fallback leg + session mirror | ➖ helpers extracted to the minimum testable seam |
| R1b row persistence + failure reporting | `TarifArticulosFamiliaImportTest` | Unit (behavioral, mocked rows) | ✅ | ✅ 3 behavioral | ✅ | ✅ create-branch defaults / update-branch JSON overrides / save-failure | ➖ |
| R1c re-scoped writer #1 tests (C2) | `TarifArticulosFamiliaImportTest` | Source-inspection (method region) | ✅ | ✅ 3 RED (proven live) | ✅ | ➖ region needles pinned to method start/end | ➖ |
| R1d writer #2 checked save (W3) | `TarifArticulosFamiliaImportTest` | Source-inspection (method region) | ✅ | ✅ 1 RED (proven live) | ✅ | ➖ | ➖ |
| R2 writer-site ext-write sweep (W2) | `TarifFamiliaWriteRetirementTest` | Source-inspection (pinning) | ✅ 22/22 | ➖ pinning test (approval style; can-fail mechanism proven live by the scoped DELETE collision) | ✅ 13/13 | ✅ 3 files + region-scoped AD-8 exception | ➖ |
| R3 docblock refresh | — (doc-only) | — | ✅ 63/63 | N/A — no behavior change | ✅ suite green | ➖ | ➖ |
| W1 spec wording | — (spec delta) | — | — | N/A | ✅ wording matches implemented reality | ➖ | ➖ |

**Test summary (remediation)**: 9 tests written (5 behavioral + 4
region/source, incl. 1 writer #2) + 1 sweep test = 10 new/rewritten tests;
total change-scope tests now 12 retirement + 16 import (+5 Excel and 10
writer #2 pre-existing). All passing.

## 4. Work Unit Evidence (hard gate)

| Evidence | WU-A (tarifario: code + tests + docblocks) | WU-B (parent: spec + this artifact + verify report tracking) |
|----------|--------------------------------------------|--------------------------------------------------------------|
| Focused test command + exact result | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter "ExcelHierarchy\|TarifFamiliaWriteRetirementTest\|TarifArticulosFamiliaImportTest"` → OK (63 tests, 188 assertions). Plugin suite: 173 tests / 604 assertions / 1 failure — the documented pre-existing `VentasArticulosQuickCreateGateCompositionTest` failure (suite RED-committed at `87b8a4a`, unrelated subsystem). catalogo_core suite: 267 tests, OK (25 pre-existing warnings in untouched `model/core/articulo.php`). | N/A — no code change in this unit (docs/spec only) |
| Runtime harness command/scenario | N/A — no runtime boundary is crossed: writer flows execute against mocked row models; the only DB touch is a read-only `tarif_tarifa::get_default()` in the fallback behavioral test (same connection class as existing `TarifTarifaTest`). Live drift probe before/after remediation: `tarif_familia_ext` = 233 rows (unchanged), `tarif_tarifa_familia` = 238, `familias` = 239 → the zero-ext-write invariant holds. | N/A — artifact-only unit |
| Rollback boundary | `git revert f48702d` in `plugins/tarifario` (single commit: controller fix, tests, docblocks). No DDL, no data writes. | `git revert` of the parent remediation commit (spec + apply-progress + verify-report tracking) |

## 5. Deviations from design

- The C1 fix is implemented through two small private helpers
  (`resolve_import_codtarifa()`, `upsert_tarifa_familia_row()`) plus a
  protected row-factory seam (`new_tarifa_familia_row()`), instead of inlining
  the chain inside the `import_tarifa_json()` loop. Reason: the orchestrator's
  remediation requires behavioral tests through the method, and
  `import_tarifa_json()` cannot execute in-process (`exit` + header redirect
  + real model writes); the helper seam is the minimal testable shape and
  mirrors the proven `import_json_upload()` chain verbatim (AD-7).
- Writer #2 keeps its inline tarifa-write shape and receives only the
  checked-save fix, so its existing region-scoped tests keep their needles.

## 6. Remaining knowns (not remediation scope)

- Pre-existing `VentasArticulosQuickCreateGateCompositionTest` failure
  (before this change; ventas_articulos permission-gate work stream).
- Suggestion 2/4 of the verify report: optional VARI backfill SQL (AD-4) and
  a DB-backed integration test for writer #2 — explicitly out of this slice.
- `limpiar_todo()` re-index on `tarif_tarifa_familia` (AD-8 future work).

## 7. Micro-slice: coverage backfill for the 3 PARTIAL scenarios (post-re-verify session)

The re-verification verdict was `fail` **solely** because 3 scenarios lacked
covering tests (0 criticals; 15/18 scenarios). This slice closes exactly
those 3 gaps with 3 new test files. **Coverage backfill mode**: the tests are
approval-style — they must PASS against current code and pin existing
behavior; **no production code was touched** (the strict-TDD RED gate is
honored the way Cycle R2 handled pinning tests: live can-fail proofs obtained
during authoring with throwaway scratch variants, removed afterwards — zero
residue).

### Cycle M1 — TFH Req 2 S2 "Historical ext data remains readable"

**Test**: `plugins/tarifario/tests/Model/TarifFamiliaHistoricalExtReadTest.php`
(4 tests / 27 assertions). Runs the REAL `get()` / `hijas()` /
`all_by_capitulo()` of `FSFramework\model\tarif_familia` against a mock db
returning rows shaped like the production
`SELECT f.*, e.capitulo, e.nivel ... LEFT JOIN tarif_familia_ext e` SQL:
historical values returned when the ext row exists; LEFT JOIN keeps rows with
no ext counterpart (empty historical fields); per-row values in order; SQL
pin on the LEFT JOIN. Constructor-bypassed anonymous subclass (real read
methods, mock db — `select()`/`var2str()` overrides ONLY, the root-suite-safe
pattern); `fs_model::$checked_tables` reflection-seeded so the nested real
`new tarif_familia($row)` hydration is DB-free, saved/restored in tearDown.

**Can-fail proof (live RED probe, scratch variant removed after run)**:
deliberately wrong expected capitulo values → `FAILURES! Tests: 4,
Assertions: 20, Failures: 2` (both value assertions fired through the real
read methods).

**GREEN**: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter TarifFamiliaHistoricalExtReadTest` → OK (4 tests, 27 assertions).

### Cycle M2 — CDM-06 S2 "Stale caches cannot resurrect the override"

**Test**: `plugins/catalogo_core/tests/Integration/FamiliaStaleCacheResolutionTest.php`
(2 tests / 13 assertions), placed next to the S1 canary
(`FamiliaTarifaResolutionTest`) per the existing autoloader-test precedent
(`tests/Base/FsModelAutoloaderAliasTest.php`, `FamiliaTarifaResolutionTest`).
Simulates BOTH poisoned legs S1 never exercises:

1. **deleted-override leg** — in-memory class map still carrying the deleted
   `plugins/tarifario/model/familia.php` path; `loadClass('familia')` must
   evict the dead entry, re-resolve to `FSFramework\model\familia`
   (`is_a` + `get_class(new \familia())` + no `capitulo` property), and
   repair the map to the real base path
   (`plugins/catalogo_core/model/core/familia.php`).
2. **inactive-plugin leg** — a real poisoned `tmp/*model_class_map.php`
   fixture mapping `familia` to an inactive plugin, loaded through the real
   private `loadCache()`; `validateCache()` must unlink the file and empty
   the map; resolution still lands on the base.

Autoloader state (`classMap`/`cacheFile`) saved via reflection and restored
in tearDown; the cache file is redirected to a private tempnam path so any
`saveCache()` during the test cannot leave residue in the repo `tmp/`
(verified: no `fam_stale_cache_*` remains after the run).

**Can-fail proof (live RED probe, scratch variant removed after run)**:
flipped expectations (map keeps the stale path; poisoned map survives
validation) → `FAILURES! Tests: 2, Assertions: 9, Failures: 2` (eviction and
cache-discard genuinely flow through the autoloader).

**GREEN**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter FamiliaStaleCacheResolutionTest` → OK (2 tests, 13 assertions).

### Cycle M3 — CDM-12 S1 "Read-only consumers unaffected"

**Test**: `plugins/tarifario/tests/Model/TarifOpcionalFamiliaConsumerReadTest.php`
(2 tests / 18 assertions). Consumer-level seam per the verify-report wording:
the verified read-only wrapper model `tarif_opcional_familia`
(design §1 consumer list, "wrapper models") runs its REAL
`get_familias_from_opcional()` at runtime: hydrates exactly the deprecated
`FSFramework\model\tarif_familia` (identity pinned via `get_class`),
fields/order unchanged, binds the requested opcional id, keeps its
documented plain-`familias` SQL (no ext join of its own), and the hydrated
objects keep the historical `capitulo`/`nivel` columns available (empty here;
the ext-row-present value leg is Cycle M1's coverage).

**Can-fail proof (live RED probe, scratch variant removed after run)**:
flipped SQL pin (must-contain instead of must-not-contain `tarif_familia_ext`)
→ `FAILURES! Tests: 2, Assertions: 14, Failures: 1`. The scratch also
surfaced that plain `assertInstanceOf('familia', ...)` cannot distinguish the
subclass (parent instanceof passes) — the identity assertion was strengthened
to an exact `get_class` pin and re-run green.

**GREEN**: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter "TarifOpcionalFamiliaConsumerReadTest\|TarifFamiliaHistoricalExtReadTest"` → OK (6 tests, 45 assertions).

### TDD Cycle Evidence (micro-slice)

| Cycle | Test file | Layer | Safety net | RED | GREEN | TRIANGULATE | REFACTOR |
|-------|-----------|-------|------------|-----|-------|-------------|----------|
| M1 | `TarifFamiliaHistoricalExtReadTest` | Unit (real read methods, mock db) | ✅ 63/63 change-scope + suites at baselines | ➖ approval-style; live can-fail probe: 2 value assertions fired (4 tests, 2 failures) | ✅ 4/4, 27 assertions | ✅ ext-present / ext-absent / multi-row / capitulo-centric read | ➖ None needed |
| M2 | `FamiliaStaleCacheResolutionTest` | Unit (real autoloader, poisoned map + real cache file) | ✅ S1 canary 4/4 baseline | ➖ approval-style; live can-fail probe: 2 flipped expectations fired | ✅ 2/2, 13 assertions | ✅ deleted-path eviction + inactive-plugin cache discard | ➖ None needed |
| M3 | `TarifOpcionalFamiliaConsumerReadTest` | Unit (real consumer method, mock db) | ✅ | ➖ approval-style; live can-fail probe: SQL pin fired; led to exact-identity pin | ✅ 2/2, 18 assertions | ✅ hydration/identity leg + SQL/contract leg | ✅ identity assertion strengthened to `get_class` |

**Test summary (micro-slice)**: 8 tests / 58 assertions written, all passing.
No production code changed; no spec wording changed (the 3 scenarios were
already correct — they lacked runtime coverage only).

### Work Unit Evidence (micro-slice hard gate)

| Evidence | WU-M (3 new test files, one commit per plugin repo + parent artifacts) |
|----------|------------------------------------------------------------------------|
| Focused test commands + exact results | Cycle commands above (4/4, 2/2, 6/6). Combined: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter "TarifFamiliaHistoricalExtReadTest\|TarifOpcionalFamiliaConsumerReadTest"` → OK (6/6). Plugin suites: tarifario 179 tests / 649 assertions / 1 failure / 3 skipped — the 1 failure IS the documented pre-existing `VentasArticulosQuickCreateGateCompositionTest` (baseline preserved); catalogo_core 269 tests / 584 assertions, OK with 25 pre-existing warnings + 1 skipped (baseline preserved). Root suite check: `ddev exec php vendor/bin/phpunit --filter "TarifFamiliaHistoricalExtReadTest\|TarifOpcionalFamiliaConsumerReadTest\|FamiliaStaleCacheResolutionTest"` → OK (8 tests, 58 assertions, exit 0; the 6 reported deprecations are the documented pre-existing docblock-metadata notices from SessionManagerTest/StealthModeTest/SessionAuthTest, collected at discovery). Full root `--testsuite Plugins`: 791 tests — errors are exactly the documented pre-existing 47 `FacturaPdf1\*` + the 1 documented `VentasArticulosQuickCreateGateCompositionTest` failure; zero failures/errors mention the 3 new files (root-suite mock restrictions respected: `select()`-only mock overrides, no `exec()` overrides, no inline stubs). |
| Runtime harness command/scenario | N/A — unit-level coverage backfill by design (the verify partials asked for runtime unit evidence of reads/caches/consumers, not a new runtime boundary). The only file-system side effects (private tempnam cache fixtures) are created under the system temp dir and removed in tearDown. |
| Rollback boundary | `git revert` of the tarifario commit (2 test files) and the catalogo_core commit (1 test file); the parent commit reverts only `apply-progress.md` + carries the already-present `verify-report.md` rewrite. No production file is touched by this slice. |

### Deviations from design (micro-slice)

- None beyond the documented approval-style RED (the orchestrator mandated
  coverage-backfill tests that must PASS against current code with no
  production changes; the can-fail mechanism is proven live per cycle).
- `all()` was deliberately NOT driven in M1: its recursion calls
  `get_hijas()` on NESTED real objects (their own real `fs_db2`), which
  would hit the live DB — `get()`/`hijas()`/`all_by_capitulo()` cover the
  same LEFT JOIN hydration across three read paths instead.

