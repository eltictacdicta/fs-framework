# Design: admin-only-pages

- **Change**: `admin-only-pages` (CORE FSFramework change)
- **Artifact store**: openspec
- **Phase**: design
- **Inputs**: `exploration.md`, `proposal.md`, `specs/page-authorization/spec.md` (PA-01…PA-10)

## Technical Approach

A class-level `#[AdminOnly]` marker is read by **name string** only, resolved to a boolean, and mirrored on `fs_pages.admin_only` so the role UI can query it without instantiating controllers. Enforcement is layered: listing filters always, `fs_rol_access::save()` refuses unconditionally, and `fs_user::get_menu()`'s non-admin branch skips the pages. A one-shot idempotent migration adopts the column, backfills the 9-name allowlist, purges stale grants, clears caches, and records an `fs_var` flag.

## Architecture Decisions

| # | Decision | Choice | Alternative rejected | Rationale |
|---|---|---|---|---|
| D1 | Where the attribute is read | `fs_page::is_admin_only_class(string $class, string $expected = AdminOnly::class): bool` — one shared static used by both paths | Duplicate reflection in `fs_controller` and modern `Controller` | Single tested source; comparing `getName()` never autoloads/instantiates the attribute |
| D2 | Effective value | `fs_page::resolve_admin_only(bool $attr, $pageData): bool` = `$attr || filter_var($pageData, FILTER_VALIDATE_BOOLEAN)` | Store attribute and pageData separately | Fail-closed OR-escalation; attribute cannot be downgraded |
| D3 | Migration placement | Called immediately **after** `fs_schema::selfHealCoreTables()` in each entry point, in its own try/catch | Hook into `Kernel::boot` / `system_updater` | Same guarantee window as self-heal; runs before any controller is constructed |
| D4 | Migration class shape | Non-final `AdminOnlyPagesMigration` with overridable step methods (`forgetCheckedTables`, `adoptColumn`, `backfill`, `purgeStaleGrants`, `clearPageCache`, `markApplied`) | Final class with private steps | Harness has no DB; anonymous subclass overrides steps to test ordering/failure |
| D5 | Missing page in guard | Treat absent `fs_pages` row as **not** admin-only (allow save) | Fail-closed refuse | Only existing rows can be reached; FK already blocks orphans; refusing adds a new install/repair failure mode with no security gain |
| D6 | Menu composition | Extract `fs_user::compose_menu(array $pages, array $allowed, bool $admin, bool $demo): array` | Inline loop | Pure function, directly unit-testable for PA-06/PA-09 |
| D7 | Attribute reader test seam | Optional 2nd parameter `$expected` (default `AdminOnly::class`) | Hard-coded FQCN | Lets tests use a non-loadable FQCN and an exploding spy attribute to prove name-string-only matching |
| D8 | XML default literal | `<defecto>false</defecto>` | `<defecto>0</defecto>` | `fs_postgresql::compare_columns` emits the raw default; `0` is invalid for PG `boolean`, `false` is valid for both engines |

## 1. Migration Hook Point

Insert after the existing self-heal try/catch, unchanged in each file:

```php
try { \FSFramework\Core\Schema\AdminOnlyPagesMigration::run(); }
catch (\Throwable $e) { error_log('AdminOnlyPagesMigration bootstrap failed: ' . $e->getMessage()); }
```

| Entry point | Insert after | Runs before |
|---|---|---|
| `index.php` | line 88 (`selfHealCoreTables` catch) | `Kernel::boot()` (91), modern dispatch (250/288), legacy `new $controller` (319) |
| `api.php` | line 134 (self-heal catch) | `api.runtime->handle()` (137) |
| `cron.php` | line 56 (self-heal catch) | plugin `cron.php` execution (101) |

Why here: `selfHealCoreTables()` guarantees `fs_vars`/`fs_pages` exist and loads `fs_model` (`loadModelDependencies`, `fs_schema.php:817`). `Kernel::boot()` only loads the enabled-plugin list; it does not construct page controllers or read the menu, so this window is before **any** page access on a fresh request. `api.php` already requires `fs_model` (line 115); `cron.php` calls `require_all_models()` (line 48). `AdminOnlyPagesMigration::execute()` owns guarded `require_once` of `base/fs_model.php`, `model/fs_page.php`, `model/fs_var.php`, `base/fs_cache.php` (by `class_exists(..., false)`), so it is safe even if self-heal threw.

## 2. Column Adoption Mechanics

1. `model/table/fs_pages.xml` gains `<nombre>admin_only</nombre><tipo>boolean</tipo><nulo>NO</nulo><defecto>false</defecto>`.
2. Migration step (a) `fs_model::forgetCheckedTables(['fs_pages'])` (`base/fs_model.php:444`) drops the name from the static `$checked_tables` array **and** rewrites the `fs_checked_tables` cache key (5400s TTL, `:116`/`:134`).
3. Step (b) `new \fs_page()` re-enters `fs_model::__construct` → `check_table('fs_pages')` → `buildExistingTableSql` → `db->compare_columns()`.
   - **MySQL** (`fs_mysql.php:132` → `SchemaComparator::buildAddColumnSql`): `boolean`→`TINYINT(1)`, `false`→`0` via `TypeNormalizer::normalizeDefault` (FS_DB_TYPE MYSQL). Emits `ALTER TABLE fs_pages ADD \`admin_only\` TINYINT(1) NOT NULL DEFAULT 0;` — valid.
   - **PostgreSQL** (`fs_postgresql.php:83`): raw `boolean` + raw `false`. Emits `ALTER TABLE "fs_pages" ADD COLUMN "admin_only" boolean DEFAULT false NOT NULL;` — valid.
   - Re-running is a no-op: `compare_columns` finds the column and emits `''`.
4. `fs_page` gets `public $admin_only`; constructor `$this->admin_only = $this->str2bool($data['admin_only'] ?? FALSE)` / default `FALSE`; `save()` writes it in UPDATE + INSERT; `__clone()` block adds `$page->admin_only = $this->admin_only;` (note: PHP shallow-copies before `__clone`, so the clone already carries it).

`fs_schema::syncColumns()` is an equivalent fallback but is not used: it runs from plugin sync only, not the core bootstrap.

## 3. Effective Value / `getPageData()` / `mustUpdatePage()`

- **Legacy** (`base/fs_controller.php:820`): `$adminOnly = fs_page::is_admin_only_class(get_class($this));` — the concrete controller, never `$name`. Included in the `fs_page` payload (`:822`). `mustUpdatePage(..., bool $adminOnly)` gains `|| $page->admin_only != $adminOnly`; `updateExistingPage()` sets `$page->admin_only = $adminOnly;`.
- **Modern** (`src/Core/Base/Controller.php:159`): `$adminOnly = fs_page::resolve_admin_only(fs_page::is_admin_only_class(static::class), $pageData['admin_only'] ?? null);` set on both the update path (`:164-169`) and the create payload (`:174-181`).
- **Precedence**: attribute `true` always wins; `getPageData()['admin_only'] === false` or absent cannot downgrade it (PA-03). Neither source → `false` (PA-10). Legacy has no `getPageData()`, so its effective value is the attribute alone. All 9 scoped pages declare the attribute, so the backfilled `true` is never reset on their next visit.

## 4. Cache Invalidation

`fs_page::all()` caches under `m_fs_page_all` (`model/fs_page.php:204`,`:216`). The migration's raw `UPDATE`/`DELETE` bypass `fs_page::save()`→`clean_cache()` (`:192`), so step (e) explicitly calls `(new \fs_cache())->delete('m_fs_page_all')`. `fs_page::save()` continues to invalidate for normal writes; the modern create path's `$this->cache->delete('m_fs_page_all')` (`Controller.php:183`) stays redundant but harmless.

## 5. Guard Placement (`fs_rol_access::save()`)

At the very top of `save()` (`model/fs_rol_access.php:68`), before `exists()`/SQL:

```php
if ($this->is_admin_only_page((string) $this->fs_page)) { return false; }

protected function is_admin_only_page(string $name): bool
{
    if ($name === '') { return false; }
    $page = (new \fs_page())->get($name);   // SELECT * FROM fs_pages WHERE name = <var2str>; (PK, single indexed lookup)
    return $page !== false && $page->admin_only === true;
}
```

Missing row → allow (D5). This protects the core role editor and the direct writer `plugins/factura_pdf1/.../LegacyRolePermissionsGateway.php:56-67`.

## 6. Testability Seam (PA-01…PA-10)

Harness: `ddev exec php vendor/bin/phpunit`, PHPUnit 11, no DB. Use anonymous `fs_model` subclasses with empty constructors + injected mock `$db`, `CacheManager::reset()` in `setUp()`.

| Spec | Test | Seam |
|---|---|---|
| PA-01 | `tests/Base/AdminOnlyAttributeTest.php` | Plain fixtures (legacy/modern) with `#[AdminOnly]`; a fixture annotated with a **non-loadable** FQCN + resolver 2nd arg; an `ExplodingAttribute` whose ctor throws → resolution true, no throw (proves no `newInstance()`, verified: `getName()` needs no autoload) |
| PA-02 | `tests/Base/FsPageAdminOnlyTest.php` | Anonymous `fs_page` subclass (empty ctor) + mock db; default false, save SQL contains `admin_only`, `clone` subclass stub `__clone()` preserves it (limitation below) |
| PA-03 | same file | Call `fs_page::resolve_admin_only()` truth table (attr-only, data-only, false-key no-downgrade, both absent) |
| PA-04 | `tests/Controller/AdminOnlyListingTest.php` | `newInstanceWithoutConstructor()` + reflection-set `$this->menu` / `$this->rol`; assert all three `all_pages()` exclude admin-only for admin and non-admin |
| PA-05 | `tests/Base/FsRolAccessAdminOnlyTest.php` | Anonymous `fs_rol_access` subclass overriding `is_admin_only_page()` true/false; assert `save()` false and no SQL / normal save reaches db |
| PA-06 | `tests/Base/FsUserComposeMenuTest.php` | Call `fs_user::compose_menu()` with synthetic page/allowed arrays (stale grant excluded; admin keeps all) |
| PA-07 | `tests/Controller/AdminOnlyScopeTest.php` | Static source scan of the 9 controller paths + `admin_home` absence (pattern of `AdminAuthorityGuardsTest`) |
| PA-08 | `tests/Core/AdminOnlyPagesMigrationTest.php` | Anonymous migration subclass overriding each step as a recorder; assert order, flag written only on success, throw → no flag; assert `PAGE_ALLOWLIST` excludes `admin_custom` |
| PA-09 | `FsUserComposeMenuTest` | `compose_menu(..., demo: true)` returns all pages |
| PA-10 | resolver + subclass tests | Absent attribute/key/legacy row → `false` |

**Two flagged ambiguities resolved**: (1) migration trigger is the three bootstrap post-self-heal blocks (§1) — a source-scan assertion can pin the call ordering; (2) `m_fs_page_all` invalidation *is* observable: `fs_cache` is a thin wrapper over the process-wide `CacheManager` (`base/fs_cache.php:59`), so populate via a mock-backed `all()`, delete the key, change the mock result, and `all()` returns the new list — with `CacheManager::reset()` in `setUp()` for isolation. Limitation: the `__clone` test stubs `__clone()` because the inherited body constructs a real `fs_page` (DB); the flag preservation is PHP shallow-copy semantics.

## 7. Files to Change (implementation order)

1. `src/Attribute/AdminOnly.php` — new marker class (`TARGET_CLASS`).
2. `model/table/fs_pages.xml` — add `admin_only` column.
3. `model/fs_page.php` — property, ctor, `save()`, `__clone()`, `is_admin_only_class()`, `resolve_admin_only()`.
4. `src/Core/Schema/AdminOnlyPagesMigration.php` — new migration.
5. `index.php`, `api.php`, `cron.php` — invoke migration post-self-heal.
6. `base/fs_controller.php` — attribute read, payload, `mustUpdatePage`/`updateExistingPage`.
7. `src/Core/Base/Controller.php` — OR-escalation in `resolveOrCreatePage()`.
8. `model/core/fs_user.php` — `compose_menu()` + non-admin filter in `get_menu()`.
9. `model/fs_rol_access.php` — `is_admin_only_page()` guard in `save()`.
10. `controller/admin_rol.php`, `admin_users.php`, `admin_user.php` — skip `admin_only` in `all_pages()`.
11. The 9 core controllers — add `#[\FSFramework\Attribute\AdminOnly]`.
12. Tests (PA-01…PA-10) then docs/instruction parity (proposal list).

No `fs_plugin_manager.php` change: both registration paths instantiate the controller and save `$controller->page` (`:707`, `:758`).

## 8. Tradeoffs, Rejected Alternatives, Unverified

- **Rejected**: revive `$admin` (silent BC, fixed non-goal); `LIKE 'admin_%'` backfill (over-broad vs fixed allowlist); fail-closed missing-page guard (D5).
- **Tradeoff**: non-final migration class exists only for the no-DB seam; documented.
- **Tradeoff**: `fs_rol_access::save()` now constructs `fs_page` per call (schema check cached after first) in exchange for closing the direct-writer path.
- **Unverified**: exact live row counts; whether any writer of `fs_pages` exists outside the two registration paths (exploration found none); the `__clone()` production path (no call sites found).

## Threat Matrix

N/A — no shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. The change alters application-level page authorization only.

## Migration / Rollout

One-shot, idempotent, gated by `fs_var` `admin_only_pages_migrated`; rollback is code revert + clearing the flag and the two caches (proposal §Rollback).

## Open Questions

None blocking.
