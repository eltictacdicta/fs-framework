# FSFramework — Tech Debt & Security Remediation

## What This Is

Incremental remediation of technical debt and security hardening in the FSFramework codebase. Shipped v0.12.0 (security audit) after v0.11.0 (tech debt) and v0.10.8 (initial cleanup).

## Core Value

Fix real issues with minimal risk. Every change must be verifiable by the existing test suite and must not break plugins that depend on current behavior.

## Current Milestone: v0.13.0 API Plugin Autonomy

**Goal:** Completar la extracción de la API moviendo dependencias Composer y tests al plugin `api_base`, dejando en el core solo bootstrap mínimo y contratos declarativos.

**Target features:**
- `composer.json` propio en `plugins/api_base` con dependencias API
- Eliminar del core las deps que solo consume `api_base`
- Tests API solo en `plugins/api_base/tests/`; limpiar restos en core
- Mantener `src/Api/` como contratos (`#[ApiResource]`, interfaces, excepciones)

## Previous Milestone (v0.12.0)

- **Shipped:** 2026-05-23
- **4 phases (8-11), 21/21 requirements met**
- **15 files changed** in security commits (695 insertions, 84 deletions)
- **Security suite:** 140 tests, 0 failures
- **Deliverables:** `SECURITY.md`, `.planning/security/BASELINE-AUDIT.md`, CSRF blocking, CSP/DebugBar hardening

## Previous Milestones

<details>
<summary>v0.11.0 — Deferred Items Cleanup (shipped 2026-05-16)</summary>

- **4 phases, 5 plans, 16 tasks**
- **31 files changed** (2643 insertions, 660 deletions)
- Test suite recovery, MailService delegation, plugin mgmt extraction, fs_mysql decomposition

</details>

<details>
<summary>v0.10.8 — Tech Debt Cleanup (shipped 2026-05-16)</summary>

- **3 phases, 9 plans, 22 tasks**
- **107 files changed** (2055 insertions, 10039 deletions)
- PHP guards, PHPMailer removal, strict_types, StealthMode decomposition

</details>

## Requirements

### Validated

- ✓ PHP version guards updated to 8.2 — v0.10.8
- ✓ `install.php` weak random fallback removed — v0.10.8
- ✓ PHPMailer 5.x + compat bridge eliminated — v0.10.8
- ✓ `@` error suppression replaced with proper guards — v0.10.8
- ✓ `declare(strict_types=1)` in 15 base files — v0.10.8
- ✓ Plugin test coverage: business_data + catalogo_core — v0.10.8
- ✓ SHA1/MD5 verification delegated to legacy_support plugin — v0.10.8
- ✓ Monolithic classes decomposed: StealthMode + FsMysqlSchemaUtility — v0.10.8
- ✓ 5 pre-existing test failures fixed — v0.11.0 Phase 4
- ✓ empresa.php email delegated to MailService — v0.11.0 Phase 5
- ✓ Plugin management extracted from admin_home — v0.11.0 Phase 6
- ✓ fs_mysql decomposed into TypeNormalizer + SchemaInspector + SchemaComparator — v0.11.0 Phase 7
- ✓ Security baseline audit (21 REQ-IDs) — v0.12.0
- ✓ CSRF blocking in pre_private_core + no double validation — v0.12.0 Phase 9
- ✓ Input hardening + SECURITY.md + CSP/DebugBar controls — v0.12.0 Phases 10-11

### Active

- [ ] Database migration system — separate initiative
- [ ] CSP strict mode (remove `unsafe-inline`) — v2 SEC-02
- [ ] Legacy password sunset (SHA1/MD5) — v2 SEC-01

### Out of Scope

- Queue/job system — infrastructure change, not tech debt
- API versioning beyond v1 — premature until API stabilizes
- Full Twig migration — ongoing, not a debt item
- Dynamic properties on fs_model/fs_controller — high plugin breakage risk
- admin_cache controller — too trivial (5 lines) to justify separate controller
- Non-core plugin security audit — scoped to versioned core plugins in v0.12.0

## Context

- **Version:** 0.12.0 core (see `VERSION`); v0.13.0 milestone in progress
- **API architecture:** Runtime in `plugins/api_base`; core entry `api.php` + `src/Api/` contracts
- **Note:** `plugins/api_base/.planning/codebase/STACK.md` documents current plugin stack (2026-05-23)
- **Test suite:** Security 140 tests; full suite via `ddev exec php vendor/bin/phpunit`
- **Philosophy**: Core stays thin, plugins extend. Domain models live in plugins.
- **Legacy compatibility**: `legacy_support` plugin is the designated compatibility layer.
- **Security docs**: `SECURITY.md`, `.planning/security/`
- **New architecture**: Service classes in `src/Core/` and `src/Database/`

## Constraints

- **Backward compatibility**: Plugins depending on current class names, method signatures, and global state must continue to work
- **No breaking changes to public API**: `fs_model`, `fs_controller`, `fs_db2` interfaces are sacred
- **Test-first**: Add tests before refactoring; run full suite after each change

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Proxy pattern for domain models | Keeps backward compatibility while centralizing implementation | ✓ Good |
| `legacy_support` as sole SHA1 handler | Core should not carry legacy crypto | ✓ Good |
| CSRF block in pre_private_core | Invalid tokens must not run private_core mutations | ✓ Good — v0.12.0 |
| requireCsrf uses isCsrfValid() | Avoid double token consumption after pre_private_core | ✓ Good — v0.12.0 |
| DebugBar local-IP only | Prevent SQL/log leak when FS_DEBUG left on in production | ✓ Good — v0.12.0 |
| CSP unsafe-inline deferred | AdminLTE inline JS migration required first | ⚠️ v2 SEC-02 |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-05-23 — milestone v0.13.0 API Plugin Autonomy started*
