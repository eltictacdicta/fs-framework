# Design: First-Login Password Change

## Technical Approach

Wire existing infrastructure to replace the fragile random-password flash-message flow with a known-default (`admin`/`admin`) + forced-change-on-first-login flow. The `force_password_change` controller, Twig template, session interceptor (`shouldForcePasswordChange()`), and `fs_vars` flag mechanism already exist. Changes are limited to reordering when `completeInitialSetupIfPending()` fires and adding the `initial_setup` reason branch.

## Architecture Decisions

| Decision | Option A | Option B | Choice | Rationale |
|----------|----------|----------|--------|-----------|
| Default password | `'admin'` (known) | Random + flash message | A | FP-01. Flash message is fragile (missed = locked out). `admin` is simple, memorable, and forced-change makes it ephemeral. |
| Where to set force-password flag | `log_in_user()` after credential verify | `login.php` controller after `$user->login()` | A | FP-04, UA-01. `log_in_user()` is the single auth gate — cookie login and autologin also flow through `fs_login`. Placing it here covers all entry points. |
| When to complete initial setup | `force_password_change` controller on success | `log_in_user()` immediately (current) | A | FP-08, FP-10. Completing setup before password change defeats the purpose. The controller already calls `completeInitialSetupIfPending()` on line 81. |
| Template reason branching | Conditional block in existing Twig | Separate template file | A | Minimal change. The template already receives `fsc.change_reason` from the controller. Add `{% if %}` for welcome vs warning message. |

## Data Flow

```
INSTALL
  fs_user::install()
    ├── password = 'admin' → Argon2id hash → INSERT INTO fs_users
    ├── markInitialSetupPending() → fs_var: initial_admin_setup = 'pending'
    └── (no flash message)

FIRST LOGIN
  login.php → $user->login('admin','admin')
    └── fs_login::log_in_user()
          ├── password_verify() → OK
          ├── isInitialSetupPending() → TRUE
          │     ├── $_SESSION['force_password_change'] = true
          │     ├── $_SESSION['force_password_change_reason'] = 'initial_setup'
          │     └── SKIP completeInitialSetupIfPending()
          └── save_session_data()

NAVIGATION INTERCEPT
  fs_controller::shouldForcePasswordChange()
    ├── reads $_SESSION['force_password_change'] === true
    └── header('Location: index.php?page=force_password_change')

PASSWORD CHANGE
  force_password_change::private_core()
    ├── reads reason = 'initial_setup' → welcome message (not warning)
    ├── POST: validate ≥8 chars, match, Argon2id hash
    ├── save new password
    ├── completeInitialSetupIfPending() → fs_var: 'completed'
    ├── unset session flags
    └── redirect → index.php (normal flow)

EXISTING INSTALLS
  isInitialSetupPending() → FALSE (fs_var = 'completed')
    └── log_in_user() → no flag set → normal login (zero behavior change)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `model/core/fs_user.php` | Modify | `install()` (L216-239): Replace random password with `'admin'`. Remove flash message block (L218-235). Keep `markInitialSetupPending()` and Argon2id hash. |
| `base/fs_login.php` | Modify | `log_in_user()` (L452): Check `isInitialSetupPending()` BEFORE `completeInitialSetupIfPending()`. If pending → set session flag + reason, skip completion. |
| `controller/login.php` | Modify | Remove L178 and L193 (`completeInitialSetup()` calls). Update `showInitialSetupMessageIfPending()` (L114-125) to display `admin/admin` credentials. |
| `controller/force_password_change.php` | Modify | `private_core()` (L50): Set `change_reason` from session (already done). No logic changes needed — template handles reason display. |
| `themes/AdminLTE/view/force_password_change.html.twig` | Modify | Add conditional: when `fsc.change_reason == 'initial_setup'` show welcome alert; otherwise show existing insecure-password warning. |

## Interfaces / Contracts

No new interfaces. Existing contracts unchanged:

- `$_SESSION['force_password_change']` — bool flag, checked by `shouldForcePasswordChange()` in `fs_controller` (L875-894)
- `$_SESSION['force_password_change_reason']` — string (`'insecure_password'` | `'initial_setup'`), read by `force_password_change` controller (L50)
- `fs_var::simple_get('initial_admin_setup')` — `'pending'` | `'completed'` | `false`

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `install()` produces Argon2id hash of `'admin'` | Mock `fs_db2`, verify SQL INSERT contains valid Argon2id hash |
| Unit | `log_in_user()` sets flag when pending, skips when not | Mock `fs_user::isInitialSetupPending()` return true/false, assert session state |
| Unit | `login.php` does NOT call `completeInitialSetup()` | Verify removal — no direct test needed; covered by integration |
| Integration | Fresh install → login with `admin/admin` → redirected to force_password_change → change password → normal flow | Requires DB; test in `tests/Integration/` or plugin test |
| Unit | Existing install (fs_var = 'completed') → no flag set | Mock `isInitialSetupPending()` → false, assert session flag not set |

## Migration / Rollout

No migration required. Existing installations have `initial_admin_setup = 'completed'` in `fs_vars` — `isInitialSetupPending()` returns `false`, so the new branch is never entered. The `force_password_change` controller already handles the `insecure_password` reason for existing short-password users.

## Open Questions

- None. All infrastructure exists; changes are purely wiring.
