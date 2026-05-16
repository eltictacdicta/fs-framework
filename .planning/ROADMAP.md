# Roadmap: FSFramework — Deferred Items Cleanup

**Milestone:** v0.11.0
**Created:** 2026-05-16
**Phases:** 4 (continuing from Phase 3)
**Requirements covered:** 16/16

## Phase Structure

Risk-ascending build order: fix tests first (clean baseline), then lowest-risk refactors, then highest-risk extraction last.

### Phase 4: Test Suite Recovery

**Goal:** All 5 pre-existing test failures are fixed. Full test suite passes clean (0 failures, 0 errors).

**Requirements:** TEST-01, TEST-02, TEST-03, TEST-04, TEST-05

**Success criteria:**
1. `ddev exec php vendor/bin/phpunit` exits with 0 failures and 0 errors
2. `SessionManagerTest::testResolveCookiePathUsesCurrentInstallationPath` passes regardless of filesystem path
3. `DataSrcRepositoryTest` has no cross-test static state contamination
4. `ResourceTransformerTest` (2 failures) pass consistently
5. `DebugBarTest::testAddQueryStoresSqlStatements` completes without error

### Phase 5: MailService Delegation

**Goal:** `empresa` model delegates all email operations to `MailService`. No direct PHPMailer usage remains in `empresa.php`.

**Requirements:** MAIL-01, MAIL-02, MAIL-03, MAIL-04

**Success criteria:**
1. `empresa::new_mail()` returns a MailService-configured PHPMailer via delegation
2. `empresa::mail_connect()` returns same result as `MailService::testConnection()`
3. `empresa::can_send_mail()` matches `MailService::canSendMail()` behavior
4. No `new PHPMailer()` instantiation remains in `empresa.php`
5. All existing callers of empresa mail methods continue to work (backward compat)
6. Full test suite passes with no new failures

### Phase 6: Plugin Management Extraction

**Goal:** Plugin installation and action handling are extracted from `admin_home` into dedicated classes. `admin_home` becomes a thin delegator.

**Requirements:** PLUGIN-01, PLUGIN-02, PLUGIN-03

**Success criteria:**
1. `install_system_updater` logic lives in a dedicated class, not in `admin_home`
2. Plugin action routing (enable/disable/delete/install/restore) is handled by `PluginActionHandler`
3. `admin_home::exec_actions()` delegates to the new handler instead of calling private methods
4. All existing admin_home plugin operations work identically
5. Full test suite passes with no new failures

### Phase 7: fs_mysql Decomposition

**Goal:** Schema-related methods extracted from `fs_mysql` into focused classes. `fs_mysql` retains only core DB operations.

**Requirements:** MYSQL-01, MYSQL-02, MYSQL-03, MYSQL-04

**Success criteria:**
1. Type normalization utilities live in `src/Database/TypeNormalizer.php` (pure functions)
2. Schema introspection methods live in `src/Database/SchemaInspector.php`
3. Schema comparison and generation live in `src/Database/SchemaComparator.php`
4. `fs_mysql` delegates schema operations to extracted classes
5. All existing `fs_mysql` callers continue to work without changes
6. Full test suite passes with no new failures

## Phase Dependency Graph

```
Phase 4 (Tests) ── independent, no dependencies
    │
    ├── Phase 5 (MailService) ── depends on clean test baseline
    │
    ├── Phase 6 (Plugin Mgmt) ── depends on clean test baseline
    │
    └── Phase 7 (fs_mysql) ── depends on clean test baseline, deferred to last (highest risk)
```

## Coverage Map

| Requirement | Phase | Status |
|-------------|-------|--------|
| TEST-01 | Phase 4 | Pending |
| TEST-02 | Phase 4 | Pending |
| TEST-03 | Phase 4 | Pending |
| TEST-04 | Phase 4 | Pending |
| TEST-05 | Phase 4 | Pending |
| MAIL-01 | Phase 5 | Pending |
| MAIL-02 | Phase 5 | Pending |
| MAIL-03 | Phase 5 | Pending |
| MAIL-04 | Phase 5 | Pending |
| PLUGIN-01 | Phase 6 | Pending |
| PLUGIN-02 | Phase 6 | Pending |
| PLUGIN-03 | Phase 6 | Pending |
| MYSQL-01 | Phase 7 | Pending |
| MYSQL-02 | Phase 7 | Pending |
| MYSQL-03 | Phase 7 | Pending |
| MYSQL-04 | Phase 7 | Pending |

**Coverage:** 16/16 mapped ✓

---
*Roadmap created: 2026-05-16*
*Last updated: 2026-05-16 after initial definition*
