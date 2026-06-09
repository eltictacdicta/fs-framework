# Tasks: Standalone CSRF Hardening — Remove Symfony Fallback

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~96 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | CSRF guard changes + tests + version bump | PR 1 | All changes fit under 400 lines |

## Phase 1: Tests (TDD RED — write tests for CSRF helpers)

- [x] 1.1 Create `tests/CsrfGuardTest.php` requiring `lib/csrf_guard.php`. Add `testReadStoredTokenFromSf2Attributes()` — set `$_SESSION['_sf2_attributes']['_csrf/fs_form']`, assert `system_updater_csrf_read_stored_token()` returns the token.
- [x] 1.2 Add `testReadStoredTokenFromLegacyKey()` — set `$_SESSION['_csrf/fs_form']`, assert function returns it.
- [x] 1.3 Add `testReadStoredTokenEmptyWhenMissing()` — no session keys set, assert returns `''`.
- [x] 1.4 Add `testVerifyTokenExactMatch()` — `system_updater_csrf_verify_token('abc', 'abc')` → `true`.
- [x] 1.5 Add `testVerifyTokenSymfonyRandomizedFormat()` — build valid `checksum.key.xored` token, verify against stored original.
- [x] 1.6 Add `testVerifyTokenMismatchRejected()` — different tokens → `false`. Add `testVerifyTokenMalformedRejected()` — non-3-part dotted string → `false`.
- [x] 1.7 Run `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` → new CsrfGuardTest passes.

## Phase 2: Implementation (TDD GREEN — remove fallback, close session, clean logging)

- [x] 2.1 Remove `CsrfManager::isValid()` fallback block (`lib/csrf_guard.php` lines 92–101: `$diagInfo` declaration + try/catch).
- [x] 2.2 Add `session_write_close()` before the `return;` on successful direct-read validation (inside the `if` at line 87–88).
- [x] 2.3 Remove `, diag=%s` from `error_log` format string (line 110) and drop the `$diagInfo` argument from `sprintf` (line 117).
- [x] 2.4 Run `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` → all tests pass.

## Phase 3: Version & Regression

- [x] 3.1 Bump `version` in `fsframework.ini` from `2.4.18` to `2.4.19`.
- [x] 3.2 Run full regression: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` → all existing + new tests pass (SessionAuthTest, ProcessBackupTest, CoreUpdaterTest, PluginDownloaderTest, CsrfGuardTest).
