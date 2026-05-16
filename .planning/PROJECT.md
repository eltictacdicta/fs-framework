# FSFramework — Tech Debt Remediation

## What This Is

Incremental remediation of technical debt identified in the FSFramework codebase. The goal is to clean up legacy issues, improve type safety, consolidate duplicate code, and strengthen security — all without breaking backward compatibility or destabilizing the running system.

## Core Value

Fix real issues with minimal risk. Every change must be verifiable by the existing test suite and must not break plugins that depend on current behavior.

## Requirements

### Validated

- ✓ Proxy pattern for domain models (almacen, pais, divisa) — `business_data` delegates to `catalogo_core`
- ✓ `legacy_support` plugin handles SHA1/MD5 password migration on login
- ✓ `PasswordHasherService` auto-migrates legacy hashes to bcrypt/argon2id
- ✓ Composer enforces PHP >=8.2 at install time
- ✓ PHPUnit 11 test suite covers base/ and src/ components

### Active

- [ ] PHP version guards updated to 8.2 in entry points
- [ ] `declare(strict_types=1)` added incrementally to base files
- [ ] `install.php` weak random fallback removed
- [ ] PHPMailer 5.x vendored code removed, compat bridge eliminated
- [ ] Legacy SHA1 password support delegated entirely to `legacy_support` plugin
- [ ] Monolithic classes decomposed (StealthMode, fs_mysql, admin_home)
- [ ] Untested plugins receive basic test coverage (business_data, catalogo_core models)
- [ ] Error suppression (`@` operator) replaced with proper error handling

### Out of Scope

- Database migration system — too large, separate initiative
- Queue/job system — infrastructure change, not tech debt
- API versioning beyond v1 — premature until API stabilizes
- Full Twig migration — ongoing, not a debt item
- Dynamic properties on fs_model/fs_controller — high plugin breakage risk

## Context

- **Philosophy**: Core stays thin, plugins extend. Domain-specific models (Almacenes, Pais, Divisa, Articulos) live in plugins. Essential base models (fs_user, agente) stay in core.
- **Legacy compatibility**: `legacy_support` plugin is the designated compatibility layer for SHA1 passwords, legacy FS2017 APIs, and old technology bridges. All legacy compatibility code should eventually migrate there.
- **Testing**: PHPUnit 11 with `ddev exec php vendor/bin/phpunit`. Tests in `tests/` for core, `plugins/*/tests/` for plugins.
- **Risk tolerance**: Changes must be incremental and individually revertible. No big-bang refactors.

## Constraints

- **Backward compatibility**: Plugins depending on current class names, method signatures, and global state must continue to work
- **No breaking changes to public API**: `fs_model`, `fs_controller`, `fs_db2` interfaces are sacred
- **Incremental delivery**: Each fix should be a separate, testable, revertible change
- **Test-first**: Add tests before refactoring; run full suite after each change

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Proxy pattern for domain models | Keeps backward compatibility while centralizing implementation | ✓ Good — already in place |
| `legacy_support` as sole SHA1 handler | Core should not carry legacy crypto; delegates to dedicated plugin | — Pending |
| Incremental strict_types adoption | Big-bang would break too many things; per-file with tests | — Pending |
| Remove PHPMailer 5.x entirely | Composer 6.x is the dependency; compat bridge adds maintenance burden | — Pending |
| Extract monolithic classes into services | Reduces blast radius of changes; enables lazy loading | — Pending |

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
*Last updated: 2026-05-16 after initialization*
