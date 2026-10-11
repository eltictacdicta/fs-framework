# Apply Progress: `user-groups`

Change: `user-groups` (CORE, additive-only) — artifact store: **openspec**.
Mode: **Strict TDD**. Chain strategy: **stacked-to-main**.

This artifact records the cumulative state across apply batches. No previous
apply-progress existed before batch 1 (PR 1); batch 2 (PR 2) is appended below
without altering batch 1's record.

---

## Batch 1 — PR 1: `fs_group` entity slice

### Completed tasks (Phase 1)

- [x] 1.1 `tests/Base/FsGroupTest.php` created (RED-first, entity + registry cases).
- [x] 1.2 `model/table/fs_groups.xml` created (exact schema from design §1.1).
- [x] 1.3 `model/fs_group.php` created (entity methods only, GREEN).
- [x] 1.4 `base/fs_schema.php` registers `fs_groups` in `CORE_TABLE_FILES` +
  `CORE_MODEL_MAP` (GREEN for the registry case).
- [x] 1.5 REFACTOR: docblock states groups are organizational (no permissions);
  no `#[ApiResource]`, no auth/session/agent coupling (UG-07).

### Coherence adjustment applied (overrides tasks.md Phase 1 wording)

Deferred to **PR 2** (NOT implemented in PR 1):

- `fs_group::all_for_user($nick)`
- `fs_group::get_users()`
- `fs_group::get_responsables()`
- Their tests: `allForUserReturnsGroups`, `getUsersDelegatesToMembership`,
  `getResponsablesDelegatesToMembership`.

Reason: these members delegate to `fs_group_user`, whose class/file/table do not
exist until Phase 2. Shipping them in PR 1 would make the Phase-1 tests fatal
("class not found") and break strict-TDD GREEN. `model/fs_group.php` in PR 1
therefore contains **entity methods only** and references `fs_group_user`
nowhere. The three tests move to Phase 2 (`FsGroupUserTest` / extended
`FsGroupTest`).

`FsGroupTest` in PR 1 covers exactly: `getReturnsModelWhenRowExists`,
`getReturnsFalseWhenMissing`, `saveInsertsWhenNew`, `saveUpdatesWhenExisting`,
`deleteReturnsTrueAndTargetsCode`, `allOrdersByDescripcion`,
`urlFallsBackToListWhenCodeIsNull`, `urlPointsToEditor`,
`saveSanitizesDescripcion`, `coreRegistryListsBothGroupTables`.

### Files

| File | Action | What was done |
|------|--------|---------------|
| `tests/Base/FsGroupTest.php` | Created (271 lines) | Strict-TDD harness (anonymous `fs_group` subclass + `GroupDbStub` injected via `ReflectionProperty(\fs_model::class, 'db')`) and 10 entity/registry cases. |
| `model/table/fs_groups.xml` | Created (24 lines) | `codgrupo varchar(20) NOT NULL` PK + `descripcion varchar(200) NOT NULL`; header comment states the table grants no permissions. |
| `model/fs_group.php` | Created (114 lines) | `class fs_group extends fs_model` with `__construct`, `install`, `url`, `get`, `exists`, `save` (PK upsert, `no_html()` on `descripcion`), `delete`, `all` (`ORDER BY descripcion ASC, codgrupo ASC`). No `fs_group_user` reference. |
| `base/fs_schema.php` | Modified (+2 lines) | `'fs_groups.xml',` appended immediately after `'fs_roles_users.xml',`; `'fs_groups' => ['file' => 'model/fs_group.php', 'class' => 'fs_group'],` added after the `fs_roles_users` map entry. |
| `openspec/changes/user-groups/apply-progress.md` | Created | This artifact. |

Authored change count: **411 lines** (409 new + 2 modified). See the workload note below.

### Test commands and results

| Step | Command | Result |
|------|---------|--------|
| Safety net (baseline) | `ddev exec php vendor/bin/phpunit --testsuite Base` | `Tests: 311, Assertions: 827, Skipped: 2` — OK |
| RED | `ddev exec php vendor/bin/phpunit --testsuite Base --filter FsGroupTest` | `Tests: 10, Assertions: 2, Errors: 9, Failures: 1` — 9× `Error: Class "fs_group" not found` + registry assertion failure (`fs_vars.xml` where `fs_groups.xml` expected). RED confirmed. |
| GREEN (focused) | `ddev exec php vendor/bin/phpunit --testsuite Base --filter FsGroupTest` | `OK (10 tests, 36 assertions)` |
| Regression | `ddev exec php vendor/bin/phpunit --testsuite Base` | `Tests: 321, Assertions: 863, Skipped: 2` — OK, no regressions |
| Lint | `ddev exec php -l model/fs_group.php` / `base/fs_schema.php` / `tests/Base/FsGroupTest.php` | No syntax errors |
| XML well-formedness | `xmllint --noout model/table/fs_groups.xml` | OK |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1 | `tests/Base/FsGroupTest.php` | Unit | ✅ 311/311 | ✅ Written (9 errors + 1 failure) | ✅ 10/10 | ✅ 10 cases | ➖ None needed (test) |
| 1.2 | — (XML schema) | Config | N/A (new) | ➖ Single output, no branching | ✅ `xmllint` OK | ➖ Single | ➖ None |
| 1.3 | `tests/Base/FsGroupTest.php` | Unit | ✅ 311/311 | ✅ Covered by 1.1 RED | ✅ 10/10 | ✅ 9 entity cases | ✅ Docblock scope note |
| 1.4 | `tests/Base/FsGroupTest.php` | Unit | ✅ 311/311 | ✅ `coreRegistry…` RED | ✅ `coreRegistry…` GREEN | ➖ Single (2 entries) | ➖ None |
| 1.5 | — | — | ✅ 321/321 | — | ✅ 321/321 | — | ✅ Scope confirmed clean |

### Test summary

- Total tests written: **10**
- Total tests passing: **10** (Base suite: 321 passing, 2 pre-existing skips)
- Layers used: Unit (10); no DB-backed integration layer exists in `Base`
- Approval tests: None — no refactoring of existing code
- Pure functions created: `fs_group::url()` (DB-free); model CRUD verified via the DB double

### Work Unit Evidence

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit --testsuite Base --filter FsGroupTest` → `OK (10 tests, 36 assertions)`, exit 0 |
| Runtime harness command/scenario and exact result | **N/A** — the `Base` suite has no DB; the registry + model branches are proven by the recording `fs_db2` double (SELECT/EXEC capture) and reflection over the `fs_schema` private consts. `Runtime harness: N/A` with reason. |
| Rollback boundary | Delete `model/table/fs_groups.xml`, `model/fs_group.php`, `tests/Base/FsGroupTest.php`; revert the two `fs_groups` lines in `base/fs_schema.php`. Nothing else in this slice touches existing behavior. |

### Deviations from design

1. **Membership-delegating members/tests deferred to PR 2** — the mandatory
   coherence adjustment (detailed above). Design §2.1 lists `all_for_user`,
   `get_users`, `get_responsables`; PR 1 ships the entity slice only.
2. **Harness `require_once` guard** — design §5.1 shows an unguarded
   `require_once FS_FOLDER . '/model/fs_group.php';`. The test guards it with
   `if (file_exists($groupModelFile))` so the RED run surfaces as per-test
   `Error: Class "fs_group" not found` errors instead of a hard process abort
   from a missing `require`. Behavior is identical once the model file exists
   (the model autoloader resolves the same path).

All other implementation matches design: schema §1.1 verbatim, `save()` PK
upsert with `no_html()` on `descripcion`, `all()` with the secondary
`codgrupo ASC` tie-break, registry positions per §3.1/§3.2.

### Issues found

None. No out-of-scope artifact (`fs_group_user`, `fs_groups_users.xml`,
`controller/admin_group*`, templates, translations) was created. No `#[ApiResource]`;
no edits to `fs_rol`, `fs_rol_access`, `fs_users`, auth/session, or the admin-only
allowlist.

### Workload / PR boundary

- Mode: **stacked PR slice** (PR 1 of 4; `stacked-to-main`, base `main`).
- Current work unit: `fs_group` entity + `fs_groups` table + registry entry, with tests.
- Boundary: starts from `main`; ends with the entity + registry slice; PR 2
  (membership) is out of scope here.
- **Review budget note:** 411 authored lines vs the 400-line budget (≈3% over).
  The unit is a single cohesive concern (entity + schema + registry + its tests);
  it cannot shrink further without deleting the license header, the test
  harness, or legitimate cases — all forbidden by the no-compression rule.
  Recommendation: accept as-is (near-budget, one concern), or record
  `size:exception` if the strict budget must be honored.

### Suggested work-unit commit plan (PR 1)

Recommended — one atomic, green commit:

```
feat(groups): add fs_groups entity (model + table + core registry) with tests

- model/table/fs_groups.xml, model/fs_group.php
- base/fs_schema.php: register fs_groups in CORE_TABLE_FILES + CORE_MODEL_MAP
- tests/Base/FsGroupTest.php: fs_group lifecycle + registry coverage
```

Optional strict-TDD split (commit 1 leaves the suite red by design):

1. `test(groups): add failing fs_group entity + registry tests` — `tests/Base/FsGroupTest.php`
2. `feat(groups): add fs_groups table, fs_group model and registry entry` — `model/table/fs_groups.xml`, `model/fs_group.php`, `base/fs_schema.php`

The orchestrator owns delivery (no `git commit`/`git push` was run here).

---

## Batch 2 — PR 2: `fs_group_user` membership slice

### Completed tasks (Phase 2)

- [x] 2.1 `tests/Base/FsGroupUserTest.php` created (RED-first; 13 §5.3 cases +
  the **3 deferred PR-1 delegation cases**).
- [x] 2.2 `model/fs_group_user.php` created (GREEN).
- [x] 2.3 `model/table/fs_groups_users.xml` created (exact schema from design §1.2).
- [x] 2.4 `base/fs_schema.php` registers `fs_groups_users` in `CORE_TABLE_FILES`
  (after `fs_groups.xml`) + `CORE_MODEL_MAP` (after the `fs_groups` entry).
- [x] 2.5 `tests/Base/FsGroupTest.php::coreRegistryListsBothGroupTables` extended.
- [x] 2.6 REFACTOR: `FUNCIONES` is the single source of truth for both `save()`
  and `set_funcion()`; enabling a future value is a one-line constant change with
  no schema change (UG-04 last scenario).

### Closes the PR-1 coherence deferral

`model/fs_group.php` gained the three membership-delegating methods deferred in
PR 1 (design §2.1):

- `all_for_user($nick)` — instantiates `new fs_group_user()` first to guarantee
  the membership table exists, then `SELECT ... WHERE codgrupo IN (SELECT
  codgrupo FROM fs_groups_users WHERE fs_user = ...)`.
- `get_users()` — `(new fs_group_user())->all_from_group($this->codgrupo)`.
- `get_responsables()` — `(new fs_group_user())->responsables_from_group($this->codgrupo)`.

Their three tests (`allForUserReturnsGroups`, `getUsersDelegatesToMembership`,
`getResponsablesDelegatesToMembership`) live in `FsGroupUserTest` as instructed.

### Files

| File | Action | What was done |
|------|--------|---------------|
| `tests/Base/FsGroupUserTest.php` | Created (463 lines) | Strict-TDD harness (anonymous `fs_group_user`/`fs_group` subclasses + `GroupUserDbStub` injected via `ReflectionProperty(\fs_model::class, 'db')`; duck-typed `fs_db2::$engine` double for the delegation paths) and 16 cases. |
| `model/fs_group_user.php` | Created (167 lines) | `class fs_group_user extends fs_model` with `FUNCIONES = ['miembro','responsable']`, private `SQL_SELECT_ALL_FROM`/`SQL_WHERE`, `__construct`, `install()` (loads `fs_group`/`fs_user`), `exists()` scoped to the pair, `save()` (normalize → validate-before-any-SQL → upsert), `delete()`, `all_from_group`, `all_from_user`, `responsables_from_group`, `set_funcion`. |
| `model/table/fs_groups_users.xml` | Created (39 lines) | 3 columns (`codgrupo`/`fs_user` NOT NULL, `funcion` `DEFAULT 'miembro'` NOT NULL), PK `(codgrupo,fs_user)`, two cascade FKs to `fs_groups(codgrupo)` and `fs_users(nick)`. |
| `base/fs_schema.php` | Modified (+4 lines) | `'fs_groups_users.xml',` appended after `'fs_groups.xml',`; `'fs_groups_users' => ['file' => 'model/fs_group_user.php', 'class' => 'fs_group_user'],` added after the `fs_groups` map entry. |
| `model/fs_group.php` | Modified (+47 lines) | Added `all_for_user()`, `get_users()`, `get_responsables()` (+ docblocks). |
| `tests/Base/FsGroupTest.php` | Modified (+5 lines) | `coreRegistryListsBothGroupTables` now also asserts `fs_groups_users.xml` at `fs_roles_users.xml`+2 and the `fs_groups_users` map entry. |
| `openspec/changes/user-groups/tasks.md` | Modified | Phase 2 tasks 2.1–2.6 marked `[x]`; PR-1 deferral note updated. |

Authored change count (PR 2 work unit): **725 lines** (669 new + 56 modified).
See the workload note below.

### Test commands and results

| Step | Command | Result |
|------|---------|--------|
| Safety net (baseline) | `ddev exec php vendor/bin/phpunit --testsuite Base --filter 'FsGroupTest'` | `OK (10 tests, 36 assertions)` — PR-1 state |
| RED (focused) | `ddev exec php vendor/bin/phpunit --testsuite Base --filter 'FsGroupUserTest'` | `Tests: 16, Assertions: 0, Errors: 16` — 13× `Class "fs_group_user" not found` + 3× `Call to undefined method fs_group::all_for_user/get_users/get_responsables()`. RED confirmed. |
| RED (registry) | `ddev exec php vendor/bin/phpunit --testsuite Base --filter 'FsGroupTest'` | `Tests: 10, Failures: 1` — `fs_groups_users.xml` missing (got `fs_vars.xml`). RED confirmed. |
| GREEN (combined) | `ddev exec php vendor/bin/phpunit --testsuite Base --filter 'FsGroup(Test\|UserTest)'` | `OK (26 tests, 98 assertions)` |
| Regression | `ddev exec php vendor/bin/phpunit --testsuite Base` | `Tests: 337, Assertions: 925, Skipped: 2` — OK, no regressions (+16 tests, +62 assertions vs the PR-1 baseline 321/863) |
| Lint | `ddev exec php -l` on `model/fs_group_user.php`, `model/fs_group.php`, `base/fs_schema.php`, `tests/Base/FsGroupUserTest.php`, `tests/Base/FsGroupTest.php` | No syntax errors |
| XML well-formedness | `xmllint --noout model/table/fs_groups_users.xml` | OK |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 2.1 | `tests/Base/FsGroupUserTest.php` | Unit | ✅ 10/10 FsGroupTest | ✅ Written (16 errors) | ✅ 16/16 | ✅ 16 cases | ➖ None needed (test) |
| 2.2 | `tests/Base/FsGroupUserTest.php` | Unit | ✅ 10/10 FsGroupTest | ✅ Covered by 2.1 RED | ✅ 16/16 | ✅ 13 membership cases | ✅ `FUNCIONES` single source of truth |
| 2.3 | — (XML schema) | Config | N/A (new) | ➖ Single output, no branching | ✅ `xmllint` OK | ➖ Single | ➖ None |
| 2.4 | `tests/Base/FsGroupTest.php` | Unit | ✅ 10/10 | ✅ `coreRegistry…` RED | ✅ `coreRegistry…` GREEN | ➖ Single (2 entries) | ➖ None |
| 2.5 | `tests/Base/FsGroupTest.php` | Unit | ✅ 10/10 | ✅ 1 failure (missing `fs_groups_users.xml`) | ✅ 10/10 | ➖ Single | ➖ None |
| 2.6 | — | — | ✅ 337/337 | — | ✅ 337/337 | — | ✅ `FUNCIONES` confirmed |

### Test summary

- Total tests written in PR 2: **16** (13 membership/`funcion` + 3 deferred
  delegation cases).
- Total tests passing: **16** (Base suite: 337 passing, 2 pre-existing skips).
- Layers used: Unit (16); no DB-backed integration layer exists in `Base`.
- Approval tests: None — no refactoring of existing code.
- Pure/DB-free logic exercised: `funcion` normalization + allowed-value guard;
  SQL shape of every membership query; `fs_group` delegation.

### Work Unit Evidence

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit --testsuite Base --filter 'FsGroup(Test\|UserTest)'` → `OK (26 tests, 98 assertions)`, exit 0 |
| Runtime harness command/scenario and exact result | **N/A** — the `Base` suite has no DB. Membership SQL branches are proven by the recording `GroupUserDbStub` (SELECT/EXEC capture); the two `fs_group::get_users()`/`get_responsables()` delegation paths are proven by a duck-typed `fs_db2::$engine` double (`GroupUserEngineStub`) because those paths build their own `fs_group_user`. Cascade behaviour is DB-level and exercised end-to-end after bootstrap (Phase 5.3). `Runtime harness: N/A` with reason. |
| Rollback boundary | Delete `model/table/fs_groups_users.xml`, `model/fs_group_user.php`, `tests/Base/FsGroupUserTest.php`; revert the two `fs_groups_users` lines in `base/fs_schema.php`; revert the three added methods in `model/fs_group.php` and the +5 lines in `tests/Base/FsGroupTest.php`; drop `fs_groups_users` then `fs_groups`. |

### Deviations from design

1. **Registry test key — table name, not class name.** Task 2.5's wording says
   "assert `fs_group_user` is in `CORE_MODEL_MAP`", but `CORE_MODEL_MAP` is keyed
   by **table** name (`fs_groups_users`) exactly like the existing
   `fs_roles_users => fs_rol_user` entry and per design §3.2. The test therefore
   asserts `arrayHasKey('fs_groups_users')` and that its `class` is
   `fs_group_user`. An interim literal-key attempt surfaced as a RED failure and
   was corrected; this is a wording-vs-convention clarification, not a design
   deviation.
2. **Delegation harness (`GroupUserEngineStub`).** `fs_group::get_users()` /
   `get_responsables()` instantiate a *fresh* `fs_group_user` (mirroring
   `fs_rol::get_users()`), so the per-instance injected `$db` is unreachable. To
   keep those two cases hermetic without a DB, the test seeds `fs_db2::$engine`
   with a duck-typed double (save/restore in `setUp`/`tearDown`). `all_for_user`
   needs no such seam because it queries through the group's injected `$db`.

All other implementation matches design §1.2 / §2.1 / §2.2 / §3.1 / §3.2 exactly:
schema verbatim, allowed-value guard before any SQL, pair-scoped `exists()` /
`delete()`, ordered `all_from_*`, `responsables_from_group` filter.

### Issues found

None. No out-of-scope artifact (controllers, templates, translations) was
created. No `#[ApiResource]`; no edits to `fs_rol`, `fs_rol_access`, `fs_users`
schema, auth/session, or the admin-only allowlist. `git diff --stat` touches only
`base/fs_schema.php` (+4) plus the new files.

### Workload / PR boundary

- Mode: **stacked PR slice** (PR 2 of 4; `stacked-to-main`, base `main` after
  PR 1 merges).
- Current work unit: `fs_group_user` model + `fs_groups_users` table + registry
  entry + `fs_group` membership delegation, with tests.
- Boundary: starts after PR 1 (`fs_groups`); ends with the membership slice;
  no controller/template/i18n work (PR 3/PR 4).
- **Review budget note:** 725 authored lines vs the 400-line budget (≈81% over).
  The unit is a single cohesive concern (one model + its schema + registry +
  delegation + its dedicated test file). It cannot shrink further without
  deleting the license header, the reused test harness, or legitimate cases — all
  forbidden by the no-compression rule. Recommendation: accept as a cohesive
  slice, or record `size:exception` if the strict budget must be honored.
- Note: `git status` still shows PR-1 files as untracked because PR 1 has not
  been committed yet locally; the orchestrator owns delivery ordering.

### Suggested work-unit commit plan (PR 2)

One atomic, green commit:

```
feat(groups): add fs_group_user membership (model + table + registry + delegation) with tests

- model/table/fs_groups_users.xml, model/fs_group_user.php
- base/fs_schema.php: register fs_groups_users in CORE_TABLE_FILES + CORE_MODEL_MAP
- model/fs_group.php: add all_for_user/get_users/get_responsables (closes PR-1 deferral)
- tests/Base/FsGroupUserTest.php: membership + funcion + delegation coverage
- tests/Base/FsGroupTest.php: extend registry assertion
```

The orchestrator owns delivery (no `git commit`/`git push` was run here).

---

## Batch 3 — PR 3: `admin_groups` admin list page (UI slice)

### Completed tasks (Phase 3)

- [x] 3.1 `controller/admin_groups.php` created (GREEN; UI slice, no unit tests).
- [x] 3.2 `themes/AdminLTE/view/admin_groups.html.twig` created.
- [x] 3.3 List-page keys appended to `translations/messages.es.yaml` +
  `translations/messages.en.yaml`.

### Files

| File | Action | What was done |
|------|--------|---------------|
| `controller/admin_groups.php` | Created (116 lines) | `#[\FSFramework\Attribute\AdminOnly] class admin_groups extends fs_controller`; public `$groups = []` + explicit `public $allow_delete;`; `parent::__construct(__CLASS__, 'Grupos', 'admin', FALSE, TRUE)` (5th arg `TRUE` → own admin menu entry). `private_core()` sets `$this->allow_delete = $this->user->admin;`, routes on `filter_input(INPUT_POST,'ncodgrupo')` (create) / `filter_input(INPUT_GET,'delete_grupo')` (delete), then loads `$this->groups = (new fs_group())->all()`. `add_group()` and `delete_group()` are gated on `$this->user->admin` (fail-closed); `add_group()` reports the "already exists" case via `get(code)` before `save()` and redirects to `$group->url()` on success. No SQL, no `#[ApiResource]`. |
| `themes/AdminLTE/view/admin_groups.html.twig` | Created (117 lines) | Header/footer includes; list table (`group-code`, `group-description`, per-row editor link `value.url()`, `bootbox`-confirmed delete button guarded by `fsc.allow_delete`); "New group" modal posting `ncodgrupo` + `descripcion` with `{{ csrf_field() }}`; labels via `trans()` for `groups`, `new-group`, `group-code`, `group-description`, `delete-group`, `no-groups` (+ existing `save`). No `\|raw` on user data. |
| `translations/messages.es.yaml` | Modified (+11 lines) | Appended the 7 list-page keys (`group`, `groups`, `new-group`, `group-code`, `group-description`, `delete-group`, `no-groups`) under a new section header. |
| `translations/messages.en.yaml` | Modified (+11 lines) | Same 7 keys in English. |
| `openspec/changes/user-groups/tasks.md` | Modified | Phase 3 tasks 3.1–3.3 marked `[x]`. |

Authored change count (PR 3 work unit): **255 lines** (233 new + 22 modified) — **within** the 400-line budget.

### Test commands and results

| Step | Command | Result |
|------|---------|--------|
| Lint (new controller) | `ddev exec php -l controller/admin_groups.php` | `No syntax errors detected` |
| Regression (safety net) | `ddev exec php vendor/bin/phpunit --testsuite Base` | `Tests: 337, Assertions: 925, Skipped: 2` — OK, no regressions (identical to the PR-2 baseline) |
| YAML parse + key resolution | `ddev exec php tmp_yaml_check.php` (throwaway) | Both files parse (`yaml_parse_file` returns arrays, `count es=385 en=385`); all 7 keys resolve in ES and EN with the design §4.4 values |
| Key uniqueness | grep `^(group\|groups\|new-group\|group-code\|group-description\|delete-group\|no-groups):` on both YAML files | Exactly one top-level occurrence per key per file (7 lines each) — no duplicates |
| Twig POST + CSRF | grep `method="post"\|csrf_field` in the template | `method="post"` + `{{ csrf_field() }}` present (lines 87–88) |
| Attribute + menu arg | grep `AdminOnly\|parent::__construct` in `controller/admin_groups.php` | `#[\FSFramework\Attribute\AdminOnly]` present (line 27); 5th ctor arg is `TRUE` (line 39) |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 3.1 | — (controller; UI slice) | UI | ✅ 337/337 | ➖ No unit test by scope — CLI/integration runtime harness (tasks §Work Units row 3) | ✅ `php -l` clean | ➖ N/A | ✅ Guard ordering + explicit messages |
| 3.2 | — (Twig template) | UI | ✅ 337/337 | ➖ No unit test by scope — renders only under `ddev start` runtime harness | ✅ csrf_field + trans keys verified statically | ➖ N/A | ✅ `data-url` (no user data in JS) |
| 3.3 | — (translations) | Config | ✅ 337/337 | ➖ Single output, no branching | ✅ YAML parses; 7/7 keys in both locales | ➖ Single set | ➖ None |

Per the preflight, this slice is UI (controllers + Twig + i18n) with **no unit
tests**; strict-TDD RED/GREEN is satisfied by the spec's runtime harness
(tasks.md Work Units row 3), not by PHPUnit. The `Base` suite is used only as a
regression safety net.

### Test summary

- Total tests written in PR 3: **0** (UI slice — the change's requirement for
  this slice is the runtime harness from tasks.md row 3, not a PHPUnit case).
- `Base` suite: **337 passing, 2 pre-existing skips** — unchanged from PR 2.
- Layers used: none new (UI/config only).
- Approval tests: None — no refactoring of existing code.

### Work Unit Evidence

| Evidence | Required value |
|---|---|
| Focused test command and exact result | N/A for this UI slice; regression run `ddev exec php vendor/bin/phpunit --testsuite Base` → `Tests: 337, Assertions: 925, Skipped: 2`, exit 0 |
| Runtime harness command/scenario and exact result | **Pending (optional, requires DB)** — tasks.md row 3: `ddev start` → sign in as admin → `index.php?page=admin_groups`: create a group, open the editor link, delete the group. Not executed here (no DB-driven runtime in this apply slice); the page is static-lint + template/CSRF/YAML verified. |
| Rollback boundary | Delete `controller/admin_groups.php`, `themes/AdminLTE/view/admin_groups.html.twig`; remove the 7 appended keys from both `translations/messages.{es,en}.yaml`. No model, schema, registry or authorization path is touched. |

### Deviations from design

1. **Delete-confirm JS carries no user data.** Design §4.3 specifies a
   `bootbox`-confirmed delete button. To keep the group code out of inline JS,
   the row button exposes the target via a Twig-escaped `data-url` attribute
   (`url_encode`d `codgrupo`) and the click handler reads `$(this).data('url')`.
   This is behaviourally identical to the `admin_rol` inline-JS pattern while
   being strictly safer (no user-controlled value interpolated into a script
   string).
2. **`trans('save')` reused for the modal submit button.** Not in the frozen
   list-page key set, but it is an existing core key (used by
   `admin_system_branding`), so no new key was introduced and the list-page key
   set stays exactly as scoped.

All other implementation matches design §4.1/§4.3/§4.4 exactly: `#[AdminOnly]`
+ 5th ctor arg `TRUE`, fail-closed `$this->user->admin` guards in both mutating
methods, "already exists" via `get(code)` before `save()`, redirect to
`$group->url()`, `fsc.allow_delete` guard on the delete control, and the 7
list-page YAML keys added without reordering existing entries.

### Issues found

None. No out-of-scope artifact was created: no `controller/admin_group.php`, no
editor template, no editor i18n keys (`manager`/`function`/`member`/`edit-group`)
— those remain PR 4. No `#[ApiResource]`; no edits to `fs_rol`, `fs_rol_access`,
`fs_users`, auth/session, or `AdminOnlyPagesMigration::PAGE_ALLOWLIST`.
`git status` shows only the two new PR-3 files plus the two modified YAML files
beyond the PR-1/PR-2 untracked work.

### Security review (fsframework-security-review)

- **SQL injection:** OK — the controller issues no SQL; all reads/writes go
  through `fs_group::get()/save()/delete()/all()`, which escape values with
  `var2str()`.
- **XSS:** OK — Twig auto-escapes `{{ }}`; no `|raw` in
  `admin_groups.html.twig`; the delete target is `url_encode`d into `data-url`.
- **CSRF:** OK — the POST modal form includes `{{ csrf_field() }}`.
- **Open redirect:** OK — the only redirect is `header('Location: ' .
  $group->url())`, an internal URL built from `urlencode($codgrupo)`.
- **Page authorization:** OK — `#[\FSFramework\Attribute\AdminOnly]` on the
  class, and every mutating method re-checks `$this->user->admin` fail-closed.
- Password handling / file uploads / session: N/A (none in this slice).

### Workload / PR boundary

- Mode: **stacked PR slice** (PR 3 of 4; `stacked-to-main`, base `main` after
  PR 2 merges).
- Current work unit: `admin_groups` list controller + AdminLTE template + list
  i18n keys.
- Boundary: starts after PR 2 (models + tables); ends with the read-only list
  page and its create/delete flows; the editor page is PR 4.
- **Review budget:** 255 authored lines vs the 400-line budget — within budget.

### Suggested work-unit commit plan (PR 3)

One atomic commit:

```
feat(groups): add admin_groups list page with create/delete

- controller/admin_groups.php: #[AdminOnly] list + create/delete (own menu entry)
- themes/AdminLTE/view/admin_groups.html.twig: list table + new-group modal (CSRF)
- translations/messages.{es,en}.yaml: list-page keys
```

The orchestrator owns delivery (no `git commit`/`git push` was run here).

---

## Batch 4 — PR 4: `admin_group` admin editor page + i18n (UI slice)

### Completed tasks (Phase 4)

- [x] 4.1 `controller/admin_group.php` created (GREEN; UI slice, no unit tests).
- [x] 4.2 `themes/AdminLTE/view/admin_group.html.twig` created.
- [x] 4.3 Editor keys appended to `translations/messages.es.yaml` +
  `translations/messages.en.yaml`.

### Files

| File | Action | What was done |
|------|--------|---------------|
| `controller/admin_group.php` | Created (150 lines) | `#[\FSFramework\Attribute\AdminOnly] class admin_group extends fs_controller`; public `$group`, `public $allow_delete;`, `public $funciones = [];`; `parent::__construct(__CLASS__, 'Editar grupo', 'admin', FALSE, FALSE)` (5th arg `FALSE` → **not** a menu entry, matching `admin_rol`/`admin_user`). `private_core()` sets `$this->allow_delete = $this->user->admin;`, `$this->funciones = fs_group_user::FUNCIONES;`, loads the group from `fs_filter_input_req('codgrupo')`, emits `Grupo no encontrado.` when missing, calls `modify()` on POST. `public function all_users()` annotates every `fs_user` with `included` + current `funcion` (default `'miembro'`). `private function modify()` performs the fail-closed `$this->user->admin` check, updates `descripcion` only when the field travelled in the POST, then processes memberships **only** when the hidden `members_form_present` marker is present: for each user it upserts `member[]` selections via `new fs_group_user(['codgrupo' => …, 'fs_user' => $u->nick])` with the validated `funcion[<nick>]` (out-of-set → `'miembro'`), and `delete()` for unchecked users (single-POST membership save, UG-06). No SQL, no `#[ApiResource]`. |
| `themes/AdminLTE/view/admin_group.html.twig` | Created (119 lines) | Header/footer includes; editor `<form>` posting to `{{ fsc.group.url() }}` with `{{ csrf_field() }}`; hidden `<input type="hidden" name="members_form_present" value="1"/>`; `descripcion` input; users table with per-row `member[]={nick}` checkbox and `<select name="funcion[{nick}]">` populated from `fsc.funciones` (label `miembro`→`trans('member')`, `responsable`→`trans('manager')`), preselected to the user's current `funcion` and toggled by JS (hidden for non-members); `bootbox`-confirmed delete-group button navigating to `admin_groups?delete_grupo=<code>` guarded by `fsc.allow_delete`; "not found" block when `fsc.group` is falsy; labels via `trans()` for `group`, `function`, `member`, `manager`, `delete-group`, `save` (+ existing `groups`, `group-description`, `nick`, `email`, `administrator`). No `\|raw` on user data. |
| `translations/messages.es.yaml` | Modified (+4 lines) | Appended the 4 editor keys (`manager`, `function`, `member`, `edit-group`) after the PR-3 list keys; `save` already existed. |
| `translations/messages.en.yaml` | Modified (+4 lines) | Same 4 keys in English. |
| `openspec/changes/user-groups/tasks.md` | Modified | Phase 4 tasks 4.1–4.3 marked `[x]`. |

Authored change count (PR 4 work unit): **277 lines** (269 new + 8 modified) — **within** the 400-line budget.

### Test commands and results

| Step | Command | Result |
|------|---------|--------|
| Lint (new controller) | `ddev exec php -l controller/admin_group.php` | `No syntax errors detected` |
| Regression (safety net) | `ddev exec php vendor/bin/phpunit --testsuite Base` | `Tests: 337, Assertions: 925, Skipped: 2` — OK, no regressions (identical to the PR-3 baseline) |
| YAML parse + key resolution | `ddev exec php tmp_yaml_check.php` (throwaway, Symfony YAML) | Both files parse (`count es=389 en=389`); `manager`/`function`/`member`/`edit-group`/`save` resolve in ES and EN with the design §4.4 values |
| Key uniqueness | grep `^(manager\|function\|member\|edit-group\|save):` on both YAML files | Exactly one top-level occurrence per key per file (5 lines each) — no duplicates |
| Twig POST + CSRF | grep `method="post"`/`csrf_field` in both templates | `admin_group.html.twig`: 1 form + 1 `csrf_field`; `admin_groups.html.twig`: 1 form + 1 `csrf_field` |
| Attribute + menu arg | grep `AdminOnly`/`parent::__construct` in `controller/admin_group.php` | `#[\FSFramework\Attribute\AdminOnly]` present (line 28); 5th ctor arg is `FALSE` (line 44) |
| `\|raw` scan | grep `\|raw` in both group templates | NONE |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 4.1 | — (controller; UI slice) | UI | ✅ 337/337 | ➖ No unit test by scope — CLI/integration runtime harness (tasks §Work Units row 4) | ✅ `php -l` clean | ➖ N/A | ✅ Guard ordering (admin check → descripcion → memberships) + marker-gated section |
| 4.2 | — (Twig template) | UI | ✅ 337/337 | ➖ No unit test by scope — renders only under `ddev start` runtime harness | ✅ csrf_field + trans keys + no-`\|raw` verified statically | ➖ N/A | ✅ `funcion[]` selector keyed by nick; no user data in inline JS |
| 4.3 | — (translations) | Config | ✅ 337/337 | ➖ Single output, no branching | ✅ YAML parses; 4/4 keys in both locales | ➖ Single set | ➖ None |

Per the preflight, this slice is UI (controller + Twig + i18n) with **no unit
tests**; strict-TDD RED/GREEN is satisfied by the spec's runtime harness
(tasks.md Work Units row 4), not by PHPUnit. The `Base` suite is used only as a
regression safety net.

### Test summary

- Total tests written in PR 4: **0** (UI slice — the change's requirement for
  this slice is the runtime harness from tasks.md row 4, not a PHPUnit case).
- `Base` suite: **337 passing, 2 pre-existing skips** — unchanged from PR 3.
- Layers used: none new (UI/config only).
- Approval tests: None — no refactoring of existing code.

### Work Unit Evidence

| Evidence | Required value |
|---|---|
| Focused test command and exact result | N/A for this UI slice; regression run `ddev exec php vendor/bin/phpunit --testsuite Base` → `Tests: 337, Assertions: 925, Skipped: 2`, exit 0 |
| Runtime harness command/scenario and exact result | **Pending (optional, requires DB)** — tasks.md row 4: `ddev start` → `index.php?page=admin_group&codgrupo=comercial`: edit description and save memberships + `funcion` in one POST. Not executed here (no DB-driven runtime in this apply slice); the page is static-lint + template/CSRF/YAML verified. |
| Rollback boundary | Delete `controller/admin_group.php`, `themes/AdminLTE/view/admin_group.html.twig`; remove the 4 appended editor keys from both `translations/messages.{es,en}.yaml`. No model, schema, registry or authorization path is touched. |

### Deviations from design

1. **Delete-confirm JS carries the group code via a Twig-escaping JS literal.**
   Design §4.3 specifies a `bootbox`-confirmed delete button. Mirrors the
   `admin_rol` inline-JS pattern using `{{ fsc.group.codgrupo|e('js') }}` inside
   `encodeURIComponent(...)`, so the user-controlled value is JS-escaped before
   interpolation and URL-encoded on navigation.
2. **`all_users()` annotates every `fs_user`, including administrators.** The
   preflight task text and design §4.2 both say "every `fs_user`" (unlike
   `admin_rol`, which locks admin rows). Groups are organizational, so an
   administrator may be a member/responsable; the template shows the normal
   `administrator` label without locking the checkbox.
3. **Out-of-set `funcion` maps to the default `'miembro'`.** The controller
   validates `funcion[<nick>]` against `$this->funciones` and falls back to
   `'miembro'`; the model's own guard (`save()` returns `false` and persists
   nothing for out-of-set values) remains the authoritative boundary.
4. **`edit-group` key added but not consumed by the template.** Design §4.4 /
   task 4.3 list it as a scoped editor key; the editor heading reuses
   `trans('group')` (matching the frozen template label list, which does not
   include `edit-group`).

All other implementation matches design §4.2/§4.3/§4.4 exactly: `#[AdminOnly]`
+ 5th ctor arg `FALSE`, fail-closed `$this->user->admin` guard, partial-POST-safe
`descripcion`, `members_form_present`-gated membership save, single-POST upsert
with validated `funcion`, and the 4 editor YAML keys added without reordering
existing entries.

### Issues found

None. No out-of-scope artifact was created: no `#[ApiResource]`, no edit to
`fs_rol`, `fs_rol_access`, `fs_users` schema, auth/session, or
`AdminOnlyPagesMigration::PAGE_ALLOWLIST`. `git status` shows only the two new
PR-4 files plus the two modified YAML files beyond the PR-1/PR-2/PR-3 work.

### Security review (fsframework-security-review)

- **SQL injection:** OK — the controller issues no SQL; all reads/writes go
  through `fs_group::get()/save()` and `fs_group_user::save()/delete()`, which
  escape values with `var2str()`.
- **XSS:** OK — Twig auto-escapes `{{ }}`; no `|raw` in
  `admin_group.html.twig`; the delete target is `e('js')`-escaped and
  `encodeURIComponent`-wrapped.
- **CSRF:** OK — the POST editor form includes `{{ csrf_field() }}`.
- **Open redirect:** OK — the only navigation is the internal fixed
  `index.php?page=admin_groups&delete_grupo=…`.
- **Page authorization:** OK — `#[\FSFramework\Attribute\AdminOnly]` on the
  class, and `modify()` re-checks `$this->user->admin` fail-closed.
- **Input validation:** OK — `member[]`/`funcion[]` are read as typed arrays and
  `funcion` is validated against `fs_group_user::FUNCIONES`.
- Password handling / file uploads / session: N/A (none in this slice).

### Workload / PR boundary

- Mode: **stacked PR slice** (PR 4 of 4; `stacked-to-main`, base `main` after
  PR 3 merges).
- Current work unit: `admin_group` editor controller + AdminLTE template +
  editor i18n keys.
- Boundary: starts after PR 3 (list page); ends with the parameterised editor
  and its single-POST membership save (UG-06). No model/schema change.
- **Review budget:** 277 authored lines vs the 400-line budget — within budget.

### Suggested work-unit commit plan (PR 4)

One atomic commit:

```
feat(groups): add admin_group editor with single-POST memberships

- controller/admin_group.php: #[AdminOnly] editor + funcion-validated membership upsert
- themes/AdminLTE/view/admin_group.html.twig: editor form + member/funcion selector (CSRF)
- translations/messages.{es,en}.yaml: editor keys (manager/function/member/edit-group)
```

The orchestrator owns delivery (no `git commit`/`git push` was run here).

---

## Batch 5 — C-1 correction (apply, post-verify)

### Trigger

Verify finding **C-1 (CRITICAL)**: the FULL suite was RED because
`tests/Controller/AdminOnlyScopeTest.php` (the pre-existing PA-07 scope-guard
contract) asserted an **exact nine-name** set while UG-06 legitimately adds two
new `#[AdminOnly]` pages (`admin_group`, `admin_groups`). The implementation is
spec-correct; the stale artifact was the **test contract**. This correction
extends the test contract only — no production, model, schema, auth or
authorization file was touched.

### Completed task

- [x] 5.2 scope-guard contract extension (C-1). `tests/Controller/AdminOnlyScopeTest.php`
  updated: `require_once` both new controllers; `admin_group`/`admin_groups`
  added to `scopedControllerClasses()` and `scopedPageNames()`; class docblock
  reworded from "exactly the nine scoped core pages" to the eleven-name scoped
  set (nine core pages + the two user-groups pages, UG-06). Mechanism unchanged.
- [x] 5.1 re-run: full suite GREEN.

### Files

| File | Action | What was done |
|------|--------|---------------|
| `tests/Controller/AdminOnlyScopeTest.php` | Modified (+10 lines) | Two `require_once`s; two `scopedControllerClasses()` entries; two `scopedPageNames()` entries; docblock reworded to the eleven-name scoped set. No mechanism/behavior change. |
| `openspec/changes/user-groups/design.md` | Modified | §7 affected-files gains the modified `AdminOnlyScopeTest.php` row. |
| `openspec/changes/user-groups/tasks.md` | Modified | Phase 5.1/5.2 marked `[x]` with the C-1 correction note. |

Authored change count (Batch 5 work unit): **~35 lines** (well within the
400-line budget).

### Test commands and results

| Step | Command | Result |
|------|---------|--------|
| RED (reproduced) | `ddev exec php vendor/bin/phpunit tests/Controller/AdminOnlyScopeTest.php` | `Tests: 12, Assertions: 22, Failures: 1` — expected 9 vs actual 11 (`admin_group`, `admin_groups` extra). RED confirmed. |
| Lint | `ddev exec php -l tests/Controller/AdminOnlyScopeTest.php` | `No syntax errors detected` |
| GREEN (focused) | `ddev exec php vendor/bin/phpunit tests/Controller/AdminOnlyScopeTest.php` | `OK (14 tests, 26 assertions)` (was 12/22; +2 data-provider cases) |
| GREEN (full suite) | `ddev exec php vendor/bin/phpunit` | `Tests: 1474, Assertions: 3754, Skipped: 10` — OK, exit 0 (was `1472 / 3750 / Failures: 1`) |
| Base regression | `ddev exec php vendor/bin/phpunit --testsuite Base --filter 'FsGroup\(Test\|UserTest\)'` | `OK (26 tests, 98 assertions)` — unchanged |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 5.2 (C-1) | `tests/Controller/AdminOnlyScopeTest.php` | Unit (test-contract) | ✅ RED reproduced 12/22/1 | ✅ Existing failing assertion *is* the RED (9-name list vs 11 flagged files) | ✅ 14/14 | ✅ Data-provider grew 9→11 classes (two new cases) | ✅ Docblock reworded; mechanism unchanged |

Approval-test shape (Strict TDD, test-contract change): the spec's expected
behavior changed (two new admin-only pages are legitimate), so the frozen
approval list was updated to the new expected reality; the assertion then passes
against the real `controller/admin_*.php` glob. No production code was modified.

### Work Unit Evidence

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit tests/Controller/AdminOnlyScopeTest.php` → `OK (14 tests, 26 assertions)`, exit 0 |
| Runtime harness command/scenario and exact result | **N/A** — pure test-contract change; the PA-07 guard is a filesystem-glob + reflection check with no runtime/DB boundary. No production behavior changed. |
| Rollback boundary | Revert `tests/Controller/AdminOnlyScopeTest.php` (remove the two `require_once`s and four list entries, restore the docblock) plus the design §7 / tasks Phase 5 / this Batch-5 record. No production file is involved. |

### Deviations from design

None. PA-07 semantics and the `page-authorization` spec are unchanged; PA-07
mandates the nine core pages declare admin-only and does **not** forbid new
admin-only pages, which UG-06 introduces. `openspec/specs/page-authorization/spec.md`
was not modified.

### Issues found

None. No `#[ApiResource]`; no edits to `fs_rol`, `fs_rol_access`, `fs_users`,
auth/session, or `AdminOnlyPagesMigration::PAGE_ALLOWLIST`.

---

## Status

- Phase 1 (PR 1): **complete** — 5/5 tasks `[x]`.
- Phase 2 (PR 2): **complete** — 6/6 tasks `[x]`; PR-1 membership deferral closed.
- Phase 3 (PR 3): **complete** — 3/3 tasks `[x]`; `admin_groups` list page +
  template + list i18n shipped (255 authored lines, within budget).
- Phase 4 (PR 4): **complete** — 3/3 tasks `[x]`; `admin_group` editor +
  template + editor i18n shipped (277 authored lines, within budget).
- Phase 5 (verification): 5.1 full-suite run **[x] GREEN**
  (`1474 tests / 3754 assertions / Skipped: 10`, exit 0); 5.2 scope guard **[x]**
  (C-1 correction applied — `AdminOnlyScopeTest.php` extended); 5.3 optional DB
  runtime smoke still **outstanding** (verify-report WARNING W-1).
- Batch 5 (C-1 correction): **complete** — `tests/Controller/AdminOnlyScopeTest.php`
  test contract extended; focused `OK (14 tests, 26 assertions)`; full suite
  GREEN. No production file changed.
- Focused suites green; `Base` suite green with no regressions
  (26/98 for the group filter; full-suite green).
- All PR 1–PR 4 slices plus the Batch-5 correction are landed in the working
  tree, uncommitted; the orchestrator owns delivery.
- `next_recommended`: optional 5.3 DB runtime smoke to close W-1, then
  **sdd-archive** for the `user-groups` change (full suite is now green).

