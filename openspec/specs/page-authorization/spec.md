# page-authorization Specification

## Purpose

Declare, persist and enforce administrator-only pages across listing, role
assignment and runtime access. A class-level `#[AdminOnly]` attribute on a
controller is the only declaration surface; its resolved boolean is mirrored on
`fs_pages.admin_only` so it is queryable without instantiating controllers; a
one-shot idempotent migration adopts the column, backfills the core allowlist
and purges stale grants. An absent declaration or value resolves to `false`
(backwards compatible).

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| PA-01 | Declaration via the `#[AdminOnly]` class attribute | MUST |
| PA-02 | Persisted `admin_only` mirror on `fs_pages` | MUST |
| PA-03 | Effective value rule (OR-escalation, fail-closed) | MUST |
| PA-04 | Listing excludes admin-only pages for every actor | MUST |
| PA-05 | Saving refuses admin-only grants unconditionally | MUST |
| PA-06 | Access enforcement at the menu source | MUST |
| PA-07 | Core page scope | MUST |
| PA-08 | Idempotent one-shot migration | MUST |
| PA-09 | `FS_DEMO` exception is acknowledged | MUST |
| PA-10 | Backwards compatibility | MUST |

### Requirement: PA-01 — Declaration via the `#[AdminOnly]` class attribute

The system MUST provide `FSFramework\Attribute\AdminOnly` at `src/Attribute/AdminOnly.php` as `#[\Attribute(\Attribute::TARGET_CLASS)]`, valid on legacy `fs_controller` and modern `FSFramework\Core\Base\Controller` subclasses. Detection MUST use the attribute name string (`ReflectionClass::getAttributes()` + `getName()`) and MUST NOT call `newInstance()`. Reviving the 4th `$admin` constructor parameter is a non-goal and MUST NOT be a declaration source.

#### Scenario: Both controller kinds resolve the declaration

- GIVEN a legacy controller and a modern controller each annotated `#[AdminOnly]`
- WHEN their class declarations are resolved
- THEN both resolve to `true`

#### Scenario: Detection never instantiates the attribute

- GIVEN a legacy controller whose attribute class is not autoloadable
- WHEN the declaration is read
- THEN resolution succeeds by name string without an autoload error

#### Scenario: Constructor flag is not a declaration

- GIVEN a controller passing `TRUE` as the 4th `$admin` argument with no attribute
- WHEN its page is persisted
- THEN the persisted `admin_only` is `false`

### Requirement: PA-02 — Persisted `admin_only` mirror on `fs_pages`

`model/table/fs_pages.xml` MUST declare an `admin_only` boolean column (`NOT NULL DEFAULT false`). `fs_page` MUST read it in the constructor, persist it in `save()`, copy it in `__clone()`, and expose it in the cached `all()` list. It MUST be queryable without instantiating any controller.

#### Scenario: Default and round-trip

- GIVEN an `fs_page` persisted without a value
- WHEN it is loaded
- THEN `admin_only` is `false`
- AND a page saved with `admin_only = true` re-reads as `true`

#### Scenario: Clone copies the flag

- GIVEN an `fs_page` with `admin_only = true`
- WHEN cloned
- THEN the clone has `admin_only = true`

#### Scenario: `all()` exposes the flag without controllers

- GIVEN `fs_pages` mixes admin-only and ordinary rows
- WHEN `fs_page::all()` is called with no controller instantiated
- THEN each item exposes its persisted `admin_only` value

### Requirement: PA-03 — Effective value rule (OR-escalation, fail-closed)

The resolved value MUST be `attribute OR getPageData()['admin_only']`. Either source `true` MUST persist `true`; both absent MUST persist `false`; the attribute MUST NOT be downgraded by a `false` key.

#### Scenario: Attribute escalates over false or absent page data

- GIVEN the attribute is present and `getPageData()` omits `admin_only` or sets it `false`
- WHEN the page is persisted
- THEN `admin_only` is `true`

#### Scenario: Page data alone escalates

- GIVEN the attribute is absent and `getPageData()['admin_only']` is `true`
- WHEN the page is persisted
- THEN `admin_only` is `true`

#### Scenario: Both absent stays false

- GIVEN the attribute is absent and `getPageData()` omits `admin_only`
- WHEN the page is persisted
- THEN `admin_only` is `false`

### Requirement: PA-04 — Listing excludes admin-only pages for every actor

`admin_rol::all_pages()`, `admin_users::all_pages()` and `admin_user::all_pages()` MUST exclude `admin_only` pages always, including for administrators; ordinary pages MUST remain.

#### Scenario: All three matrices exclude them, even for an admin

- GIVEN an admin-only page and an ordinary page exist
- WHEN each `all_pages()` runs, including for an administrator actor
- THEN the admin-only page is absent from all three results
- AND the ordinary page is present

### Requirement: PA-05 — Saving refuses admin-only grants unconditionally

`fs_rol_access::save()` MUST return `false` and persist nothing when the target page is `admin_only`, regardless of actor, including the direct caller `plugins/factura_pdf1/Services/LegacyRolePermissionsGateway.php`. Non-admin-only saves MUST be unaffected.

#### Scenario: Guard refuses and blocks the direct writer

- GIVEN an `fs_rol_access` targeting an admin-only page
- WHEN `save()` is called by any actor, including an admin and the factura_pdf1 gateway
- THEN it returns `false`
- AND no `fs_roles_access` row is created or updated

#### Scenario: Ordinary pages still save

- GIVEN an `fs_rol_access` targeting a non-admin-only page
- WHEN `save()` is called
- THEN the grant persists normally

### Requirement: PA-06 — Access enforcement at the menu source

In `fs_user::get_menu()`, the non-admin branch MUST skip `admin_only` pages regardless of `fs_rol_access`. Administrators MUST keep the full list. The rule MUST propagate to `have_access_to()`, `select_default_page()`, `Controller::enforcePageAccessOrExit()` and the `admin_user` default-page check.

#### Scenario: Stale grant is denied on the next request

- GIVEN a non-admin has a pre-existing `fs_roles_access` row for an admin-only page
- WHEN a fresh `fs_user` builds its menu
- THEN the page is excluded and `have_access_to()` returns `false`

#### Scenario: Admin keeps the full list

- GIVEN the acting user is an administrator
- WHEN `get_menu()` is built
- THEN admin-only pages are present

#### Scenario: Propagation to default-page selection

- GIVEN a non-admin whose stored default page is admin-only
- WHEN `select_default_page()` runs
- THEN the page is not selected and the modern gate denies it

### Requirement: PA-07 — Core page scope

The 9 core pages `admin_users`, `admin_user`, `admin_rol`, `admin_info`, `admin_email`, `admin_system_branding`, `admin_stealth`, `admin_orden_menu` and `admin_agentes` MUST declare admin-only and persist `admin_only = true`. `admin_home` MUST stay accessible (`false`).

#### Scenario: Scoped pages true, home open

- GIVEN each scoped core controller is registered
- WHEN its `fs_pages` row is written
- THEN all nine persist `admin_only = true`
- AND `admin_home` persists `false`

### Requirement: PA-08 — Idempotent one-shot migration

`AdminOnlyPagesMigration` (`FSFramework\Core\Schema`) MUST run from the bootstrap sequence that calls `fs_schema::selfHealCoreTables()` and, on success: (a) call `fs_model::forgetCheckedTables(['fs_pages'])`; (b) trigger lazy column adoption; (c) backfill the explicit 9-name allowlist (no `LIKE 'admin_%'`); (d) delete stale `fs_roles_access` rows for admin-only pages; (e) clear `m_fs_page_all`. It MUST write an `fs_var` success flag only after all steps succeed and MUST be a no-op once set.

#### Scenario: First run performs all steps and records success

- GIVEN the migration has never run
- WHEN the bootstrap invokes it successfully
- THEN the column is adopted, the 9 names backfilled, stale grants deleted, caches cleared
- AND the `fs_var` flag is written

#### Scenario: Subsequent run is a no-op; failure retries

- GIVEN the `fs_var` flag is present
- WHEN the migration runs again
- THEN nothing is repeated
- AND if a step throws instead, the flag stays absent and the next run retries

#### Scenario: Backfill uses the allowlist only

- GIVEN a plugin page named `admin_custom` is not in the allowlist
- WHEN the migration backfills
- THEN `admin_custom` is not set to `admin_only = true`

### Requirement: PA-09 — `FS_DEMO` exception is acknowledged

With `FS_DEMO` enabled the existing all-pages branch in `fs_user::get_menu()` MUST be preserved; this deliberate exception MUST NOT be implemented away. With `FS_DEMO` disabled, PA-06 applies.

#### Scenario: Demo keeps all pages, production filters

- GIVEN `FS_DEMO` is enabled
- WHEN a non-admin menu is built
- THEN admin-only pages remain present as before
- AND with `FS_DEMO` disabled they are excluded

### Requirement: PA-10 — Backwards compatibility

Absent attribute and absent `admin_only` value MUST resolve to `false`. No existing controller, plugin page or role grant MUST change behaviour unless it declares admin-only or is one of the 9 scoped core pages.

#### Scenario: Undeclared controller keeps today's behaviour

- GIVEN an existing controller with no attribute and no `admin_only` key, and a legacy row without a value
- WHEN its page is registered and access is evaluated
- THEN its effective `admin_only` is `false` and it stays governed solely by role grants
