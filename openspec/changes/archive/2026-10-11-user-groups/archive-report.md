# Archive Report: user-groups

**Change**: `user-groups` (CORE, additive-only)
**Archived**: 2026-10-11
**Destination**: `openspec/changes/archive/2026-10-11-user-groups/`
**Mode**: openspec (core `openspec/` tree — the change touches `base/`, `model/`,
`controller/`, `translations/`, `themes/` and `tests/`, so it is owned by the core
tree, never a plugin SDD)
**Artifact store**: openspec
**Verdict at close**: **PASS** (`verify-report.md` re-run: 0 CRITICAL remaining, 1
WARNING (W-1), 4 SUGGESTIONs (S-1…S-4)).

## Final-State Authority (snapshots vs. close state)

`apply-progress.md` and `verify-report.md` are intermediate snapshots. The
orchestrator's explicit final-state facts for this archive outrank them where they
disagree. No unrankable contradiction was found: the launch-prompt facts and the
repository evidence agree with the re-run `verify-report.md`.

| Fact | Snapshot state | Close state | Evidence |
|---|---|---|---|
| **C-1** (full suite RED: `AdminOnlyScopeTest` asserted exactly nine `#[AdminOnly]` controllers) | First verify run: **CRITICAL**. Re-run `verify-report.md`: **RESOLVED** | **RESOLVED** | `tests/Controller/AdminOnlyScopeTest.php` extended to the eleven `#[AdminOnly]` controllers (`admin_group`, `admin_groups` added: two `require_once`s + `scopedControllerClasses()` + `scopedPageNames()` entries, docblock reworded). Test-contract-only change; no production/authorization file touched. Re-run `OK (14 tests, 26 assertions)`. |
| Full suite | First run RED (`Tests: 1472 … Failures: 1`) | **GREEN**, exit 0: `Tests: 1474, Assertions: 3754, Skipped: 10` | `ddev exec php vendor/bin/phpunit` (verify re-run §1). |
| `AdminOnlyScopeTest.php` as a modified file | Not listed in design §7 | **Modified** — additional file beyond the original design §7 scope | Confirmed by verify re-run §2 and by this archive's own read of the working tree. |

**Per the Final-State Authority hierarchy:** the `verify-report.md` CRITICAL→RESOLVED
and the four non-blocking items it carried forward are restated below as the close
state. No stale snapshot claim ("RED", "CRITICAL open") is echoed as current.

## Specs Synced

| Domain | Action | Details |
|---|---|---|
| `user-groups` | **Created** | No main spec existed before. The delta spec (`specs/user-groups/spec.md`) IS the full capability spec and was promoted **verbatim** (mechanical `cp` + empty `diff -r` readback). It defines UG-01…UG-07: `fs_group` entity + `fs_groups` table, `fs_group_user` membership + `fs_groups_users` table with one `funcion` per `(codgrupo, fs_user)`, responsable assignment, allowed-value validation, core bootstrap registration + cascade FKs, two `#[AdminOnly]` admin pages, and explicit non-requirements (no permission semantics). |
| `page-authorization` | **Not modified (intentional)** | PA-07 mandates the nine core admin-only pages; it does not forbid new admin-only pages. UG-06 adds two more pages that comply with the existing `#[AdminOnly]` mechanism. `openspec/specs/page-authorization/spec.md` was deliberately left untouched. |

**Source of truth now reflects the new behavior:**
- `openspec/specs/user-groups/spec.md` (created)
- `openspec/specs/page-authorization/spec.md` (unchanged, by design)

### PA-07 scope note (recorded)

The effective PA-07-scoped set of `#[AdminOnly]` pages grew 9 → 11 because UG-06
legitimately declares `admin_group` and `admin_groups`. This is enforced by the
updated `tests/Controller/AdminOnlyScopeTest.php` (globs `controller/admin_*.php`).
The `page-authorization` main spec was NOT edited: PA-07 requires the nine core
pages and does not prohibit additional admin-only pages.

## What Shipped

An additive-only core capability for organizational user groups. Groups grant
nothing: no permission semantics, no auth/session/API integration, flat only.

- **Model** `model/fs_group.php` (`fs_groups`: PK `codgrupo varchar(20)`, required
  `descripcion varchar(200)`): `get()`, `exists()`, `save()` (PK upsert, no
  duplicate rows), `delete()`, `all()` (ordered by description), `all_for_user()`,
  `get_users()`, `get_responsables()`, `install()` seeds nothing.
- **Model** `model/fs_group_user.php` (`fs_groups_users`: composite PK
  `(codgrupo, fs_user)`, single `funcion varchar(20) NOT NULL DEFAULT 'miembro'`):
  `FUNCIONES = ['miembro','responsable']`, `save()` (validate before any SQL;
  rejects out-of-set values returning `false` and persisting nothing; upsert on the
  pair), `delete()`, `all_from_group()`, `all_from_user()`,
  `responsables_from_group()`, `set_funcion()`.
- **Schema** `model/table/fs_groups.xml`, `model/table/fs_groups_users.xml` (FKs
  `(codgrupo) → fs_groups(codgrupo)` and `(fs_user) → fs_users(nick)`, both
  `ON DELETE CASCADE ON UPDATE CASCADE`).
- **Registry** `base/fs_schema.php`: both XML files appended to `CORE_TABLE_FILES`
  immediately after `fs_roles_users.xml`; both models added to `CORE_MODEL_MAP`.
  Bootstrap self-heal and lazy `check_table()` create both tables.
- **Admin pages**: `controller/admin_groups.php` (list; own menu entry; 5th ctor arg
  `TRUE`) and `controller/admin_group.php` (editor selected by `?codgrupo=`;
  not a menu entry; 5th ctor arg `FALSE`), both `#[\FSFramework\Attribute\AdminOnly]`.
  Single-POST membership save in the editor.
- **Views**: `themes/AdminLTE/view/admin_groups.html.twig`,
  `themes/AdminLTE/view/admin_group.html.twig` (both POST forms carry
  `{{ csrf_field() }}`; no `|raw` on user data).
- **i18n**: group keys appended to `translations/messages.es.yaml` and
  `translations/messages.en.yaml` (entity labels and function values resolve in
  both locales).
- **Tests**: `tests/Base/FsGroupTest.php`, `tests/Base/FsGroupUserTest.php` (26
  focused tests / 98 assertions green); `tests/Controller/AdminOnlyScopeTest.php`
  extended to the eleven-name scoped set (14 tests / 26 assertions green).

## Task Completion

`tasks.md`: **19/20 checked, 1 unchecked (19 complete, 1 unfinished)**.

- Unfinished: **5.3 Runtime smoke (optional, requires DB)** — `ddev start`, then
  exercise create/edit/delete on `admin_groups` and the single-POST membership save
  on `admin_group`, confirming `admin_only = true` persistence and menu visibility.
  Not executed (no DB harness). Tracked as **W-1**. Non-blocking.

## Known Items (carried forward, honestly)

| Item | Classification | Rationale |
|---|---|---|
| Tasks 5.3 DB-backed runtime smoke NOT executed | **WARNING (W-1)** | No DB-backed integration harness exists in the `Base` suite. UG-05 runtime table creation + both FK cascades and all UG-06 persistence/menu/UI scenarios rest on static evidence only. Non-blocking per the report contract. |
| Hardcoded Spanish bootbox strings in the list/editor templates (`title: '<b>Atención</b>'`, `Los grupos son organizativos: no conceden permisos.`, `title="Recargar la página"`, `aria-label="Cerrar"`) | **SUGGESTION (S-1)** | Outside UG-06's ES/EN mandate (entity labels + function values only, which do resolve). Minor i18n gap, not a raw-key leak. |
| `admin_groups::add_group()` uses `header('Location: …')` without `exit;` | **SUGGESTION (S-2)** | Mirrors the shipped `admin_users::add_rol()` precedent; no output precedes the header. Hardening only. |
| `edit-group` i18n key ships unused | **SUGGESTION (S-3)** | Dead key present in both locales; no template consumes it (editor heading uses `trans('group')`). Harmless. |
| `admin_group::all_users()` includes administrator users as members | **SUGGESTION (S-4)** | Matches design §4.2 / tasks 4.1 ("every `fs_user`"); groups are organizational, so an admin may be a member/responsable. No action unless product wants admin rows locked. |

## Delivery State (NOT done)

Delivery is **not** complete and is outside this phase's authority. As of archive:

- Nothing was committed or pushed.
- The change directory and the new main spec are **untracked** in the working tree
  (`git status --porcelain openspec/` → `?? openspec/changes/archive/2026-10-11-user-groups/`,
  `?? openspec/specs/user-groups/`).
- All production/view/test/i18n changes are uncommitted in the working tree.
- The commit/PR decision is the user's under ordinary repository policy.

**Review note (RDD).** Receipt-driven development review was ENABLED globally, but
the user explicitly declined the review for this candidate
(`consent: declined_this_candidate`, candidate-scoped). No review record exists and
the review switch stays on. SDD never offers or launches RDD; review mode is not
archive state.

## Mechanical Copy Evidence

- **Spec promotion** (`delta → openspec/specs/user-groups/spec.md`), verbatim
  `diff -r` output:
  ```
  === diff -r (delta vs staged copy) ===
  [diff empty — byte-identical]
  ```
- **Archive move** (pre-move recursive snapshot vs. archive destination), verbatim
  `diff -r` output:
  ```
  === diff -r (pre-move snapshot vs archive destination) ===
  [diff empty — byte-identical archive]
  ```
  `git mv` was attempted first; it failed because the source was untracked
  (`fatal: directorio de fuente está vacío`), so the documented plain-`mv` fallback
  ran after the source-unchanged snapshot check passed. The active source is gone
  and the archive preserves all seven original artifacts byte-for-byte. This
  `archive-report.md` is additive and was written after the move, so it is excluded
  from the destination comparison.

## Final Numbers

- Full suite: `Tests: 1474, Assertions: 3754, Skipped: 10`, exit 0 — **GREEN**
  (10 skips are pre-existing baseline skips, not introduced by this change).
- Focused group suites: `OK (26 tests, 98 assertions)`.
- PA-07 scope contract: `OK (14 tests, 26 assertions)`.
- Tasks: 19/20 complete; 1 optional task (5.3) unfinished.

## SDD Cycle Complete

The change is archived. Implementation: all four slices (PR 1–4) implemented; full
suite green. Verification: **pass** (re-run, no CRITICAL remaining). Unfinished
tasks and unresolved findings: **W-1** (optional DB runtime smoke not executed) and
**S-1…S-4** (non-blocking suggestions) as tabulated above. Delivery (commit/PR) was
not performed.
