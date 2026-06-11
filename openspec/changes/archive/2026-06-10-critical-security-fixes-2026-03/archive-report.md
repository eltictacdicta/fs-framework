# Archive Report: critical-security-fixes-2026-03

## Change Summary

| Field | Value |
|-------|-------|
| Change ID | critical-security-fixes-2026-03 |
| Framework Version | FSFramework v0.14.8 |
| Verification Status | PASS WITH WARNINGS |
| Spec Compliance | 14/14 scenarios verified (100%) |
| Archived Date | 2026-06-10 |

## Fixes Applied

| ID | Severity | Description | File |
|----|----------|-------------|------|
| C1 | Critical | SQL injection in list search — replaced `htmlspecialchars()` with `$this->db->escape_string()` | `base/fs_list_controller.php:355` |
| H2 | High | CSRF token access via superglobals — replaced `$_POST`/`$_SERVER` with `Kernel::request()` + try-catch | `base/fs_auth.php:429-442` |
| H3 | High | Login superglobal access — replaced all 6 `$_GET`/`$_POST` accesses with `$this->request` | `controller/login.php:70,146,158-159,180,188` |

## Files Modified

| File | Lines Changed | Type |
|------|--------------|------|
| `base/fs_list_controller.php` | 1 | Production |
| `base/fs_auth.php` | ~14 | Production |
| `controller/login.php` | 6 | Production |
| **Total production** | **~21** | |

## Test Files Created

| Test File | Tests | Assertions | Coverage |
|-----------|-------|------------|----------|
| `tests/Base/FsListControllerSearchTest.php` | 5 | 9 | C1 scenarios + injection + empty query |
| `tests/Base/FsAuthCsrfRequestTest.php` | 3 | 3 | H2 POST token, header token, null kernel |
| `tests/Base/LoginSuperglobalsTest.php` | 7 | 14 | H3 all scenarios including DB switch |
| **Total** | **15** | **26** | **All 14 spec scenarios + extras** |

## Verification Results

### Test Evidence
- New tests: 15 tests, 26 assertions — ALL PASS
- Base suite regression: 147+ tests — EXIT=0
- Security suite regression: 172 tests, 309 assertions — OK (6 deprecations, 13 skipped)
- Full suite: ALL GREEN

### Spec Compliance Matrix
| Scenario | Status | Notes |
|----------|--------|-------|
| C1-1: Normal search | ✅ COMPLIANT | |
| C1-2: Single quote escaped | ✅ COMPLIANT | |
| C1-3: HTML chars raw | ✅ COMPLIANT | |
| C1-extra: SQL injection | ✅ COMPLIANT | |
| C1-extra: Empty query | ✅ COMPLIANT | |
| H2-1: POST token | ✅ COMPLIANT | |
| H2-2: Header token | ✅ COMPLIANT | |
| H2-3: Null kernel | ✅ COMPLIANT | try-catch (positive deviation from design) |
| H3-1: Credential login | ✅ COMPLIANT | |
| H3-2: Logout | ✅ COMPLIANT | |
| H3-3: DB switch | ✅ COMPLIANT | Test added post-verify |
| H3-4: Remember-me | ✅ COMPLIANT | |
| H3-5: Autologin | ✅ COMPLIANT | |
| H3-6: Missing params | ✅ COMPLIANT | |

### Residual Unsafe Patterns
- `base/fs_list_controller.php`: 0 `htmlspecialchars` in search context ✅
- `base/fs_auth.php`: 2 `$_SERVER['HTTPS']` at lines 468, 489 — out of scope (cookie HTTPS detection) ✅
- `controller/login.php`: 0 `$_GET`/`$_POST`/`$_SERVER` ✅

## Specs Synced

| Domain | Action | Details |
|--------|--------|---------|
| `list-search-security` | Created | New spec with LSS-01, LSS-02 (C1 fix) |
| `session-integrity` | Updated | Added SI-04 (H2 fix: Symfony Request for CSRF) |
| `login-csrf` | Updated | Added LC-05 (H3 fix: Symfony Request for all login input) |

## Engram Artifact Trace

| Artifact | Observation ID | Topic Key |
|----------|---------------|-----------|
| Proposal | #100 | `sdd/critical-security-fixes/proposal` |
| Spec | #101 | `sdd/critical-security-fixes/spec` |
| Design | #102 | `sdd/critical-security-fixes/design` |
| Tasks | #103 | `sdd/critical-security-fixes/tasks` |
| Apply Progress | #104 | `sdd/critical-security-fixes/apply-progress` |
| Verify Report | #105 | `sdd/critical-security-fixes/verify-report` |

## Follow-up Recommendations

1. **Manual smoke test** (pending): login flow, search with `'`, CSRF form submission
2. **Out of scope**: `$_SERVER['HTTPS']` at `base/fs_auth.php:468,489` — cookie HTTPS detection, not a direct input vector but could be hardened in a future change

## SDD Cycle Complete

This change has been fully planned, implemented, verified, and archived.
Ready for the next change.
