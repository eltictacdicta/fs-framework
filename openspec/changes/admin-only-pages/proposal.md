# Proposal: admin-only-pages

- **Change**: `admin-only-pages` (CORE FSFramework change)
- **Artifact store**: openspec
- **Phase**: propose
- **Evidence base**: `openspec/changes/admin-only-pages/exploration.md` (verified `file:line`)

## Intent

Today **no page can be declared administrator-only**. The 4th `$admin` constructor parameter is dead (`base/fs_controller.php:191` is its only occurrence), `fs_pages` has no authority column, and access is purely role-driven: a stale or crafted `fs_rol_access` row grants a non-admin access to `admin_users` or `admin_rol` (`model/core/fs_user.php:332-351`). This change introduces an explicit, co-located declaration (`#[AdminOnly]`), persists the resolved boolean as a queryable mirror on `fs_pages`, and enforces the invariant at three layers — listing, saving, access — so admin-only pages are neither assignable nor reachable by non-admins.

## Scope

### In Scope

- New attribute `src/Attribute/AdminOnly.php` (`FSFramework\Attribute`, `#[\Attribute(\Attribute::TARGET_CLASS)]`), valid on legacy `fs_controller` and modern `src/Core/Base/Controller` subclasses.
- New `fs_pages` boolean column + `fs_page` model support (constructor, `__clone()`, `save()`, cached `all()`).
- Declaration applied to the 9 core pages in the confirmed scope.
- One-shot, idempotent migration: column adoption, backfill, stale-grant cleanup, cache invalidation.
- Enforcement in listing (`admin_rol` / `admin_users` / `admin_user`), saving (`fs_rol_access::save()`), and access (`fs_user::get_menu()` non-admin branch).
- Tests (strict TDD) and scoped documentation updates.

### Out of Scope

- Marking specific **plugin** controllers (`system_updater`, `business_data`, `tpvmod`). `plugins/*` are separate gitignored repos; each plugin adopts `#[AdminOnly]` in its own SDD. The core only ships the mechanism.
- Any change to `fs_rol_access::allow_delete` semantics beyond the admin-only guard.
- Removing or renaming `fs_rol_access` / `fs_access`.

### Non-Goals (fixed)

- **Reviving the 4th `$admin` constructor parameter is OUT OF SCOPE.** It silently turns 4 existing plugin controllers admin-only, including `plugins/tpvmod/controller/tpvmod_settings.php:43`, which is not named `admin_*`. Legacy `check_fs_page()` keeps its signature; the attribute is read from `get_class($this)` internally.
- Attribute detection is by **name string** (`ReflectionClass::getAttributes()` + `getName()`), never `newInstance()`, so legacy plugin controllers with unreliable namespaced autoloading stay safe.
- `admin_home` **stays accessible to everyone** (it is the default landing page, `base/fs_controller.php:657`).

## Capabilities

### New Capabilities

- `page-authorization`: declare, persist and enforce administrator-only pages across listing, role assignment and runtime access.

### Modified Capabilities

- None.

## Approach

**Declaration surface**: an attribute on the controller class. **Persistence**: the resolved boolean is mirrored on `fs_pages`, because `admin_rol` cannot see other controllers' attributes without instantiating them (`exploration.md` §1.5).

| Decision | Choice | Rationale |
|---|---|---|
| Column name | **`admin_only`** | Unambiguous in predicates (`WHERE admin_only = TRUE`) and joins; avoids confusion with `fs_users.admin`, which means "this user is an admin", not "this page is admin-only". |
| `getPageData()` contract | `admin_only` is an **optional** key; effective value is `attribute OR pageData['admin_only']` (OR-escalation) | Fail-closed and deterministic. The attribute can never be downgraded by an oversight in `getPageData()`. Neither present → `false` (BC preserved). |
| Legacy read | inside `check_fs_page()` from `get_class($this)`; persisted into the `fs_page` payload and compared in `mustUpdatePage()` | No signature change; declaration travels with the concrete controller. |
| Modern read | in `resolveOrCreatePage()`; persisted on both create and update | The existing atomic create/update path. |
| Plugin registration | **no `fs_plugin_manager` code change required** | Both paths instantiate the controller (`new $page_name()` at `:707`, `new $full_class()` at `:758`) and save `$controller->page`, so the flag is already persisted once the two construction paths write it. |
| Demo mode | `FS_DEMO` keeps its current all-pages branch (`fs_user.php:338`) | Deliberate, pre-existing showcase behavior; recorded as an acknowledged exception, not silently changed. |

## Enforcement Model

| Layer | Location | Behavior |
|---|---|---|
| **Listing** | `admin_rol::all_pages()` (`controller/admin_rol.php:57`), `admin_users::all_pages()` (`:168`), `admin_user::all_pages()` (`:129`) | **Always** exclude `admin_only` pages, for every actor. They are not role-grantable by definition, so showing them (even to an admin) is misleading and would produce refused saves. |
| **Saving** | `fs_rol_access::save()` (`model/fs_rol_access.php:68`) | **Refuse unconditionally** (`return false`) when the target page is `admin_only`. The invariant belongs to the data, not to the actor: models here are session-agnostic, and an "admin-facing grant" flow is meaningless because admins already see every page. This closes the direct writer `plugins/factura_pdf1/Services/LegacyRolePermissionsGateway.php:56-67`. Page resolved by a single indexed `fs_page->get()`. |
| **Access** | `fs_user::get_menu()` non-admin branch (`model/core/fs_user.php:340-347`) | Skip pages with `admin_only = true` regardless of `fs_rol_access`. Propagates to `have_access_to()` (`:390`), `select_default_page()` (`base/fs_controller.php:641`), the modern gate (`src/Core/Base/Controller.php:188`) and the user default-page check (`controller/admin_user.php:249`). Admins keep the full list. |

The role editor (`admin_rol::modify()`, `controller/admin_rol.php:112`) iterates `all_pages()`, so filtering the listing already makes a crafted `enabled[]=admin_users` a no-op; the model guard makes it a hard guarantee.

## Migration & Backfill

Trigger: a new `src/Core/Schema/AdminOnlyPagesMigration.php` (`FSFramework\Core\Schema`) invoked once from the same bootstrap sequence that already calls `fs_schema::selfHealCoreTables()` (`index.php:85`, `api.php:131`, `cron.php:53`), because `selfHealCoreTables()` is create-only and `system_updater` performs no core schema sync. The step is idempotent and gated by an `fs_var` flag written only after success.

1. `fs_model::forgetCheckedTables(['fs_pages'])` (`base/fs_model.php:444`) — bypass the 5400s `fs_checked_tables` cache.
2. Instantiate `new fs_page()` to trigger lazy column adoption via `check_table()` → `compare_columns()`.
3. Backfill with an **explicit allowlist** (no `LIKE 'admin_%'` prefix): `admin_users`, `admin_user`, `admin_rol`, `admin_info`, `admin_email`, `admin_system_branding`, `admin_stealth`, `admin_orden_menu`, `admin_agentes`. Deterministic; avoids the over-broad prefix risk and excludes `admin_home`.
4. `DELETE FROM fs_roles_access WHERE fs_page IN (SELECT name FROM fs_pages WHERE admin_only = TRUE);`
5. Delete the `m_fs_page_all` cache (raw SQL bypasses `fs_page::save()` invalidation).

`model/table/fs_roles_access.xml` already cascades on `fs_pages.name`; no FK change. Boolean default must be validated for both engines (`fs_mysql` / `fs_postgresql`).

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Incomplete migration window: pages never re-instantiated keep `admin_only = false` | Med | One-shot allowlist backfill + `forgetCheckedTables` + cache clear; access layer is the permanent net. Plugin pages close on plugin re-registration when they declare the attribute. |
| Stale `fs_rol_access` rows keep granting access | High (today) | Mandatory, not optional: access-layer filter + model guard + one-shot cleanup. |
| Silent BC if `$admin` were revived | — | Eliminated by the explicit non-goal (attribute-only). |
| Attribute silently ineffective if instantiated via autoload | Med | Match by name string only; never `newInstance()`. |
| `FS_DEMO` bypasses the access filter | Low | Acknowledged exception; demo mode is inherently read-mostly and not a production posture. |
| Boolean DDL default differs by engine | Low | Validate `compare_columns()` output on MySQL and PostgreSQL; column is `NOT NULL DEFAULT false`. |
| `tests/Controller/AdminAuthorityGuardsTest.php:24-28` docblock encodes the old assumption | Low | Revisit the docblock (and assertions) in the same change. |

## Rollback Plan

Revert the code commits. The persisted column is additive and harmless; with the attribute absent and enforcement code reverted, `admin_only` is ignored. Clear the `fs_var` migration flag and the `fs_checked_tables` / `m_fs_page_all` caches to force re-adoption on the next upgrade. Deleted stale grants for admin-only pages are not a functional loss (they were non-grantable by design); no automatic restore is attempted, and the column may be dropped manually if required.

## Plugin Impact & Backwards Compatibility

- **What a plugin author writes**: add `#[AdminOnly]` (imported from `FSFramework\Attribute`) to the controller class. Modern: `#[AdminOnly] class AdminAlmacenes extends \FSFramework\Core\Base\Controller`. Legacy: `#[\FSFramework\Attribute\AdminOnly] class admin_mi_modulo extends fs_controller`. Legacy authors may also add an explicit `require_once` for the attribute class if their bootstrap does not autoload namespaced classes.
- **Backwards compatible by default**: absent attribute / absent `admin_only` key → `false`; no existing controller changes behavior. The persisted column defaults to `false`.
- **Non-admin users** who previously held a grant to an admin-only page lose it on their next request (fresh `fs_user` per request) — intended, and announced here.
- Plugin pages named `admin_*` are **not** auto-flagged by the core allowlist; they become admin-only only when their controller declares the attribute.

## Documentation Deliverables (scoped only — not written in this phase)

Shared canon + synchronized derivatives, per `AGENTS.md` "Instruction Parity Across IDEs":

1. `AGENTS.md` — new subsection: the `#[AdminOnly]` public API and enforcement guarantees.
2. `.github/copilot-instructions.md` — concise parity summary.
3. `.cursor/rules/fs-framework-general.mdc` — derived baseline parity.
4. `.cursor/rules/fs-framework-plugins.mdc` — controller templates (line ~332) must show the declaration and remove the misleading `true, true` example.
5. `.cursor/rules/fs-framework-security.mdc` — add "admin-only pages are never role-grantable" to authorization guidance.

Skills (both `.opencode/skills` and `.cursor/skills` mirrors):

6. `fsframework-plugin-scaffold/SKILL.md` — legacy and modern controller templates.
7. `fsframework-security-review/SKILL.md` — authorization check for admin-only pages.
8. `fsframework-model-crud/SKILL.md` — only if the `AdminOnly` attribute / `fs_page` boolean-column pattern is documented there.

Optional: `docs/` guide if the `AGENTS.md` subsection exceeds a few paragraphs.

## Review Workload Forecast

| Area | Files | Approx. changed lines |
|---|---|---|
| New source (`AdminOnly.php`, `AdminOnlyPagesMigration.php`) | 2 | ~160 |
| Model + schema (`fs_page`, `fs_rol_access`, `fs_user`, XML) | 4 | ~60 |
| Construction paths (`fs_controller`, modern `Controller`) | 2 | ~35 |
| Role/user controllers + 9 core declarations + bootstrap | 13 | ~70 |
| Tests (attribute, migration, guard, access filter, docblock) | ~5 | ~400 |
| Documentation (canon + rules + skill mirrors) | ~8 | ~200 |
| **Total** | **~34** | **~925** |

`Decision needed before apply: Yes`
`Chained PRs recommended: Yes`
`400-line budget risk: High`

The session delivery strategy is `single-pr` with an **800-line** review budget; the forecast (~925 lines) exceeds it. Before apply, the owner must either accept `size:exception` for a single PR or split into chained work units: (1) attribute + model/schema + migration, (2) enforcement layers, (3) tests, (4) documentation. Documentation-only lines may be excluded from the authored-risk count if generated/mechanical.

## Dependencies

- Existing Symfony 7.4 / PHP 8.2+ baseline; no new Composer dependency.
- `fs_var` for the migration flag; `fs_model::forgetCheckedTables()` (`base/fs_model.php:444`).
- Follow-up (out of scope): each plugin repo adds `#[AdminOnly]` to its admin controllers in its own SDD.

## Success Criteria

- [ ] `fs_pages.admin_only` exists and is queryable without instantiating any controller.
- [ ] The 9 scoped core pages persist `admin_only = TRUE`; `admin_home` remains `FALSE`.
- [ ] A non-admin with a pre-existing grant to an admin-only page is denied access.
- [ ] `fs_rol_access::save()` returns `false` for an admin-only page (hard guarantee, incl. `factura_pdf1` gateway).
- [ ] Admin-only pages never appear in the role/user page matrices.
- [ ] Migration is idempotent and clears `fs_checked_tables` + `m_fs_page_all`.
- [ ] Full suite passes via `ddev exec php vendor/bin/phpunit` with new focused coverage.
- [ ] Documentation deliverables are updated with cross-IDE parity.

## Key Learnings

1. Page authority cannot be resolved in-memory because only the requested controller is instantiated; the declaration must be mirrored on `fs_pages` to be queryable by the role UI.
2. Reviving `$admin` would silently change behavior for 4 plugin controllers, including one not named `admin_*`, which is why the attribute is the only declaration surface.
3. Plugin registration instantiates controllers, so no `fs_plugin_manager` change is needed once both page-construction paths persist the flag.
4. `fs_schema::selfHealCoreTables()` never adds columns, so new core columns require an explicit migration plus `fs_model::forgetCheckedTables()` to bypass the 5400s cache.
