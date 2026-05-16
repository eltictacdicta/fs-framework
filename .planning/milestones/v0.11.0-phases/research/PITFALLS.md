# Pitfalls Research: Deferred Items

## Item 1: MailService Delegation

| Pitfall | Severity | Prevention |
|---------|----------|------------|
| `empresa` and `MailService` have separate config loading | Medium | Verify both read from same fs_var keys before merging |
| Callers in plugins depend on `empresa->new_mail()` return type | Medium | Deprecate old method, don't remove — keep backward compat |
| `empresa::mail_connect()` catches Exception differently than `MailService::testConnection()` | Low | Test both paths return compatible results |
| `empresa` caches email_config internally — stale cache risk | Low | Clear cache on delegation switch |

**Warning signs:** If `empresa` loads email_config from different fs_var entries than MailService, the delegation will use different credentials.

## Item 2: fs_mysql Decomposition

| Pitfall | Severity | Prevention |
|---------|----------|------------|
| Schema methods use `$this->` for select/exec/quote methods | **HIGH** | Pass fs_mysql as dependency; don't duplicate DB logic |
| Static state (`self::$link`, `self::$core_log`, `self::$last_affected_rows`) used by extracted methods | **HIGH** | Extract via dependency injection or keep as static helpers |
| 68 methods are deeply interdependent | **HIGH** | Extract in phases; don't try to decompose everything at once |
| `compare_columns` calls 4 private helpers which all need table quoting | Medium | Make quoting utilities static or inject formatter |
| `generate_table` depends on `validate_fk_constraints` which calls `list_tables()` | Medium | Identify dependency graph before extracting |
| Type normalization utilities (`convert_pg_type`, `normalize_mysql_default`) are pure functions | Low | Safest to extract first — no DB dependency |

**Strategy:** Start with pure functions (lowest risk), then read-only schema methods, then mutation methods. Each extraction verifiable by test suite.

## Item 3: Plugin Management Extraction

| Pitfall | Severity | Prevention |
|---------|----------|------------|
| `install_system_updater` has 4 private helper methods with tight coupling | Medium | Extract as a self-contained class with clear interface |
| 3-line delegations (`delete_plugin`, `disable_plugin`) not worth over-engineering | Low | Leave thin wrappers; only extract substantial logic |
| `exec_actions()` routing is the main integration surface | Low | Keep the router in admin_home, just redirect to new handler |
| `install_plugin()` uses `$_SESSION['pending_plugin']` for overwrite flow | Medium | Keep session handling explicit in the extracted class |

## Item 4: Test Failures

| Pitfall | Severity | Prevention |
|---------|----------|------------|
| Test environment differs from CI (path expectations) | Medium | Make path tests environment-agnostic |
| Static state leaks between tests (`TestDataSrc::$testData`) | Medium | Always reset static state in tearDown() |
| Fixing test without fixing root cause (masking bug) | Medium | Investigate each failure before fixing |
| Breaking other tests while fixing these 5 | Low | Run full suite after each fix |

**Prevention strategy:**
1. Investigate all 5 failures first (root cause)
2. Fix test isolation issues (tearDown resets)
3. Fix environment assumptions (path tests)
4. Run full suite: `ddev exec php vendor/bin/phpunit`
5. Fix any new failures introduced before committing
