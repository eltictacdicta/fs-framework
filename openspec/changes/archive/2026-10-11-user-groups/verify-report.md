# Verify Report: Core user groups with responsable assignment

- **Change**: `user-groups` (CORE, additive-only)
- **Artifact store**: openspec
- **Phase**: verify (re-run after the C-1 correction)
- **Date**: 2026-10-11
- **Mode**: Strict TDD (apply evidence reviewed as recorded)
- **Spec**: `specs/user-groups/spec.md` (UG-01…UG-07)
- **Tasks**: `tasks.md` (Phases 1–5 `[x]` except optional 5.3)
- **Scope of this report**: diagnose only. No code, tests, or tasks were modified.

> **Re-run note.** The first verify run returned **partial** with CRITICAL **C-1**
> (full suite RED: `AdminOnlyScopeTest` asserted exactly nine `#[AdminOnly]`
> controllers while UG-06 legitimately adds two). Apply landed the **Batch 5
> (C-1 correction)** — a test-contract-only extension of
> `tests/Controller/AdminOnlyScopeTest.php` plus design §7 / tasks Phase 5
> updates. This re-run confirms the correction. The original C-1 reproduction is
> preserved verbatim in §1.2 and §3 (C-1 · RESOLVED) so the history is not lost.

---

## 1. Commands executed and actual results (post-correction)

| # | Command | Exit | Result |
|---|---------|------|--------|
| 1 | `ddev exec php vendor/bin/phpunit` (FULL suite) | **0** | `OK, but some tests were skipped! Tests: 1474, Assertions: 3754, Skipped: 10.` |
| 2 | `ddev exec php vendor/bin/phpunit --filter 'FsGroup\(Test\|UserTest\)'` | **0** | `OK (26 tests, 98 assertions)` |
| 3 | `ddev exec php vendor/bin/phpunit tests/Controller/AdminOnlyScopeTest.php` (isolated) | **0** | `OK (14 tests, 26 assertions)` |
| 4 | `git status` / `git diff --stat` (scope guard) | 0 | See §2 — only expected files changed |

Environment: DDEV running, PHP 8.3.33, PHPUnit 11.5.56. All four commands were
executed for real in this run. The `ddev` wrapper re-quotes the argument, so the
focused filter required escaping `|`/parentheses (`'FsGroup\(Test\|UserTest\)'`).

### 1.1 Full-suite tail (verbatim, command 1)

```
Time: 00:07.438, Memory: 22.00 MB

OK, but some tests were skipped!
Tests: 1474, Assertions: 3754, Skipped: 10.
```

The 10 skips are pre-existing (unchanged from the pre-change baseline): they are
the same `S` markers seen in the earlier run, not new skips introduced by this
change. The suite is GREEN.

### 1.2 C-1 regression — original reproduction (preserved, now fixed)

The previous run was RED:

```
Tests: 1472, Assertions: 3750, Failures: 1
1) Tests\Controller\AdminOnlyScopeTest::attributeIsDeclaredOnExactlyTheScopedFiles
Failed asserting that two arrays are identical.
--- Expected
+++ Actual
 Array &0 [
     0 => 'admin_agentes',
     1 => 'admin_email',
-    2 => 'admin_info',
-    3 => 'admin_orden_menu',
-    4 => 'admin_rol',
-    5 => 'admin_stealth',
-    6 => 'admin_system_branding',
-    7 => 'admin_user',
-    8 => 'admin_users',
+    2 => 'admin_group',
+    3 => 'admin_groups',
+    4 => 'admin_info',
+    5 => 'admin_orden_menu',
+    6 => 'admin_rol',
+    7 => 'admin_stealth',
+    8 => 'admin_system_branding',
+    9 => 'admin_user',
+    10 => 'admin_users',
 ]
```

**Cause (as recorded).** `tests/Controller/AdminOnlyScopeTest.php` (pre-existing
PA-07 contract) globs `controller/admin_*.php`, collects every file containing
`#[\FSFramework\Attribute\AdminOnly]`, and asserted an **exact** nine-name list.
The change correctly adds two new `#[AdminOnly]` controllers (`admin_group`,
`admin_groups`) per UG-06, so the glob yields 11 names. The implementation was
spec-correct; the frozen **test contract** was the stale artifact.

**Correction applied (Batch 5).** `tests/Controller/AdminOnlyScopeTest.php` was
extended: `require_once` both new controllers; `admin_group`/`admin_groups` added
to `scopedControllerClasses()` and `scopedPageNames()` (now eleven names each);
class docblock reworded to the eleven-name scoped set. The assertion mechanism
was unchanged; no production file was touched.

---

## 2. Scope guard (tasks 5.2 / design §7)

`git status --porcelain` / `git diff --stat`:

**Modified (4)** — all expected:

| File | Diff | Content |
|------|------|---------|
| `base/fs_schema.php` | +4 | `fs_groups.xml` + `fs_groups_users.xml` appended to `CORE_TABLE_FILES` immediately after `fs_roles_users.xml`; both `CORE_MODEL_MAP` entries added after `fs_roles_users` (verified in the diff: registry order `fs_roles_users.xml → fs_groups.xml → fs_groups_users.xml → fs_vars.xml`). |
| `tests/Controller/AdminOnlyScopeTest.php` | +12 / −2 | C-1 correction: two `require_once`s, two `scopedControllerClasses()` entries, two `scopedPageNames()` entries, docblock reworded. Test-contract extension only. |
| `translations/messages.en.yaml` | +15 | 11 new group keys (verified present, lines 462–472). |
| `translations/messages.es.yaml` | +15 | 11 new group keys (verified present, lines 462–472). |

**New (10 code/view/test + SDD dir)** — all expected:

- `model/fs_group.php`, `model/fs_group_user.php`
- `model/table/fs_groups.xml`, `model/table/fs_groups_users.xml`
- `controller/admin_groups.php`, `controller/admin_group.php`
- `themes/AdminLTE/view/admin_groups.html.twig`, `themes/AdminLTE/view/admin_group.html.twig`
- `tests/Base/FsGroupTest.php`, `tests/Base/FsGroupUserTest.php`
- `openspec/changes/user-groups/` (SDD artifacts)

**Forbidden-change checks (all PASS):**

| Check | Method | Result |
|-------|--------|--------|
| `#[ApiResource]` on new models/controllers/templates | grep over the 6 new code/view files | NONE |
| Edits to `fs_rol` / `fs_rol_access` | modified-file set is only `fs_schema.php` + `AdminOnlyScopeTest.php` + 2 YAML | PASS |
| Edits to `fs_users` schema | no schema file touches `fs_users` | PASS |
| Edits to `fs_user::get_menu()` / `have_access_to()` | no `model/`/`src/` user file modified | PASS |
| Edits to `AdminOnlyPagesMigration::PAGE_ALLOWLIST` | no migration file modified | PASS |
| `codagente` / `almacen` coupling | grep over the 6 new code/view files | NONE |
| `\|raw` on user data in templates | grep both templates | NONE |
| CSRF on mutating forms | grep both templates | `method="post"` + `{{ csrf_field() }}` in both |
| Stray temp files | `git status --porcelain` | none |

**Verdict:** the working tree matches the frozen scope exactly. Scope guard PASSES.

---

## 3. Findings by severity

### CRITICAL

**None.** C-1 is **RESOLVED** (see below).

**C-1 · RESOLVED — `AdminOnlyScopeTest` extended for the two new admin-only pages.**
- Evidence: §1.2 (original RED reproduction preserved) vs §1 command 3
  (`OK (14 tests, 26 assertions)`, exit 0) and §1 command 1 (full suite green).
- The test contract now expects the eleven `#[AdminOnly]` controllers
  (`require_once` both new controllers + `scopedControllerClasses()` /
  `scopedPageNames()` entries), matching the real `controller/admin_*.php` glob.
- No production/authorization file was modified; the fix is a legitimate,
  expected consequence of adding admin-only pages, exactly as the original C-1
  analysis prescribed.

### WARNING

**W-1 — No runtime/DB evidence for the DB-backed scenarios (tasks 5.3 not executed).**
- The DB-less `Base` suite cannot exercise: `selfHealCoreTables()` creating both
  tables, lazy `check_table()` creation, FK cascade on group delete, FK cascade on
  user delete, `fs_pages.admin_only = true` persistence, `show_on_menu` values in
  `fs_pages`, the create→list→edit→delete UI flow, and the single-POST membership
  save end-to-end. All of these are asserted only statically (registry reflection
  test + XML inspection + source inspection).
- This is an optional task per tasks.md 5.3, but it is the only evidence class
  that would prove UG-05 (bootstrap/cascade) and UG-06 (persistence/menu) at
  runtime. Non-blocking per the report contract; carried forward for archive.

### SUGGESTION

**S-1 — Hardcoded Spanish UI strings not routed through `trans()` (frozen key set
excludes them).**
- `themes/AdminLTE/view/admin_groups.html.twig` and
  `themes/AdminLTE/view/admin_group.html.twig`: `title: '<b>Atención</b>'`, the
  help text `Los grupos son organizativos: no conceden permisos.`,
  `title="Recargar la página"`, `aria-label="Cerrar"`.
- Classify SUGGESTION: UG-06 only mandates ES/EN for **entity labels and function
  values**, which do resolve (see §4). These strings are outside the frozen key
  set, so they render in Spanish in the `en` locale — a minor i18n gap, not a spec
  violation, and not a raw-key leak.

**S-2 — `admin_groups::add_group()` redirects with `header('Location: …')` and no
`exit;`.**
- No output is emitted before the header (messages are buffered by the framework),
  and this exactly mirrors the shipped `admin_users::add_rol()` precedent.
- Classify SUGGESTION: consistency-with-precedent; hardening (`exit;`) would be an
  improvement but is not required by the spec.

**S-3 — `edit-group` i18n key ships but is never consumed.**
- Present in both `messages.es.yaml` and `messages.en.yaml` (lines 472); no
  template calls `trans('edit-group')` — the editor heading uses `trans('group')`.
- Classify SUGGESTION: dead key, harmless; either consume it or drop it.

**S-4 — `admin_group::all_users()` includes administrator users as members.**
- `all_users()` iterates `$this->user->all()` with no admin lock; the template
  shows the `administrator` label without disabling the membership checkbox.
- Classify SUGGESTION: matches design §4.2 and tasks 4.1 ("every `fs_user`");
  groups are organizational, so an admin may legitimately be a member/responsable.
  No action required unless product wants admin rows locked (contrast `admin_rol`).

---

## 4. Requirement-by-requirement assessment

| Req | Verified by | Status | Notes |
|-----|-------------|--------|-------|
| **UG-01** Group entity and lifecycle | **Automated tests** | ✅ Verified | `getReturnsModelWhenRowExists`, `getReturnsFalseWhenMissing`, `saveInsertsWhenNew`, `saveUpdatesWhenExisting` (duplicate-code upsert, no second row), `deleteReturnsTrueAndTargetsCode`, `allOrdersByDescripcion`, `urlFallsBackToListWhenCodeIsNull`, `urlPointsToEditor`, `saveSanitizesDescripcion`, `allForUserReturnsGroups`. `install()` returns `''` (source). |
| **UG-02** Membership lifecycle / one function per pair | **Automated tests** | ✅ Verified | `constructorDefaultsFuncionToMiembro`, `saveAcceptsMiembroAndResponsable`, `saveUpsertsExistingPair`, `saveNeverDuplicatesPair`, `existsIsScopedToThePair`, `deleteTargetsThePair`, `allFromGroupReturnsGroupMembers`, `allFromUserReturnsUserMemberships`. |
| **UG-03** Responsable assignment | **Automated tests** | ✅ Verified | `responsablesFromGroupFiltersResponsable`, `responsablesFromGroupReturnsEmptyWhenNone`, `setFuncionAcceptsAllowedAndRejectsOtherwise`, `getResponsablesDelegatesToMembership`, `getUsersDelegatesToMembership`. |
| **UG-04** Allowed-value validation | **Automated tests** | ✅ Verified | `funcionesConstantIsExactSet` (`FUNCIONES === ['miembro','responsable']`), `saveRejectsOutOfSetValueAndExecutesNoSql` (asserts `executedSql === []`, i.e. guard before any SQL), default-to-`miembro`, allowed values accepted. |
| **UG-05** Bootstrap registration + cascade | **Mixed** | ⚠️ Partial | Registry (`CORE_TABLE_FILES` order + `CORE_MODEL_MAP`) — **automated** (`coreRegistryListsBothGroupTables` reflection) and **confirmed in the diff** (§2). FK `ON DELETE/UPDATE CASCADE` — **static** (XML inspected, both cascade FKs present verbatim). `selfHealCoreTables()` creation, lazy `check_table()` creation, and both cascade scenarios — **unverified (runtime/DB, W-1)**. |
| **UG-06** Admin-only management pages | **Mixed** | ⚠️ Partial (regression resolved) | `#[AdminOnly]` + 5th-ctor args (`TRUE` list / `FALSE` editor) — **static** (grep: `admin_groups.php:27,39` `TRUE`; `admin_group.php:28,44` `FALSE`) and **detected** by `AdminOnlyScopeTest` — now **GREEN** (C-1 resolved). ES/EN labels + function values — **static** (all keys present in both locales; no raw key). Single-POST membership save and `members_form_present` gating — **static** (source + CSRF present). `admin_only` persistence, menu listing, and create/edit/delete UI flow — **unverified (runtime/DB, W-1)**. |
| **UG-07** No permission semantics / no API / no coupling | **Static** | ✅ Static-only | No edits to `fs_rol`/`fs_rol_access`/`fs_user::get_menu()`/`have_access_to()`/`PAGE_ALLOWLIST`; no `#[ApiResource]`; no `codagente`/`almacen` coupling; flat memberships (no group→group reference); nothing added to `admin_user`. "Membership does not change access" has no runtime test, but static evidence shows no authorization path is touched. |

### Verification-method summary

- **Verified by automated tests (green, focused):** UG-01, UG-02, UG-03, UG-04,
  and the UG-05 registry scenario. 26 focused tests / 98 assertions pass; the
  UG-06 PA-07 contract is green (14 tests / 26 assertions).
- **Verified only by static inspection:** UG-05 FK cascade declaration; UG-06
  attribute/menu args/labels/single-POST gating; UG-07 entirely.
- **Unverified (runtime/DB required):** UG-05 table creation (self-heal + lazy)
  and both cascade deletes; UG-06 `admin_only` persistence, `show_on_menu` in
  `fs_pages`, and the full UI create/edit/delete + membership save. Tracked as
  **W-1**.
- **Regression:** none outstanding (C-1 resolved).

---

## 5. Known items — explicit classification (carried forward)

| Item | Classification | Rationale |
|------|----------------|-----------|
| `admin_groups`/`admin_group` bootbox confirm title + help text hardcoded in Spanish; frozen key set excluded them | **SUGGESTION (S-1)** | Outside UG-06's ES/EN mandate (entity labels + function values only); no raw-key leak. |
| `add_group()` `header('Location: …')` without `exit;` | **SUGGESTION (S-2)** | Mirrors `admin_users::add_rol()`; no output precedes the header. |
| `edit-group` key ships but is not consumed | **SUGGESTION (S-3)** | Dead key; harmless. |
| `all_users()` includes administrator users as members | **SUGGESTION (S-4)** | Matches design §4.2 / tasks 4.1 ("every `fs_user`"); organizational, not permission. |
| DB-backed runtime smoke (tasks 5.3) NOT executed | **WARNING (W-1)** | Leaves UG-05 runtime and all UG-06 persistence/menu/UI scenarios without runtime evidence. Non-blocking. |

---

## 6. Limitations

- No DB-backed integration harness exists in the `Base` suite; the DB double
  proves SQL shape and branches, not real DDL, FKs, or cascade behavior. This is
  the sole reason W-1 remains open.
- `ddev` was available and running (PHP 8.3.33, PHPUnit 11.5.56); all four
  commands executed for real.
- The 10 skipped tests are pre-existing baseline skips, not introduced by this
  change.

---

## 7. Overall verdict

**The change PASSES verification.**

- The full suite is **GREEN** (`Tests: 1474, Assertions: 3754, Skipped: 10`,
  exit 0), the focused group suites are GREEN (`OK (26 tests, 98 assertions)`),
  and the previously-failing PA-07 contract is GREEN
  (`OK (14 tests, 26 assertions)`).
- **C-1 is resolved** by a test-contract-only correction; no production file
  changed. The scope guard matches the frozen file set exactly (4 modified, 10
  new code/view/test files).
- No CRITICAL findings remain.

**Remaining non-blocking items:**
- **WARNING W-1** — optional DB-backed runtime smoke (tasks 5.3) not executed;
  UG-05 runtime creation/cascade and UG-06 persistence/menu/UI rest on static
  evidence only.
- **SUGGESTION S-1…S-4** — hardcoded Spanish bootbox strings; `header()` without
  `exit`; dead `edit-group` key; admins included in `all_users()`.

**Recommended next:** archive the change, recording this **actual** state (green
suite, C-1 resolved, W-1 open). W-1 may be closed before or after archive by
running tasks 5.3 under `ddev start`; it does not block.

---

## 8. TDD evidence review (as recorded in `apply-progress.md`)

Strict TDD was active. The apply record shows per-task RED→GREEN for the two model
slices (Batch 1: RED 9 errors + 1 failure → `OK (10 tests, 36 assertions)`;
Batch 2: RED 16 errors → `OK (26 tests, 98 assertions)`), correctly discloses that
the two UI slices (PR 3/PR 4) ship no PHPUnit cases (scope assigns them the
`ddev start` runtime harness instead), and records **Batch 5** as an
approval-test-shape change: the spec's expected behavior changed (two new
admin-only pages are legitimate), so the frozen PA-07 approval list was updated
(9→11), producing the RED→GREEN record `12/22/1 → 14/14`.

This verify phase reproduces the recorded GREEN numbers independently:
`OK (26 tests, 98 assertions)` (focused) and `OK (14 tests, 26 assertions)`
(C-1 guard), with the full suite green. What the original apply run did not catch
was the cross-suite regression C-1, because its safety net was `--testsuite Base`
only — now closed.
