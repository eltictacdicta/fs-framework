# Requirements: FSFramework — API Plugin Autonomy

**Defined:** 2026-05-23
**Milestone:** v0.13.0
**Core Value:** Fix real issues with minimal risk. Every change must be verifiable by the existing test suite and must not break plugins that depend on current behavior.

## v1 Requirements

### Dependencies — Plugin Composer

- [ ] **DEPS-01**: `plugins/api_base/composer.json` declares all API-only Composer packages (`zircote/swagger-php` and any Symfony packages used exclusively by the API runtime)
- [ ] **DEPS-02**: Plugin bootstrap loads plugin vendor autoload when `api_base` is active (e.g. `Init.php` or `config/services.php`)
- [ ] **DEPS-03**: Core `composer.json` removes packages that only `api_base` consumes after migration is verified
- [ ] **DEPS-04**: Documented install path works: root `ddev exec composer install` plus plugin dependency resolution without breaking `api.php`

### Core — Slim Surface

- [ ] **CORE-01**: `src/Api/` contains only declarative contracts (attributes, interfaces, exceptions) — no runtime/router/middleware classes
- [ ] **CORE-02**: `api.php` remains a thin bootstrap that delegates to `Container::get('api.runtime')` from the plugin
- [ ] **CORE-03**: No orphaned API runtime code remains in core `src/` outside `src/Api/` contracts

### Testing — Plugin-Owned Suite

- [ ] **TEST-01**: All API-specific tests live under `plugins/api_base/tests/` (no duplicates in core tree)
- [ ] **TEST-02**: Root `phpunit.xml` removes the empty `tests/Api` suite and stale API test references
- [ ] **TEST-03**: Plugin suite runs in isolation: `ddev exec php vendor/bin/phpunit -c plugins/api_base/phpunit.xml`
- [ ] **TEST-04**: Root PHPUnit suite passes with API tests discovered only via plugin path (Plugins suite or documented exclusion)

### Documentation

- [ ] **DOC-01**: Update `AGENTS.md`, `README.md`, and `plugins/api_base/AGENTS.md` with dependency install and test commands
- [ ] **DOC-02**: Update `.planning/codebase/STACK.md` and `INTEGRATIONS.md` to reflect plugin-owned API dependencies

## v2 Requirements

Deferred to future release.

### API Platform

- **API-04**: Composer merge-plugin at root to auto-merge all plugin `composer.json` manifests
- **API-05**: Move `firebase/php-jwt` to consumer plugins (e.g. OidcProvider) when those plugins ship JWT features

## Out of Scope

| Feature | Reason |
|---------|--------|
| Moving `src/Api/Attribute/*` into `api_base` | Consumer plugins annotate models with `#[ApiResource]`; contracts must stay in core |
| API versioning beyond v1 | Premature until API stabilizes (unchanged from PROJECT.md) |
| OAuth/OIDC plugin dependency work | OidcProvider not in workspace; JWT removal from core only if unused |
| Non-`api_base` plugin API consumers refactor | Milestone scoped to dependency/test ownership, not consumer model changes |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| DEPS-01 | Phase 12 | Pending |
| DEPS-02 | Phase 12 | Pending |
| DEPS-03 | Phase 13 | Pending |
| DEPS-04 | Phase 12 | Pending |
| CORE-01 | Phase 13 | Pending |
| CORE-02 | Phase 13 | Pending |
| CORE-03 | Phase 13 | Pending |
| TEST-01 | Phase 14 | Pending |
| TEST-02 | Phase 14 | Pending |
| TEST-03 | Phase 14 | Pending |
| TEST-04 | Phase 14 | Pending |
| DOC-01 | Phase 14 | Pending |
| DOC-02 | Phase 14 | Pending |

**Coverage:**
- v1 requirements: 13 total
- Mapped to phases: 13
- Unmapped: 0 ✓

---
*Requirements defined: 2026-05-23*
*Last updated: 2026-05-23 after milestone v0.13.0 initialization*
