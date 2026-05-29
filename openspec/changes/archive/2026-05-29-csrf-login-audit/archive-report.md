# Archive Report: csrf-login-audit

**Date**: 2026-05-29
**Status**: Complete
**Persistence**: Hybrid (Engram + OpenSpec)

## Executive Summary

Se archivó el cambio `csrf-login-audit` que arregló **7 bugs** (2 CRÍTICOS, 3 ALTOS, 2 MEDIOS) en el sistema de login, CSRF y cookies del FSFramework. El bug más grave — el formulario de login enviaba POST sin `page=login`, impidiendo que el controlador `login` se ejecutara — fue corregido eliminando `unset($query['page'])` de `loginActionUrl()`. Se implementaron métodos `login()`, `logout()`, `login_from_cookie()` en `fs_user`, se agregó `exit()` tras redirects, se sincronizó el token CSRF post-regeneración de sesión, y se protegió la integridad de `log_key`. 17 nuevos tests pasan (436 total, 0 regresiones). El test tautológico en `logout()` fue corregido post-verificación.

## Specs Synced

| Domain | Action | Requirements |
|--------|--------|-------------|
| `login-csrf` | **Created** | 4 requirements (LC-01..LC-04) |
| `session-integrity` | **Created** | 3 requirements (SI-01..SI-03) |
| `user-auth-methods` | **Created** | 4 requirements (UA-01..UA-04) |

All 3 specs were copied to `openspec/specs/` as full specs (no prior main specs existed).

## Verification Summary

- **Tests**: 436 passed (815 assertions), 0 new regressions, 1 pre-existing error (unrelated)
- **New tests**: 17/17 passed, 4 test files created
- **Spec compliance**: 11/11 scenarios verified (after post-verification fix for UA-02)

## Post-Verification Fix

The `logout()` test (`FsUserAuthMethodsTest::logoutMethodCanBeCalledWithoutCrash`) contained a tautology (`$this->assertTrue(true)`). Fixed by:
- Adding `$this->logged_on = false` to the `logout()` wrapper in `fs_user`
- Changing the test to assert `$user->logged_on === false` after logout

## Engram Lineage

| Artifact | Observation ID | Topic Key |
|----------|---------------|-----------|
| explore | #31 | `sdd/csrf-login-audit/explore` |
| proposal | #32 | `sdd/csrf-login-audit/proposal` |
| spec | #33 | `sdd/csrf-login-audit/spec` |
| design | #34 | `sdd/csrf-login-audit/design` |
| tasks | #35 | `sdd/csrf-login-audit/tasks` |
| apply-progress | #36 | `sdd/csrf-login-audit/apply-progress` |
| verify-report | #38 | `sdd/csrf-login-audit/verify-report` |

## Archive Contents (OpenSpec)

```
openspec/changes/archive/2026-05-29-csrf-login-audit/
├── proposal.md          ✅
├── design.md            ✅
├── tasks.md             ✅
├── verify-report.md     ✅
├── archive-report.md    ✅
└── specs/
    ├── login-csrf/spec.md          ✅
    ├── session-integrity/spec.md   ✅
    └── user-auth-methods/spec.md   ✅
```

## Source of Truth Updated

- `openspec/specs/login-csrf/spec.md`
- `openspec/specs/session-integrity/spec.md`
- `openspec/specs/user-auth-methods/spec.md`

## Files Changed (Implementation)

| File | Change |
|------|--------|
| `controller/login.php` | Removed `unset($query['page'])` from `loginActionUrl()` |
| `model/core/fs_user.php` | Added `login()`, `logout()`, `login_from_cookie()`; `rotate_logkey()` in `set_password()`; NULL guard in `save()` |
| `base/fs_controller.php` | Added `exit;` after redirects in `select_default_page()` |
| `base/fs_login.php` | Added `CsrfManager::refreshToken()` after `session->migrate(true)` |
| `config.php` | Added `FS_CSRF_SOFT=true` |

## SDD Cycle Complete

✅ **Explored** → ✅ **Proposed** → ✅ **Specified** → ✅ **Designed** → ✅ **Tasked** → ✅ **Applied** → ✅ **Verified** → ✅ **Archived**
