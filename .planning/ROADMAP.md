# Roadmap: FSFramework Tech Debt Remediation

## Overview

Incremental cleanup of legacy technical debt in FSFramework. Three phases move from low-risk quick fixes (version guards, dead code removal) through type safety hardening to the larger structural decompositions. Each phase is independently revertible and must pass the full test suite. Backward compatibility is sacred — no public API breaks.

## Phases

- [x] **Phase 1: Quick Wins & Security Fixes** - Remove dead code, fix version guards, eliminate dual PHPMailer, replace error suppression
- [x] **Phase 2: Type Safety & Test Coverage** - Add strict_types to base files, add tests for untested plugins
- [x] **Phase 3: Structural Decomposition** - Delegate SHA1 to legacy_support, decompose monolithic classes

## Phase Details

### Phase 1: Quick Wins & Security Fixes
**Goal**: Entry points are correct, dead security code is removed, only one PHPMailer version exists, and error suppression is eliminated
**Depends on**: Nothing (first phase)
**Requirements**: REQ-01, REQ-03, REQ-04, REQ-08
**Success Criteria** (what must be TRUE):
  1. `index.php` and `install.php` check for PHP >= 8.2 instead of 5.6
  2. `install.php` uses only `random_bytes()` — no `str_shuffle()` fallback exists
  3. `extras/phpmailer/` directory and `phpmailer_compat.php` are gone; all email uses Composer PHPMailer 6.x
  4. No `@` error suppression operators remain in `base/` files — replaced with proper checks or try/catch
  5. Full test suite passes with zero regressions
**Plans**: 4 plans

Plans:

**Wave 1** (independent, can run in parallel):
- [x] 01-01: Update PHP version guards in index.php and install.php to 8.2 [REQ-01]
- [x] 01-02: Remove weak random fallback (str_shuffle) from install.php [REQ-03]
- [x] 01-03: Remove PHPMailer 5.x vendored code and compat bridge [REQ-04]

**Wave 2** (depends on Wave 1):
- [x] 01-04: Replace @ error suppression with proper error handling in base/ [REQ-08]

### Phase 2: Type Safety & Test Coverage
**Goal**: Core files have strict type enforcement and business-critical plugins have automated tests
**Depends on**: Phase 1
**Requirements**: REQ-02, REQ-07
**Success Criteria** (what must be TRUE):
  1. At least 10 files in `base/` have `declare(strict_types=1)` — starting with simplest files
  2. `business_data` plugin has test coverage for empresa, ejercicio, serie, divisa models
  3. `catalogo_core` plugin has test coverage for articulo, familia, fabricante models
  4. All new and existing tests pass
**Plans**: 2 plans

Plans:
- [x] 02-01: Add declare(strict_types=1) to 15 base files (3 batches by complexity)
- [x] 02-02: Add test coverage for business_data (empresa, ejercicio, serie, divisa) and catalogo_core (familia, fabricante)

### Phase 3: Structural Decomposition
**Goal**: SHA1 legacy code lives only in legacy_support plugin, and monolithic classes are split into focused services
**Depends on**: Phase 2
**Requirements**: REQ-05, REQ-06
**Success Criteria** (what must be TRUE):
  1. SHA1/MD5 password verification code removed from `PasswordHasherService` — only `legacy_support` plugin handles legacy hash verification
  2. `StealthMode` split into `StealthAccessGate`, `HtmlSanitizer`, and `CssSanitizer` — each under 400 lines
  3. `fs_mysql` schema operations extracted to `FsMysqlSchema` class — driver focused on connection/query only
  4. `admin_home` cache management extracted to `admin_cache` controller — dashboard stays focused
  5. Full test suite passes; no public API signatures changed
**Plans**: 3 plans

Plans:
- [x] 03-01: Delegate SHA1 password verification entirely to legacy_support plugin
- [x] 03-02: Decompose StealthMode into CssSanitizer + HtmlSanitizer + StealthAccessGate + thin facade
- [x] 03-03: Extract FsMysqlSchema from fs_mysql + create admin_cache controller

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Quick Wins & Security Fixes | 4/4 | ✓ Complete | 2026-05-16 |
| 2. Type Safety & Test Coverage | 2/2 | ✓ Complete | 2026-05-16 |
| 3. Structural Decomposition | 3/3 | ✓ Complete | 2026-05-16 |
