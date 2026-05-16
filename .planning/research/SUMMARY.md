# Research Summary: Deferred Items Cleanup

**Date:** 2026-05-16

## Stack Changes
None. All 4 items are pure refactors within existing codebase using already-installed dependencies (PHPMailer 6.x, PHPUnit 11, Symfony 7.4).

## Feature Analysis

### 1. MailService Delegation (MAIL-01)
**Effort:** Low | **Risk:** Low
- `MailService` already exists at `src/Core/MailService.php` (490 lines, fully functional)
- `empresa.php` has ~45 lines of duplicate PHPMailer logic to replace
- Strategy: make `empresa::new_mail()` delegate to `MailService::createMailer()`, `empresa::mail_connect()` to `MailService::testConnection()`
- Backward compatibility: keep old method signatures, just delegate internally

### 2. fs_mysql Decomposition (MYSQL-01)
**Effort:** High | **Risk:** Medium-High
- 1577 lines, 68 methods, 4 main groups: schema, type normalization, FK utilities, core DB ops
- Schema methods are tightly coupled to `fs_mysql` internals (`$this->select()`, `$this->exec()`, `$this->quoteIdentifier()`)
- **Recommended approach:** Extract pure functions first (type normalizers → no DB dependency), then read-only schema methods, then mutations
- **Precedent:** StealthMode decomposition in v0.10.8 followed same incremental pattern

### 3. Plugin Management Extraction (PLUGIN-01)
**Effort:** Medium | **Risk:** Low-Medium
- `admin_home.php` is 1053 lines, ~150 plugin-related
- Most plugin methods are 3-line delegations to `fs_plugin_manager`
- The `install_system_updater` (86 lines + 4 helpers) is the main value to extract
- Strategy: extract the updater installer as a standalone class; keep thin delegations in admin_home

### 4. Test Failures (TEST-01)
**Effort:** Medium | **Risk:** Low
- 5 failures: 2 are test isolation/env issues (easy fixes), 3 need investigation
- SessionManagerTest path mismatch (ddev env) — make test path-agnostic
- DataSrcRepositoryTest static leak — reset in tearDown()
- ResourceTransformerTest (2 failures) + DebugBarTest (1 error) — investigate

## Build Order Recommendation
1. **Phase A:** Test fixes (TEST-01) — unblock the suite first
2. **Phase B:** MailService delegation (MAIL-01) — lowest risk, quick win
3. **Phase C:** Plugin management extraction (PLUGIN-01) — medium scope
4. **Phase D:** fs_mysql decomposition (MYSQL-01) — highest risk, do last
