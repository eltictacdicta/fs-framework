# Tasks: admin-only-pages

## Review Workload Forecast

**~925 lines / ~34 files exceeds the 800-line single-PR budget. Accept `size:exception` or split per the units below.**

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: size-exception
400-line budget risk: High

### Work Units

| Unit | PR | Focused test command | Runtime harness | Rollback boundary |
|------|----|----------------------|-----------------|-------------------|
| 1 Attribute + XML + `fs_page` + migration + bootstrap | PR 1 | `ddev exec php vendor/bin/phpunit tests/Base/AdminOnlyAttributeTest.php tests/Base/FsPageAdminOnlyTest.php tests/Core/AdminOnlyPagesMigrationTest.php` | N/A — no-DB recorder | new files + XML/bootstrap |
| 2 Legacy + modern construction | PR 2 | `ddev exec php vendor/bin/phpunit --filter Construction` | N/A — source-scan + resolver | 2 construction files |
| 3 Enforcement | PR 3 | `ddev exec php vendor/bin/phpunit tests/Base/FsUserComposeMenuTest.php tests/Base/FsRolAccessAdminOnlyTest.php tests/Controller/AdminOnlyListingTest.php` | N/A — stubs + mock db | `fs_user`, `fs_rol_access`, 3 controllers |
| 4 9 declarations + docs | PR 4 | `ddev exec php vendor/bin/phpunit tests/Controller/AdminOnlyScopeTest.php` | N/A — static scan | 9 attributes; docs additive |

All commands via `ddev exec`. Tests run WITHOUT a database (anonymous `fs_model` subclasses, mock `fs_db2`, reflection, `CacheManager::reset()`).

## Phase 1: Declaration + persistence

- [ ] 1.1 (RED) `tests/Base/AdminOnlyAttributeTest.php` — PA-01: legacy+modern → `true`; non-loadable FQCN via 2nd `$expected`; ctor-throwing `ExplodingAttribute` still resolves (no `newInstance()`); 4th `$admin=TRUE` alone → `false`.
- [ ] 1.2 (GREEN) `src/Attribute/AdminOnly.php` — `#[\Attribute(\Attribute::TARGET_CLASS)]`.
- [ ] 1.3 (RED) `tests/Base/FsPageAdminOnlyTest.php` — PA-02 default `false`, save SQL has `admin_only`, clone preserves; **W3: `all()` exposes the flag for mixed rows**; PA-03 truth table; PA-10 absent→`false`.
- [ ] 1.4 (GREEN) `model/table/fs_pages.xml` — boolean `NOT NULL`, `<defecto>false</defecto>` (D8).
- [ ] 1.5 (GREEN) `model/fs_page.php` — property, ctor, save UPDATE+INSERT, `__clone`, name-string-only `is_admin_only_class($class,$expected=AdminOnly::class)` (D1/D7), OR-escalating `resolve_admin_only($attr,$pageData)` (D2).

## Phase 2: Migration + bootstrap

- [ ] 2.1 (RED) `tests/Core/AdminOnlyPagesMigrationTest.php` — PA-08 order, flag only on success, throw→retry; **W1: override `isApplied(): true` → zero steps**; `PAGE_ALLOWLIST` excludes `admin_custom`; source-scan pins call right after `selfHealCoreTables()`.
- [ ] 2.2 (GREEN) `src/Core/Schema/AdminOnlyPagesMigration.php` — non-final (D4) with overridable **`isApplied()`** (W1) + `forgetCheckedTables`, `adoptColumn`, `backfill`, `purgeStaleGrants`, `clearPageCache`, `markApplied`; flag `admin_only_pages_migrated`.
- [ ] 2.3 (GREEN) Wire migration post-self-heal in `index.php`, `api.php`, `cron.php` (D3).

## Phase 3: Construction

- [ ] 3.1 (RED) `tests/Base/FsPageConstructionTest.php` — PA-03/07/10: legacy payload `true`; modern escalates; **W5 decide (a) accept-and-document**: `updateExistingPage()` assigns `$page->admin_only = $adminOnly`, so dropping the attribute downgrades to `false` (deliberate revocation); source-scan pins the assignment + `mustUpdatePage` compare; regression `resolve_admin_only(false,null) === false`.
- [ ] 3.2 (GREEN) `base/fs_controller.php` — read attribute in `check_fs_page()`, carry in payload + `mustUpdatePage`/`updateExistingPage`; `$admin` param stays dead.
- [ ] 3.3 (GREEN) `src/Core/Base/Controller.php` — OR-escalate in `resolveOrCreatePage()`.

## Phase 4: Enforcement

- [ ] 4.1 (RED) `tests/Base/FsUserComposeMenuTest.php` — PA-06 stale grant denied for non-admin, admin keeps all; PA-09 `demo:true` returns all.
- [ ] 4.2 (GREEN) `model/core/fs_user.php` — extract `compose_menu(...)` (D6); non-admin `get_menu()` skips `admin_only`; `FS_DEMO` branch preserved.
- [ ] 4.3 (RED) `tests/Base/FsRolAccessAdminOnlyTest.php` — PA-05 guard `true`→`false` + no SQL; `false`→normal save; D5 missing row→allow.
- [ ] 4.4 (GREEN) `model/fs_rol_access.php` — `is_admin_only_page()` + top-of-`save()` unconditional refusal.
- [ ] 4.5 (RED) `tests/Controller/AdminOnlyListingTest.php` — PA-04 `newInstanceWithoutConstructor()` + reflection; **W2: inject stub `$this->user` (`all()`) for `admin_users` and stub `$this->suser` (`get_role_allowed_pages()`) for `admin_user`**; all 3 matrices drop admin-only, keep ordinary, admin and non-admin.
- [ ] 4.6 (GREEN) `controller/admin_rol.php`, `controller/admin_users.php`, `controller/admin_user.php` — skip `admin_only` in `all_pages()`.

## Phase 5: 9-page scope

- [ ] 5.1 (RED) `tests/Controller/AdminOnlyScopeTest.php` — PA-07 scan: attribute on exactly the 9 below, absent on `admin_home.php`; **W4: data-provider `is_admin_only_class(FQCN)` true for the 9 / false for `admin_home`**; persistence proven by 1.3 round-trip + 3.1 payload scan.
- [ ] 5.2 (GREEN) Add attribute to exactly: `admin_users.php`, `admin_user.php`, `admin_rol.php`, `admin_info.php`, `admin_email.php`, `admin_system_branding.php`, `admin_stealth.php`, `admin_orden_menu.php`, `admin_agentes.php`. `admin_home.php` stays `false`. Migration allowlist = these 9 only.

## Phase 6: Full verification

- [ ] 6.1 `ddev exec php vendor/bin/phpunit` — full suite, no regressions.
- [ ] 6.2 Revisit `tests/Controller/AdminAuthorityGuardsTest.php:24-28` stale `$admin`-ignored docblock.

## Phase 7: Docs + instruction parity

- [ ] 7.1 `AGENTS.md` canonical subsection (API, guarantees, W5 revocation, allowlist).
- [ ] 7.2 `.github/copilot-instructions.md` + `.cursor/rules/fs-framework-general.mdc` parity.
- [ ] 7.3 `.cursor/rules/fs-framework-plugins.mdc` — replace the `true, true` template (~332) with the attribute.
- [ ] 7.4 `.cursor/rules/fs-framework-security.mdc` — admin-only pages never role-grantable.
- [ ] 7.5 `fsframework-plugin-scaffold/SKILL.md` in `.opencode/skills/` + `.cursor/skills/` — Step 6 templates.
- [ ] 7.6 `fsframework-security-review/SKILL.md` both mirrors — authorization check.
- [ ] 7.7 `fsframework-model-crud/SKILL.md` both mirrors — only if the pattern is documented there.
