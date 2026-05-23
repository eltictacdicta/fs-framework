# Roadmap: FSFramework — API Plugin Autonomy (v0.13.0)

## Milestones

- 🔄 **v0.13.0 API Plugin Autonomy** — Phases 12-14 (planning)
- ✅ **v0.12.0 Security Audit & Hardening** — Phases 8-11 (shipped 2026-05-23) — [Archive](milestones/v0.12.0-ROADMAP.md)
- ✅ **v0.11.0 Deferred Items Cleanup** — Phases 4-7 (shipped 2026-05-16) — [Archive](milestones/v0.11.0-ROADMAP.md)
- ✅ **v0.10.8 Tech Debt Cleanup** — Phases 1-3 (shipped 2026-05-16) — [Archive](milestones/v0.10.8-ROADMAP.md)

## Current Milestone Phases

### Phase 12: Plugin Composer Setup

**Goal:** `api_base` owns its Composer manifest, vendor autoload, and install documentation.

**Requirements:** DEPS-01, DEPS-02, DEPS-04

**Status:** ✓ Complete (2026-05-23) — [Summary](phases/12-plugin-composer-setup/12-01-SUMMARY.md)

**Success Criteria:**
1. `plugins/api_base/composer.json` exists with API-specific packages and PSR-4 autoload for plugin code
2. Plugin loads `vendor/autoload.php` when active without breaking core autoload
3. OpenAPI generation (`SwaggerGenerator`) and runtime classes resolve dependencies from plugin vendor
4. Install steps documented in `plugins/api_base/AGENTS.md` and verified under DDEV

---

### Phase 13: Core Trim & Dependency Removal

**Goal:** Remove API-only packages from core and confirm `src/Api/` is contracts-only.

**Requirements:** DEPS-03, CORE-01, CORE-02, CORE-03

**Success Criteria:**
1. Core `composer.json` no longer lists packages used exclusively by `api_base` (e.g. `zircote/swagger-php`, unused `firebase/php-jwt`)
2. `src/Api/` audited — only attributes, interfaces, and exceptions remain
3. `api.php` still returns 404 when plugin inactive and delegates to runtime when active
4. `ddev exec composer install` at root succeeds; API smoke test passes with plugin active

---

### Phase 14: Test Migration & Documentation

**Goal:** API tests live entirely in the plugin; root PHPUnit and docs reflect the new layout.

**Requirements:** TEST-01, TEST-02, TEST-03, TEST-04, DOC-01, DOC-02

**Success Criteria:**
1. No API-specific test files under core `tests/` (empty `tests/Api/` removed)
2. Root `phpunit.xml` has no stale `Api` testsuite pointing at an empty directory
3. `ddev exec php vendor/bin/phpunit -c plugins/api_base/phpunit.xml` passes
4. Root `ddev exec php vendor/bin/phpunit` passes (Plugins suite or documented policy)
5. `AGENTS.md`, `README.md`, and codebase map docs updated

---

## Progress

| Phase | Milestone | Status | Requirements |
|-------|-----------|--------|--------------|
| 12. Plugin Composer Setup | v0.13.0 | ✓ Complete | DEPS-01, DEPS-02, DEPS-04 |
| 13. Core Trim & Dependency Removal | v0.13.0 | Pending | DEPS-03, CORE-01–03 |
| 14. Test Migration & Documentation | v0.13.0 | Pending | TEST-01–04, DOC-01–02 |

---
*Roadmap created: 2026-05-23*
*Continues phase numbering from v0.12.0 (last phase: 11)*
