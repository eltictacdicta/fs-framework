# Proposal: schema-sync-defensive-fk

## Status

Draft — pending spec/design.

## Executive Summary

Production DBs run utf8mb3 tables while schema sync creates new tables utf8mb4; MySQL then rejects every FK referencing an existing parent (errno 150) because FK columns must share charset/collation. The comparator never compares collation, so the mismatch is permanent and blocks every schema sync. This change makes the core schema-sync machinery defensive (omit incompatible FKs, never block table creation) and centralizes sync/ordering classes from `system_updater` into the core so plugins reuse them.

## Intent

- **Problem**: `SchemaComparator::tableCharsetCollationSql` (src/Database/SchemaComparator.php:521-545) creates new tables as utf8mb4_general_ci while legacy parents are utf8mb3_general_ci. MySQL errno 150 on every FK to an existing parent (e.g. `articulo_descripciones→articulos`, `cuentasbcocli→clientes`). `columnTypeReallyDiffers` (:227-245) never compares collation and refuses to alter FK-referenced columns when only length differs — mismatch is permanent; resync cannot fix it.
- **User direction (in scope)**: centralize schema-sync machinery in core so `system_updater` and future plugins reuse it; incorporate collation/type-safe FK handling into the core migration path.
- **Why now**: failure class blocks schema syncs on every production DB with legacy collation; duplication (2 FK validators, 3 referenced-table extractors, 2 orderers) grows with each plugin.

## Scope

### In Scope

1. **NEW `src/Database/FkCompatibilityValidator.php`** — shared FK compatibility check: parse referenced table+column from constraint SQL, query `information_schema.columns` for referenced column charset/collation/type, compare to local FK column. Return `false` + `error_log` warning on mismatch. **Permissive**: allow FK when non-MySQL (`FS_DB_TYPE` guard), non-collatable type (int, etc.), or metadata missing.
2. **Wire into BOTH CREATE builders** — `SchemaComparator::shouldKeepFkConstraint` (:447-459) and `fs_schema::addForeignKeyConstraint` (base/fs_schema.php:412-440): omit FK on mismatch; table still created; FK re-attempted via `compare_constraints` when aligned later.
3. **Centralize sync machinery** — NEW `src/Core/Plugin/PluginUpdateOrderer.php` + `PluginSchemaResyncer.php` (moved from `plugins/system_updater/lib/`, generalized, API-compatible); refactor `plugins/system_updater` (`controller/admin_updater.php` + lib calls) to use core classes. Plugin repo gets a follow-up commit; SDD lives in core openspec (core is beneficiary — AGENTS.md hybrid rule).
4. **Tests (TDD, no DB)**: `FkCompatibilityValidatorTest`, `SchemaComparatorTest` extension (FK omitted on collation mismatch / kept on match), new `FsSchemaTest`, core `PluginUpdateOrdererTest` + `PluginSchemaResyncerTest` (moved), `system_updater` tests updated to delegate to core.
5. **Delivery decision (D3)**: bump `system_updater` `min_version` to the core release shipping the centralized classes (currently `"0.13"` vs core 0.17.0), or keep thin BC wrappers — decide and document.

### Out of Scope (documented follow-ups)

- Full `fs_schema` → `SchemaComparator` consolidation.
- Referenced-table extraction unify beyond what the validator needs internally (validator seeds one shared parser).
- Dead-code cleanup in `base/fs_mysql.php` (:741 `extract_referenced_table_name`, :782-816 `parse_fk_collation`).
- `compareColumns` ALTER-alignment for collations (deeper change).
- Operational DB conversion to utf8mb4 in production (user action).

## Capabilities

### New Capabilities

- **`schema-sync`**: defensive schema synchronization — FK constraints are created only when column charset/collation/type are compatible; a mismatch never blocks table creation or the sync run. Covers the core migration path (`fs_model::check_table` / `fs_schema` + `PluginSchemaSynchronizer`) and the centralized reusable ordering/resync services (`PluginUpdateOrderer`, `PluginSchemaResyncer`) owned by core. Canonical: `openspec/specs/schema-sync/spec.md` after archive.

### Modified Capabilities

- None (no existing spec domain covers schema sync).

## Approach

1. Build `FkCompatibilityValidator` as the single collation/type-aware FK gate (permissive defaults, MySQL-only strict path).
2. Inject it into both CREATE builders (SchemaComparator and fs_schema) — FK dropped on mismatch, table DDL proceeds.
3. Move/generalize `PluginUpdateOrderer` + `PluginSchemaResyncer` into `src/Core/Plugin/`; refactor `system_updater` to delegate; update its `fsframework.ini` `min_version` per D3.
4. TDD throughout: red (failing test with fake-db pattern from `SchemaComparatorTest`), green, refactor.

## Design Decisions

- **D1 — Validator semantics**: permissive-by-default; fails closed only on MySQL with present metadata and real mismatch. Mirrors dead-code intent in `fs_mysql.php::parse_fk_collation` (:782-816) — ready reference.
- **D2 — Core homes**: validator in `src/Database/` (next to SchemaComparator); centralized classes in `src/Core/Plugin/` (next to PluginSchemaSynchronizer). Matches existing PSR-4 layout.
- **D3 — system_updater delegation**: prefer core-owned classes + `min_version` bump to the releasing core version; keep plugin call sites API-compatible. Fallback: thin BC wrappers in the plugin if multi-core compatibility is required. Decide at spec/design; document in proposal (this file) via tasks.
- **D4 — Follow-ups**: items above stay out of scope; validator seeds a single shared referenced-table parser for later unification.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `src/Database/FkCompatibilityValidator.php` | New | Shared FK charset/collation/type gate |
| `src/Database/SchemaComparator.php` | Modified | `shouldKeepFkConstraint` consults validator (:447-459) |
| `base/fs_schema.php` | Modified | `addForeignKeyConstraint` consults validator (:412-440) |
| `src/Core/Plugin/PluginUpdateOrderer.php` | New | Moved from plugin, generalized |
| `src/Core/Plugin/PluginSchemaResyncer.php` | New | Moved from plugin, generalized |
| `plugins/system_updater/lib/PluginUpdateOrderer.php` | Removed | Replaced by core class |
| `plugins/system_updater/lib/PluginSchemaResyncer.php` | Removed | Replaced by core class |
| `plugins/system_updater/controller/admin_updater.php` | Modified | Delegates to core classes |
| `plugins/system_updater/fsframework.ini` | Modified | `min_version` bump (D3) |
| `tests/Core/FkCompatibilityValidatorTest.php` | New | No-DB fake-db tests |
| `tests/Core/SchemaComparatorTest.php` | Modified | FK omitted/kept scenarios |
| `tests/Core/FsSchemaTest.php` | New | fs_schema static via Reflection |
| `tests/Core/PluginUpdateOrdererTest.php` | New | Moved from plugin |
| `tests/Core/PluginSchemaResyncerTest.php` | New | Moved from plugin |
| `plugins/system_updater/tests/` | Modified | Updated to delegate to core |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| FK silently dropped on false positive (metadata stale) | Med | Validator only strict on MySQL + present metadata; mismatch logged via error_log; FK re-attempted by compare_constraints later |
| `system_updater` version coupling breaks plugin installs on older cores | Med | D3: min_version bump with API-compatible signatures; BC wrapper fallback documented |
| information_schema query cost on big DBs | Low | Validator runs only for FK-bearing CREATEs (small subset) |
| Behavioral change for existing aligned FKs | Low | No-op when collation matches — existing tests guard the keep path |

## Rollback Plan

- Revert core commits (new classes are additive; `SchemaComparator`/`fs_schema` changes are small, localized to FK decision points). Old behavior returns: FKs kept, sync may fail with errno 150 — pre-existing condition, no new data loss.
- Revert plugin delegation: restore `plugins/system_updater/lib/` classes and admin_updater references (single commit each, per work-unit-commits).
- No DDL is executed by the validator itself; nothing to undo in the DB.

## Dependencies

- Core version bump (0.17.0 → next) before/with the `system_updater` min_version change.
- `system_updater` repo follow-up commit (plugin is a separate git repo).

## Success Criteria

- [ ] `FkCompatibilityValidatorTest`, `SchemaComparatorTest` extension, `FsSchemaTest` green via `ddev exec php vendor/bin/phpunit` (no DB).
- [ ] FK to a utf8mb3 parent is omitted in generated CREATE DDL on collation mismatch; kept when collations match.
- [ ] Table creation succeeds (no errno 150) in the mismatch scenario at unit level.
- [ ] Core `PluginUpdateOrdererTest` + `PluginSchemaResyncerTest` pass; `system_updater` tests green delegating to core classes.
- [ ] D3 delivery decision documented and applied (min_version bump or BC wrappers).
- [ ] Full suite (Base/Core/Security/Plugins) green; phpstan level 5 clean on new files.
