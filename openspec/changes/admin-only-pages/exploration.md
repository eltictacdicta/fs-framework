# Exploration: admin-only-pages

- **Change**: `admin-only-pages` (CORE FSFramework change)
- **Artifact store**: openspec
- **Phase**: explore
- **Status**: ready for proposal (see §12)
- **Scope constraint**: this change touches `base/`, `model/`, `src/` and `themes/`, so its SDD lives in the core `openspec/` tree. It MUST NOT be routed to a plugin SDD.

---

## 1. Problem and current state

There is **no way to declare a page as administrator-only**. Page visibility is entirely role-driven: any page registered in `fs_pages` can be granted to any role, and a role grant is sufficient for access.

### 1.1 The `$admin` constructor flag exists but is dead

`fs_controller::__construct()` accepts a 4th parameter literally named `$admin`, documented as obsolete:

- `base/fs_controller.php:187` — `@param boolean $admin OBSOLETO`
- `base/fs_controller.php:191` — `public function __construct($name = __CLASS__, $title = 'home', $folder = '', $admin = FALSE, $shmenu = TRUE, $important = FALSE)`
- The value is **never read**: the only occurrence of `$admin` in the file is the signature itself. The constructor immediately calls `$this->check_fs_page($name, $title, $folder, $shmenu, $important)` at `base/fs_controller.php:210`, dropping `$admin`.

So a plugin author who writes `parent::__construct(__CLASS__, 'T', 'admin', TRUE, TRUE)` gets **no** authority guarantee. This is already documented as a known hazard in `tests/Controller/AdminAuthorityGuardsTest.php:24-28`: because `$admin` is ignored, an `admin_*` page is reachable by any user whose role grants it, so the per-controller `$this->user->admin` checks are the only barrier.

### 1.2 Core `admin_*` controllers pass an inconsistent flag

| Controller | Call | Passes admin=TRUE? |
|---|---|---|
| `admin_users.php:34` | `parent::__construct(__CLASS__, 'Usuarios', 'admin', TRUE, TRUE)` | yes |
| `admin_user.php:38` | `parent::__construct(__CLASS__, 'Usuario', 'admin', TRUE, FALSE)` | yes |
| `admin_agentes.php:47` | `..., TRUE, TRUE` | yes |
| `admin_info.php:33` | `..., TRUE, TRUE` | yes |
| `admin_email.php:42` | `..., true, true` | yes |
| `admin_system_branding.php:45` | `..., true, true` | yes |
| `admin_rol.php:32` | `..., FALSE, FALSE` | **no** |
| `admin_orden_menu.php:30` | `..., FALSE, TRUE` | **no** |
| `admin_stealth.php:45` | `..., FALSE, TRUE` | **no** |
| `admin_home.php:96` | `parent::__construct(__CLASS__, 'Panel de control', 'admin')` | **no** (default) |

The flag is therefore not a reliable current declaration either way.

### 1.3 `fs_pages` has no authority column

- `model/table/fs_pages.xml` declares only `name, title, folder, version, show_on_menu, important, orden`.
- `model/fs_page.php:34-65` mirrors those columns; `fs_page::save()` (`:160-184`) writes them; `__clone()` (`:103-113`) copies them; `fs_page::all()` (`:201-220`) caches the list under `m_fs_page_all` (`:204`, `:216`).

### 1.4 Pages are persisted on controller instantiation

- Legacy: `fs_controller::check_fs_page()` (`base/fs_controller.php:820`) builds an `fs_page`, calls `get()` and then `createNewPage()` (`:847`) or `updateExistingPage()` (`:855`), gated by `mustUpdatePage()` (`:871`).
- Modern: `src/Core/Base/Controller::resolveOrCreatePage()` (`src/Core/Base/Controller.php:159`) fed by `getPageData()` (`:217`); it updates existing rows (`:164-172`) or inserts new ones (`:174-182`).
- Plugin registration: `fs_plugin_manager::enableLegacyControllers()` (`base/fs_plugin_manager.php:690`) instantiates each legacy controller inside `setRegisteringPluginPages(true)` (`:696`); `enableModernControllers()` / `registerModernControllerPage()` (`:718`, `:729`) do the same for `Controller/` and persist through `saveControllerPage()` (`:671`).

### 1.5 The visibility constraint

When `admin_rol` loads, only that controller is instantiated. No other admin controller runs, so an in-memory registry cannot know that `admin_users` is admin-only. The source of truth MUST be queryable **without instantiating the controllers** — i.e. persisted on `fs_pages`, or a complete declarative list. This constraint drives the approach choice in §2.

### 1.6 Current enforcement points (all listing-only today)

- Listing: `admin_rol::all_pages()` (`controller/admin_rol.php:57-86`) iterates `$this->menu`; `admin_users::all_pages()` (`controller/admin_users.php:168-227`); `admin_user::all_pages()` (`controller/admin_user.php:129-155`). The Twig template just renders `fsc.all_pages()` (`themes/AdminLTE/view/admin_rol.html.twig:133`).
- Saving: `admin_rol::modify()` (`controller/admin_rol.php:112`) loops over `all_pages()` and creates/updates/deletes `fs_rol_access` per page (`:137`, `:148`, `:150`, `:153`). Because it only iterates pages returned by `all_pages()`, a crafted `enabled[]=admin_users` is a no-op **today** — but only because nothing filters; if `admin_users` is in the list, a crafted POST succeeds.
- Access: legacy `fs_controller` gates with `$this->user->have_access_to($this->page->name)` (`base/fs_controller.php:253`), else renders `access_denied` (`:271`). Modern `Controller::enforcePageAccessOrExit()` (`src/Core/Base/Controller.php:188-196`) does the same. `fs_user::have_access_to()` (`model/core/fs_user.php:390`) iterates `get_menu()` (`:332`), which for admins returns **all** pages (`:338-339`) and for non-admins returns `fs_page::all()` filtered by role-allowed names (`:340-347`). **A stale `fs_rol_access` row for `admin_users` therefore grants a non-admin access** — there is no admin-only check anywhere.

### 1.7 Direct role-access writers (bypass paths)

`fs_rol_access::save()` (`model/fs_rol_access.php:68-82`) is called directly by at least one plugin: `plugins/factura_pdf1/Services/LegacyRolePermissionsGateway.php:56-67` (`grantPageAccess`). Filtering the core UI alone does not close this path; a model-level guard does.

---

## 2. Approaches for DECLARING an admin-only page

All approaches must cover **both registration paths** (legacy `fs_controller` and modern `src/Core/Base/Controller`) and must persist a queryable signal for §1.5.

### Approach A — Revive the `$admin` constructor parameter and persist it on `fs_pages`

- **How**:
  - `fs_controller::__construct()` stops discarding `$admin` and passes it through `check_fs_page()` (`base/fs_controller.php:210`, `:820`) into the `fs_page` payload (`:822-832`) and into `mustUpdatePage()` (`:871-877`).
  - `fs_page` gains an `admin` property, persists it in `save()` (`model/fs_page.php:160-184`), reads it in the constructor (`:70-95`), copies it in `__clone()` (`:103-113`), and `model/table/fs_pages.xml` gains an `admin` boolean column defaulting to `false`.
  - Modern path: `getPageData()` (`src/Core/Base/Controller.php:217`) gains an optional `admin` key and `resolveOrCreatePage()` (`:159`) persists it on create/update.
- **Pros**:
  - Zero new runtime parsing: the flag is already passed by 6 core controllers and 4 plugin controllers.
  - One queryable source of truth in `fs_pages`, satisfying §1.5 directly.
  - Backwards compatible by default (`FALSE`).
- **Cons / risks**:
  - The parameter is **positional and misleading**; `$admin` sits between `$folder` and `$shmenu`. Reviving it silently changes behaviour for every existing caller that already passed `TRUE` (see §5 BC).
  - Modern controllers do not use the constructor flag at all, so A alone is incomplete unless `getPageData()` is also extended — meaning two declaration surfaces.
  - Does not express intent at the class level; a reader of `admin_users.php` must count arguments.
- **Effort**: Low–Medium.

### Approach B — Explicit declarative registry/list

- **How**: maintain a canonical list of admin-only page names (e.g. a `model/admin_only_pages.php` array, or a `fs_page`/`fs_controller` static list) that is merged during page creation and used by listing/access; optionally extensible by plugins via `FSEventDispatcher`.
- **Pros**:
  - Fully queryable without instantiating any controller (best fit for §1.5).
  - Trivial to unit-test (pure data).
- **Cons**:
  - Creates a **second source of truth** next to `fs_pages`; drift is inevitable.
  - Declaring a plugin page requires editing the core list or an event contract the core does not have yet.
  - Seeding existing rows still requires a backfill, and the list does not travel with the plugin's code the way a constructor/attribute does.
- **Effort**: Low, but high long-term maintenance cost.

### Approach C — PHP 8 attribute on the controller class

- **How**: add `src/Attribute/AdminOnly.php` (namespace `FSFramework\Attribute`, consistent with `src/Attribute/FSRoute.php`), e.g. `#[\Attribute(\Attribute::TARGET_CLASS)]`. The core reads it by reflection:
  - Legacy: in `check_fs_page()` (`base/fs_controller.php:820`), reflect on `get_class($this)` (the concrete controller, not `$name`) and persist the result on `fs_pages` exactly as in A.
  - Modern: read the attribute in `resolveOrCreatePage()` / `getPageData()` handling (`src/Core/Base/Controller.php:159`, `:217`) and persist it.
  - Plugin registration paths (`base/fs_plugin_manager.php:690`, `:729`) already instantiate the controller, so the attribute is observable at registration time; the persisted `fs_pages` row remains the query source for the UI.
- **Pros**:
  - Single, explicit, co-located declaration for **both** legacy and modern controllers.
  - No positional-argument trap; opt-in by construction (no existing controller becomes admin-only by accident).
  - Idiomatic in this codebase (`FSRoute`, `ApiResource` are already used).
- **Cons / risks**:
  - Still must be **persisted** on `fs_pages` to satisfy §1.5; the attribute alone is not queryable from `admin_rol`.
  - Match by attribute **name string** rather than `newInstance()` to avoid autoloading the namespaced attribute inside legacy plugin controllers (see §5).
  - A page flagged only by attribute that has never been instantiated after the change keeps a stale `fs_pages` value until registration/backfill runs.
- **Effort**: Medium.

### Approach comparison

| Approach | Queryable w/o controller | Covers legacy | Covers modern | BC risk | Duplication | Effort |
|---|---|---|---|---|---|---|
| A — revive `$admin` + column | Yes (column) | Yes | Only via `getPageData()` extension | **High** (silent flag activation) | Two surfaces (ctor + pageData) | Low–Med |
| B — registry list | Yes (list) | Yes | Yes | Low | **High** (list vs DB) | Low |
| C — attribute + column | Yes (column) | Yes | Yes | **Low** (opt-in) | One surface + persisted mirror | Medium |

### Recommendation

**Adopt C as the declaration surface, with A's persistence column as the queryable mirror.** Concretely: `#[AdminOnly]` on the controller class is the author-facing API (works for legacy and modern); the resolved boolean is persisted as `fs_pages.admin` so listing, saving and access can query it without instantiating controllers. This avoids A's silent activation of the existing `TRUE` flags and B's dual source of truth, while satisfying the visibility constraint.

A hybrid detail to decide in design/proposal: whether to **also** revive the `$admin` constructor flag as a backward-compatible alias when the attribute is absent. If done, it must be opt-in and documented as a compatibility path, and the 4 existing `TRUE,TRUE` plugin controllers must be reviewed.

---

## 3. Enforcement points required

### 3.1 Listing (hide admin-only pages from role assignment)

| Point | Location | Change |
|---|---|---|
| Role page list | `controller/admin_rol.php:57` `all_pages()` | Skip pages whose persisted admin flag is true unless `$this->user->admin`. |
| User→page matrix | `controller/admin_users.php:168` `all_pages()` | Same filter. |
| User edit page matrix | `controller/admin_user.php:129` `all_pages()` | Same filter. |
| Template | `themes/AdminLTE/view/admin_rol.html.twig:133` | No change: it renders `fsc.all_pages()`; filtering belongs in the controller. |

### 3.2 Saving (cannot be assigned, even by crafted POST)

| Point | Location | Change |
|---|---|---|
| Role modify loop | `controller/admin_rol.php:112-155` | Because it iterates `all_pages()`, filtering §3.1 already makes a crafted `enabled[]` a no-op. Add an explicit guard/cleanup so stale rows for admin-only pages are removed on save. |
| Model hard guarantee | `model/fs_rol_access.php:68` `save()` | Reject (return `false`) when the target page is admin-only and the actor is not admin, or unconditionally refuse admin-only pages. This closes the `plugins/factura_pdf1` direct-save path (`LegacyRolePermissionsGateway.php:56-67`). |
| Role/user assignment | `controller/admin_users.php:63` `add_user()`, `controller/admin_user.php:291` `aplicar_roles()` | These assign roles, not pages; no page-level change needed. |
| CSRF | `controller/admin_rol.php` POST path | Already covered by the framework CSRF flow (`pre_private_core` / `validateCsrf`); confirm in design that the guard runs on the same request. |

### 3.3 Access (defense in depth)

| Point | Location | Change |
|---|---|---|
| Menu/access source | `model/core/fs_user.php:332` `get_menu()` | In the non-admin branch (`:340-347`), skip pages whose admin flag is true, regardless of `fs_rol_access`. Admins keep the full list (`:338-339`). |
| Role-allowed map | `model/core/fs_user.php:359` `get_role_allowed_pages()` | Optional hardening: exclude admin-only pages from the allowed map so every consumer inherits the rule. |
| Legacy gate | `base/fs_controller.php:253` | No direct change needed; it uses `have_access_to()` → `get_menu()`, so the §3.3 fix propagates. |
| Modern gate | `src/Core/Base/Controller.php:188` `enforcePageAccessOrExit()` | Same propagation via `have_access_to()`. |
| Default page | `base/fs_controller.php:641` `select_default_page()` | Uses `$this->menu`, already filtered for non-admins. |
| User default page | `controller/admin_user.php:249` | Checks `have_access_to($udpage)`; after the fix a non-admin cannot select an admin-only default. |
| Cache | `model/fs_page.php:204`/`:216` `m_fs_page_all`; `base/fs_model.php:116`/`:132-135` `fs_checked_tables` (5400s) | Must be invalidated during migration/backfill so the new column and values are visible. |

---

## 4. Migration and backfill

### 4.1 Adding the column

- Add `<columna><nombre>admin</nombre><tipo>boolean</tipo><nulo>NO</nulo><defecto>false</defecto></columna>` to `model/table/fs_pages.xml`.
- Runtime column adoption happens lazily through `fs_model::check_table()` → `buildExistingTableSql()` → `db->compare_columns()` (`base/fs_model.php:277`, `:338`, `:358`). `fs_schema::syncColumns()` (`base/fs_schema.php:711`) offers the same capability from the XML definitions.
- **Caveat**: `fs_model::$checked_tables` is cached for 5400s (`base/fs_model.php:116`, `:134`), so `check_table()` may be skipped until the cache expires. The migration MUST call `fs_model::forgetCheckedTables(['fs_pages'])` (`base/fs_model.php:444`) — the same mechanism `src/Core/Plugin/PluginSchemaSynchronizer.php:147` already uses — and clear `m_fs_page_all`.
- **Gap to resolve**: `fs_schema::selfHealCoreTables()` (`base/fs_schema.php:809`, invoked at `index.php:85`, `api.php:131`, `cron.php:53`) only **creates missing tables**; `healCoreTable()` never adds columns to an existing table. There is no core post-update schema step in `system_updater` (`plugins/system_updater/lib/core_updater.php` does not call `fs_schema`). The column-add therefore depends on the lazy `fs_model::check_table()` path plus the explicit `forgetCheckedTables` call. Confirm and pin this in design.

### 4.2 Backfilling existing rows

Existing `fs_pages` rows get `admin = false` by default. How the known admin pages acquire `true`:

1. **Declared pages**: any controller that declares the flag (attribute or revived param) refreshes its row when it is next instantiated — `check_fs_page()` for legacy (`base/fs_controller.php:820`) and `resolveOrCreatePage()` for modern (`src/Core/Base/Controller.php:159`). Plugin pages refresh on plugin activation/update via `forceEnable`/`applyPluginSchemaUpdates` (`base/fs_plugin_manager.php:690`, `:729`).
2. **Never-visited pages**: a page is only refreshed when its controller actually runs or its plugin is re-registered. This leaves a window where a stale row is still `false`. Options (decide in proposal/design):
   - (a) one-shot migration `UPDATE fs_pages SET admin = TRUE WHERE name LIKE 'admin_%'` as a pragmatic backfill; and/or
   - (b) a one-shot migration that instantiates/bootstraps known core controllers and refreshes their rows; and/or
   - (c) ship call-site updates so every core admin page declares the flag, and rely on lazy refresh + a name-prefix fallback.
   The `LIKE 'admin_%'` heuristic would also catch plugin pages named `admin_*`, which is likely desired but must be called out (see §7).
3. **Core call-site consistency**: `admin_rol`, `admin_orden_menu`, `admin_stealth` (and possibly `admin_home`) currently do not declare admin-only; they should be updated in the same change so the declaration matches intent.

### 4.3 Cleaning existing grants

A one-shot cleanup is needed so pre-existing/stale rows cannot linger:
`DELETE FROM fs_roles_access WHERE fs_page IN (SELECT name FROM fs_pages WHERE admin = TRUE);`
This is redundant if §3.3 access filtering is in place, but it removes confusing state and is cheap.

### 4.4 FK interactions

`model/table/fs_roles_access.xml` already declares `FOREIGN KEY (fs_page) REFERENCES fs_pages (name) ON DELETE CASCADE ON UPDATE CASCADE`, and `fs_roles_users` cascades on `fs_roles`. No FK change is required; deleting/flagging pages cascades cleanly.

---

## 5. Plugin impact and backwards compatibility

### 5.1 What a plugin author would write

- **Modern (recommended)**: add `#[AdminOnly]` from `FSFramework\Attribute` to the controller class and, if needed, keep `getPageData()` as-is:
  ```php
  #[AdminOnly]
  class AdminAlmacenes extends \FSFramework\Core\Base\Controller { ... }
  ```
- **Legacy**: add the same class-level attribute:
  ```php
  #[\FSFramework\Attribute\AdminOnly]
  class admin_mi_modulo extends fs_controller { ... }
  ```
- If the hybrid keeps the constructor flag, legacy authors may alternatively pass `TRUE` as the 4th argument, but the attribute should be presented as the canonical contract.

### 5.2 Backwards-compatibility concerns (ordered by severity)

1. **Reviving `$admin` is a silent behaviour change.** 4 plugin controllers already pass `TRUE` as the 4th argument: `plugins/business_data/controller/admin_empresa.php:81`, `plugins/system_updater/controller/admin_plugin_store.php:102`, `plugins/system_updater/controller/admin_updater.php:108`, and `plugins/tpvmod/controller/tpvmod_settings.php:43`. The last one is **not** named `admin_*`, so it would become admin-only purely because of the parameter. The scaffold skill template (`​.opencode/skills/fsframework-plugin-scaffold/SKILL.md:338`) and the Cursor rule (`.cursor/rules/fs-framework-plugins.mdc:332`) currently show `parent::__construct(__CLASS__, 'Mi Módulo', 'admin', true, true)`; note the Cursor rule already uses `false, true`. Any plugin that followed the skill's legacy template would flip to admin-only on upgrade. **This is the strongest argument for the attribute (Approach C) over A.**
2. **Attribute autoloading in legacy controllers.** Legacy plugin controllers are documented as unreliable for autoloading namespaced classes (`​.opencode/skills/fsframework-plugin-scaffold/SKILL.md` "Plugin Controller Requires (HARD RULE)"). The core reader should therefore match the attribute by its **name string** (via `ReflectionClass::getAttributes()` + `getName()`), not by `newInstance()`, unless legacy authors add an explicit `require_once` of the attribute class.
3. **Stale rows and access denial.** Once §3.3 is active, a non-admin who previously had access to an admin-only page loses it on the next request (fresh `fs_user` per request, per `tests/Base/AuthorizationFreshnessTest.php:112-125`). This is the intended behaviour but should be announced in the proposal.
4. **`admin_home` semantics.** `admin_home` is the default landing page for every user (`base/fs_controller.php:657`). Marking it admin-only would break non-admin landing; it should most likely stay accessible. Design must decide per page rather than blanket `admin_*`.
5. **Plugin-named pages with `admin_` prefix.** The prefix heuristic in §4.2(b) would flag them; the declaration-based approach would not. Prefer declaration, use the heuristic only as a transition aid.

---

## 6. Documentation deliverables (list only — do NOT write yet)

Shared canon and its synchronized derivatives (per `AGENTS.md` "Instruction Parity Across IDEs" and the `fsframework-instruction-sync` skill):

1. `AGENTS.md` — new subsection documenting the admin-only page public API (core + plugin), the attribute, and the enforcement guarantees. Owns the shared baseline.
2. `.github/copilot-instructions.md` — concise parity summary of the new rule.
3. `.cursor/rules/fs-framework-general.mdc` — always-on derived baseline parity.
4. `.cursor/rules/fs-framework-plugins.mdc` — topic owner for plugin controller conventions; update the controller example at line 332 and document the attribute for legacy and modern controllers.
5. `.cursor/rules/fs-framework-security.mdc` — topic owner for the security baseline; add "admin-only pages must not be assignable to roles" to the authorization guidance.

Skills (both mirrors, keep them in sync):

6. `.opencode/skills/fsframework-plugin-scaffold/SKILL.md` and `.cursor/skills/fsframework-plugin-scaffold/SKILL.md` — Step 6 controller templates (legacy and modern) must show the admin-only declaration and remove/qualify the misleading `true, true` example.
7. `.opencode/skills/fsframework-security-review/SKILL.md` and `.cursor/skills/fsframework-security-review/SKILL.md` — add an authority/authorization check covering admin-only pages and role assignment.
8. `.opencode/skills/fsframework-model-crud/SKILL.md` and `.cursor/skills/fsframework-model-crud/SKILL.md` — only if a new `AdminOnly` attribute class or `fs_page` column pattern is documented there.

Possible additional doc (decide in proposal):

9. `docs/` — a short "Admin-only pages" guide if the AGENTS.md subsection grows beyond a few paragraphs.

No documentation content is written in this phase.

---

## 7. Open questions, risks, and unverified items

### Open questions

1. **Attribute vs revived parameter vs both**: should the revived `$admin` parameter be supported as a compatibility alias, or should it stay dead and require the attribute? (Recommendation: attribute only for v1; consider the alias in a later change.)
2. **Column name**: `admin`, `admin_only`, or `is_admin`? `fs_pages.admin` is the natural counterpart to `fs_users.admin`, but an explicit `admin_only` reads better in queries. Decide in proposal/design.
3. **Scope of the core backfill**: which core pages become admin-only — only the ones already passing `TRUE`, or all `admin_*` controllers including `admin_rol`/`admin_orden_menu`/`admin_stealth`? Should `admin_home` stay open?
4. **Model-level guard semantics**: does `fs_rol_access::save()` refuse admin-only pages unconditionally, or only when the actor is not an admin? Refusing unconditionally is simpler and safer, but would also block a future admin-facing "grant admin page" flow and the `factura_pdf1` gateway.
5. **Migration trigger**: is `fs_model::forgetCheckedTables(['fs_pages'])` callable from a recognizable core update hook, or do we need a small one-shot migration in the core update path? `system_updater` currently performs no core schema sync.
6. **Modern `getPageData()` contract**: is `admin` a new supported key, or does the attribute take precedence when both are present? Need a single deterministic rule.

### Risks

1. **Silent activation BC** if the `$admin` parameter is revived (§5.2.1) — 4 known plugin controllers, plus any plugin built from the scaffold skill template.
2. **Incomplete migration window**: pages never re-registered after upgrade may keep `admin = false` until visited; combined with `fs_checked_tables` (5400s) and `m_fs_page_all` caches, there is a period where enforcement is partial.
3. **Stale `fs_rol_access` rows** from before the change continue to be granted by any code that bypasses role filtering; the access-path fix (§3.3) and/or the model guard (§3.2) are mandatory, not optional.
4. **Legacy attribute autoloading** in plugin controllers could make an admin-only marker silently ineffective if the reflection read instantiates the attribute class.
5. **Over-broad prefix heuristic** (§4.2(b)) could mark unrelated plugin pages admin-only.
6. **Tests today encode the old assumption** — `tests/Controller/AdminAuthorityGuardsTest.php:24-28` explicitly documents that `$admin` is ignored and admin pages are role-grantable. That test's docblock (not necessarily its assertions) becomes stale and should be revisited.

### Unverified / needs design confirmation

- Whether `fs_schema::selfHealCoreTables()` is ever extended to add columns; today it is create-only (`base/fs_schema.php:809-861`). Verified by reading; the migration strategy in §4.1 depends on this staying true.
- The exact count of live `fs_pages` rows and stale `fs_roles_access` rows in a real installation was not queried (no DB access in this phase).
- Whether any additional core code path writes `fs_pages` outside the two registration paths. The greps found only `base/fs_controller.php`, `src/Core/Base/Controller.php`, and `base/fs_plugin_manager.php` (`saveControllerPage()` at `:671`).
- Whether the `FS2025` modern controllers are all routed through `resolveOrCreatePage()` for persistence; the files inspected (`plugins/catalogo_core/Controller/AdminAlmacenes.php:46`) use `getPageData()` and are handled there.
- Exact DB engine in use (`FS_DB_TYPE`); `compare_columns()` is engine-specific (`fs_postgresql.php:93`, `fs_mysql.php`), so the boolean default DDL should be validated for both engines.

---

## 8. Affected areas

- `base/fs_controller.php` — stop dropping `$admin`; read attribute in `check_fs_page()`; extend `mustUpdatePage()`/page payload.
- `src/Core/Base/Controller.php` — persist the flag from `getPageData()`/attribute in `resolveOrCreatePage()`; keep `enforcePageAccessOrExit()` correct.
- `model/fs_page.php` — new property, constructor, `save()`, `__clone()`.
- `model/table/fs_pages.xml` — new boolean column.
- `model/core/fs_user.php` — filter admin-only pages in `get_menu()` (and optionally `get_role_allowed_pages()`).
- `model/fs_rol_access.php` — model-level assignment guard.
- `controller/admin_rol.php` — listing filter + stale-grant cleanup.
- `controller/admin_users.php` — listing filter.
- `controller/admin_user.php` — listing filter.
- `controller/admin_orden_menu.php`, `controller/admin_stealth.php`, `controller/admin_rol.php` (and possibly `admin_home.php`) — declare the flag consistently.
- `src/Attribute/AdminOnly.php` — new attribute (if Approach C).
- `base/fs_plugin_manager.php` — ensure registration persists the flag for legacy and modern plugin controllers (likely no code change if the two registration paths handle it).
- `themes/AdminLTE/view/admin_rol.html.twig` — no change expected (controller filters).
- Tests: `tests/Controller/`, `tests/Base/AuthorizationFreshnessTest.php`, and new coverage for `fs_page`/`fs_user`/`fs_rol_access`.

---

## 9. Ready for proposal

**Yes.** The problem, both registration paths, all enforcement points, the migration caveats and the BC trap are all evidenced with `file:line`. The proposal phase should decide:

1. Declaration surface (recommend `#[AdminOnly]` + persisted `fs_pages` column).
2. Column name and `getPageData()` contract.
3. Core page scope (which `admin_*` pages, and whether `admin_home` stays open).
4. Migration/backfill trigger and stale-grant cleanup.
5. `fs_rol_access::save()` guard semantics.

The orchestrator should tell the user that the main risk to settle before implementation is the backwards-compatibility of reviving the `$admin` parameter, and that the attribute path avoids it.

## Key Learnings

1. `fs_controller`'s 4th `$admin` constructor parameter is dead (only occurrence is the signature at `base/fs_controller.php:191`), so `admin_*` pages are currently grantable to any role.
2. Page authority cannot be enforced from an in-memory registry because only the requested controller is instantiated; the flag must be persisted on `fs_pages` to be queryable by the role UI.
3. Reviving the `$admin` parameter is a silent breaking change: 4 plugin controllers already pass `TRUE` there, and the scaffold skill template still shows `true, true`.
4. A stale `fs_rol_access` row grants a non-admin access because `fs_user::get_menu()` (`model/core/fs_user.php:332`) has no admin-only filter; revocation must be enforced at the access path or model level.
5. `fs_schema::selfHealCoreTables()` only creates missing tables and never adds columns; new core columns rely on the lazy `fs_model::check_table()` path plus `forgetCheckedTables`.
