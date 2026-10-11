# Proposal: Core user groups with responsable assignment

## Intent

The core has no way to organize users into **groups**. Today the only
user-related aggregate is the permission package (`fs_rol` + `fs_rol_access`),
which is about what a user may do, not about how users are organized
operationally. Product needs a first-class organizational entity: a group that
holds users, where one or more of those users can be marked as its
**responsable** (lead).

This iteration introduces the entity and its management UI with **no permission
semantics**. Groups grant nothing and no authorization path reads them. The model
is deliberately shaped so that future functional roles (e.g. `comercial`,
`backoffice`) become new **data values**, not schema changes. The intent is to
establish the organizational primitive now so later changes can build on it
without reworking the schema.

## Scope

### In Scope

- Two new core tables: `fs_groups` (group) and `fs_groups_users` (membership).
- Two new legacy models in `model/`: `fs_group` and `fs_group_user`.
- Two XML schemas in `model/table/`: `fs_groups.xml`, `fs_groups_users.xml`.
- Registration of both tables in `base/fs_schema.php` (`CORE_TABLE_FILES` after
  `fs_roles_users.xml`, plus both entries in `CORE_MODEL_MAP`) so bootstrap
  self-heal creates them.
- Two `#[AdminOnly]` controllers: `admin_groups` (list + create/delete) and
  `admin_group` (editor, `?codgrupo=...`), each with its own admin menu entry.
- Two AdminLTE templates: `admin_groups.html.twig`, `admin_group.html.twig`.
- YAML translations (ES/EN) for the entity labels and function values.
- PHPUnit tests for model logic (allowed-value validation, upsert, filtered
  responsables query, membership lifecycle).

### Out of Scope (explicit non-goals)

- **Permission enforcement** — groups grant nothing.
- **Auth/session bridge** integration.
- **Changes to `fs_rol`, `fs_rol_access`, `fs_user::get_menu()`,
  `have_access_to()`** or any existing authorization path.
- **API exposure** (`src/Api/` attributes).
- **Group hierarchy / nesting** — flat groups only.
- **Multiple functions per membership** — one function per (group, user).
- **Agent (`codagente`) / warehouse (`almacen`) coupling.**
- **Surfacing groups on the user profile** (`admin_user`) in this iteration.

## Capabilities

### New Capabilities

- `user-groups`: Core user-group entity. Human-entered group code PK, group
  description, per-group membership rows carrying a single `funcion`
  (`miembro` default / `responsable`), and admin-only management pages
  (list + editor). No permission semantics; no enforcement; extensible to future
  function values without schema change.

### Modified Capabilities

- None. The new admin pages comply with the existing `#[AdminOnly]` declaration
  contract (page-authorization) and the tables are registered through the
  existing core-table mechanism (schema-sync). No existing requirement changes.

## Approach

Follow the confirmed exploration recommendation, mirroring the proven
`fs_rol` / `fs_rol_user` split.

**Data model — a single `funcion` column on the membership row (Option B).**

- `fs_groups`: `codgrupo character varying(20) NOT NULL` (human-entered PK, like
  `fs_rol.codrol`), `descripcion character varying(200) NOT NULL`,
  `PRIMARY KEY (codgrupo)`.
- `fs_groups_users`: `codgrupo character varying(20) NOT NULL`,
  `fs_user character varying(12) NOT NULL`,
  `funcion character varying(20) NOT NULL DEFAULT 'miembro'`,
  `PRIMARY KEY (codgrupo, fs_user)`,
  `FOREIGN KEY (codgrupo) REFERENCES fs_groups (codgrupo) ON DELETE CASCADE ON UPDATE CASCADE`,
  `FOREIGN KEY (fs_user) REFERENCES fs_users (nick) ON DELETE CASCADE ON UPDATE CASCADE`.

`'responsable'` is simply the first non-default function value; future values
(`comercial`, `backoffice`) are data, not schema. The column is named `funcion`
(not `rol`/`role`) to avoid semantic overlap with the permission package. Allowed
values are validated in application code through a
`fs_group_user::FUNCIONES` constant — no DB enum. `install()` returns `''` for
both models (no seed rows).

**Models.**

- `model/fs_group.php` — `fs_group` on `fs_groups`. Public `codgrupo`,
  `descripcion`. Methods: `__construct($data = FALSE)`, `install()`, `url()`
  (`index.php?page=admin_group&codgrupo=...`, falling back to the list page),
  `get($codgrupo)`, `exists()`, `save()`, `delete()`, `all()` (ordered by
  `descripcion`), `all_for_user($nick)`, `get_users()`,
  `get_responsables()`.
- `model/fs_group_user.php` — `fs_group_user` on `fs_groups_users`. Public
  `codgrupo`, `fs_user`, `funcion`. Methods: `__construct($data = FALSE)`,
  `install()`, `exists()` (by `(codgrupo, fs_user)`), `save()` (upsert of
  `funcion`), `delete()`, `all_from_group($codgrupo)`, `all_from_user($nick)`,
  `responsables_from_group($codgrupo)` (`WHERE funcion = 'responsable'`),
  `set_funcion()` and allowed-value validation against a
  `public const FUNCIONES = ['miembro', 'responsable']`.

**Admin UI — dedicated pages (mirrors the `admin_users` / `admin_rol` split).**

- `controller/admin_groups.php` (`#[\FSFramework\Attribute\AdminOnly]`): list
  groups, create with a code, delete. Own admin menu entry.
- `controller/admin_group.php` (`#[\FSFramework\Attribute\AdminOnly]`, editor at
  `?codgrupo=...`): edit `descripcion`; list users with membership and a
  `funcion` selector (member / responsable); save memberships in one POST.
- Templates copied from the `admin_users` / `admin_rol` structure
  (tabs, tables, modals).
- Entity labels "Grupo"/"Grupos"; i18n keys `group`, `groups`, `manager`
  (responsable), `function`.

A group may have zero responsables. Memberships cascade-delete with the user
(mirroring `fs_roles_users`). Pages persist `admin_only` through the existing
`check_fs_page()` attribute mechanism; the one-shot
`AdminOnlyPagesMigration::PAGE_ALLOWLIST` is **not** touched.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `model/table/fs_groups.xml` | New | Group table schema (code PK + description). |
| `model/table/fs_groups_users.xml` | New | Membership schema (composite PK, `funcion`, cascade FKs). |
| `model/fs_group.php` | New | `fs_group` model (CRUD, `all`, `all_for_user`, `get_users`, `get_responsables`). |
| `model/fs_group_user.php` | New | `fs_group_user` model (membership upsert, filtered responsables, `FUNCIONES`). |
| `base/fs_schema.php` | Modified | Append both XML files to `CORE_TABLE_FILES`; add both entries to `CORE_MODEL_MAP`. |
| `controller/admin_groups.php` | New | `#[AdminOnly]` group list + create/delete. |
| `controller/admin_group.php` | New | `#[AdminOnly]` group editor + memberships. |
| `themes/AdminLTE/view/admin_groups.html.twig` | New | Group list view (tabs/tables/modals). |
| `themes/AdminLTE/view/admin_group.html.twig` | New | Group editor view with `funcion` selector. |
| `translations/messages.es.yaml` | Modified | Add `group`/`groups`/`manager`/`function` and page labels. |
| `translations/messages.en.yaml` | Modified | Same keys in English. |
| `tests/Base/FsGroupTest.php` | New | Model logic tests. |
| `tests/Base/FsGroupUserTest.php` | New | Membership/funcion tests. |
| `openspec/changes/user-groups/proposal.md` | New | This proposal (and downstream spec/design/tasks artifacts). |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Forgetting `CORE_TABLE_FILES` / `CORE_MODEL_MAP`, so bootstrap self-heal silently skips the tables | Med | Make registry registration an explicit task; verify via a bootstrap/self-heal test. Lazy `check_table()` still covers first instantiation but the bootstrap guarantee would be lost. |
| Semantic overlap "grupo" vs "rol" | Med | Distinct table/class names (`fs_group` vs `fs_rol`), distinct menu label ("Grupos" ≠ "Roles"), and explicit comments that groups are organizational while roles are permissions. |
| Plugin class/table name collision (`fs_group`, `fs_groups`) | Low | No such class/table exists in this checkout. Verify against installed plugins before apply; keep names core-owned. |
| `tpvmod` plugin ships its own groups/roles (external, absent here) | Low | Cannot verify in this checkout; document as an external coordination risk for plugin maintainers. |
| PostgreSQL has no guaranteed index on `fs_groups_users.fs_user` (MySQL auto-indexes FK columns) | Low | Admin-scale impact is negligible; document it and optionally add an explicit index via a later one-shot migration if `all_from_user` ever scans at scale. |
| `admin_only` not propagated on already-migrated installs | Low | Rely on the `#[AdminOnly]` class attribute (the load-bearing mechanism); verify the new pages' `fs_pages.admin_only` is `true` after first visit. |
| `strict_tdd: true` — model logic without tests fails the gate | Med | Ship model logic with PHPUnit tests (anonymous `fs_model` subclass + mocked `fs_db2`, per `tests/Base/FsRolAccessAdminOnlyTest.php`). |

## Rollback Plan

1. Remove the two controllers and their templates:
   `controller/admin_groups.php`, `controller/admin_group.php`,
   `themes/AdminLTE/view/admin_groups.html.twig`,
   `themes/AdminLTE/view/admin_group.html.twig`.
2. Remove the two models and their XML schemas: `model/fs_group.php`,
   `model/fs_group_user.php`, `model/table/fs_groups.xml`,
   `model/table/fs_groups_users.xml`.
3. Revert the `base/fs_schema.php` additions (`CORE_TABLE_FILES`,
   `CORE_MODEL_MAP`).
4. Revert the added translation keys and remove the model tests.
5. Drop the created tables if desired: `DROP TABLE fs_groups_users;` then
   `DROP TABLE fs_groups;` (drop the child table first because of the FK).
   Optionally delete the resulting `fs_pages` rows for `admin_groups` /
   `admin_group`.

Because no existing table, model or authorization path is modified, rollback has
no effect on current data or behavior beyond the two new tables.

## Dependencies

- **DDEV** for all PHP/Composer/PHPUnit commands (`ddev exec php ...`).
- No new Composer dependencies; no external services.
- No dependency on any other SDD change.

## Success Criteria

- [ ] `fs_groups` and `fs_groups_users` are created by both bootstrap self-heal
      (`fs_schema::selfHealCoreTables()`) and lazy `fs_model::check_table()`.
- [ ] `fs_group` and `fs_group_user` implement the methods listed in Approach,
      with `save()`/`exists()`/`delete()` returning correct booleans.
- [ ] `fs_group_user::save()` rejects any `funcion` outside `FUNCIONES` and
      upserts on `(codgrupo, fs_user)`.
- [ ] `get_responsables()` / `responsables_from_group()` return only members
      whose `funcion` is `'responsable'`; a group with zero responsables returns
      an empty result without error.
- [ ] Deleting a `fs_user` removes its `fs_groups_users` rows via FK cascade.
- [ ] `admin_groups` and `admin_group` are `#[AdminOnly]`, appear under their own
      menu entry, and persist `admin_only = true` in `fs_pages`.
- [ ] A group can be created with a human-entered code, edited, and deleted from
      the UI; memberships and functions save in one POST.
- [ ] ES/EN translations render for the new pages and function labels.
- [ ] `ddev exec php vendor/bin/phpunit` passes, including the new model tests.
- [ ] No permission behavior changed: `fs_rol`, `fs_rol_access`,
      `fs_user::get_menu()` and `have_access_to()` are untouched.
