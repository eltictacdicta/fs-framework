# Apply Progress: admin-only-pages

- **Change**: `admin-only-pages` (CORE FSFramework change)
- **Artifact store**: openspec
- **Phase**: apply
- **Mode**: Strict TDD (`strict_tdd: true`)
- **Delivery**: `single-pr` with maintainer-approved `size:exception` (no git commit; working tree only)

## Summary

All 7 phases of `tasks.md` (1.1–7.7) are implemented. The declaration
(`#[AdminOnly]`), its persisted mirror (`fs_pages.admin_only`), the one-shot
migration, both construction paths, the three enforcement layers and the 9-page
scope are complete, with 61 new hermetic tests.

## Commands and exact results

| Command | Result |
|---|---|
| `ddev exec php vendor/bin/phpunit` (baseline, before) | 2293 tests, 7718 assertions, **2 pre-existing failures** (`plugins/system_updater/tests/MaintenanceModeCompatTest.php`, environment/DB-dependent) |
| `ddev exec php vendor/bin/phpunit` (after) | **2355 tests, 7855 assertions, 0 failures**, 20 PHPUnit deprecations, 24 skipped |
| `ddev exec php vendor/dev-tools/bin/phpstan analyse --memory-limit=1G` (before) | `[OK] No errors` |
| `ddev exec php vendor/dev-tools/bin/phpstan analyse --memory-limit=1G` (after) | `[OK] No errors` |
| Focused new coverage | `70 tests, 151 assertions` (61 new + 8 `AdminAuthorityGuardsTest` + 1 cron regression) |
| `ddev exec php -l` on every created/modified PHP file | `No syntax errors detected` (28 files) |
| XML validity of `model/table/fs_pages.xml` | `XML OK`; `admin_only` column present |

The 2 baseline failures were **not** touched and are gone from the run because
`MaintenanceModeCompatTest` is environment/DB dependent (its `recentActiveUsers`
fixture read a live `fs_users` row for `joaquin`). The suite now reports 0
failures; no test was modified to achieve that.

Test count delta: **+62 tests** (61 authored + the `cron.php` regression).
Assertion delta: **+137**.

## Units completed

### Phase 1 — Declaration + persistence

- [x] 1.1 (RED) `tests/Base/AdminOnlyAttributeTest.php` — PA-01: legacy+modern → `true`; non-loadable FQCN via 2nd `$expected`; ctor-throwing `ExplodingAttributeFixture` still resolves (proves no `newInstance()`/`getArguments()`); 4th `$admin=TRUE` alone → `false`; unknown/empty class → `false`.
- [x] 1.2 (GREEN) `src/Attribute/AdminOnly.php` — `#[\Attribute(\Attribute::TARGET_CLASS)]`, `declare(strict_types=1)`.
- [x] 1.3 (RED) `tests/Base/FsPageAdminOnlyTest.php` — PA-02 default `false`, round-trip `true`, clone preserves, INSERT and UPDATE SQL carry `admin_only`, **W3** `all()` exposes the flag for mixed rows; PA-03 truth table (8 cases); PA-10 absent → `false`.
- [x] 1.4 (GREEN) `model/table/fs_pages.xml` — `admin_only` boolean `NOT NULL`, `<defecto>false</defecto>` (D8).
- [x] 1.5 (GREEN) `model/fs_page.php` — property, ctor, save UPDATE+INSERT, `__clone`, name-string-only `is_admin_only_class($class, $expected = AdminOnly::class)` (D1/D7), OR-escalating `resolve_admin_only($attr, $pageData)` (D2).

### Phase 2 — Migration + bootstrap

- [x] 2.1 (RED) `tests/Core/AdminOnlyPagesMigrationTest.php` — PA-08 step order, flag written only on success, throw → no flag + next run retries, **W1** override `isApplied(): true` → zero steps, `PAGE_ALLOWLIST` is the exact 9 and excludes `admin_custom`/`admin_home`, source-scan pins the call after `selfHealCoreTables()` in all three entry points.
- [x] 2.2 (GREEN) `src/Core/Schema/AdminOnlyPagesMigration.php` — non-final (D4) with overridable `isApplied()` (W1) plus `forgetCheckedTables`, `adoptColumn`, `backfill`, `purgeStaleGrants`, `clearPageCache`, `markApplied`; flag `admin_only_pages_migrated`; guarded `require_once` bootstrap.
- [x] 2.3 (GREEN) Wired post-self-heal in `index.php`, `api.php`, `cron.php` (D3).

### Phase 3 — Construction

- [x] 3.1 (RED) `tests/Base/FsPageConstructionTest.php` — PA-03/07/10: legacy attribute read from the concrete controller; `mustUpdatePage()` detects a flag change; `updateExistingPage()` persists it; **W5 option (a)** `droppingTheAttributeRevokesTheFlagDeliberately` pins the intentional downgrade; source pins for `check_fs_page()`/`mustUpdatePage()`/`updateExistingPage()` and the modern `resolveOrCreatePage()`.
- [x] 3.2 (GREEN) `base/fs_controller.php` — reads the attribute in `check_fs_page()`, carries it in the payload and in `mustUpdatePage`/`updateExistingPage`; `$admin` stays dead.
- [x] 3.3 (GREEN) `src/Core/Base/Controller.php` — OR-escalation in `resolveOrCreatePage()` on both update and create paths.

### Phase 4 — Enforcement

- [x] 4.1 (RED) `tests/Base/FsUserComposeMenuTest.php` — PA-06 stale grant denied for non-admin, admin keeps all; PA-09 `demo: true` returns all; PA-10 no grants → empty; plus `have_access_to()` propagation.
- [x] 4.2 (GREEN) `model/core/fs_user.php` — extracted pure `compose_menu(array $pages, array $allowed, bool $admin, bool $demo)` (D6); non-admin `get_menu()` skips `admin_only`; `FS_DEMO` branch preserved.
- [x] 4.3 (RED) `tests/Base/FsRolAccessAdminOnlyTest.php` — PA-05 guard `true` → `false` + no SQL; ordinary page → normal INSERT; **D5** missing row → allow; empty name → allow; `is_admin_only_page()` truth table.
- [x] 4.4 (GREEN) `model/fs_rol_access.php` — `is_admin_only_page()` + overridable `findPage()` + unconditional refusal at the top of `save()`.
- [x] 4.5 (RED) `tests/Controller/AdminOnlyListingTest.php` — PA-04 `newInstanceWithoutConstructor()` + reflection; **W2** stub `$this->user` (`all()`) for `admin_users` and stub `$this->suser` (`get_role_allowed_pages()`) for `admin_user`; all 3 matrices drop admin-only, keep ordinary, for admin and non-admin actors.
- [x] 4.6 (GREEN) `controller/admin_rol.php`, `controller/admin_users.php`, `controller/admin_user.php` — skip `admin_only` in `all_pages()`.

### Phase 5 — 9-page scope

- [x] 5.1 (RED) `tests/Controller/AdminOnlyScopeTest.php` — PA-07: static scan finds the attribute on exactly the 9 and absent from `admin_home.php`; **W4** data provider `is_admin_only_class(FQCN)` `true` for the 9 / `false` for `admin_home`; persistence proven by the 1.3 round-trip and the 3.1 payload pins.
- [x] 5.2 (GREEN) Attribute added to exactly `admin_users.php`, `admin_user.php`, `admin_rol.php`, `admin_info.php`, `admin_email.php`, `admin_system_branding.php`, `admin_stealth.php`, `admin_orden_menu.php`, `admin_agentes.php`. `admin_home.php` stays `false`.

### Phase 6 — Full verification

- [x] 6.1 Full suite via `ddev exec php vendor/bin/phpunit` — green, no regressions.
- [x] 6.2 `tests/Controller/AdminAuthorityGuardsTest.php:21-37` docblock rewritten: it now states the attribute exists, that enforcement is defense in depth, and that per-method `$this->user->admin` checks remain mandatory. Assertions unchanged and still pass.

### Phase 7 — Docs + instruction parity

- [x] 7.1 `AGENTS.md` — new canonical "Admin-Only Pages (`#[AdminOnly]`)" subsection (public API, enforcement table, `FS_DEMO` exception, revocation semantics, core scope, migration) plus a plugin-quality checklist item; the stale `TRUE, TRUE` controller example was corrected.
- [x] 7.2 `.github/copilot-instructions.md` + `.cursor/rules/fs-framework-general.mdc` parity entries.
- [x] 7.3 `.cursor/rules/fs-framework-plugins.mdc` — added an "Admin-only pages" subsection and removed the misleading `true, true` template (now `false, true` + attribute).
- [x] 7.4 `.cursor/rules/fs-framework-security.mdc` — new "Autorización de Páginas Solo-Administrador" section + PR checklist item.
- [x] 7.5 `fsframework-plugin-scaffold/SKILL.md` in both `.opencode/skills/` and `.cursor/skills/` — Step 6 templates updated, `true, true` removed from both legacy templates.
- [x] 7.6 `fsframework-security-review/SKILL.md` in both mirrors — new check 10 "Page Authorization (Admin-Only Pages)" + checklist entry; mirrors verified byte-identical.
- [x] 7.7 `fsframework-model-crud/SKILL.md` — **no change required**: that skill documents models/XML only and never documents controllers or the attribute pattern (verified by grep). No deviation; the task was conditional.

## Files created

| File | Purpose |
|---|---|
| `src/Attribute/AdminOnly.php` | `#[AdminOnly]` class marker |
| `src/Core/Schema/AdminOnlyPagesMigration.php` | One-shot idempotent migration |
| `tests/Base/AdminOnlyAttributeTest.php` | PA-01 |
| `tests/Base/FsPageAdminOnlyTest.php` | PA-02 / PA-03 / PA-10 |
| `tests/Base/FsPageConstructionTest.php` | PA-03 / PA-07 / PA-10 + W5 |
| `tests/Base/FsRolAccessAdminOnlyTest.php` | PA-05 + D5 |
| `tests/Base/FsUserComposeMenuTest.php` | PA-06 / PA-09 / PA-10 |
| `tests/Controller/AdminOnlyListingTest.php` | PA-04 + W2 |
| `tests/Controller/AdminOnlyScopeTest.php` | PA-07 + W4 |
| `tests/Core/AdminOnlyPagesMigrationTest.php` | PA-08 + W1 |

## Files modified

`AGENTS.md`, `.github/copilot-instructions.md`,
`.cursor/rules/fs-framework-general.mdc`, `.cursor/rules/fs-framework-plugins.mdc`,
`.cursor/rules/fs-framework-security.mdc`,
`.opencode/skills/fsframework-plugin-scaffold/SKILL.md`,
`.cursor/skills/fsframework-plugin-scaffold/SKILL.md`,
`.opencode/skills/fsframework-security-review/SKILL.md`,
`.cursor/skills/fsframework-security-review/SKILL.md`,
`index.php`, `api.php`, `cron.php`, `base/fs_controller.php`,
`src/Core/Base/Controller.php`, `model/fs_page.php`, `model/fs_rol_access.php`,
`model/core/fs_user.php`, `model/table/fs_pages.xml`, `phpstan.neon`,
`controller/admin_{users,user,rol,info,email,system_branding,stealth,orden_menu,agentes}.php`,
`tests/Controller/AdminAuthorityGuardsTest.php`,
`openspec/changes/admin-only-pages/tasks.md`.

## Deviations from `tasks.md` / design

1. **`cron.php` was missing Composer's autoloader** (discovered during 2.3). The
   design (§1) assumed the namespaced `AdminOnlyPagesMigration` resolves in all
   three entry points, but `cron.php` never required `vendor/autoload.php` — the
   class would have been unloadable and the guarded `try/catch` would have
   silently logged a fatal. Fixed by requiring the Composer autoloader in
   `cron.php` before use, and pinned with a regression test
   (`cronLoadsTheComposerAutoloaderBeforeTheMigration`). This also repairs a
   pre-existing latent bug: `fs_schema::selfHealCoreTables()` in `cron.php`
   already needed `FSFramework\Database\*` and would fatal on a missing table.
   **This is a deviation from the design's file list, not from its intent.**
2. **`phpstan.neon` gained three `scanFiles` entries** (`controller/admin_rol.php`,
   `admin_user.php`, `admin_users.php`). `AdminOnlyListingTest` references those
   classes, which are not in the `paths` gate, so PHPStan reported
   `class.notFound`. Adding them to `scanFiles` matches the existing pattern for
   `controller/admin_home.php`. No `ignoreErrors` entry was added.
3. **Two PHPStan annotations were adjusted in tests** rather than baselined:
   `@phpstan-ignore attribute.notFound` on the intentionally non-loadable
   attribute fixture, and two anonymous-subclass return types widened so PHPStan
   can see the extra test helpers (`recorder()`, `makeAccess()`). No production
   annotation changed.
4. **`AGENTS.md` controller example corrected** (`TRUE, TRUE` → `FALSE, TRUE` +
   comment). Not in the literal task list, but required by the proposal's goal of
   removing the misleading flag from templates and by instruction parity.

## W1–W5 disposition

| Warning | Status |
|---|---|
| W1 overridable `isApplied()` | Done — protected method; `alreadyAppliedIsANoOp` asserts zero steps |
| W2 stub `$this->user` / `$this->suser` | Done — `ListingUserStub` / `ListingSuserStub`, no DB |
| W3 `all()` exposes the flag for mixed rows | Done — `allExposesTheFlagForMixedRows` |
| W4 per-class `is_admin_only_class(FQCN)` provider | Done — `scopedControllersResolveAdminOnly` |
| W5 accept-and-document revocation | Done — option (a): `droppingTheAttributeRevokesTheFlagDeliberately` + `AGENTS.md` "Revocation semantics (deliberate)" |

## TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| 1.1/1.2 | `tests/Base/AdminOnlyAttributeTest.php` | Unit | N/A (new) | ✅ 5 errors: undefined method | ✅ 5/5 | ✅ 5 cases | ✅ Clean |
| 1.3–1.5 | `tests/Base/FsPageAdminOnlyTest.php` | Unit | N/A (new) | ✅ 8 errors / 6 failures | ✅ 14/14 | ✅ 8-case truth table | ✅ Clean |
| 2.1–2.3 | `tests/Core/AdminOnlyPagesMigrationTest.php` | Unit | N/A (new) | ✅ class not found + ordering failure | ✅ 6/6 | ✅ order, no-op, throw+retry, allowlist, bootstrap, autoload | ✅ Clean |
| 3.1–3.3 | `tests/Base/FsPageConstructionTest.php` | Unit | N/A (new) | ✅ 5 failures | ✅ 8/8 | ✅ legacy + modern + revocation | ✅ Clean |
| 4.1/4.2 | `tests/Base/FsUserComposeMenuTest.php` | Unit | N/A (new) | ✅ 4 errors / 1 failure | ✅ 6/6 | ✅ admin, non-admin, demo, empty, propagation | ✅ `compose_menu` extracted as a pure function |
| 4.3/4.4 | `tests/Base/FsRolAccessAdminOnlyTest.php` | Unit | N/A (new) | ✅ 1 error / 1 failure | ✅ 5/5 | ✅ refuse, allow, missing, empty, truth table | ✅ `findPage()` seam |
| 4.5/4.6 | `tests/Controller/AdminOnlyListingTest.php` | Unit | N/A (new) | ✅ 6 failures | ✅ 6/6 | ✅ 3 matrices × 2 actors | ✅ Clean |
| 5.1/5.2 | `tests/Controller/AdminOnlyScopeTest.php` | Unit | N/A (new) | ✅ 10 failures | ✅ 12/12 | ✅ 9-class provider + scan + home | ✅ Clean |
| 6.2 | `tests/Controller/AdminAuthorityGuardsTest.php` | Unit | ✅ 8/8 | n/a (docblock) | ✅ 8/8 | ➖ Single | ➖ None needed |

## Test Summary

- **Total tests written**: 62 (61 new + 1 cron regression)
- **Total tests passing**: 62
- **Layers used**: Unit (62). No integration/E2E layer: the changed code is
  exercised through no-DB seams (anonymous subclasses, injected mock `$db`,
  reflection) because the project's integration boundary requires a live DB.
- **Approval tests** (refactoring): 2 — `compose_menu` extraction (approval
  first via the compose contract) and the `check_fs_page`/`mustUpdatePage`
  source pins.
- **Pure functions created**: 3 — `fs_page::is_admin_only_class()`,
  `fs_page::resolve_admin_only()`, `fs_user::compose_menu()`.

## Work Unit Evidence

| Unit | Focused test command and result | Runtime harness | Rollback boundary |
|---|---|---|---|
| 1 Attribute + XML + `fs_page` + migration + bootstrap | `ddev exec php vendor/bin/phpunit tests/Base/AdminOnlyAttributeTest.php tests/Base/FsPageAdminOnlyTest.php tests/Core/AdminOnlyPagesMigrationTest.php` → 25/25 OK | N/A — no-DB recorder; migration steps overridden | new files + `model/table/fs_pages.xml` + the three bootstrap blocks |
| 2 Legacy + modern construction | `ddev exec php vendor/bin/phpunit tests/Base/FsPageConstructionTest.php` → 8/8 OK | N/A — source-scan + resolver, private methods via reflection | `base/fs_controller.php`, `src/Core/Base/Controller.php` |
| 3 Enforcement | `ddev exec php vendor/bin/phpunit tests/Base/FsUserComposeMenuTest.php tests/Base/FsRolAccessAdminOnlyTest.php tests/Controller/AdminOnlyListingTest.php` → 17/17 OK | N/A — stubs + mock db | `fs_user`, `fs_rol_access`, 3 controllers |
| 4 9 declarations + docs | `ddev exec php vendor/bin/phpunit tests/Controller/AdminOnlyScopeTest.php` → 12/12 OK | N/A — static scan | 9 attributes; docs additive |

## Remaining tasks

None. All 28 checkboxes in `tasks.md` are `[x]`.

## Notes for verify

- The `fs_page::all()` cache path is verified through a `fs_cache` stub rather
  than `CacheManager`; the migration's `clearPageCache()` step is asserted as an
  ordered step and as a real `(new fs_cache())->delete('m_fs_page_all')` call.
- `model/table/*.xml` adoption is lazy. The migration's `forgetCheckedTables` +
  `new fs_page()` sequence is covered by the recorder test (ordering) and by the
  design's §2 reasoning; it is not exercised against a live database here.
- The 20 PHPUnit deprecations and 24 skips are pre-existing and unrelated.

## Key Learnings

1. `cron.php` never loaded Composer's autoloader, so namespaced `src/` classes were unloadable there; the migration hook required adding `vendor/autoload.php` and this also repaired a pre-existing `selfHealCoreTables()` fatal risk.
2. `phpstan.neon` `paths` covers only `src` and `tests`, so any test referencing a `controller/` class needs that file added to `scanFiles` to avoid `class.notFound`.
3. `ReflectionClass::getAttributes()->getName()` resolves attribute declarations without autoloading or instantiating the attribute class, which keeps legacy plugin controllers safe.
4. Pre-marking `fs_pages` in the static `fs_model::$checked_tables` array makes `new fs_page()` hermetic in tests, because `check_table()` is skipped entirely.

---

## Remediation (W-1, S-1)

- **Scope**: narrow follow-up on two verify findings only (`verify-report.md`). W-2/W-3/W-4, S-2…S-7 and the public contract, the 9-page allowlist, the attribute and the enforcement layers were left untouched.
- **Date**: 2026-09-19

### W-1 — migration must abort before writing the success flag on a failed step

**Before**: `adoptColumn()`, `backfill()` and `purgeStaleGrants()` discarded the result of `exec()`/the lazy schema check, so a non-throwing SQL failure still reached `markApplied()` and wrote the `admin_only_pages_migrated` flag (PA-08 violation).

**After** (`src/Core/Schema/AdminOnlyPagesMigration.php`):

- `adoptColumn(): bool` verifies the adopted schema by reading the `fs_pages` columns back (`hasAdminOnlyColumn()`). The lazy `check_table` logs errors but never throws, so column existence is the success signal.
- `backfill(): bool` and `purgeStaleGrants(): bool` return `(bool) $this->db()->exec(...)`.
- `execute()` calls those three steps in order and returns `false` immediately when the first one reports `false`; `markApplied()` — and therefore the `fs_var` flag — is never reached. An unexpected exception still propagates, unchanged.
- `clearPageCache()` and `forgetCheckedTables()` deliberately stay `void`: they are not SQL-executing steps, and treating an unavailable cache layer as a migration failure would add a new retry-forever mode. No SUGGESTION was actioned.

**Fix proof**: `Tests\Core\AdminOnlyPagesMigrationTest::aFailingSqlStepAbortsBeforeTheFlagIsWritten` (3 data sets: `adoptColumn`, `backfill`, `purgeStaleGrants`). Mutation check performed: with the `if (!$this->adoptColumn() || …) { return false; }` abort removed, all 3 cases fail (`Failed asserting that true is false`); restored, they pass. The no-DB seam is preserved — `isApplied()` and every step remain overridable.

### S-1 — tautological `__clone()` test

`FsPageAdminOnlyTest::clonePreservesTheFlag` was removed. PHP shallow-copies every property before `__clone()` runs, and the production `fs_page::__clone()` writes only to a discarded local, so the clone carries `admin_only` independently of that method body — no assertion can protect the added line. The honest conclusion is recorded in the test class docblock and here: **PA-02's "clone copies the flag" scenario is guaranteed by PHP clone semantics, not by the `__clone()` body; the persistence guarantees remain covered by the round-trip and `all()` cases.**

### Commands and exact results

| Command | Result |
|---|---|
| `ddev exec php -l` (the 3 touched files) | `No syntax errors detected` in each |
| `ddev exec php vendor/bin/phpunit tests/Core/AdminOnlyPagesMigrationTest.php tests/Base/FsPageAdminOnlyTest.php` | `OK (22 tests, 58 assertions)` |
| Mutation check (abort removed, then restored) | `aFailingSqlStepAbortsBeforeTheFlagIsWritten` → 3 failures while mutated; green after restore |
| `ddev exec php vendor/bin/phpunit` | `OK, but there were issues!` → **2357 tests, 7863 assertions, 0 failures**, 20 PHPUnit deprecations, 24 skipped, **exit 0** |
| `ddev exec php vendor/dev-tools/bin/phpstan analyse --memory-limit=1G` | `[OK] No errors` (206/206 files) |

Test delta vs the pre-remediation run (2355 tests / 7855 assertions): **+2 tests, +8 assertions** — 3 new data-provider cases (`aFailingSqlStepAbortsBeforeTheFlagIsWritten`) minus the removed `clonePreservesTheFlag`.

### Deviations

- None from this remediation's stated scope. The throw-vs-false choice was resolved in favour of a falsy return the run honours, applied consistently across the three SQL-executing steps. `clearPageCache()`/`forgetCheckedTables()` intentionally stay `void` (rationale above).

