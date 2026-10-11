# Design: Core user groups with responsable assignment

## Context

This document is the technical design for the CORE change `user-groups`, which
implements the capability defined in
`openspec/changes/user-groups/specs/user-groups/spec.md` (UG-01…UG-07) and the
scope fixed by `proposal.md`.

The design mirrors the proven `fs_rol` / `fs_rol_user` split that already exists
in this framework:

| Roles package | Groups package (this change) |
|---|---|
| `fs_rol` → `fs_roles` | `fs_group` → `fs_groups` |
| `fs_rol_user` → `fs_roles_users` | `fs_group_user` → `fs_groups_users` |
| `controller/admin_rol.php` | `controller/admin_group.php` (editor) |
| (list inside `admin_users`) | `controller/admin_groups.php` (own list) |

Groups are **organizational only**. No permission semantics, no auth/session
bridge, no API attributes, flat, no agent/warehouse coupling, and nothing
surfaced on the user profile. The design is deliberately shaped so that future
functional roles (`comercial`, `backoffice`) are **new data values** in the
membership's `funcion` set, never schema changes.

### Frozen decisions (carried verbatim from the preflight)

- Human-entered code PK `codgrupo varchar(20)`, like `fs_rol.codrol`.
- Membership carries a single `funcion varchar(20) NOT NULL DEFAULT 'miembro'`
  (Option B). Allowed-values source of truth is
  `public const FUNCIONES = ['miembro', 'responsable']` on the membership model;
  future values are data-only.
- Tables `fs_groups`, `fs_groups_users`; models `fs_group`, `fs_group_user` in
  `model/`; XML in `model/table/`.
- Dedicated `#[AdminOnly]` controllers `admin_groups` + `admin_group` + AdminLTE
  templates + YAML translations.
- No permissions/auth/API; flat; cascade delete with user; zero responsables
  allowed.

---

## 1. Data model / DDL

Both schemas use only the constraints the framework's XML dialect supports:
`PRIMARY KEY` and `FOREIGN KEY` (the parser also accepts `UNIQUE`, but we add
none). There is **no explicit index element** in this dialect.

### 1.1 `model/table/fs_groups.xml` (New)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!--
    Document   : fs_groups.xml
    Author     : FSFramework
    Description:
        Estructura de la tabla fs_groups. Entidad organizativa (grupos de
        usuarios). No concede permisos.
-->
<tabla>
    <columna>
        <nombre>codgrupo</nombre>
        <tipo>character varying(20)</tipo>
        <nulo>NO</nulo>
    </columna>
    <columna>
        <nombre>descripcion</nombre>
        <tipo>character varying(200)</tipo>
        <nulo>NO</nulo>
    </columna>
    <restriccion>
        <nombre>fs_groups_pkey</nombre>
        <consulta>PRIMARY KEY (codgrupo)</consulta>
    </restriccion>
</tabla>
```

Effective DDL (PostgreSQL):

```sql
CREATE TABLE IF NOT EXISTS "fs_groups" (
  "codgrupo" character varying(20) NOT NULL,
  "descripcion" character varying(200) NOT NULL,
  PRIMARY KEY (codgrupo)
);
```

MySQL: `character varying(20)` → `VARCHAR(20)` via `fs_schema::$typeMapping`,
table options `ENGINE=InnoDB DEFAULT CHARSET=… COLLATE=…` (resolved from the DB
at create time).

### 1.2 `model/table/fs_groups_users.xml` (New)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!--
    Document   : fs_groups_users.xml
    Author     : FSFramework
    Description:
        Estructura de la tabla fs_groups_users. Pertenencia de un usuario a
        un grupo, con una única función (miembro / responsable).
-->
<tabla>
    <columna>
        <nombre>codgrupo</nombre>
        <tipo>character varying(20)</tipo>
        <nulo>NO</nulo>
    </columna>
    <columna>
        <nombre>fs_user</nombre>
        <tipo>character varying(12)</tipo>
        <nulo>NO</nulo>
    </columna>
    <columna>
        <nombre>funcion</nombre>
        <tipo>character varying(20)</tipo>
        <defecto>miembro</defecto>
        <nulo>NO</nulo>
    </columna>
    <restriccion>
        <nombre>fs_groups_users_pkey</nombre>
        <consulta>PRIMARY KEY (codgrupo,fs_user)</consulta>
    </restriccion>
    <restriccion>
        <nombre>fs_groups_users_group</nombre>
        <consulta>FOREIGN KEY (codgrupo) REFERENCES fs_groups (codgrupo) ON DELETE CASCADE ON UPDATE CASCADE</consulta>
    </restriccion>
    <restriccion>
        <nombre>fs_groups_users_user</nombre>
        <consulta>FOREIGN KEY (fs_user) REFERENCES fs_users (nick) ON DELETE CASCADE ON UPDATE CASCADE</consulta>
    </restriccion>
</tabla>
```

Effective DDL (PostgreSQL):

```sql
CREATE TABLE IF NOT EXISTS "fs_groups_users" (
  "codgrupo" character varying(20) NOT NULL,
  "fs_user" character varying(12) NOT NULL,
  "funcion" character varying(20) DEFAULT 'miembro' NOT NULL,
  PRIMARY KEY (codgrupo,fs_user),
  CONSTRAINT "fs_groups_users_group" FOREIGN KEY (codgrupo)
    REFERENCES fs_groups (codgrupo) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT "fs_groups_users_user" FOREIGN KEY (fs_user)
    REFERENCES fs_users (nick) ON DELETE CASCADE ON UPDATE CASCADE
);
```

Notes on the DDL contract:

- `funcion` default is rendered by `fs_schema::buildDefaultClause()` as
  `DEFAULT 'miembro'` (plain string, not numeric/boolean/keyword). This is valid
  on both PostgreSQL and MySQL.
- FK emission is guarded by `fs_schema::addForeignKeyConstraint()`: a FK is
  emitted only if the referenced table already exists at creation time. This
  makes the **creation order load-bearing**: `fs_groups.xml` must be registered
  and created before `fs_groups_users.xml`, and `fs_users.xml` already precedes
  both. See §3.
- Deleting a group cascades to its membership rows; deleting a user cascades to
  that user's membership rows. Both cascades are DB-level, satisfying the
  "deleting a user removes its rows" and "deleting a group cascades" scenarios.
- No explicit index on `fs_groups_users.fs_user`: MySQL auto-indexes FK columns,
  PostgreSQL does not guarantee one. Admin-scale impact is negligible
  (documented risk in §6).

---

## 2. Model API

Both models are **legacy** (`model/`, no namespace, not PSR-4 autoloaded) and
extend `fs_model`. Constructor signature and style mirror `fs_rol` / `fs_rol_user`
(`FALSE` default, `NULL` when no data).

### 2.1 `model/fs_group.php` — `class fs_group extends fs_model` (New)

Public members:

```php
public $codgrupo;     // string|null
public $descripcion;  // string|null
```

Methods (exact signatures):

```php
public function __construct($data = FALSE)
    // parent::__construct('fs_groups')
    // $data ? codgrupo/descripcion from array : NULL/NULL

protected function install()
    // returns '' (no seed rows) — mirrors fs_rol::install()

public function url()
    // is_null(codgrupo) => 'index.php?page=admin_groups'
    // else              => 'index.php?page=admin_group&codgrupo=' . urlencode($this->codgrupo)

public function get($codgrupo)
    // SELECT * FROM fs_groups WHERE codgrupo = <var2str($codgrupo)>
    // returns new fs_group(row) or FALSE when no row

public function exists()
    // is_null(codgrupo) => FALSE
    // else SELECT * ... WHERE codgrupo = <var2str>  (row array or FALSE)

public function save()
    // no_html($descripcion)
    // exists() ? UPDATE descripcion WHERE codgrupo : INSERT (codgrupo, descripcion)
    // returns (bool) $this->db->exec($sql)

public function delete()
    // DELETE FROM fs_groups WHERE codgrupo = <var2str>; returns (bool)

public function all()
    // SELECT * FROM fs_groups ORDER BY descripcion ASC, codgrupo ASC
    // returns fs_group[]

public function all_for_user($nick)
    // SELECT * FROM fs_groups
    //   WHERE codgrupo IN (SELECT codgrupo FROM fs_groups_users WHERE fs_user = <var2str($nick)>)
    // Instantiates fs_group_user first to guarantee the membership table exists.
    // returns fs_group[]

public function get_users()
    // (new fs_group_user())->all_from_group($this->codgrupo); returns fs_group_user[]

public function get_responsables()
    // (new fs_group_user())->responsables_from_group($this->codgrupo); returns fs_group_user[]
```

**`save()` duplicate-code contract (resolves open point).** `save()` is an
**upsert keyed on the PK** `codgrupo`: `exists()` decides `UPDATE` vs `INSERT`.
A second create attempt with an existing code therefore performs an `UPDATE`,
creates no second row (UG-01 "does not duplicate it"), and returns **`true`** on
success. This exactly matches `fs_rol::save()`, which is the canonical behaviour
for human-entered-code PKs in this framework. There is no "reject duplicate"
mode; the caller distinguishes create-vs-edit by first calling `get()` (as
`admin_groups` does before showing the "already exists" message).

`all()` tie-break: primary sort `descripcion ASC` (required by UG-01). A
secondary `codgrupo ASC` is added so equal descriptions yield a deterministic
order (resolves the open point; see §6).

### 2.2 `model/fs_group_user.php` — `class fs_group_user extends fs_model` (New)

Constants and SQL fragments (mirroring `fs_rol_user`):

```php
public const FUNCIONES = ['miembro', 'responsable'];

private const SQL_SELECT_ALL_FROM = 'SELECT * FROM ';
private const SQL_WHERE = ' WHERE ';
```

Public members:

```php
public $codgrupo;  // string|null
public $fs_user;   // string|null
public $funcion;   // string  (default 'miembro')
```

Methods (exact signatures):

```php
public function __construct($data = FALSE)
    // parent::__construct('fs_groups_users')
    // $data ? (codgrupo, fs_user, funcion = $data['funcion'] ?? 'miembro') : (NULL, NULL, 'miembro')

protected function install()
    // new fs_group(); new fs_user();  // force dependency tables to load/create
    // returns '' (no seed rows) — mirrors fs_rol_user::install()

public function exists()
    // is_null(codgrupo) => FALSE
    // else SELECT * FROM fs_groups_users
    //        WHERE codgrupo = <var2str> AND fs_user = <var2str>

public function save()
    // 1. normalize: if (funcion === null || funcion === '') funcion = 'miembro'
    // 2. validate : if (!in_array(funcion, self::FUNCIONES, true)) return FALSE
    // 3. exists() ? UPDATE funcion WHERE (codgrupo, fs_user)
    //             : INSERT (codgrupo, fs_user, funcion)
    // returns (bool) $this->db->exec($sql)

public function delete()
    // DELETE FROM fs_groups_users
    //   WHERE codgrupo = <var2str> AND fs_user = <var2str>; returns (bool)

public function all_from_group($codgrupo)
    // SELECT * WHERE codgrupo = <var2str> ORDER BY fs_user ASC
    // returns fs_group_user[]

public function all_from_user($nick)
    // SELECT * WHERE fs_user = <var2str> ORDER BY codgrupo ASC
    // returns fs_group_user[]

public function responsables_from_group($codgrupo)
    // SELECT * WHERE codgrupo = <var2str> AND funcion = 'responsable' ORDER BY fs_user ASC
    // returns fs_group_user[] (empty array when none — never errors)

public function set_funcion($funcion = 'miembro')
    // normalize empty/null => 'miembro'
    // if (!in_array(funcion, self::FUNCIONES, true)) return FALSE
    // $this->funcion = funcion
    // return (bool) $this->save()
```

**`save()` boolean contract (resolves open point).**

| Input | Behaviour | Return |
|---|---|---|
| `funcion` in `FUNCIONES` (`miembro`/`responsable`) | upsert the single `(codgrupo, fs_user)` row | `true` on exec success |
| `funcion` omitted / `null` / `''` | normalized to `'miembro'`, then upsert | `true` on exec success |
| `funcion` outside `FUNCIONES` (e.g. `'administrador'`, `'comercial'`) | **nothing is executed** (no SQL reaches the DB), no row created/updated | `false` |
| `codgrupo`/`fs_user` null | `exists()` false → INSERT would violate PK/FK; caller must set both. Documented precondition, not a validation branch (mirrors `fs_rol_user`). | — |

The out-of-set guard runs **before** any `$this->db->exec()`, so "persists
nothing" (UG-04) is guaranteed at the model boundary, not merely by a DB
constraint.

`set_funcion()` is a thin convenience wrapper: validate → assign → `save()`.
Callers that already hold a persisted membership use it directly; callers that
build from data rely on the constructor default plus `save()`.

---

## 3. Schema registration

Single file edited: `base/fs_schema.php`.

### 3.1 `CORE_TABLE_FILES` (private const, line ~44)

Insert both XML files **immediately after** `'fs_roles_users.xml'` and before
`'fs_vars.xml'`:

```php
private const CORE_TABLE_FILES = [
    'fs_pages.xml',
    'fs_users.xml',
    'fs_roles.xml',
    'fs_access.xml',
    'fs_roles_access.xml',
    'fs_roles_users.xml',
    'fs_groups.xml',        // + New
    'fs_groups_users.xml',  // + New
    'fs_vars.xml',
];
```

Order rationale: `installCoreTables()` and `selfHealCoreTables()` both iterate
this list in order. `fs_groups` must exist before `fs_groups_users` so the FK
`REFERENCES fs_groups (codgrupo)` passes the `table_exists()` guard. `fs_users`
is already listed earlier, satisfying the second FK's reference.

### 3.2 `CORE_MODEL_MAP` (private const, line ~53)

Add both entries after the `fs_roles_users` entry, before `fs_vars`:

```php
'fs_groups' => ['file' => 'model/fs_group.php', 'class' => 'fs_group'],           // + New
'fs_groups_users' => ['file' => 'model/fs_group_user.php', 'class' => 'fs_group_user'], // + New
```

`selfHealCoreTables()` → `healCoreTable()` requires a `CORE_MODEL_MAP` entry for
every missing table; without it, healing reports
`No hay mapeo de modelo para tabla: fs_groups`. The mapped model instantiation
(`new fs_group()` / `new fs_group_user()`) triggers lazy `check_table()`, which
creates the table from XML.

### 3.3 Lazy path

`fs_model::check_table()` reads the XML from `model/table/<table>.xml` and, on a
missing table, calls `ensureInstallDependencies()` before `generate_table()`.
To make the FK references resolvable on the lazy path (independent of bootstrap
order), `fs_group_user::install()` instantiates `new fs_group()` and
`new fs_user()` — the same dependency-loading trick `fs_rol_user::install()`
uses for `fs_rol`/`fs_user`.

---

## 4. Admin UI

Both controllers are legacy (`controller/`), declare
`#[\FSFramework\Attribute\AdminOnly]` at class level, and extend `fs_controller`.
Page titles follow the existing literal-string convention of sibling controllers
(`admin_users`: `'Usuarios'`, `admin_rol`: `'Editar rol'`); translated entity
labels live in the templates via `trans()`.

### 4.1 `controller/admin_groups.php` (New) — list + create/delete

```php
#[\FSFramework\Attribute\AdminOnly]
class admin_groups extends fs_controller
{
    public $groups = [];
    public $allow_delete;

    public function __construct()
    {
        // 4th $admin param is obsolete/ignored; 5th = show_on_menu (own menu entry)
        parent::__construct(__CLASS__, 'Grupos', 'admin', FALSE, TRUE);
    }

    protected function private_core()
    {
        // Mirrors admin_rol::private_core(): deleting this page is admin-only.
        $this->allow_delete = $this->user->admin;

        $fs_group = new fs_group();

        if (filter_input(INPUT_POST, 'ncodgrupo')) {
            $this->add_group($fs_group);
        } elseif (filter_input(INPUT_GET, 'delete_grupo')) {
            $this->delete_group($fs_group);
        }

        $this->groups = $fs_group->all();
    }
    // add_group():  admin check -> new fs_group(codgrupo, descripcion) -> save()
    //               message -> redirect to $group->url()  (or error)
    // delete_group(): admin check -> $fs_group->get(code) -> delete() -> message
}
```

Responsibilities:

- `private_core()` routes on `ncodgrupo` (POST, create) and `delete_grupo`
  (GET, delete), mirroring `admin_users::add_rol()` / `delete_rol()`.
- `$allow_delete` is declared as a public property and assigned
  `$this->user->admin` at the top of `private_core()`, exactly as
  `controller/admin_rol.php:28,39`. `base/fs_controller.php` does **not** define
  `allow_delete` (only `fs_list_controller.php` does, and this controller
  extends `fs_controller`), so the property must be declared and set here; the
  template's `fsc.allow_delete` guard (§4.3) depends on this assignment.
- **Menu entry.** This page is the only menu entry of the change: the 5th
  constructor argument is `TRUE` (`show_on_menu`). Only the list is reachable
  from the admin menu; the editor is reached from the list (see §4.2).
- Both mutations require `$this->user->admin` (fail-closed, like the role
  controller); `error` otherwise.
- Create uses the human-entered code directly; if `get(code)` already exists the
  controller reports "already exists" instead of blindly upserting (README of
  the create flow). `save()` itself stays an upsert (§2.1).
- After create, redirects to the editor `admin_group?codgrupo=…`.

### 4.2 `controller/admin_group.php` (New) — editor + memberships

```php
#[\FSFramework\Attribute\AdminOnly]
class admin_group extends fs_controller
{
    public $group;
    public $allow_delete;
    public $funciones = [];

    public function __construct()
    {
        // 5th = FALSE: editor is not a menu entry (reached from the list)
        parent::__construct(__CLASS__, 'Editar grupo', 'admin', FALSE, FALSE);
    }

    protected function private_core()
    {
        $this->allow_delete = $this->user->admin;
        $this->funciones = fs_group_user::FUNCIONES;

        if (fs_filter_input_req('codgrupo')) {
            $this->group = (new fs_group())->get(fs_filter_input_req('codgrupo'));
        }

        if ($this->group && $this->request->isMethod('POST')) {
            $this->modify();
        } elseif (!$this->group) {
            $this->new_error_msg('Grupo no encontrado.');
        }
    }

    public function all_users()   // all fs_user rows annotated with membership + funcion
    public function grupos_member() // helper: current user nicks of the group
    private function modify()      // one POST: descripcion + memberships
}
```

Responsibilities:

- **Not a menu entry.** The 5th constructor argument is `FALSE`, so
  `admin_group` never appears in the admin menu; it is reached only from the
  list via `fs_group::url()` (`index.php?page=admin_group&codgrupo=…`). Same
  precedent as the `controller/admin_rol.php:33` and `controller/admin_user.php`
  editors, both declared with 5th argument `FALSE` (UG-06).
- Selected by `?codgrupo=…`; missing/unknown code → error message, template
  renders the "not found" block (like `admin_rol`).
- `all_users()` mirrors `admin_rol::all_users()`: starts from `$this->user->all()`
  and annotates each `fs_user` with `included` (is member) and `funcion`
  (current value, default `'miembro'`). Non-admin users only.
- `modify()`:
  1. Admin check (`$this->user->admin`); otherwise error and return.
  2. Update `descripcion` only if the field travelled in the POST (partial-POST
     safety, like `admin_rol::modify()`); call `$this->group->save()`.
  3. Process memberships **only if** the hidden marker
     `members_form_present` is present (partial-POST safety). Iterate
     `$this->all_users()`; for each user build `fs_group_user(['codgrupo' =>
     $this->group->codgrupo, 'fs_user' => $u->nick])`:
     - if the user's `member[]` marker is checked → read `funcion[<nick>]`,
       validate against `FUNCIONES`, assign, `save()`;
     - else → `delete()` (removes the membership).
     This upserts every submitted membership in a single POST (UG-06).
  4. Emit success/error messages.

### 4.3 Templates (New, AdminLTE)

- `themes/AdminLTE/view/admin_groups.html.twig`
  - Header include + `footer.html.twig`.
  - List `<table>`: code, description, link to editor (`g.url()`), delete
    button (confirm via `bootbox`), guarded by `fsc.allow_delete`.
  - "New group" modal/form posting `ncodgrupo` + `descripcion` with
    `{{ csrf_field() }}`.
  - Labels via `trans()`: `groups`, `new-group`, `group-code`,
    `group-description`, `delete-group`.
- `themes/AdminLTE/view/admin_group.html.twig`
  - Editor form posting to `{{ fsc.group.url() }}` with `{{ csrf_field() }}`.
  - `descripcion` input.
  - Hidden marker `<input type="hidden" name="members_form_present" value="1"/>`.
  - Users table: per-row member checkbox `member[]=nick` and a `<select
    name="funcion[<nick>]">` populated from `fsc.funciones`, preselecting the
    user's current `funcion`; select shown only when the user is a member.
  - Delete-group button (confirm) posting navigation to `admin_groups` with
    `delete_grupo=<code>`.
  - "No grup found" block when `fsc.group` is falsy.
  - Labels via `trans()`: `group`, `function`, `member`, `manager` (the
    `responsable` value), `delete-group`, `save`.

### 4.4 Translations

Append the same keys to `translations/messages.es.yaml` and
`translations/messages.en.yaml` (existing top-level flat-key format):

| Key | ES | EN |
|---|---|---|
| `group` | Grupo | Group |
| `groups` | Grupos | Groups |
| `manager` | Responsable | Manager |
| `function` | Función | Function |
| `member` | Miembro | Member |
| `new-group` | Nuevo grupo | New group |
| `edit-group` | Editar grupo | Edit group |
| `delete-group` | Eliminar grupo | Delete group |
| `group-code` | Código | Code |
| `group-description` | Descripción | Description |
| `no-groups` | No hay grupos | No groups |

Function values in the selector are rendered through the keys
`member`/`manager` (mapping `miembro`→`member`, `responsable`→`manager`), so no
raw key is shown in either locale (UG-06).

---

## 5. Tests

Location: `tests/Base/` (open-core coverage; namespace `Tests\Base`, PSR-4 via
`Tests\`). Run with `ddev exec php vendor/bin/phpunit --testsuite Base`.
`tests/bootstrap.php` defines `FS_FOLDER`; model files are non-autoloaded and
must be `require_once`-ed.

### 5.1 Harness pattern

Follow `tests/Base/FsRolAccessAdminOnlyTest.php`:

- **Anonymous model subclass** that bypasses `parent::__construct()` (which
  would open a DB connection / run `check_table()`) and sets public properties
  directly.
- **DB double** injected via `ReflectionProperty(\fs_model::class, 'db')`, with
  `select()`, `exec()`, `var2str()` and an `executedSql` recorder. `select()`
  returns a configurable row set, so both the "row found" and "no row" branches
  of `get`/`exists` are exercised without a real DB.
- No DB, no cache, no session.

```php
require_once FS_FOLDER . '/model/fs_group.php';
require_once FS_FOLDER . '/model/fs_group_user.php';
```

### 5.2 `tests/Base/FsGroupTest.php` (New)

| Test | Covers |
|---|---|
| `getReturnsModelWhenRowExists` | UG-01 read by code |
| `getReturnsFalseWhenMissing` | UG-01 missing group, no invented row |
| `saveInsertsWhenNew` | UG-01 create |
| `saveUpdatesWhenExisting` (duplicate code → UPDATE, returns `true`, no second row) | UG-01 duplicate-code contract |
| `deleteReturnsTrueAndTargetsCode` | UG-01 delete by code |
| `allOrdersByDescripcion` (two rows, `Alfa` before `Zeta`) | UG-01 listing order |
| `allForUserReturnsGroups` | UG-01 memberships scoped to a user |
| `getUsersDelegatesToMembership` | UG-01 users of a group |
| `getResponsablesDelegatesToMembership` | UG-01/UG-03 responsables |
| `urlFallsBackToListWhenCodeIsNull` / `urlPointsToEditor` | editor routing |
| `saveSanitizesDescripcion` (no `<script>`) | security baseline |
| `coreRegistryListsBothGroupTables` (ReflectionClass on `fs_schema`, asserts `fs_groups.xml`/`fs_groups_users.xml` in `CORE_TABLE_FILES` after `fs_roles_users.xml`, and both keys in `CORE_MODEL_MAP`) | UG-05 registry scenario |

### 5.3 `tests/Base/FsGroupUserTest.php` (New)

| Test | Covers |
|---|---|
| `funcionesConstantIsExactSet` | UG-04 `FUNCIONES === ['miembro','responsable']` |
| `constructorDefaultsFuncionToMiembro` | UG-04 omitted function |
| `saveAcceptsMiembroAndResponsable` | UG-04 allowed values accepted |
| `saveRejectsOutOfSetValueAndExecutesNoSql` | UG-04 rejected + nothing persisted (`executedSql === []`) |
| `saveUpsertsExistingPair` (exists → UPDATE, not INSERT) | UG-02 upsert |
| `saveNeverDuplicatesPair` | UG-02 one row per pair |
| `existsIsScopedToThePair` | UG-02 existence by pair |
| `deleteTargetsThePair` | UG-02 remove user from group |
| `allFromGroupReturnsGroupMembers` | UG-02 group members |
| `allFromUserReturnsUserMemberships` | UG-02 multi-group user |
| `responsablesFromGroupFiltersResponsable` | UG-03 filtered query |
| `responsablesFromGroupReturnsEmptyWhenNone` | UG-03 zero responsables, no error |
| `setFuncionAcceptsAllowedAndRejectsOtherwise` | UG-03/UG-04 set/unset responsable |

### 5.4 Schema/self-heal coverage

The UG-05 bootstrap and lazy scenarios are asserted structurally in
`FsGroupTest::coreRegistryListsBothGroupTables` (registry constants) and
exercised end-to-end by the existing bootstrap self-heal path once both entries
are registered. No new integration harness is introduced (the suite has no DB).

---

## 6. Tradeoffs, alternatives and risks

### 6.1 Alternatives considered

- **Separate `responsable` boolean vs single `funcion` column (Option B).**
  A boolean column would encode only "responsable/not" and force a schema change
  for the next function (`comercial`, `backoffice`). Option B with a validated
  string is forward-compatible: extending the allowed set is a one-line constant
  change with no migration. Cost: the string needs application-side validation
  (accepted; `FUNCIONES` is the single source of truth).
- **Naming `funcion` vs `rol`/`role`.** `rol`/`role` collides semantically with
  the permission package (`fs_rol`, `fs_rol_access`). `funcion` keeps the
  organizational membership distinct. Mitigation for human confusion: distinct
  table/class names and a distinct menu label ("Grupos" ≠ "Roles").
- **Full separate `fs_group_user` model vs reusing `fs_rol_user` shape.**
  Chose a dedicated model to keep permissions and organization decoupled, even
  though the structure parallels.
- **List page inside `admin_users` (as roles are today) vs dedicated
  `admin_groups`.** Chose dedicated pages: the **list** `admin_groups` gets its
  own menu entry (5th constructor argument `TRUE`) while the **editor**
  `admin_group` does not (5th constructor argument `FALSE`), matching the
  `admin_rol`/`admin_user` editor precedents (UG-06). This gives the entity
  first-class navigation without listing a parameterised editor in the menu.

### 6.2 Open points resolved

- **`all()` tie-break.** Spec mandates only `ORDER BY descripcion`. Chosen:
  secondary `codgrupo ASC` for deterministic ordering (mirrors the intent;
  `fs_rol::all()` uses only `descripcion`, but groups may legitimately share a
  description, so a deterministic tie-break is safer).
- **Duplicate-code `save()` boolean.** Chosen: PK upsert (UPDATE on existing
  code), returning `true` and creating no second row. Rejection is a caller-side
  concern (`admin_groups` checks `get()` first). Rationale: matches
  `fs_rol::save()` and keeps `save()` total (never silently no-ops).
- **`fs_schema` position verification.** Chosen: `fs_groups.xml` then
  `fs_groups_users.xml`, both after `fs_roles_users.xml` and before
  `fs_vars.xml`; `CORE_MODEL_MAP` entries adjacent. Verified by the reflection
  test in §5.2. Rationale: the FK guard requires `fs_groups` to precede
  `fs_groups_users`.
- **Menu-entry declaration.** Chosen: only the list page `admin_groups` is a menu
  entry (5th constructor argument `TRUE`); the editor `admin_group` is not
  (`FALSE`) and is reached from the list. This is the normative rule of UG-06 and
  matches the `controller/admin_rol.php:33` / `controller/admin_user.php` editor
  precedents. The proposal's Scope line mentioned "menu entries" imprecisely;
  the corrected spec is the authoritative rule for this point.

### 6.3 Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Missing `CORE_TABLE_FILES`/`CORE_MODEL_MAP` entries leave self-heal blind; lazy path still creates tables but bootstrap guarantee is lost | Med | Explicit task; `coreRegistryListsBothGroupTables` test; keep registry edit atomic with the XML files. |
| FK emission silently skipped if `fs_groups` is missing when `fs_groups_users` is created | Low | Strict ordering in `CORE_TABLE_FILES`; lazy path seeds `fs_group` from `fs_group_user::install()`. |
| Semantic overlap "grupo" vs "rol" | Med | Distinct names/classes/menu labels; doc comments state groups are organizational, roles are permission. |
| Plugin class/table collision (`fs_group`, `fs_groups`) | Low | No such class/table in this checkout; names are core-owned; verify against installed plugins before apply. |
| `tpvmod` plugin ships its own groups/roles (external, absent here) | Low | Cannot verify here; documented external coordination risk. |
| PostgreSQL has no guaranteed index on `fs_groups_users.fs_user` | Low | Admin-scale only; document; optional later one-shot index migration if `all_from_user` ever scans at scale. |
| `all()` shared descriptions produce unstable order without the tie-break | Low | Secondary `codgrupo ASC` added. |
| `strict_tdd` gate | Med | Both models ship with the PHPUnit tests in §5. |
| `admin_only` not propagated on already-migrated installs | Low | `#[AdminOnly]` class attribute is the load-bearing mechanism; `check_fs_page()` persists `admin_only = true`. `AdminOnlyPagesMigration::PAGE_ALLOWLIST` is **not** touched. |

---

## 7. Affected files

| File | New/Modified | Purpose |
|---|---|---|
| `model/table/fs_groups.xml` | New | Group table schema (code PK + description). |
| `model/table/fs_groups_users.xml` | New | Membership schema (composite PK, `funcion` default/valued, cascade FKs). |
| `model/fs_group.php` | New | `fs_group` model (CRUD, `all`, `all_for_user`, `get_users`, `get_responsables`). |
| `model/fs_group_user.php` | New | `fs_group_user` model (upsert, filtered responsables, `FUNCIONES`, `set_funcion`). |
| `base/fs_schema.php` | Modified | Append both XML files to `CORE_TABLE_FILES` after `fs_roles_users.xml`; add both `CORE_MODEL_MAP` entries. |
| `controller/admin_groups.php` | New | `#[AdminOnly]` list + create/delete; own menu entry. |
| `controller/admin_group.php` | New | `#[AdminOnly]` editor (`?codgrupo=`) + single-POST memberships. |
| `themes/AdminLTE/view/admin_groups.html.twig` | New | Group list view (table + create modal). |
| `themes/AdminLTE/view/admin_group.html.twig` | New | Group editor with `funcion` selector. |
| `translations/messages.es.yaml` | Modified | Add entity/function keys (ES). |
| `translations/messages.en.yaml` | Modified | Add entity/function keys (EN). |
| `tests/Base/FsGroupTest.php` | New | Model + registry tests. |
| `tests/Base/FsGroupUserTest.php` | New | Membership/`funcion` tests. |
| `tests/Controller/AdminOnlyScopeTest.php` | Modified | Extend the PA-07 scope guard to include the two new admin-only pages (`admin_group`, `admin_groups`) introduced by UG-06 (C-1 correction). |
| `openspec/changes/user-groups/design.md` | New | This document. |

No existing table, model, authorization path, translation key or API attribute
is modified beyond the additive `fs_schema` registry entries.

---

## 8. Implementation order (handoff to tasks)

1. XML schemas (`fs_groups.xml`, `fs_groups_users.xml`).
2. `base/fs_schema.php` registry entries.
3. `model/fs_group.php`.
4. `model/fs_group_user.php`.
5. `tests/Base/FsGroupTest.php`, `tests/Base/FsGroupUserTest.php`.
6. `controller/admin_groups.php`, `controller/admin_group.php`.
7. Templates + ES/EN translations.

Steps 3–6 preserve the frozen scope: no permissions, no auth/session bridge, no
API, flat groups, no profile surfacing, zero-responsable allowed.
