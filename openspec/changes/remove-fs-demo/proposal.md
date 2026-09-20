# Proposal: Remove FS_DEMO constant and all demo-mode code

## Problem

`FS_DEMO` is a legacy constant that enabled a "demo mode" — users could log in
without a real password, the system created throwaway users with demo agents,
and several controllers blocked mutations to prevent abuse. The authority-
widening side (`get_menu()`, `compose_menu()`, `allow_delete_on()`) was already
removed in the `admin-only-pages` SDD (archived 2026-09-19), but the constant
itself and ~20 references across PHP controllers, templates and config still
remain as dead code.

Nobody deploys the demo mode. The constant is never defined in `config.php`
or `config-sample.php` — only `tests/bootstrap.php` defines it as `false` and
`phpstan.neon` lists it for static analysis. The remaining branches are
unreachable in production.

## Goal

Delete **every** reference to `FS_DEMO` from the codebase: the constant
definition, the conditional blocks, the `log_in_demo()` method, the Twig
conditionals, the PHPStan bootstrap entry, and the documentation entries that
describe it as a live feature. After this change, the concept of "demo mode"
no longer exists in FSFramework.

## Scope

### In Scope

| File | What to remove / change |
|------|------------------------|
| `base/fs_login.php` | Remove `log_in_demo()` method (~40 lines), remove `FS_DEMO` branch in `login()` |
| `model/core/fs_user.php` | Remove `FS_DEMO` guard in `rotate_logkey()`, remove demo comments |
| `controller/admin_home.php` | Remove `FS_DEMO` advice block |
| `controller/admin_users.php` | Remove `FS_DEMO` guard in `delete_user()` |
| `controller/admin_user.php` | Remove `FS_DEMO` guard in `modificar_user()` |
| `controller/admin_agentes.php` | Remove `FS_DEMO` guard in agente deletion |
| `themes/AdminLTE/view/login/default.html.twig` | Remove 5 `FS_DEMO` conditionals (title, autofocus, hidden password, remember-me, password-reset link) |
| `themes/AdminLTE/view/admin_users.html.twig` | Remove email/IP masking, restore permissions tab |
| `themes/AdminLTE/view/admin_user.html.twig` | Remove conditional email input |
| `themes/AdminLTE/view/password_reset.html.twig` | Remove demo title branch |
| `themes/AdminLTE/view/feedback.html.twig` | Remove demo guard on background tasks |
| `tests/bootstrap.php` | Remove `define('FS_DEMO', false)` |
| `phpstan.neon` | Remove `FS_DEMO` from bootstrap constants |
| `tests/Base/FsUserComposeMenuTest.php` | Update comments/assertions that reference `FS_DEMO` (the test logic stays — it guards the removal) |
| `AGENTS.md` | Remove "FS_DEMO never widens authority" section; update any mention of demo mode |
| `.cursor/rules/fs-framework-security.mdc` | Remove any `FS_DEMO` reference if present |
| Skill files (`fsframework-security-review`) | Remove `FS_DEMO` mention |

### Out of Scope

- Archived SDD documents (`openspec/changes/archive/`) — historical, no touch.
- The `add-user-impersonation-decided-against` proposal — historical, no touch.
- `openspec/specs/page-authorization/spec.md` — update the PA-09 requirement to reflect completion.
- Any plugin that might reference `FS_DEMO` (grep shows none).

## Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|------------|
| A production `config.php` defines `FS_DEMO=true` | Very Low | Constant is not in `config-sample.php`; no known deployment uses it. If defined, PHP will raise an "undefined constant" warning — harmless, logged once. |
| `log_in_demo()` removal breaks login flow | None | The method is only called inside an `if (FS_DEMO)` that is always `false`. |
| Tests reference `FS_DEMO` | Low | `FsUserComposeMenuTest` assertions will be updated to assert the demo code is gone, not to use it. |

## Estimated Size

~15 files touched, ~120 lines removed, ~20 lines modified. Well under the
400-line review budget.
