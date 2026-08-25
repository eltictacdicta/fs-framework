# Design: schema-sync-defensive-fk

## Technical Approach

One shared `FkCompatibilityValidator` gates FK emission in BOTH CREATE builders (`SchemaComparator` and `fs_schema`): on real charset/collation/type mismatch it omits the FK, logs a Spanish `error_log` warning, and lets table creation proceed (SS-01/SS-02/SS-03). In parallel, `PluginUpdateOrderer`/`PluginSchemaResyncer` move from `system_updater` into core with a core-owned default requirements source, and the plugin delegates with injected catalog-backed callables (SS-05/SS-06).

## Architecture Decisions

| # | Decision | Alternatives | Rationale |
|---|----------|--------------|-----------|
| D1 | Validator API: `final class FkCompatibilityValidator` with `__construct(object $db, ?bool $isMySql = null)` and `isFkCompatible(string $constraintSql, array $localColInfo): bool`; `public static parseFkParts(string $constraintSql): ?array{localColumn, refTable, refColumn}` seeds the shared parser (D4 of proposal) | `(object $db, ?SchemaInspector)` mirroring SchemaComparator | Inspector adds nothing — metadata comes from `$db->select()` on `information_schema.columns`; `?bool $isMySql` is the testability seam (fs_schema already threads `$isMySQL`; FS_DB_TYPE=MYSQL in test bootstrap prevents constant redefinition) |
| D2 | `$localColInfo = ['name' => string, 'type' => string, 'charset' => ?string, 'collation' => ?string]`. Local charset/collation default to DB `@@` config (queried once, cached), because BOTH builders emit `DEFAULT CHARSET/COLLATE` from those same `@@` vars | Query local column from `information_schema` | At CREATE time the local table does NOT exist — querying it would always yield "missing metadata" → permissive → never omits. This is the key trap avoided |
| D3 | Strict-omit ONLY when: charset differs, collation differs, or normalized type differs. Normalization: XML side via `TypeNormalizer::convertPostgresType`, lowercase, strip integer display width (`int(n)`→`int`), keep char length (`varchar(n)` exact). Missing metadata / missing referenced column / non-collatable local type / non-MySQL → allow (SS-02) | Full MySQL identical-type rule vs family-only | MySQL requires identical charset+collation+type for FK columns; exact-after-normalization avoids XML variance false omissions (varchar vs character varying) while still catching real drift; int display width varies across MySQL 8 versions |
| D4 | Core `PluginUpdateOrderer::order()` default `requirementsFn` = `LocalPluginRequirementsReader(FS_FOLDER.'/plugins')->read()` (local `fsframework.ini`/`facturascripts.ini`); default `isInstalledFn` = `is_dir(FS_FOLDER.'/plugins/'.$name)` (true if FS_FOLDER undefined) | Keep `CatalogPluginInstallProvider` as default | Core MUST NOT depend on a plugin class (SS-05). Injection story: system_updater passes `fn(string $n): array => $this->catalogProvider()->getDirectRequirements($n)` (memoized `CatalogPluginInstallProvider`) at the 2 `order()` sites (:765, :843) AND the 2 `withDependencyVisibility()` sites (:961, :996) — the download path NEEDS catalog fallback or dependencies of a not-yet-installed plugin are invisible during sync (the exact failure the resyncer fixes). `resyncInstalled` (:920) keeps the default (installed plugins carry local ini) |
| D5 | Delivery coupling: core 0.17.0 → **0.18.0** (MINOR — new public classes). `system_updater` `min_version` "0.13" → "0.18" (SS-06). VERSION bump + tag happens at release time per fsframework-core-release skill; design pins the target + coupling | BC wrappers in plugin | Plugin loads only against cores with the centralized classes; no duplicate implementations (SS-05) |
| D6 | Symfony-first delegation | Adopt Doctrine DBAL / delegate FK validation to a Symfony schema component | No integrated Symfony/Doctrine solution exists for schema FK validation: `composer.json` and `vendor/` contain NO `doctrine/dbal`, and the installed Symfony components (cache, validator, yaml, etc.) do not cover schema management. The new logic therefore lives in the modern PSR-4 layer `src/Database/` (home of SchemaComparator/SchemaInspector), honoring the legacy `base/` + modern `src/` Symfony-first split WITHOUT adding a new dependency. Adding Doctrine DBAL is a separate architectural decision (core `vendor/` is intentionally versioned) — documented follow-up, OUT of scope. |

## Data Flow

```
fs_model::check_table → fs_db2::generate_table → fs_mysql::generate_table
  → SchemaComparator::generateTable → validateFkConstraints(xmlCons, xmlCols)
    → shouldKeepFkConstraint → FkCompatibilityValidator::isFkCompatible → omit/keep FK

PluginSchemaSynchronizer::syncXmlTables → fs_schema::syncPluginTables
  → collectConstraints(xml, isMySQL, validateFks) [resolves local col type from xml->columna]
    → addForeignKeyConstraint(query, name, db, …, localColInfo)
      → FkCompatibilityValidator::isFkCompatible → omit/keep FK

admin_updater → PluginUpdateOrderer::order / PluginSchemaResyncer::*   (use-imported core classes)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/Database/FkCompatibilityValidator.php` | Create | Final class; `parseFkParts()` (regex seeded from dead `fs_mysql::parse_fk_collation`), `isFkCompatible()`, permissive defaults, `error_log` warning on omit |
| `src/Database/SchemaComparator.php` | Modify | `validateFkConstraints(array $xmlCons, array $xmlCols)`; `shouldKeepFkConstraint(array $con, array $tableNames, array $xmlCols)` — after table-exists check, resolve local type from `$xmlCols` via `TypeNormalizer::convertPostgresType` and consult lazily-built validator |
| `base/fs_schema.php` | Modify | `collectConstraints` parses local FK column via `FkCompatibilityValidator::parseFkParts`, resolves type from `$xml->columna` (`convertType`), passes `$localColInfo` to `addForeignKeyConstraint` (new trailing param, private → safe) |
| `src/Core/Plugin/PluginUpdateOrderer.php` | Create | Moved from `plugins/system_updater/lib/` (namespace `FSFramework\Core\Plugin`, final); Kahn algorithm unchanged; defaults per D4 |
| `src/Core/Plugin/PluginSchemaResyncer.php` | Create | Moved; same static API (`resyncInstalled(\fs_plugin_manager $manager, ?string $only, ...)`, `withDependencyVisibility`); references `\fs_plugin_manager` — acceptable (PluginSchemaSynchronizer already references base classes); no `require_once` (PSR-4) |
| `tests/Core/FkCompatibilityValidatorTest.php` | Create | Fake-db `select()` interception; `error_log` capture via `ini_set` temp file (PluginUpdateOrdererTest cycle pattern) |
| `tests/Core/SchemaComparatorTest.php` | Modify | Extend `createSchemaDb` fake to serve `information_schema.columns` rows; add omit-on-mismatch + keep-on-match cases (collatable XML type required, e.g. `character varying(32)`) |
| `tests/Core/FsSchemaTest.php` | Create | `require_once base/fs_schema.php`; inject fake db into `fs_schema::$db` via Reflection; assert FK omitted/present in SQL passed to `exec()` |
| `tests/Core/PluginUpdateOrdererTest.php` | Create | Moved from plugin; namespace `Tests\Core`; `use` core classes; no `require_once` |
| `tests/Core/PluginSchemaResyncerTest.php` | Create | Moved; anonymous `fs_plugin_manager` subclass (empty-constructor pattern) |
| `plugins/system_updater/lib/PluginUpdateOrderer.php` | Delete | Replaced by core class (plugin repo commit) |
| `plugins/system_updater/lib/PluginSchemaResyncer.php` | Delete | Replaced by core class (plugin repo commit) |
| `plugins/system_updater/controller/admin_updater.php` | Modify | Add `use FSFramework\Core\Plugin\{PluginUpdateOrderer, PluginSchemaResyncer};`; remove `require_once` lib lines (:757, :838, :912, :947); inject catalog-backed `requirementsFn` at :765, :843, :961, :996 |
| `plugins/system_updater/fsframework.ini` | Modify | `min_version = "0.18"` (plugin repo commit) |
| `plugins/system_updater/tests/PluginUpdateOrdererTest.php` + `PluginSchemaResyncerTest.php` | Delete | Moved to core (plugin repo commit; SS-05 no duplicates) |

## Interfaces / Contracts

```php
namespace FSFramework\Database;
final class FkCompatibilityValidator {
    public function __construct(private object $db, private ?bool $isMySql = null) {}
    /** @param array{name?: string, type?: string, charset?: ?string, collation?: ?string} $localColInfo */
    public function isFkCompatible(string $constraintSql, array $localColInfo): bool;
    /** @return array{localColumn: string, refTable: string, refColumn: string}|null */
    public static function parseFkParts(string $constraintSql): ?array;
}
```
Warning format (matches fs_schema style): `error_log("Advertencia: Foreign key '{$name}' omitida - incompatibilidad de collation (local utf8mb4_unicode_ci vs referenciada utf8mb3_general_ci). Query: {$query}")` — variants for charset and type.

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | Validator | `FkCompatibilityValidatorTest`: match→true; collation mismatch→false+warn; charset mismatch→false; type mismatch (varchar(32) vs int(11))→false; non-collatable int→true; `isMySql:false`→true; missing metadata→true; missing referenced column→true. Fake db `select()` returns configured rows for `information_schema.columns` / `@@` queries |
| Unit | SchemaComparator | Extend: FK omitted in generated DDL on collation mismatch; kept on match (collatable local type) |
| Unit | fs_schema | `FsSchemaTest` (NEW): Reflection-injected `self::$db` fake; FK omitted/present in `createTable`→`exec()` SQL |
| Unit | Orderer/Resyncer | Moved core tests: Kahn ordering, cycles (error_log), `$GLOBALS['plugins']` snapshot/restore, exception propagation, fake callables + anonymous `fs_plugin_manager` |
| Plugin | system_updater | Suite green after delegation; catalog provider tests unchanged |

All no-DB via `ddev exec php vendor/bin/phpunit`. RED first (strict_tdd).

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. (Commit sequencing below is delivery practice, not a runtime boundary.)

## Migration / Rollout

No migration; validator executes no DDL. Default-on. Order: (1) core commit 1 + 2, (2) core release 0.18.0 (fsframework-core-release), (3) system_updater repo commit. SS-04 recovery: omitted FKs are re-attempted by the existing `compare_constraints` path once columns align.

## Commit Units

- `feat(database): add FkCompatibilityValidator and wire both schema builders` — validator + SchemaComparator/fs_schema wiring + validator/SchemaComparator/FsSchema tests
- `refactor(core): centralize PluginUpdateOrderer/PluginSchemaResyncer from system_updater` — core classes + moved core tests
- `docs(openspec): schema-sync-defensive-fk SDD artifacts` — proposal/spec/design/tasks/verify (core repo)
- system_updater repo: `refactor(system_updater): delegate schema sync to core classes + bump min_version to 0.18` — admin_updater + ini + lib/tests removal

Conventional commits only; no Co-Authored-By. Estimated 400-line budget risk: Low–Medium (validator ~120 lines, wiring ~40, core classes ~330 moved, tests ~500 new).

## Open Questions

- [ ] None blocking. Follow-ups (out of scope, documented in proposal): `compareColumns` collation ALTER-alignment; unified referenced-table parser across `SchemaComparator`/`fs_schema`/`fs_mysql`; dead-code cleanup in `base/fs_mysql.php`.