# FSFramework — Tech Debt Remediation

## What This Is

Incremental remediation of technical debt in the FSFramework codebase. Completed v0.11.0 which extracted plugin management into service classes, delegated email to MailService, decomposed the monolithic MySQL driver into 3 focused classes, and fixed 5 pre-existing test failures — all without breaking backward compatibility.

## Core Value

Fix real issues with minimal risk. Every change must be verifiable by the existing test suite and must not break plugins that depend on current behavior.

## Current State (v0.11.0)

- **Shipped:** 2026-05-16
- **4 phases, 5 plans, 16 tasks** all complete (continuing from v0.10.8 Phase 3)
- **31 files changed** (2643 insertions, 660 deletions)
- **16/16 requirements met**, test suite 383 passes / 0 failures / 0 errors
- **5 new service classes** in `src/Core/` and `src/Database/`
- **admin_home** reduced from 1053 to 698 lines (34%)

## Previous Milestones

<details>
<summary>v0.10.8 — Tech Debt Cleanup (shipped 2026-05-16)</summary>

- **3 phases, 9 plans, 22 tasks**
- **107 files changed** (2055 insertions, 10039 deletions)
- **8/8 requirements met**
- Cleaned up legacy code, improved type safety, consolidated duplicate patterns, strengthened security

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
- ✓ 5 pre-existing test failures fixed (full suite: 0 errors) — v0.11.0 Phase 4
- ✓ empresa.php email delegated to MailService — v0.11.0 Phase 5
- ✓ Plugin management extracted from admin_home (1053→698 lines) — v0.11.0 Phase 6
- ✓ fs_mysql decomposed into TypeNormalizer + SchemaInspector + SchemaComparator — v0.11.0 Phase 7

### Active

- [ ] Database migration system — separate initiative

### Out of Scope

- Queue/job system — infrastructure change, not tech debt
- API versioning beyond v1 — premature until API stabilizes
- Full Twig migration — ongoing, not a debt item
- Dynamic properties on fs_model/fs_controller — high plugin breakage risk
- admin_cache controller — too trivial (5 lines) to justify separate controller; revisit if admin_home grows

## Context

- **Version:** 0.11.0 deployed
- **Test suite:** 383 tests, 0 failures, 0 errors, 14 skipped
- **Philosophy**: Core stays thin, plugins extend. Domain models live in plugins. Essential base models (fs_user, agente) stay in core.
- **Legacy compatibility**: `legacy_support` plugin is the designated compatibility layer.
- **Testing**: PHPUnit 11 with `ddev exec php vendor/bin/phpunit`. Tests in `tests/` and `plugins/*/tests/`.
- **Plugin independence**: catalogo_core autonomous, business_data independent, proxy pattern confirmed.
- **New architecture**: Service classes in `src/Core/` (MailService, PluginInstaller, PluginActionHandler) and `src/Database/` (TypeNormalizer, SchemaInspector, SchemaComparator)

## Constraints

- **Backward compatibility**: Plugins depending on current class names, method signatures, and global state must continue to work
- **No breaking changes to public API**: `fs_model`, `fs_controller`, `fs_db2` interfaces are sacred
- **Test-first**: Add tests before refactoring; run full suite after each change

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Proxy pattern for domain models | Keeps backward compatibility while centralizing implementation | ✓ Good — already in place |
| `legacy_support` as sole SHA1 handler | Core should not carry legacy crypto | ✓ Good — implemented Phase 3 |
| Incremental strict_types adoption | Big-bang would break too many things | ✓ Good — 15 files, zero regressions |
| PHPMailer 5.x removed | Composer 6.x is the dependency | ✓ Good — 61 files deleted |
| StealthMode facade pattern | Public API preserved while internally decomposed | ✓ Good — 53% reduction |
| `@` suppression → proper guards | Makes real errors visible | ✓ Good — ~35 ops in 11 files |
| admin_cache controller deferred | Cache code in admin_home too trivial (5 lines) to justify separate controller | ⚠️ Revisit if admin_home grows |
| MailService as single email gateway | empresa.php no longer owns PHPMailer config | ✓ Good — 4 requirements met Phase 5 |
| Plugin management extraction to service classes | admin_home was 1053 lines with mixed concerns | ✓ Good — 355 lines removed Phase 6 |
| TypeNormalizer as pure static class | No DB dependency, zero risk extraction | ✓ Good — Phase 7 |
| SchemaInspector/SchemaComparator DI pattern | DB dependency injected via constructor | ✓ Good — Phase 7 |
| compare_constraints kept in fs_mysql | Constraint signature normalization too complex for safe extraction | ✓ Good — pragmatic scope Phase 7 |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-05-16 after v0.11.0 milestone*
