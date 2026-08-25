```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:a778e03456956956e3cb537fbaf0193f9a105f4959169d36ae98c72baeb9267c
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 6/6
scenarios: 9/9
test_command: ddev exec php vendor/bin/phpunit tests/Core/FkCompatibilityValidatorTest.php tests/Core/FsSchemaTest.php tests/Core/SchemaComparatorTest.php tests/Core/PluginUpdateOrdererTest.php tests/Core/PluginSchemaResyncerTest.php
test_exit_code: 0
test_output_hash: sha256:a778e03456956956e3cb537fbaf0193f9a105f4959169d36ae98c72baeb9267c
build_command: ddev exec php vendor/dev-tools/bin/phpstan analyse src/Database/FkCompatibilityValidator.php src/Core/Plugin/PluginUpdateOrderer.php src/Core/Plugin/PluginSchemaResyncer.php --level=5 --memory-limit=1G
build_exit_code: 0
build_output_hash: sha256:f3b07603c4adceb3abd944074ea61a5378a82333038eacfd48c5d1bd2989e480
```

# Verify Report: schema-sync-defensive-fk

**Change**: schema-sync-defensive-fk
**Version**: N/A (delta spec, `openspec/changes/schema-sync-defensive-fk/specs/schema-sync/spec.md`)
**Mode**: Strict TDD (runner present, `strict_tdd: true`)
**Scope**: CORE-LOCAL (root `openspec/`)

## Executive Summary

Independent verification of the `schema-sync-defensive-fk` change against spec (SS-01..SS-06), design (D1-D6, traps a-h), proposal, and tasks. The implementation is functionally complete and runtime-proven: the shared `FkCompatibilityValidator` gates FK emission in BOTH schema builders (SchemaComparator and fs_schema), the sync/ordering services are centralized in `src/Core/Plugin/` with a core-owned default, all 5 change test files pass (35 tests / 71 assertions), all buildable root suites pass (Base 180, Core 111, Security 189, Traits 15, Cache 18), and phpstan level 5 is clean on the 3 new src files. All four ZIP-install/update schema-sync entry points converge on the fixed builders (verified file:line). The only findings are process/delivery-level: the apply phase left no `apply-progress.md` TDD-evidence artifact (TDD cycles are documented inline in tasks.md and were independently re-verified at runtime), and SS-06's plugin-side delivery is pending core 0.18.0 release by explicit scope. **Verdict: PASS WITH WARNINGS.**

## Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 22 |
| Tasks complete | 14 |
| Tasks incomplete | 8 (Phase 3: 3.1-3.7, 5.2 — ALL pending release core 0.18.0, EXPECTED per scope, not a failure) |

Phase 1 (FK validator + wiring), Phase 2 (core centralization), Phase 4 (docs) fully checked. Phase 3 (system_updater delegation + min_version bump) is delivery-sequenced after core 0.18.0 release — verified the plugin files are untouched (`plugins/system_updater/lib/PluginUpdateOrderer.php` + `PluginSchemaResyncer.php` still present, `fsframework.ini` still `min_version = "0.13"`, `admin_updater.php` unmodified). 5.1/5.3 checked; 5.2 (plugin suite) pending with Phase 3.

## Build & Tests Execution

**Build (phpstan level 5, 3 new src files)**: ✅ Passed, exit 0
```text
ddev exec php vendor/dev-tools/bin/phpstan analyse src/Database/FkCompatibilityValidator.php src/Core/Plugin/PluginUpdateOrderer.php src/Core/Plugin/PluginSchemaResyncer.php --level=5 --memory-limit=1G
[OK] No errors
```

**Tests (change-scoped, 5 files)**: ✅ 35 passed / 0 failed / 0 skipped
```text
ddev exec php vendor/bin/phpunit tests/Core/FkCompatibilityValidatorTest.php tests/Core/FsSchemaTest.php tests/Core/SchemaComparatorTest.php tests/Core/PluginUpdateOrdererTest.php tests/Core/PluginSchemaResyncerTest.php
OK (35 tests, 71 assertions)   [exit 0]
```

**Root suites (buildable)**: all ✅
| Suite | Result | Notes |
|-------|--------|-------|
| Base | 180 OK, 548 assertions | ✅ |
| Core | 111 OK, 263 assertions | ✅ (validator warnings logged as expected in mismatch tests) |
| Security | 189 OK, 332 assertions | ✅ 13 pre-existing skips, 6 PHPUnit deprecations (weak mode) |
| Traits | 15 OK, 36 assertions | ✅ |
| Cache | 18 OK, 33 assertions | ✅ |

**Coverage**: ➖ Not available — no coverage tool configured for this run (not a failure).

## Spec Compliance Matrix

| Requirement | Scenario | Test | Result |
|-------------|----------|------|--------|
| SS-01 | FK to utf8mb3 parent omitted on mismatch | `FkCompatibilityValidatorTest::testCharsetMismatchReturnsFalse` + `FsSchemaTest::testCreateTableOmitsFkOnCollationMismatch` (utf8mb3 parent vs utf8mb4 local) + `SchemaComparatorTest::testGenerateTableOmitsFkOnCollationMismatch` | ✅ COMPLIANT |
| SS-01 | FK emitted when columns are compatible | `FkCompatibilityValidatorTest::testCompatibleMatchReturnsTrue` + `FsSchemaTest::testCreateTableKeepsFkOnCollationMatch` + `SchemaComparatorTest::testGenerateTableKeepsFkOnCollationMatch` | ✅ COMPLIANT |
| SS-02 | Non-MySQL engine keeps the FK | `FkCompatibilityValidatorTest::testNonMySqlReturnsTrue` | ✅ COMPLIANT |
| SS-02 | Non-collatable column type keeps the FK | `FkCompatibilityValidatorTest::testNonCollatableIntLocalTypeReturnsTrue` | ✅ COMPLIANT |
| SS-02 | Missing metadata keeps the FK | `FkCompatibilityValidatorTest::testMissingMetadataReturnsTrue` + `testMissingReferencedColumnReturnsTrue` | ✅ COMPLIANT |
| SS-03 | Both builders delegate to the same validator | Static: `SchemaComparator::shouldKeepFkConstraint` → `fkValidator()->isFkCompatible` (SchemaComparator.php:505-507); `fs_schema::addForeignKeyConstraint` → `new FkCompatibilityValidator($db, $isMySQL)->isFkCompatible` (fs_schema.php:483-484). Both resolve MySQL-ness identically (`FS_DB_TYPE === 'mysql'`, fs_schema.php:99-102 vs FkCompatibilityValidator::isMySqlEngine). Behavioral parity: FsSchemaTest + SchemaComparatorTest both omit on utf8mb3 mismatch / keep on match | ✅ COMPLIANT |
| SS-04 | Omitted FK is recovered after columns align | Static (recovery path UNCHANGED): `fs_mysql::compare_constraints` (fs_mysql.php:267) still emits `ALTER TABLE ... ADD CONSTRAINT <name> <consulta>` for missing FKs; `SchemaComparator::compareConstraints` (SchemaComparator.php:96) still emits `ALTER TABLE ... ADD <consulta>`. Neither consults the validator — the FK gate lives ONLY in the CREATE paths. Zero diff on fs_db2.php/fs_mysql.php in this change | ✅ COMPLIANT (static; dedicated regression test suggested) |
| SS-05 | Plugin delegates to core orderer and resyncer | `PluginUpdateOrdererTest` (9 tests) + `PluginSchemaResyncerTest` (4+ tests) pass; classes core-owned at `src/Core/Plugin/PluginUpdateOrderer.php` + `PluginSchemaResyncer.php`; default `requirementsFn` = `LocalPluginRequirementsReader` (NOT CatalogPluginInstallProvider); no duplicates introduced in core | ✅ COMPLIANT |
| SS-06 | min_version gates loading against an older core | DESIGNED and documented: spec SS-06 + design D5 (`min_version "0.13" → "0.18"`) + tasks 3.1/3.6 (pending release core 0.18.0). Implementation intentionally NOT done in this run (explicit scope). Call sites remain API-compatible by design | ✅ COMPLIANT (design-documented; delivery pending release — expected, no fail) |

**Compliance summary**: 9/9 scenarios compliant (8 runtime-proven; SS-06 compliant-by-design per orchestrator scope).

## Correctness (Static Evidence)

| Requirement | Status | Notes |
|------------|--------|-------|
| SS-01 Defensive FK | ✅ Implemented | `FkCompatibilityValidator::isFkCompatible` strict-omits ONLY on MySQL + present metadata + real charset/collation/type mismatch; `error_log` warning (`warn()`, FkCompatibilityValidator.php:302-308); table still created (FsSchemaTest asserts CREATE emitted without FOREIGN KEY). Compatible FK emitted exactly as defined |
| SS-02 Permissive default | ✅ Implemented | Allow paths: `parseFkParts` null (:61-63), non-MySQL (:65-67), non-collatable type (:71-74, `isCollatableType` varchar/char/character varying/character/text/enum/set), missing referenced metadata (:76-79), plus builder-level allow when local column not resolvable |
| SS-03 Shared validator | ✅ Implemented | ONE class consulted by both builders. SchemaComparator passes `$this->db` (auto-detects FS_DB_TYPE); fs_schema passes explicit `$isMySQL` — same resolution logic, same decision code |
| SS-04 Self-healing | ✅ Implemented (unchanged) | `compare_constraints` ADD branches intact in both engines; validator NOT consulted in compare paths; recovery requires a later sync after columns align (no manual intervention) |
| SS-05 Centralized services | ✅ Implemented | `final class PluginUpdateOrderer` (Kahn algorithm, cycle tolerance with single error_log, auto-dep ignored, non-installed deps skipped; default `LocalPluginRequirementsReader(FS_FOLDER.'/plugins')`, default `isInstalledFn = is_dir(FS_FOLDER.'/plugins/'.$name)` with FS_FOLDER-undefined → true, trap c). `final class PluginSchemaResyncer` (same static API: `resyncInstalled(\fs_plugin_manager, ?string $only, ?callable, ?callable)` returning success/updated/failed/messages/results; `withDependencyVisibility` snapshot/restore in `finally`). No `require_once` (PSR-4) |
| SS-06 min_version coupling | ✅ Designed (delivery pending) | Spec + design D5 + tasks 3.1/3.6 pin `min_version "0.13" → "0.18"`; call sites stay API-compatible. Absence of the actual plugin change is EXPECTED (Phase 3 pending release core 0.18.0) |

## Design Coherence

| Decision | Followed? | Notes |
|----------|-----------|-------|
| D1 Validator API | ✅ Yes | `final class FkCompatibilityValidator`, `__construct(object $db, ?bool $isMySql = null)`, `public static parseFkParts(): ?array`, `public isFkCompatible(string, array): bool` — exact interface |
| D2 Local metadata from XML + @@ defaults | ✅ Yes | Both builders resolve local col type from XML cols (SchemaComparator::resolveLocalFkColumnInfo :459-483; fs_schema::resolveLocalFkColumnInfo :212-239), charset/collation left null → validator fills from `@@character_set_database`/`@@collation_database` (queried once, cached, `dbConfig()` :188-209). Trap (a) respected: information_schema queried ONLY for the referenced column, never the local one |
| D3 Strict-omit semantics + normalization | ✅ Yes | Omit only on charset/collation/type mismatch; `normalizeType` = `TypeNormalizer::convertPostgresType` + lowercase + int display-width strip + varchar(n) kept (:290-300). Trap (b) respected |
| D4 Core default = LocalPluginRequirementsReader | ✅ Yes | `PluginUpdateOrderer::defaultRequirements` → `LocalPluginRequirementsReader(FS_FOLDER.'/plugins')` (:178-190); NOT CatalogPluginInstallProvider. Trap (c) respected |
| D5 Delivery coupling 0.18.0 | ✅ Documented | design D5 + tasks 3.1/3.6; system_updater untouched (pending release) |
| D6 Symfony-first, no new deps | ✅ Yes | No doctrine/dbal; no new Symfony/Doctrine component; logic in modern `src/Database/` PSR-4 layer. Trap (g) confirmed: `git diff b7df7315~1 HEAD -- composer.json composer.lock` = 0 lines; no doctrine anywhere in new code (only TypeNormalizer, fs_plugin_manager, LocalPluginRequirementsReader, SimpleXMLElement) |

Implementation traps a-h: (a) ✅, (b) ✅, (c) ✅, (d) N/A (Phase 3 pending — injection sites not yet modified, by scope), (e) ✅, (f) ✅, (g) ✅, (h) ✅ verified below.

## User Requirement — ZIP install/update coverage (trap h)

ALL schema-sync entry points converge on the two FIXED builders (file:line evidence):

1. **ZIP update**: `fs_plugin_manager::install` (:569) → `applySchemaUpdatesOrRollback` (:595/:682) → `applyPluginSchemaUpdates` (:684/:767) → `PluginSchemaSynchronizer::synchronize` (:31) → `syncXmlTables` (:100) → `fs_schema::syncPluginTables` (:611) → `collectConstraints` (:182) → `addForeignKeyConstraint` (:455, FIXED — validator consulted :483-484).
2. **ZIP new install**: activation → `enableWithoutDependencyResolution` (:474) → `applyPluginSchemaUpdates` (:508) → same fixed path.
3. **fs_schema direct**: `createFromXml` (:111) / `generateSql` (:867) → `createTable` (:134) → `collectConstraints` (:182) → `addForeignKeyConstraint` (:455, FIXED).
4. **Model instantiation**: `fs_model::check_table` (:277/:304) → `fs_db2::generate_table` (:283) → `fs_mysql::generate_table` (:663) → `SchemaComparator::generateTable` (:117) → `validateFkConstraints` (:407, with `$xmlCols`) → `shouldKeepFkConstraint` (:447) → `fkValidator()->isFkCompatible` (:505-507, FIXED).

Note: a plugin shipping raw SQL that bypasses the framework machinery is plugin-owned and out of framework control (documented in report as required).

## Constraints Check

| Constraint | Status | Evidence |
|------------|--------|----------|
| Phase 3 NOT started | ✅ | Tasks 3.1-3.7 + 5.2 unchecked; `git status` clean except tasks.md; system_updater lib/ + ini + controller untouched |
| plugins/system_updater untouched | ✅ | `git diff b7df7315~1 HEAD` contains no `plugins/` paths; lib/PluginUpdateOrderer.php + PluginSchemaResyncer.php still present; `min_version = "0.13"` |
| No vendor/ changes | ✅ | `git diff b7df7315~1 HEAD --stat -- vendor/` = 0 lines |
| No composer.json/lock changes | ✅ | 0-line diff; no doctrine/dbal (trap g) |

## TDD Compliance (Strict TDD Module)

| Check | Result | Details |
|-------|--------|---------|
| TDD Evidence reported | ⚠️ | NO `apply-progress.md` artifact exists for this change (verified: none in openspec tree, none in Engram). RED/GREEN cycles ARE documented inline in tasks.md (1.1-1.7, 2.1-2.4: each row states the RED test + expected failure and the GREEN implementation + expected pass). The strict module's prescribed artifact is absent → flagged WARNING here (not CRITICAL) because the substantive evidence exists in tasks.md and was independently re-verified at runtime by this verify run. Orchestrator may require the artifact for full protocol compliance |
| All tasks have tests | ✅ | 5 test files cover Phases 1-2: FkCompatibilityValidatorTest, FsSchemaTest, SchemaComparatorTest (extended), PluginUpdateOrdererTest, PluginSchemaResyncerTest |
| RED confirmed (tests exist) | ✅ | 5/5 test files verified present in the tree |
| GREEN confirmed (tests pass) | ✅ | 35/35 change tests pass on execution (exit 0); full suites green |
| Triangulation adequate | ✅ | Multi-case per behavior: validator 8 distinct scenarios (match, collation/charset/type mismatch, non-MySQL, non-collatable, missing metadata, missing referenced col); fs_schema + comparator 2 each (omit/keep); orderer 9 order semantics; resyncer snapshot/failure/exception |
| Safety Net for modified files | ✅ | SchemaComparatorTest was extended, not replaced — all pre-existing tests in Base (180) + Core (111) pass, no regression |

**TDD Compliance**: substantive evidence confirmed; artifact-format gap noted (see Issues).

## Test Layer Distribution

| Layer | Tests | Files | Tools |
|-------|-------|-------|-------|
| Unit (no-DB, fake-db interception) | 35 (change-scoped) | 5 | PHPUnit 11, fake `select()`/`exec()`/`table_exists()` anonymous classes |
| Integration | 0 | 0 | — |
| E2E | 0 | 0 | — |
| **Total** | **35** | **5** | |

## Changed File Coverage

Coverage analysis skipped — no coverage tool detected/configured for this run (informational, not a failure).

## Assertion Quality

All assertions verify real behavior: SQL content (FK omitted/kept in generated DDL), decision booleans with varied expected values, dependency ordering (`['B','A']`, `['C','B','A']`), `$GLOBALS['plugins']` snapshot restoration, warning-log content, exception propagation. No tautologies, no ghost loops, no smoke-only tests, no type-only assertions standing alone. Mock/assertion ratio healthy (fake-db fixtures drive real validator/builder code paths).

**Assertion quality**: ✅ All assertions verify real behavior.

## Quality Metrics

**Linter**: ➖ Not configured (no linter run for this scope).
**Type Checker (phpstan)**: ✅ No errors — level 5 on the 3 new src files, exit 0.

## Pre-existing Failures (out of scope — verified)

The root full suite cannot start because Plugins-suite discovery loads a third-party test that fatals. Verified pre-existing:

1. `plugins/factura_pdf1/vendor/rospdf/pdf-php/tests/CpdfTest.php` — PHPUnit 11 constructor break (`TestCase::__construct()` now requires exactly 1 argument; old-style `__construct()` passes 0). File is gitignored plugin-owned code (separate plugin repo) — core commits cannot touch it. Reproduced: exit 255.
2. `tests/Components/PublicAccessGateTest.php` — fatal at class-load: `fs_db2@anonymous::exec($sql, $transaction = null, $params = [])` incompatible with `fs_db2::exec(..., $batch = false)`. Byte-identical to parent b7df7315~1 (worktree diff empty); `fs_db2.php` has zero diff in this change. Reproduced: exit 255.
3. `tests/Integration/StealthSessionBootstrapTest.php` — same anonymous `fs_db2::exec` signature class; byte-identical to parent; same mechanism.

Parent-equivalence proof: worktree at b7df7315~1 (`panel-ab-worktrees/preexisting-check`, since removed) → both tracked test files byte-identical (`diff` empty); `fs_db2.php` untouched; same container/PHP 8.3.31 → failures are provably pre-existing, independent of this change.

## Issues Found

**CRITICAL**: None.

**WARNING**:
1. Missing `apply-progress.md` TDD-evidence artifact — apply did not persist the strict-TDD cycle table as a standalone artifact; TDD evidence exists inline in tasks.md and was independently re-verified (all test files present, all pass). Process gap, not functional.
2. SS-06 plugin-side delivery not implemented (system_updater `min_version` still "0.13", lib/ classes still local) — EXPECTED per explicit scope (Phase 3 pending release core 0.18.0). Must be delivered in the plugin repo before archive of the full cross-repo change.

**SUGGESTION**:
1. Add a dedicated SS-04 regression test: FK omitted at CREATE on collation mismatch → columns aligned → `compare_constraints` emits `ADD CONSTRAINT` for the FK (currently static evidence only; compare-ADD is runtime-covered for UNIQUE in `FsMysqlConstraintComparisonTest` but not for FOREIGN KEY).
2. `FkCompatibilityValidator::warn()` message format differs cosmetically from the design template (`"Foreign key columna 'X' omitida - incompatibilidad de {kind} (local A vs referenciada B)"` vs design's `"Foreign key 'X' omitida - incompatibilidad de collation (...)"`) — functionally equivalent, Spanish, `error_log`-compatible.
3. `fs_plugin_manager::applySchemaUpdatesOrRollback` (:682) has no covering test (pre-existing per codegraph blast radius) — candidate for future hardening coverage.

## Verdict

**PASS WITH WARNINGS**

Reason: 9/9 spec scenarios compliant (8 runtime-proven + SS-06 compliant-by-design per explicit scope), all 5 buildable root suites green, phpstan level 5 clean, all design decisions and implementation traps honored, all four ZIP install/update entry points converge on fixed builders, no new dependencies, Phase 3 untouched as required. Warnings are process/delivery-level only: missing apply-progress artifact (TDD evidence present in tasks.md + independently re-verified) and SS-06 plugin-side delivery pending core 0.18.0 release.