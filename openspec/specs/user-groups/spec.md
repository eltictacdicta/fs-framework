# Delta for user-groups

## ADDED Requirements

This delta introduces the new core capability `user-groups`: a first-class
organizational entity (a group) that holds users, where one or more members MAY
be marked as the group's **responsable**. It is **additive only** — no existing
table, column, model, method, page, service id or authorization path is changed.
Groups grant nothing: the capability carries **no permission semantics**, and no
authorization path reads it. The membership shape uses a single `funcion`
column so future functional roles (`comercial`, `backoffice`) become new data
values, not schema changes.

### Requirement: UG-01 — Group entity and lifecycle by human-entered code

The system MUST provide a core group entity backed by table `fs_groups`, keyed by
a human-entered `codgrupo character varying(20) NOT NULL` primary key (mirroring
`fs_rol.codrol`), with a required `descripcion character varying(200) NOT NULL`.
The entity MUST support create, edit (description), delete (by code), existence
check (`exists()`), read by code (`get($codgrupo)`), a deterministic listing
(`all()`, ordered by `descripcion`), membership listings scoped to a user
(`all_for_user($nick)`), the users of a group (`get_users()`), and the filtered
responsables of a group (`get_responsables()`). `install()` MUST seed no rows
and return the empty string.

#### Scenario: Create a group with a human-entered code

- GIVEN no group with code `comercial` exists
- WHEN a group `comercial` with description `Equipo comercial` is saved
- THEN a single `fs_groups` row exists with `codgrupo = 'comercial'`
- AND its `descripcion` is `Equipo comercial`

#### Scenario: Edit the description of an existing group

- GIVEN group `comercial` exists with description `Equipo comercial`
- WHEN its `descripcion` is changed to `Ventas` and saved
- THEN `get('comercial')->descripcion` is `Ventas`
- AND no additional `fs_groups` row was created

#### Scenario: Creating a group whose code already exists does not duplicate it

- GIVEN group `comercial` already exists
- WHEN a second create attempt uses the code `comercial`
- THEN `fs_groups` still holds exactly one row for `comercial`

#### Scenario: Delete a group by code

- GIVEN group `comercial` exists
- WHEN it is deleted
- THEN `get('comercial')` returns no group
- AND `exists()` for that code is `false`

#### Scenario: Listing is ordered by description

- GIVEN groups `b` (description `Zeta`) and `a` (description `Alfa`)
- WHEN `all()` is called
- THEN the result is ordered by `descripcion` ascending (`Alfa` before `Zeta`)

#### Scenario: Missing group is reported, not invented

- GIVEN no group with code `inexistente` exists
- WHEN `get('inexistente')` is called
- THEN it returns no group
- AND the call does not create a row

### Requirement: UG-02 — Membership lifecycle and one function per (group, user)

The system MUST provide a membership entity backed by table `fs_groups_users`
with composite primary key `(codgrupo, fs_user)` and a single
`funcion character varying(20) NOT NULL DEFAULT 'miembro'` column. It MUST
support adding a user to a group, removing a user from a group, existence by the
pair (`exists()`), and upsert save keyed on `(codgrupo, fs_user)`. Saving MUST
never produce more than one membership row for the same `(codgrupo, fs_user)`
pair. It MUST expose a group's members (`all_from_group($codgrupo)`) and a
user's memberships (`all_from_user($nick)`).

#### Scenario: Add a user to a group uses the default function

- GIVEN user `ana` is not a member of group `comercial`
- WHEN the membership is saved without an explicit `funcion`
- THEN one `fs_groups_users` row exists for `('comercial', 'ana')`
- AND its `funcion` is `miembro`

#### Scenario: Re-saving the same membership upserts instead of duplicating

- GIVEN `('comercial', 'ana')` already exists as `miembro`
- WHEN the membership is saved again for the same pair
- THEN `fs_groups_users` still holds exactly one row for `('comercial', 'ana')`

#### Scenario: Change the function of an existing membership

- GIVEN `('comercial', 'ana')` exists as `miembro`
- WHEN the membership is saved as `responsable`
- THEN the single existing row now has `funcion = 'responsable'`

#### Scenario: Remove a user from a group

- GIVEN `('comercial', 'ana')` exists
- WHEN the membership is deleted
- THEN no `fs_groups_users` row remains for `('comercial', 'ana')`
- AND `exists()` for that pair is `false`

#### Scenario: A user belongs to more than one group

- GIVEN `ana` is a member of `comercial` and of `soporte`
- WHEN `all_from_user('ana')` is called
- THEN both memberships are returned
- AND `all_from_group('comercial')` returns only `ana`'s `comercial` membership

### Requirement: UG-03 — Responsable assignment

The system MUST allow any member of a group to be marked as its **responsable**
and later unmarked. A group MAY have multiple responsables, and a group MAY have
zero responsables. `get_responsables()` and `responsables_from_group($codgrupo)`
MUST return only members whose `funcion` is `'responsable'`; when none exist they
MUST return an empty result without raising an error.

#### Scenario: Mark a member as responsable

- GIVEN `ana` is a member of `comercial` as `miembro`
- WHEN `ana`'s function is set to `responsable`
- THEN `responsables_from_group('comercial')` includes `ana`
- AND `get_responsables('comercial')` includes `ana`

#### Scenario: Multiple responsables are allowed

- GIVEN `ana` and `luis` are both members of `comercial`
- WHEN both are marked `responsable`
- THEN `responsables_from_group('comercial')` returns both `ana` and `luis`

#### Scenario: Unmark a responsable back to a plain member

- GIVEN `ana` is `responsable` in `comercial`
- WHEN `ana`'s function is set to `miembro`
- THEN `responsables_from_group('comercial')` no longer includes `ana`
- AND `ana` remains a member of the group

#### Scenario: A group with zero responsables returns empty without error

- GIVEN `comercial` has members but none is `responsable`
- WHEN `responsables_from_group('comercial')` is called
- THEN it returns an empty result
- AND no error or warning is raised

#### Scenario: Filtered query excludes non-responsables

- GIVEN `comercial` has one `responsable` and two `miembro` members
- WHEN `responsables_from_group('comercial')` is called
- THEN only the single `responsable` member is returned

### Requirement: UG-04 — Allowed-value validation for `funcion`

The membership entity MUST expose the allowed functions as a
`FUNCIONES = ['miembro', 'responsable']` constant and MUST validate every
`funcion` against it. Saving or setting a `funcion` outside the allowed set MUST
be rejected: the operation MUST return `false` and MUST persist nothing. An
omitted `funcion` MUST resolve to the default `miembro`.

#### Scenario: Allowed values are accepted

- GIVEN a membership save with `funcion = 'miembro'` and another with `'responsable'`
- WHEN each is saved
- THEN both are accepted and persisted with the given value

#### Scenario: An out-of-set value is rejected and persists nothing

- GIVEN a membership save with `funcion = 'administrador'`
- WHEN it is saved
- THEN the operation returns `false`
- AND no `fs_groups_users` row is created or updated for that pair

#### Scenario: The default applies when the function is omitted

- GIVEN a membership save that omits `funcion`
- WHEN it is saved
- THEN the persisted `funcion` is `miembro`

#### Scenario: Adding a future value requires no schema change but is rejected until allowed

- GIVEN the allowed set is exactly `['miembro', 'responsable']`
- WHEN a `funcion` such as `comercial` is submitted
- THEN it is rejected as out-of-set
- AND enabling it later is a code-level addition to `FUNCIONES`, not a schema change

### Requirement: UG-05 — Bootstrap registration and cascade guarantees

Both new tables MUST be registered in the core table registry
`base/fs_schema.php`: `model/table/fs_groups.xml` and
`model/table/fs_groups_users.xml` appended to `CORE_TABLE_FILES` immediately
after `fs_roles_users.xml`, and both `fs_group` and `fs_group_user` present in
`CORE_MODEL_MAP`. Bootstrap self-heal (`fs_schema::selfHealCoreTables()`) MUST
create both tables, and lazy `fs_model::check_table()` MUST also create them on
first instantiation. The membership table MUST declare foreign keys
`(codgrupo) REFERENCES fs_groups (codgrupo)` and
`(fs_user) REFERENCES fs_users (nick)`, both `ON DELETE CASCADE ON UPDATE CASCADE`.

#### Scenario: Both tables are registered in the core registries

- GIVEN `base/fs_schema.php` is loaded
- WHEN `CORE_TABLE_FILES` and `CORE_MODEL_MAP` are inspected
- THEN both XML files are listed in `CORE_TABLE_FILES` after `fs_roles_users.xml`
- AND both `fs_group` and `fs_group_user` are mapped in `CORE_MODEL_MAP`

#### Scenario: Bootstrap self-heal creates both tables

- GIVEN a fresh database where neither table exists
- WHEN `fs_schema::selfHealCoreTables()` runs
- THEN `fs_groups` and `fs_groups_users` both exist with their declared columns

#### Scenario: Lazy check creates the tables on first instantiation

- GIVEN the bootstrap self-heal has not populated the tables
- WHEN a model is instantiated for the first time
- THEN lazy `check_table()` creates the missing table(s)

#### Scenario: Deleting a group cascades to its memberships

- GIVEN group `comercial` has one or more membership rows
- WHEN the group row is deleted
- THEN its `fs_groups_users` rows are removed by the foreign key cascade
- AND no orphan membership remains

#### Scenario: Deleting a user cascades to that user's memberships

- GIVEN user `ana` has one or more `fs_groups_users` rows
- WHEN the `fs_users` row for `ana` is deleted
- THEN all of `ana`'s membership rows are removed by the foreign key cascade
- AND the remaining memberships of other users are unaffected

### Requirement: UG-06 — Admin-only management pages

The system MUST provide two administrator-only management pages declared with
the `#[\FSFramework\Attribute\AdminOnly]` class attribute: `admin_groups` (list
groups, create with a code, delete) and `admin_group` (editor selected by
`?codgrupo=...`, editing the description and saving memberships with a function
selector in a single POST). Only the list page `admin_groups` MUST have its own
admin menu entry (5th constructor argument `TRUE`); the editor page `admin_group`
MUST NOT be a menu entry (5th constructor argument `FALSE`), consistent with the
`admin_rol` editor and the `admin_user` editor precedents. Both pages MUST
persist `admin_only = true` on their `fs_pages` row through the existing
`check_fs_page()` mechanism; the one-shot `AdminOnlyPagesMigration` allowlist
MUST NOT be modified. ES and EN translations MUST render for the entity labels
and function values.

#### Scenario: Both pages declare administrator-only access

- GIVEN the `admin_groups` and `admin_group` controllers are registered
- WHEN their pages are persisted
- THEN both persist `admin_only = true` in `fs_pages`
- AND both are excluded from the role-permission page listing

#### Scenario: Only the list page is an admin menu entry

- GIVEN the `admin_groups` and `admin_group` controllers are registered
- WHEN each page is persisted
- THEN `admin_groups` persists `show_on_menu = true` (5th constructor argument `TRUE`)
- AND `admin_group` persists `show_on_menu = false` (5th constructor argument `FALSE`), matching the `admin_rol` and `admin_user` editors

#### Scenario: The list page creates and deletes groups by code

- GIVEN an administrator is on `admin_groups`
- WHEN a new group with a code is created, then deleted
- THEN the group appears in the list after creation
- AND is gone from the list after deletion

#### Scenario: The editor saves memberships in one POST

- GIVEN an administrator is on `admin_group?codgrupo=comercial`
- WHEN the description and the per-user function selections are submitted together
- THEN the description is updated
- AND each submitted membership is upserted with its selected function

#### Scenario: Groups and functions are labeled in ES and EN

- GIVEN the active locale is `es` and then `en`
- WHEN the group pages render the entity labels and function values
- THEN the labels resolve in the active language
- AND no raw translation key is shown

### Requirement: UG-07 — Explicit non-requirements (no permission semantics)

This capability MUST NOT grant any permission. Membership in a group, or being
marked `responsable`, MUST NOT change what a user may do. The system MUST NOT
integrate with authentication, sessions or the REST API, MUST NOT expose groups
through `src/Api/` resource attributes, MUST NOT nest groups (groups are flat),
and MUST NOT surface groups on the user profile (`admin_user`) or couple to
agents (`codagente`) or warehouses (`almacen`). Existing `fs_rol`,
`fs_rol_access`, `fs_user::get_menu()` and `have_access_to()` MUST remain
unchanged in behavior.

#### Scenario: Membership does not change access

- GIVEN a user who is not a member of any group
- WHEN the user is added to a group and marked `responsable`
- THEN the user's menu and `have_access_to()` results are unchanged
- AND no `fs_rol_access` row is created or modified

#### Scenario: No API exposure

- GIVEN the group and membership entities
- WHEN the API model registry is scanned
- THEN neither is exposed as an API resource
- AND no `#[ApiResource]` attribute is declared on them

#### Scenario: Groups are flat

- GIVEN a group exists
- WHEN its memberships are inspected
- THEN memberships reference users only
- AND no membership references another group as a child or parent

#### Scenario: No profile surfacing in this iteration

- GIVEN an administrator opens the user profile page (`admin_user`)
- WHEN the page renders
- THEN it does not list or edit the user's group memberships

## REMOVED Requirements

None.

## MODIFIED Requirements

None. This change adds a new capability and modifies no existing requirement.
Existing `#[AdminOnly]` declaration behavior (page-authorization) and the
core-table registration mechanism (schema-sync) are reused as-is; the new pages
comply with the former and the new tables are registered through the latter.
