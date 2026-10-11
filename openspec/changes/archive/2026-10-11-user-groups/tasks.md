# Tasks: Core user groups with responsable assignment

Change: `user-groups` (CORE, additive-only). Source of truth:
`specs/user-groups/spec.md` (UG-01…UG-07) and `design.md` (§2 model API, §3
registry, §4 admin UI, §5 tests, §8 implementation order).

Scope is frozen: two tables, two legacy models, an additive `fs_schema`
registry entry, two `#[AdminOnly]` controllers, two AdminLTE templates, ES/EN
YAML keys, and PHPUnit tests. **No** permissions, auth/session bridge, REST API,
hierarchy/nesting, multi-function membership, agent/warehouse coupling, or
surfacing on `admin_user`.

Test runner (all suites): `ddev exec php vendor/bin/phpunit`. Focused runs:
`ddev exec php vendor/bin/phpunit --testsuite Base --filter <Name>`.
Strict TDD is active: every model-logic task pairs a RED test task that
immediately precedes its GREEN implementation task.

---

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1,350 (range 1,250–1,500) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 → PR 4 |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

```text
Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High
```

`auto-chain` means the orchestrator proceeds with the first slice without an
extra confirmation gate. The 400-line review budget is **High risk**: measured
scope is ~3× the budget (two schemas, two ~150–190-line legacy models, ~460
lines of tests, two controllers, two templates, two translation files). Four
stacked slices keep each PR close to a single concern.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | `fs_group` entity + `fs_groups` table + registry entry, with tests | PR 1 | `ddev exec php vendor/bin/phpunit --testsuite Base --filter FsGroupTest` | N/A — Base suite has no DB; registry + model branches proven by the mocked `fs_db2` double | Delete `model/table/fs_groups.xml`, `model/fs_group.php`, `tests/Base/FsGroupTest.php`; revert the two `fs_groups` lines in `base/fs_schema.php` |
| 2 | `fs_group_user` membership + `fs_groups_users` table + registry entry, with tests | PR 2 | `ddev exec php vendor/bin/phpunit --testsuite Base --filter FsGroupUserTest` | N/A — same Base-suite harness; cascade is DB-level and exercised end-to-end after Phase 1–2 bootstrap | Delete `model/table/fs_groups_users.xml`, `model/fs_group_user.php`, `tests/Base/FsGroupUserTest.php`; revert the two `fs_groups_users` lines in `base/fs_schema.php`; drop `fs_groups_users` then `fs_groups` |
| 3 | Admin **list** page (`admin_groups`) + template + list i18n keys | PR 3 | `ddev exec php vendor/bin/phpunit --testsuite Base` (no UI unit tests) | `ddev start` → sign in as admin → `index.php?page=admin_groups`: create a group, edit, delete | Delete `controller/admin_groups.php`, `themes/AdminLTE/view/admin_groups.html.twig`; remove the list-page YAML keys |
| 4 | Admin **editor** page (`admin_group`) + template + editor i18n keys | PR 4 | `ddev exec php vendor/bin/phpunit --testsuite Base` (no UI unit tests) | `ddev start` → `index.php?page=admin_group&codgrupo=comercial`: edit description and save memberships + `funcion` in one POST | Delete `controller/admin_group.php`, `themes/AdminLTE/view/admin_group.html.twig`; remove the editor YAML keys |

Chain bases (stacked-to-main): PR 1 base = `main`; PR 2 base = `main` (after PR 1
merged); PR 3 base = `main` (after PR 2 merged); PR 4 base = `main` (after PR 3
merged). Every slice is additive and independently revertible; none modifies an
existing table, model, authorization path or API attribute.

Chained-slicing adaptation: design §3.1 appends **both** XML files in one edit
and §5.2 asserts **both** in a single registry test. Under this chain, PR 1
registers only `fs_groups` (and asserts only it), and PR 2 appends
`fs_groups_users` and extends the same registry test to assert both. This keeps
each PR internally consistent (no `CORE_MODEL_MAP` entry pointing at a
not-yet-created model file). If apply chooses a single PR (`size:exception`),
use the original one-shot registry edit from design §3.

---

## Phase 1 — `fs_group` entity slice (PR 1)

Dependency root: the parent table must exist before the child, and the model's
tests bypass the constructor (anonymous subclass + mocked `fs_db2`).

- [x] 1.1 **[TEST-FIRST · RED]** Create `tests/Base/FsGroupTest.php` (namespace
  `Tests\Base`, `declare(strict_types=1)`, license header) with the fixture
  harness from `tests/Base/FsRolAccessAdminOnlyTest.php`: an anonymous `fs_model`
  subclass that skips `parent::__construct()`, plus a DB double injected via
  `ReflectionProperty(\fs_model::class, 'db')` exposing `select()`, `exec()`,
  `var2str()` and an `executedSql` recorder; `require_once FS_FOLDER .
  '/model/fs_group.php'`. Add the entity test cases from design §5.2:
  `getReturnsModelWhenRowExists`, `getReturnsFalseWhenMissing`,
  `saveInsertsWhenNew`, `saveUpdatesWhenExisting`, `deleteReturnsTrueAndTargetsCode`,
  `allOrdersByDescripcion`, `allForUserReturnsGroups`,
  `getUsersDelegatesToMembership`, `getResponsablesDelegatesToMembership`,
  `urlFallsBackToListWhenCodeIsNull`, `urlPointsToEditor`, `saveSanitizesDescripcion`,
  and a registry test `coreRegistryListsBothGroupTables` that asserts
  `fs_groups.xml` is in `CORE_TABLE_FILES` (immediately after
  `fs_roles_users.xml`) and `fs_groups` is in `CORE_MODEL_MAP`.
  **Files**: `tests/Base/FsGroupTest.php` (new).
  **Verify**: `ddev exec php vendor/bin/phpunit --testsuite Base --filter FsGroupTest`
  → fails (class `fs_group` not found / constants missing) = RED confirmed.

- [x] 1.2 Create `model/table/fs_groups.xml` with the exact schema from design
  §1.1: `codgrupo character varying(20) NOT NULL`, `descripcion character
  varying(200) NOT NULL`, `PRIMARY KEY (codgrupo)`. Header comment states the
  table is organizational and grants no permissions.
  **Files**: `model/table/fs_groups.xml` (new).
  **Verify**: file parses as `<tabla>` with two `<columna>` and one `<restriccion>`;
  matches design §1.1 verbatim.

- [x] 1.3 **[GREEN]** Create `model/fs_group.php` — `class fs_group extends
  fs_model` with the exact API from design §2.1: public `$codgrupo`,
  `$descripcion`; `__construct($data = FALSE)` → `parent::__construct('fs_groups')`;
  `protected function install()` returning `''` (no seed rows);
  `url()`, `get($codgrupo)`, `exists()`, `save()` (PK upsert: `exists()` decides
  UPDATE-vs-INSERT, `no_html()` on `descripcion`, returns `(bool)
  $this->db->exec($sql)`), `delete()`, `all()` (`ORDER BY descripcion ASC,
  codgrupo ASC`), `all_for_user($nick)` (instantiates `fs_group_user` first to
  guarantee the membership table exists), `get_users()` and `get_responsables()`
  delegating to `fs_group_user`.
  **Files**: `model/fs_group.php` (new).
  **Verify**: `ddev exec php vendor/bin/phpunit --testsuite Base --filter FsGroupTest`
  → the entity cases pass (registry case still RED until 1.4).

- [x] 1.4 **[GREEN · LOAD-BEARING, EASY TO FORGET]** Edit `base/fs_schema.php`:
  append `'fs_groups.xml',` to `private const CORE_TABLE_FILES` **immediately
  after** `'fs_roles_users.xml',` and **before** `'fs_vars.xml',`; add
  `'fs_groups' => ['file' => 'model/fs_group.php', 'class' => 'fs_group'],` to
  `private const CORE_MODEL_MAP` after the `fs_roles_users` entry. Without these
  entries `selfHealCoreTables()` reports `No hay mapeo de modelo para tabla:
  fs_groups` and bootstrap self-heal (UG-05) is lost.
  **Files**: `base/fs_schema.php` (modified).
  **Verify**: `ddev exec php vendor/bin/phpunit --testsuite Base --filter FsGroupTest`
  → all cases, including `coreRegistryListsBothGroupTables` (fs_groups
  assertions), GREEN.

- [x] 1.5 **[REFACTOR]** Tidy `model/fs_group.php` (docblock notes "groups are
  organizational; roles are permission"), confirm no `#[ApiResource]` attribute
  and no auth/session/agent coupling (UG-07). Re-run
  `ddev exec php vendor/bin/phpunit --testsuite Base --filter FsGroupTest` →
  GREEN.

> **PR 1 coherence adjustment (applied during apply).** The membership-delegating
> members `all_for_user($nick)`, `get_users()` and `get_responsables()` (listed in
> task 1.1's test set and task 1.3's API) are **deferred to PR 2**. They delegate
> to `fs_group_user`, whose class/file/table do not exist until Phase 2; shipping
> them in PR 1 would make the Phase-1 tests fatal ("class not found") and break
> strict-TDD GREEN. `model/fs_group.php` in PR 1 contains entity methods only and
> references `fs_group_user` nowhere. The three tests
> (`allForUserReturnsGroups`, `getUsersDelegatesToMembership`,
> `getResponsablesDelegatesToMembership`) move to `FsGroupUserTest`/Phase 2. PR 1
> `FsGroupTest` covers only the entity + `fs_groups` registry cases.
>
> **Landed in PR 2 (Phase 2).** `all_for_user($nick)`, `get_users()` and
> `get_responsables()` were added to `model/fs_group.php`, and the three
> deferred tests now live in `tests/Base/FsGroupUserTest.php`
> (`allForUserReturnsGroups`, `getUsersDelegatesToMembership`,
> `getResponsablesDelegatesToMembership`).

## Phase 2 — `fs_group_user` membership slice (PR 2)

Depends on Phase 1: the child table's `REFERENCES fs_groups (codgrupo)` FK is
guarded by `fs_schema::addForeignKeyConstraint()`, so `fs_groups` must exist
first.

- [x] 2.1 **[TEST-FIRST · RED]** Create `tests/Base/FsGroupUserTest.php`
  (same harness pattern; `require_once FS_FOLDER . '/model/fs_group_user.php'`)
  with the cases from design §5.3: `funcionesConstantIsExactSet`,
  `constructorDefaultsFuncionToMiembro`, `saveAcceptsMiembroAndResponsable`,
  `saveRejectsOutOfSetValueAndExecutesNoSql` (asserts `executedSql === []`),
  `saveUpsertsExistingPair`, `saveNeverDuplicatesPair`, `existsIsScopedToThePair`,
  `deleteTargetsThePair`, `allFromGroupReturnsGroupMembers`,
  `allFromUserReturnsUserMemberships`, `responsablesFromGroupFiltersResponsable`,
  `responsablesFromGroupReturnsEmptyWhenNone`,
  `setFuncionAcceptsAllowedAndRejectsOtherwise`.
  **Files**: `tests/Base/FsGroupUserTest.php` (new).
  **Verify**: `ddev exec php vendor/bin/phpunit --testsuite Base --filter FsGroupUserTest`
  → fails (class `fs_group_user` not found) = RED confirmed.

- [x] 2.2 **[GREEN]** Create `model/fs_group_user.php` — `class fs_group_user
  extends fs_model` with the exact API from design §2.2:
  `public const FUNCIONES = ['miembro', 'responsable'];` and
  `private const SQL_SELECT_ALL_FROM` / `SQL_WHERE`; public `$codgrupo`,
  `$fs_user`, `$funcion` (default `'miembro'`); `__construct($data = FALSE)` →
  `parent::__construct('fs_groups_users')`; `protected function install()`
  instantiating `new fs_group()` and `new fs_user()` then returning `''`;
  `exists()` scoped to the `(codgrupo, fs_user)` pair; `save()` that (1)
  normalizes null/`''` → `'miembro'`, (2) returns `FALSE` **before any SQL** when
  `funcion` is not in `FUNCIONES` (UG-04 "persists nothing"), (3) upserts via
  `exists()`; `delete()`; `all_from_group($codgrupo)`; `all_from_user($nick)`;
  `responsables_from_group($codgrupo)` (`WHERE funcion = 'responsable'`, empty
  array when none); `set_funcion($funcion = 'miembro')` (validate → assign →
  `save()`).
  **Files**: `model/fs_group_user.php` (new).
  **Verify**: `ddev exec php vendor/bin/phpunit --testsuite Base --filter FsGroupUserTest`
  → GREEN.

- [x] 2.3 Create `model/table/fs_groups_users.xml` with the exact schema from
  design §1.2: `codgrupo character varying(20) NOT NULL`, `fs_user character
  varying(12) NOT NULL`, `funcion character varying(20)` with `<defecto>miembro</defecto>`
  and `NOT NULL`; `PRIMARY KEY (codgrupo,fs_user)`; FK
  `(codgrupo) REFERENCES fs_groups (codgrupo) ON DELETE CASCADE ON UPDATE CASCADE`;
  FK `(fs_user) REFERENCES fs_users (nick) ON DELETE CASCADE ON UPDATE CASCADE`.
  **Files**: `model/table/fs_groups_users.xml` (new).
  **Verify**: file parses as `<tabla>` with three `<columna>` and three
  `<restriccion>` (one PK + two cascade FKs); matches design §1.2 verbatim.

- [x] 2.4 **[GREEN · LOAD-BEARING, EASY TO FORGET]** Edit `base/fs_schema.php`:
  append `'fs_groups_users.xml',` to `CORE_TABLE_FILES` **immediately after**
  `'fs_groups.xml',` (and before `'fs_vars.xml',`); add
  `'fs_groups_users' => ['file' => 'model/fs_group_user.php', 'class' =>
  'fs_group_user'],` to `CORE_MODEL_MAP` after the `fs_groups` entry.
  **Files**: `base/fs_schema.php` (modified).
  **Verify**: reflection over `CORE_TABLE_FILES` shows `fs_roles_users.xml` →
  `fs_groups.xml` → `fs_groups_users.xml` → `fs_vars.xml` in order.

- [x] 2.5 **[GREEN]** Extend `tests/Base/FsGroupTest.php::coreRegistryListsBothGroupTables`
  to also assert `fs_groups_users.xml` is in `CORE_TABLE_FILES` after
  `fs_roles_users.xml` and `fs_group_user` is in `CORE_MODEL_MAP` (completes the
  UG-05 registry scenario).
  **Files**: `tests/Base/FsGroupTest.php` (modified).
  **Verify**: `ddev exec php vendor/bin/phpunit --testsuite Base --filter 'FsGroup(Test|UserTest)'`
  → GREEN.

- [x] 2.6 **[REFACTOR]** Tidy `model/fs_group_user.php`; confirm `FUNCIONES` is
  the single source of truth for allowed functions and that enabling a future
  value is a one-line constant change with no schema change (UG-04 last scenario).
  Re-run the focused suite → GREEN.

## Phase 3 — Admin list page (PR 3)

Depends on Phase 1–2 (`fs_group::all()`, `save()`, `delete()`).

- [x] 3.1 Create `controller/admin_groups.php` — `#[\FSFramework\Attribute\AdminOnly]
  class admin_groups extends fs_controller` per design §4.1: public `$groups = []`
  and **`public $allow_delete;`** declared explicitly (base `fs_controller` does
  not define it); `parent::__construct(__CLASS__, 'Grupos', 'admin', FALSE, TRUE)`
  (5th arg `TRUE` → own admin menu entry); `protected function private_core()`
  sets `$this->allow_delete = $this->user->admin;` then routes on
  `filter_input(INPUT_POST, 'ncodgrupo')` (create) and
  `filter_input(INPUT_GET, 'delete_grupo')` (delete) through `add_group()` /
  `delete_group()`, both gated on `$this->user->admin` (fail-closed) and emitting
  `new_error_msg()`/`new_message()`; `add_group()` reports "already exists" via
  `get(code)` before saving and redirects to `$group->url()` on success; loads
  `$this->groups = $fs_group->all()`.
  **Files**: `controller/admin_groups.php` (new).
  **Verify**: `ddev exec php -l controller/admin_groups.php` clean; class carries
  the `AdminOnly` attribute and the 5th ctor arg is `TRUE`.

- [x] 3.2 Create `themes/AdminLTE/view/admin_groups.html.twig` per design §4.3:
  header include + `footer.html.twig`; list table (code, description, editor link
  via `g.url()`, delete button with `bootbox` confirm guarded by
  `fsc.allow_delete`); "New group" modal/form posting `ncodgrupo` + `descripcion`
  with `{{ csrf_field() }}`; labels via `trans()` for `groups`, `new-group`,
  `group-code`, `group-description`, `delete-group`, `no-groups`.
  **Files**: `themes/AdminLTE/view/admin_groups.html.twig` (new).
  **Verify**: template renders in AdminLTE without a raw `trans()` key; every
  POST form includes `{{ csrf_field() }}`; no `|raw` with user data.

- [x] 3.3 Append the list-page keys to `translations/messages.es.yaml` and
  `translations/messages.en.yaml` (existing top-level flat-key format):
  `group`/`groups`/`new-group`/`group-code`/`group-description`/`delete-group`/
  `no-groups` (ES and EN per design §4.4 table). Add only missing keys; do not
  reorder or overwrite existing entries.
  **Files**: `translations/messages.es.yaml`, `translations/messages.en.yaml`
  (modified).
  **Verify**: each added key resolves in both locales (no raw key shown);
  YAML parses.

## Phase 4 — Admin editor page + i18n (PR 4)

Depends on Phase 3 for navigation (the list links into the editor).

- [x] 4.1 Create `controller/admin_group.php` — `#[\FSFramework\Attribute\AdminOnly]
  class admin_group extends fs_controller` per design §4.2: public `$group`,
  **`public $allow_delete;`**, `public $funciones = [];`
  `parent::__construct(__CLASS__, 'Editar grupo', 'admin', FALSE, FALSE)`
  (5th arg `FALSE` → **not** a menu entry, matching `admin_rol`/`admin_user`
  editors); `protected function private_core()` sets `$this->allow_delete =
  $this->user->admin;`, `$this->funciones = fs_group_user::FUNCIONES;`, loads the
  group from `fs_filter_input_req('codgrupo')`, emits "Grupo no encontrado." when
  missing, and calls `modify()` on POST; `public function all_users()` annotates
  every `fs_user` with `included` + current `funcion` (default `'miembro'`);
  `private function modify()` performs admin check, updates `descripcion` only
  when the field travelled in the POST, and processes memberships only when the
  hidden `members_form_present` marker is present — for each user upserting
  `member[]` selections via `fs_group_user(['codgrupo' => …, 'fs_user' => $u->nick])`
  with the validated `funcion[<nick>]`, and `delete()` for unchecked users
  (single-POST membership save, UG-06).
  **Files**: `controller/admin_group.php` (new).
  **Verify**: `ddev exec php -l controller/admin_group.php` clean; class carries
  the `AdminOnly` attribute and the 5th ctor arg is `FALSE`.

- [x] 4.2 Create `themes/AdminLTE/view/admin_group.html.twig` per design §4.3:
  editor form posting to `{{ fsc.group.url() }}` with `{{ csrf_field() }}`;
  `descripcion` input; hidden `<input type="hidden" name="members_form_present"
  value="1"/>`; users table with per-row `member[]=nick` checkbox and
  `<select name="funcion[<nick>]">` populated from `fsc.funciones`, preselected to
  the user's current function and shown only for members; delete-group button
  (confirm) navigating to `admin_groups` with `delete_grupo=<code>`; a
  "not found" block when `fsc.group` is falsy; labels via `trans()` for `group`,
  `function`, `member`, `manager`, `delete-group`, `save`.
  **Files**: `themes/AdminLTE/view/admin_group.html.twig` (new).
  **Verify**: renders in AdminLTE without a raw `trans()` key; POST form includes
  `{{ csrf_field() }}`; function values render through `member`/`manager` keys.

- [x] 4.3 Append the editor keys to `translations/messages.es.yaml` and
  `translations/messages.en.yaml`: `manager` (Responsable/Manager), `function`
  (Función/Function), `member` (Miembro/Member), `edit-group` (Editar grupo/Edit
  group), `save` if absent (design §4.4). Add only missing keys; preserve existing
  entries.
  **Files**: `translations/messages.es.yaml`, `translations/messages.en.yaml`
  (modified).
  **Verify**: `member`/`manager` resolve in ES and EN; no raw key in either locale
  (UG-06 last scenario).

## Phase 5 — Verification

- [x] 5.1 Run the full suite: `ddev exec php vendor/bin/phpunit` → all Base (and
  plugin) tests pass; the new `FsGroupTest` and `FsGroupUserTest` are green.
  **Files**: none (read-only run).
  **Verify**: exit code 0. **Result (after C-1 correction)**:
  `Tests: 1474, Assertions: 3754, Skipped: 10` — OK, exit 0. (Before the fix the
  suite was RED at `Tests: 1472 … Failures: 1`, the `AdminOnlyScopeTest` failure.)

- [x] 5.2 Scope guard — confirm no out-of-frozen-scope artifact was added:
  no `#[ApiResource]` on either model (UG-07 "No API exposure"), no edit to
  `fs_rol`, `fs_rol_access`, `fs_user::get_menu()`, `have_access_to()` or
  `AdminOnlyPagesMigration::PAGE_ALLOWLIST`, and no coupling to `codagente`/
  `almacen`. Only the two additive `fs_schema` registry entries touch existing
  code.
  **Files**: `tests/Controller/AdminOnlyScopeTest.php` (modified — test contract
  extension only; no production/authorization file touched).
  **Verify**: `git diff --stat` shows only the files listed in design §7 (which
  now includes the modified `AdminOnlyScopeTest.php`). Confirmed in verify-report
  §2; the correction changed no frozen-scope artifact.

> **C-1 correction (apply).** Verify finding C-1: the PA-07 scope guard
> `tests/Controller/AdminOnlyScopeTest.php` asserted an exact nine-name set while
> UG-06 legitimately adds two new `#[AdminOnly]` pages. The test **contract** was
> extended (not the implementation): `require_once` both controllers, add
> `admin_group`/`admin_groups` to `scopedControllerClasses()` and
> `scopedPageNames()`, and update the docblock to the eleven-name scoped set.
> No production file changed. Focused run: `OK (14 tests, 26 assertions)`. See
> apply-progress.md Batch 5.

- [ ] 5.3 Runtime smoke (optional, requires DB): `ddev start`, then as admin
  create/edit/delete a group on `index.php?page=admin_groups` and save a
  description plus memberships with a `funcion` in one POST on
  `index.php?page=admin_group&codgrupo=<code>`; confirm both pages persist
  `admin_only = true` and only `admin_groups` appears in the admin menu.
  **Files**: none (manual).
  **Verify**: create → list shows the group; editor → description + functions
  saved; delete → gone; no permission behavior changed.

## Out of scope (do not implement)

Permissions/auth/session/API exposure, group nesting/hierarchy, multiple
functions per `(group, user)`, agent or warehouse coupling, surfacing groups on
`admin_user`, and any modification of the `AdminOnlyPagesMigration` allowlist.
