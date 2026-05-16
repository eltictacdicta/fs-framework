# FSFramework — Tech Debt Remediation

## What This Is

Incremental remediation of technical debt in the FSFramework codebase. Completed v0.10.8 which cleaned up legacy code, improved type safety, consolidated duplicate patterns, and strengthened security — without breaking backward compatibility.

## Core Value

Fix real issues with minimal risk. Every change must be verifiable by the existing test suite and must not break plugins that depend on current behavior.

## Current Milestone: v0.11.0 — Deferred Items Cleanup

**Goal:** Complete the 4 refactoring items deferred from v0.10.8 — finish what was started.

**Target features:**
- `empresa.php` → MailService email delegation
- `fs_mysql` decomposition (20+ schema methods)
- Plugin management extraction from `admin_home`
- Fix 5 pre-existing Security/Cache test failures

## Previous Milestone: v0.10.8

- **Shipped:** 2026-05-16
- **3 phases, 9 plans, 22 tasks** all complete
- **107 files changed** (2055 insertions, 10039 deletions)
- **8/8 requirements met**, Base test suite 124/124 throughout
- **5 pre-existing test failures** remain (Security/SessionManager + Cache/DataSrcRepository)

## Requirements

### Validated

- ✓ PHP version guards updated to 8.2 — v0.10.8
- ✓ `install.php` weak random fallback removed — v0.10.8
- ✓ PHPMailer 5.x + compat bridge eliminated — v0.10.8
- ✓ `@` error suppression replaced with proper guards — v0.10.8
- ✓ `declare(strict_types=1)` in 15 base files — v0.10.8
- ✓ Plugin test coverage: business_data + catalogo_core — v0.10.8
- ✓ SHA1/MD5 verification delegated to legacy_support plugin — v0.10.8
- ✓ Monolithic classes decomposed: StealthMode split + FsMysqlSchemaUtility — v0.10.8

### Active

- [ ] **MAIL-01**: `empresa.php` delegate email to `MailService` instead of instantiating PHPMailer directly
- [ ] **MYSQL-01**: Deeper `fs_mysql` decomposition (20+ interdependent schema methods)
- [ ] **PLUGIN-01**: Plugin management extraction from `admin_home`
- [ ] **TEST-01**: Fix 5 pre-existing Security/Cache test failures

### Out of Scope

- Queue/job system — infrastructure change, not tech debt
- API versioning beyond v1 — premature until API stabilizes
- Full Twig migration — ongoing, not a debt item
- Dynamic properties on fs_model/fs_controller — high plugin breakage risk
- Database migration system — separate initiative, not in this milestone

## Context

- **Philosophy**: Core stays thin, plugins extend. Domain models live in plugins. Essential base models (fs_user, agente) stay in core.
- **Legacy compatibility**: `legacy_support` plugin is the designated compatibility layer.
- **Testing**: PHPUnit 11 with `ddev exec php vendor/bin/phpunit`. Tests in `tests/` and `plugins/*/tests/`.
- **Plugin independence**: catalogo_core autonomous, business_data independent, proxy pattern confirmed.

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
*Last updated: 2026-05-16 after starting v0.11.0 milestone*
