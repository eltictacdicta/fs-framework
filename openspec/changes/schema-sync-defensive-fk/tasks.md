# Tasks: schema-sync-defensive-fk

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1100 (validator ~120 + wiring ~40 + core classes ~330 moved + tests ~600 new) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (FK validator + wiring) → PR 2 (core centralization) → plugin repo PR 3 (delivery-sequenced) → PR 4 (docs) |
| Delivery strategy | ask-on-risk |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | FkCompatibilityValidator + wiring into both builders | PR 1 | `ddev exec php vendor/bin/phpunit tests/Core/FkCompatibilityValidatorTest.php tests/Core/FsSchemaTest.php tests/Core/SchemaComparatorTest.php` | N/A — no-DB unit, fake-db interception | revert validator + SchemaComparator/fs_schema wiring; additive, localized to FK decision points |
| 2 | Centralize PluginUpdateOrderer/PluginSchemaResyncer in core + move tests | PR 2 | `ddev exec php vendor/bin/phpunit tests/Core/PluginUpdateOrdererTest.php tests/Core/PluginSchemaResyncerTest.php` | N/A — moved code, same unit semantics, anonymous fs_plugin_manager | revert core classes + tests; plugin not yet switched |
| 3 | system_updater delegates to core + bump min_version (plugin repo) | PR 3 (delivery-sequenced AFTER core release 0.18.0) | `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` | N/A — admin_updater is a legacy controller not unit-testable here; delegate verified via suite + code review of injected requirementsFn | revert admin_updater + restore lib/ classes + ini min_version; single plugin commit |
| 4 | SDD artifacts commit (core repo) | PR 4 | n/a | n/a | n/a |

## Phase 1: Core — FkCompatibilityValidator + wiring (PR 1)

- [x] 1.1 RED: create `tests/Core/FkCompatibilityValidatorTest.php` (namespace `Tests\Core`, fake-db `select()` intercepting `information_schema.columns` and `@@` queries, `error_log` capture via `ini_set` temp file). Test cases per design: match→true; collation mismatch→false+warn; charset mismatch→false; type mismatch (varchar(32) vs int(11))→false; non-collatable int→true; `isMySql:false`→true; missing metadata→true; missing referenced column→true. Run `ddev exec php vendor/bin/phpunit --filter FkCompatibilityValidatorTest` → fails (class missing).
- [x] 1.2 GREEN: create `src/Database/FkCompatibilityValidator.php` — `final class`, `__construct(private object $db, private ?bool $isMySql = null)`, `public static parseFkParts(string $constraintSql): ?array{localColumn, refTable, refColumn}` (regex seeded from dead `base/fs_mysql.php::parse_fk_collation` :782-816), `public isFkCompatible(string $constraintSql, array $localColInfo): bool`. Strict-omit ONLY on MySQL with present metadata and real mismatch; allow on non-MySQL, non-collatable type, missing metadata, missing referenced column (trap a/b/c/f). Normalize via `TypeNormalizer::convertPostgresType`, lowercase, strip int display width, keep varchar(n) (trap b). `error_log` warning on omit. Run filter → green.
- [x] 1.3 RED: extend `tests/Core/SchemaComparatorTest.php` — extend `createSchemaDb()` fake to serve `information_schema.columns` rows; add omit-on-collation-mismatch + keep-on-match cases (collatable XML local type, e.g. `character varying(32)`). Run `ddev exec php vendor/bin/phpunit --filter SchemaComparatorTest` → new cases fail.
- [x] 1.4 GREEN: modify `src/Database/SchemaComparator.php` — `validateFkConstraints(array $xmlCons)` gains `$xmlCols` param (resolve local type via `TypeNormalizer::convertPostgresType` from `$xmlCols`); `shouldKeepFkConstraint(array $con, array $tableNames, array $xmlCols)` consults lazily-built `FkCompatibilityValidator` after the table-exists check; propagate `$xmlCols` from `generateTable`. Local FK metadata at CREATE time from XML cols + DB `@@` defaults, NEVER information_schema (trap a). Run filter → green.
- [x] 1.5 RED: create `tests/Core/FsSchemaTest.php` — `require_once base/fs_schema.php`; Reflection-inject fake db into `fs_schema::$db`; assert FK omitted/present in SQL passed to `exec()` for `createTable`→`collectConstraints`→`addForeignKeyConstraint`. Run `ddev exec php vendor/bin/phpunit --filter FsSchemaTest` → fails.
- [x] 1.6 GREEN: modify `base/fs_schema.php` — `collectConstraints($xml, $isMySQL, $validateFks)` parses local FK column via `FkCompatibilityValidator::parseFkParts`, resolves type from `$xml->columna` (`convertType`), passes `$localColInfo` as new trailing param to private `addForeignKeyConstraint($query, $name, $db, array &$constraints, bool $isMySQL, bool $validateFks, array $localColInfo = [])`; `addForeignKeyConstraint` consults `FkCompatibilityValidator`. Run filter → green.
- [x] 1.7 Full suite for PR 1: `ddev exec php vendor/bin/phpunit` (Base/Core/Security/Plugins) green. Commit `feat(database): add FkCompatibilityValidator and wire both schema builders` (validator + both builders + 3 test files).

## Phase 2: Core — Centralize PluginUpdateOrderer/PluginSchemaResyncer (PR 2)

- [x] 2.1 RED: copy `plugins/system_updater/tests/PluginUpdateOrdererTest.php` + `PluginSchemaResyncerTest.php` to `tests/Core/`, change namespace `Tests\SystemUpdater`→`Tests\Core`, `use` core classes, drop `require_once` lib lines (PSR-4). Run `ddev exec php vendor/bin/phpunit --filter "PluginUpdateOrdererTest|PluginSchemaResyncerTest"` → fails (core classes missing).
- [x] 2.2 GREEN: create `src/Core/Plugin/PluginUpdateOrderer.php` (namespace `FSFramework\Core\Plugin`, `final`, Kahn algorithm unchanged). Default `requirementsFn` = `LocalPluginRequirementsReader(FS_FOLDER.'/plugins')->read()`; default `isInstalledFn` = `is_dir(FS_FOLDER.'/plugins/'.$name)` (true if FS_FOLDER undefined) (trap c — MUST NOT default to CatalogPluginInstallProvider). No `require_once`. Run filter → green.
- [x] 2.3 GREEN: create `src/Core/Plugin/PluginSchemaResyncer.php` (namespace `FSFramework\Core\Plugin`, `final`, same static API: `resyncInstalled(\fs_plugin_manager $manager, ?string $only, ?callable $requirementsFn, ?callable $isInstalledFn): array`, `withDependencyVisibility(string $pluginName, callable $callback, ?callable $requirementsFn, ?callable $isInstalledFn): mixed`). References `\fs_plugin_manager` (acceptable — PluginSchemaSynchronizer does). No `require_once`. Run filter → green.
- [x] 2.4 Full suite green; phpstan level 5 clean on the 2 new core files. Commit `refactor(core): centralize PluginUpdateOrderer/PluginSchemaResyncer from system_updater` (core classes + moved core tests).

## Phase 3: Plugin repo — system_updater delegation (PR 3 — DELIVERY-SEQUENCED)

> **Estado: pendiente release core 0.18.0** — esta fase NO se ejecuta en este apply run (scope guard). Se implementa en el repo del plugin `plugins/system_updater/` tras el release del core 0.18.0 (fsframework-core-release: VERSION bump + tag). No se han tocado archivos de plugins/system_updater.

- [ ] 3.1 BLOCKER: do NOT start this phase until core 0.18.0 is released (fsframework-core-release skill: VERSION bump + tag). Runs in separate git repo `plugins/system_updater/`. `[ ] (pendiente release core 0.18.0)`
- [ ] 3.2 Modify `plugins/system_updater/controller/admin_updater.php` — add `use FSFramework\Core\Plugin\{PluginUpdateOrderer, PluginSchemaResyncer};`; remove `require_once __DIR__.'/../lib/PluginUpdateOrderer.php'` (:757, :838) and `require_once __DIR__.'/../lib/PluginSchemaResyncer.php'` (:912, :947). `[ ] (pendiente release core 0.18.0)`
- [ ] 3.3 Inject catalog-backed `requirementsFn` at BOTH `order()` sites (:765 `order($pendingNames)`, :843 `order($toUpdate)`) AND BOTH `withDependencyVisibility()` sites (:961, :996): `fn(string $n): array => \FSFramework\Core\Plugin\PluginInstallProviderRegistry::get()->getDirectRequirements($n)` (memoized). `resyncInstalled` (:920) keeps the default (trap d — missing catalog-backed injection at withDependencyVisibility regresses dependency visibility during download/sync). `[ ] (pendiente release core 0.18.0)`
- [ ] 3.4 Delete `plugins/system_updater/lib/PluginUpdateOrderer.php` + `lib/PluginSchemaResyncer.php` (trap c — core is canonical, no duplicates per SS-05). `[ ] (pendiente release core 0.18.0)`
- [ ] 3.5 Delete `plugins/system_updater/tests/PluginUpdateOrdererTest.php` + `PluginSchemaResyncerTest.php` (moved to core; no duplicates SS-05). CatalogPluginInstallProviderTest stays. `[ ] (pendiente release core 0.18.0)`
- [ ] 3.6 Modify `plugins/system_updater/fsframework.ini` — `min_version = "0.13"` → `"0.18"` (SS-06, D5). `[ ] (pendiente release core 0.18.0)`
- [ ] 3.7 Run `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` → green. Commit `refactor(system_updater): delegate schema sync to core classes + bump min_version to 0.18`. `[ ] (pendiente release core 0.18.0)`

## Phase 4: Docs (PR 4)

- [x] 4.1 Commit SDD artifacts: `openspec/changes/schema-sync-defensive-fk/` (proposal.md, design.md, tasks.md, specs/) + `openspec/changes/schema-sync-defensive-fk/state.yaml` → `docs(openspec): schema-sync-defensive-fk SDD artifacts` (core repo). *(Nota: este repo no usa state.yaml por cambio — convención verificada: ningún change tiene state.yaml; se commiteó el árbol openspec/changes/schema-sync-defensive-fk/ completo.)*

## Phase 5: Final verification

- [x] 5.1 `ddev exec php vendor/bin/phpunit` (full root suite) green. *(Verificado por suites: Base 180 OK, Core 111 OK, Security 189 OK, Traits 15 OK, Cache 18 OK. El root suite completo no arranca por fallos PREEXISTENTES fuera de scope: plugins/factura_pdf1/vendor/rospdf/pdf-php/tests/CpdfTest.php rompe la discovery del suite Plugins en tiempo de carga; tests/Components/PublicAccessGateTest.php y tests/Integration/StealthSessionBootstrapTest.php tienen firma exec() de fs_db2 anónimo incompatible. Confirmado preexistente vía git stash.)*
- [ ] 5.2 `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` green. *(Fase 3 pendiente release core 0.18.0 — no ejecutado en este run.)*
- [x] 5.3 phpstan level 5 clean on new core files (`src/Database/FkCompatibilityValidator.php`, `src/Core/Plugin/PluginUpdateOrderer.php`, `src/Core/Plugin/PluginSchemaResyncer.php`). *(Command: `ddev exec php vendor/dev-tools/bin/phpstan analyse <3 files> --level=5 --memory-limit=1G` → No errors.)*

## Implementation traps (must hold)

- (a) local FK column metadata at CREATE time from XML cols + DB `@@` defaults, NEVER `information_schema.columns` (table does not exist yet).
- (b) type normalization exact-after-normalization: `TypeNormalizer::convertPostgresType`, lowercase, strip int display width, keep varchar(n) exact.
- (c) core `PluginUpdateOrderer` default = `LocalPluginRequirementsReader`, NOT `CatalogPluginInstallProvider`.
- (d) `withDependencyVisibility` sites in admin_updater MUST inject catalog-backed requirementsFn or dependency visibility regresses.
- (e) non-MySQL / non-collatable / missing metadata → permissive allow (SS-02).
- (f) referenced-column-missing → permissive allow.
- (g) Symfony-first: do NOT add doctrine/dbal or any new Symfony/Doctrine dependency for FK validation — no integrated Symfony component covers schema management (verified: no doctrine/dbal in composer.json or vendor/). Keep the logic in the modern `src/Database/` PSR-4 layer, consistent with design D6. Adding Doctrine DBAL is a documented follow-up, OUT of scope.
