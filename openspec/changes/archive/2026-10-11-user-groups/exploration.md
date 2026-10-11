# Exploration: Core user groups with responsable assignment

## Original request (verbatim, Spanish)

> "necesito poder asignar uno o varios usuarios como responsables de un grupo en el core. en un principio no va a tener ningún permiso especial, es para futuras implementaciones."

Clarified intent (confirmed): introduce a **new core entity for user groups**. A group
holds users, and one or more of those users can be marked as **responsable**
(owner/lead). **No permission logic in this iteration.** The model must be
extensible for future functional roles (e.g. "comercial", "backoffice").

Scope classification: **CORE**. SDD lives in `openspec/changes/user-groups/`.

---

## Current State

There is **no group entity anywhere in the core**. A case-insensitive search for
"grupo" across `base/ model/ controller/ src/ themes/ translations/` returns zero
hits. The only user/permission models are:

| Model | File | Table | Role in the system |
|---|---|---|---|
| `fs_user` | `model/core/fs_user.php` (+ thin `model/fs_user.php`) | `fs_users` | Users, identified by `nick`. Optional link to an agent via `codagente`. |
| `fs_rol` | `model/fs_rol.php` | `fs_roles` | **Permission package** (codrol + descripcion). |
| `fs_rol_user` | `model/fs_rol_user.php` | `fs_roles_users` | N:M membership user↔role. |
| `fs_rol_access` | `model/fs_rol_access.php` | `fs_roles_access` | Per-page grants inside a role. |

### Patterns confirmed by reading real code

- **Legacy model convention** (`model/fs_rol.php`, `model/fs_rol_user.php`):
  classes extend `fs_model`, declare the table in `parent::__construct('fs_...')`,
  expose `install()`, `exists()`, `save()`, `delete()`, `all()`, plus relation
  helpers (`all_from_rol`, `all_from_user`, `get_users`). `fs_rol` does not define
  `test()`; it only `no_html()`-sanitizes in `save()`. Newer models (`idioma.php`)
  add a real `test()` and a seed in `install()`.
- **Table creation happens twice, both lazy**:
  1. `fs_model::__construct()` → `check_table()` reads `model/table/{table}.xml`,
     creates the table and runs `install()` (seed) when missing
     (`base/fs_model.php:300-340, 504-539`).
  2. `fs_schema::selfHealCoreTables()` runs at bootstrap from `index.php:85`,
     `api.php:138`, `cron.php:69`, iterating an **explicit allowlist**
     `CORE_TABLE_FILES` (`base/fs_schema.php:44-52`) and resolving the model via
     `CORE_MODEL_MAP` (`base/fs_schema.php:53-61`).
- **Pages self-register**: instantiating a controller calls
  `fs_controller::check_fs_page()` (`base/fs_controller.php:210, 820-876`), which
  creates/updates the `fs_pages` row and persists `admin_only` from the
  `#[\FSFramework\Attribute\AdminOnly]` class attribute
  (`fs_page::is_admin_only_class()`, `model/fs_page.php:221`).
- **Admin-only**: `FSFramework\Core\Schema\AdminOnlyPagesMigration`
  (`src/Core/Schema/AdminOnlyPagesMigration.php`) backfills an **explicit 9-name
  allowlist** and is gated by the `admin_only_pages_migrated` `fs_var` flag
  (runs once). New admin pages rely on the class attribute, not the allowlist.
- **Admin UI precedent** (`fs_rol`): list/create/delete roles from a **"Roles" tab
  inside `admin_users`**; per-role editing lives in
  `controller/admin_rol.php` + `themes/AdminLTE/view/admin_rol.html.twig`
  (description field, "Páginas" tab, "Usuarios" tab with per-user checkboxes).
- **No XML index support**: `base/fs_schema.php` has no `<indice>`/`<index>`
  handling; only PRIMARY KEY and FOREIGN KEY. Indexes on FK columns come from the
  engine (MySQL auto-indexes FK columns; PostgreSQL does not).
- **Tests**: PHPUnit 11, `ddev exec php vendor/bin/phpunit`; core tests in
  `tests/`; related precedents: `tests/Base/FsRolAccessAdminOnlyTest.php`,
  `tests/Base/FsPageAdminOnlyTest.php`. `openspec/config.yaml` declares
  `strict_tdd: true`.
- **Translations**: `translations/messages.es.yaml` / `messages.en.yaml`; existing
  admin keys include `user`, `users`, `role`, `roles`.

### Collision check

- No `fs_group`/`fs_group_user` class and no `fs_groups` table exist in the tree.
- No core "grupo" concept exists → no semantic collision inside the core.
- The orchestrator flagged a plugin `tpvmod` with its own roles. **It is not
  present in this checkout** (`plugins/` contains only `api_auth`,
  `api_auth_back`, `system_updater`, `system_updater_back`), so it cannot be
  verified here. Treat as an external risk for plugin maintainers (see Risks).

---

## Data model: options

The core decision is how to represent **"responsable"** and stay open to future
functional roles.

### Option A — boolean `responsable` flag on the membership row

`fs_groups_users(codgrupo, fs_user, responsable boolean)`.

- Pros: smallest change; mirrors `fs_roles_users` exactly; trivial queries.
- Cons: future roles ("comercial", "backoffice") need new columns (schema churn)
  or a second table. Cannot express "responsable who is also comercial".
- Effort: Low.

### Option B — single `funcion` column on the membership row (RECOMMENDED)

`fs_groups_users(codgrupo, fs_user, funcion varchar(20) NOT NULL DEFAULT 'miembro')`.
Today the only meaningful values are `'miembro'` (default) and `'responsable'`;
future roles become new **data values**, not schema changes.

- Pros: one column satisfies both stated needs ("responsable" marker now +
  extensible functional roles later); zero schema churn to add "comercial"/
  "backoffice"; `get_responsables()` is a filtered query; keeps one membership
  row per (group, user); consistent footprint with `fs_roles_users`.
- Cons: app-level validation only (no DB enum in this XML dialect); a single user
  can hold **one** function per group (see Option C if multi-function is needed).
- Effort: Low.

### Option C — `responsable` boolean **plus** `funcion` column

`fs_groups_users(codgrupo, fs_user, responsable boolean, funcion varchar nullable)`.

- Pros: two orthogonal dimensions — leadership (responsable) independent from
  functional role (comercial/backoffice); a comercial can also be responsable.
- Cons: one extra column with no consumer today; two concepts to explain in UI;
  YAGNI risk.
- Effort: Low–Medium.

### Recommendation

**Option B.** It directly encodes the confirmed intent: "responsable" is simply the
first functional role value, and future roles are values rather than columns. It
avoids the YAGNI cost of Option C while beating Option A on extensibility, which
the user explicitly asked for. If the product expects a user to hold **multiple
orthogonal functions simultaneously in the same group**, escalate to Option C
(captured as an open question).

### Naming (aligned with existing conventions)

- Tables: `fs_groups`, `fs_groups_users` (English resource names, mirroring
  `fs_roles` / `fs_roles_users`).
- Columns: `codgrupo varchar(20)` PK, `descripcion varchar(200)`; membership
  `codgrupo`, `fs_user varchar(12)`, `funcion varchar(20)`.
- Column name deliberately **`funcion`**, not `rol`/`role`, to avoid semantic
  overlap with `fs_rol` (permission package). `papel` is an acceptable synonym if
  reviewers prefer it.
- Membership PK `(codgrupo, fs_user)`; FKs with `ON DELETE CASCADE ON UPDATE
  CASCADE` to `fs_groups(codgrupo)` and `fs_users(nick)` — mirroring
  `fs_roles_users`.

**Alternative PK considered:** surrogate `serial id`. Rejected for consistency:
`fs_rol` uses a human-entered code PK with identical `url()`/`get()` patterns, and
the admin UI already creates permission roles that way. A serial PK would be more
rename-safe; note as an alternative if group codes must be editable.

---

## Model classes

Two `fs_model` subclasses in `model/`, plus XML in `model/table/`.

### `model/fs_group.php` — class `fs_group` (table `fs_groups`)

Public: `codgrupo`, `descripcion`.

Methods:
- `__construct($data = FALSE)` — same shape as `fs_rol`.
- `install()` — return `''` (no seed; groups are user-created).
- `url()` — `index.php?page=admin_group&codgrupo=...` (falls back to list page).
- `get($codgrupo)`, `exists()`, `save()`, `delete()`.
- `all()` — order by `descripcion`.
- `all_for_user($nick)` — groups a user belongs to (mirror `fs_rol::all_for_user`).
- `get_users()` — members (delegates to `fs_group_user::all_from_group`).
- `get_responsables()` — members whose `funcion = 'responsable'`
  (delegates to `fs_group_user::responsables_from_group`), returning `fs_user`
  objects or membership rows.

### `model/fs_group_user.php` — class `fs_group_user` (table `fs_groups_users`)

Public: `codgrupo`, `fs_user`, `funcion`.

Methods:
- `__construct($data = FALSE)`, `install()`.
- `exists()` — by `(codgrupo, fs_user)`.
- `save()` — insert or update `funcion` (upsert).
- `delete()` — remove one membership.
- `all_from_group($codgrupo)` / `all_from_user($nick)` — mirror `fs_rol_user`.
- `responsables_from_group($codgrupo)` — `WHERE codgrupo = ? AND funcion =
  'responsable'`.
- `set_funcion($funcion)` / validation of allowed values.
- Allowed-value source of truth: a `public const FUNCIONES = ['miembro',
  'responsable'];` constant (extend later for comercial/backoffice); `save()`
  rejects values outside the list. No DB enum, no permission behaviour.

### Indexes

PK `(codgrupo, fs_user)` covers `all_from_group`. `all_from_user` filters on
`fs_user`: MySQL auto-indexes the FK column, **PostgreSQL does not**, so
`all_from_user` could scan on PG at scale. Acceptable for an admin-scale table;
optionally add an explicit index later via a small one-shot migration if needed
(see Risks).

---

## Schema / migration

1. Add `model/table/fs_groups.xml`:
   - `codgrupo character varying(20) NOT NULL`, `descripcion character varying(200) NOT NULL`;
   - `PRIMARY KEY (codgrupo)`.
2. Add `model/table/fs_groups_users.xml`:
   - `codgrupo character varying(20) NOT NULL`, `fs_user character varying(12) NOT NULL`,
     `funcion character varying(20) NOT NULL DEFAULT 'miembro'`;
   - `PRIMARY KEY (codgrupo, fs_user)`;
   - `FOREIGN KEY (codgrupo) REFERENCES fs_groups (codgrupo) ON DELETE CASCADE ON UPDATE CASCADE`;
   - `FOREIGN KEY (fs_user) REFERENCES fs_users (nick) ON DELETE CASCADE ON UPDATE CASCADE`.
3. Register for bootstrap self-heal (critical — easy to forget):
   - append `fs_groups.xml`, `fs_groups_users.xml` to
     `fs_schema::CORE_TABLE_FILES` **after** `fs_roles_users.xml`;
   - add both entries to `fs_schema::CORE_MODEL_MAP` pointing at
     `model/fs_group.php` / `model/fs_group_user.php`.
4. Table creation then happens two ways for free: bootstrap self-heal and lazy
   `fs_model::check_table()` on first instantiation.
5. **No data migration** for this iteration (new empty tables). `install()`
   returns `''` for both models (no seed rows).
6. `AdminOnlyPagesMigration::PAGE_ALLOWLIST` does **not** need the new page: the
   `#[\AdminOnly]` attribute persists `admin_only` through `check_fs_page()`. Note
   that its flag-gated one-shot backfill would not re-run even if the name were
   added, so the attribute is the load-bearing mechanism.

---

## Admin UI

Two viable shapes, mirroring the proven `fs_rol` split:

### Option 1 (RECOMMENDED) — dedicated `admin_groups` + `admin_group`

- `controller/admin_groups.php` (list + create/delete), `#[AdminOnly]`,
  `parent::__construct(__CLASS__, 'Grupos', 'admin', FALSE, TRUE)` so it shows in
  the admin menu. Create/delete via modal, like the roles tab.
- `controller/admin_group.php` (editor, `?codgrupo=...`), `#[AdminOnly]`:
  edit `descripcion`; list all users with a membership checkbox and a
  **funcion selector** (plain member / responsable); save memberships and
  responsables in one POST.
- Templates: `themes/AdminLTE/view/admin_groups.html.twig`,
  `admin_group.html.twig` — copy the tab/table/modal structure from
  `admin_users.html.twig` and `admin_rol.html.twig`.
- Pros: groups get their own lifecycle and menu entry; keeps `admin_users` from
  growing; matches the explicit rol precedent (list vs editor split).
- Cons: two new controllers + templates + translations.

### Option 2 — extend `admin_users` with a "Grupos" tab

Add groups to the existing `admin_users`/`admin_users.html.twig` tab set (next to
"Roles"), optionally reusing `admin_rol`-style editor for membership.

- Pros: fewer new files; discoverability next to Usuarios/Roles.
- Cons: `admin_users` controller/template already large; mixes two entity
  lifecycles; groups have no permission semantics so co-locating with roles may
  reinforce the grupo/rol confusion we want to avoid.

**Recommendation: Option 1**, both pages `#[AdminOnly]`, minimal CRUD only.

---

## Extensibility for future roles (without enforcement now)

- The `funcion` column is the single extension point. Adding `'comercial'` or
  `'backoffice'` is a change to `fs_group_user::FUNCIONES` plus UI option labels —
  **no schema change**.
- This iteration ships only `'miembro'` (default) and `'responsable'`. Nothing
  reads `funcion` for authorization.
- Permission work (if ever) belongs to a separate SDD change and must not be
  pre-wired here.

---

## Explicit non-goals

- **No permission enforcement**: groups grant nothing; `fs_user::get_menu()`,
  `have_access_to()`, `fs_rol`, `fs_rol_access` and the auth/session bridge are
  untouched.
- **No auth/session integration**.
- **No API exposure** (`src/Api/` attributes) — not trivially free and not needed.
- **No relation to `fs_rol`** or to the permission system.
- **No agent (`codagente`) coupling**; no warehouse/series coupling.
- **No group hierarchy / nesting** (flat groups only).
- **No multi-function per membership** unless Option C is chosen.

---

## Risks

1. **Forgetting `CORE_TABLE_FILES` / `CORE_MODEL_MAP`** — the bootstrap self-heal
   would silently skip the new tables (lazy creation would still cover it on first
   instantiation, but the bootstrap guarantee is lost). Must be part of the tasks.
2. **PostgreSQL index on `fs_user`** — `all_from_user` has no guaranteed index on
   PG. Low impact at admin scale; document and optionally add an explicit index
   migration later.
3. **Semantic overlap "grupo" vs "rol"** — mitigate with distinct table/class
   names (`fs_group` vs `fs_rol`), a distinct menu label ("Grupos" ≠ "Roles"), and
   explicit doc/comments that groups are organizational, roles are permissions.
4. **Plugin name collision** — legacy models are global classes; a third-party
   plugin could already define `fs_group`/`fs_group_user` or a `fs_groups` table.
   None exist in this checkout. Verify against installed plugins before apply and
   keep names core-owned.
5. **`admin_only` propagation on already-migrated installs** — rely on the class
   attribute; the one-shot `PAGE_ALLOWLIST` backfill will not re-run. Verify the
   new page's `fs_pages.admin_only` is TRUE after first visit.
6. **`tpvmod` roles (external)** — the flagged plugin is absent here and cannot be
   verified; coordinate semantics if it ships its own groups/roles.
7. **Cascade on user deletion** — FK `ON DELETE CASCADE` from
   `fs_groups_users.fs_user` removes memberships automatically, matching
   `fs_roles_users`. Confirm this is desired (vs blocking deletion).
8. **strict_tdd** — model logic must ship with tests (anonymous `fs_model`
   subclass + mocked `fs_db2`, per `tests/Base/FsRolAccessAdminOnlyTest.php`).

---

## Open product questions (resolve before proposal)

1. **Group code** — user-entered code PK (like `codrol`) or auto-generated? A
   human code is consistent but rename-unsafe; a serial PK needs editing support.
2. **Multiple functions per user in one group** — allowed? Yes → Option C; No →
   Option B (recommended).
3. **Minimum responsables** — may a group have zero responsables? (Assume yes.)
4. **UI placement** — dedicated `admin_groups`/`admin_group` (recommended) or a
   tab inside `admin_users`?
5. **Surfacing on the user profile** — should `admin_user` show the user's groups?
6. **Labels/i18n** — confirm entity label "Grupo"/"Grupos" and the English keys
   (`group`, `groups`, `manager`/`responsable`, `function`).
7. **Cascade on user deletion** — confirm memberships are deleted with the user.
8. **Future coupling** — will groups ever map to an agent (`codagente`) or a
   warehouse (`almacen`)? If yes, plan the FK now; if no, keep flat.

---

## Recommendation summary

- New core entity: tables `fs_groups` + `fs_groups_users`, models `fs_group` +
  `fs_group_user` in `model/`, XML in `model/table/`.
- Membership row carries a single **`funcion`** column (`'miembro'` default,
  `'responsable'` today) — Option B. Extensible to comercial/backoffice as values.
- Two `#[AdminOnly]` controllers (`admin_groups`, `admin_group`) + two AdminLTE
  templates + translations.
- Register the two tables in `CORE_TABLE_FILES` and `CORE_MODEL_MAP`.
- No permissions, no auth/API integration, no hierarchy.

## Ready for Proposal

**Yes.** The design space is bounded and one approach is clearly recommended. The
orchestrator should ask the user the **open product questions** above (most
importantly Q1 group code vs serial PK and Q2 single vs multiple functions per
membership) before writing the proposal, because they change the schema.
