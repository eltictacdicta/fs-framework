# Proposal: First-Login Password Change

## Intent

After `install()`, the admin user gets a random 16-char password that is shown once in a flash message. This is fragile — if the admin misses it, they're locked out. We change the flow so the default password is `admin` (known, simple) and the system **forces** a password change on first login via the existing `force_password_change` interceptor. The infrastructure is 90% built; we just need to wire it correctly.

## Scope

### In Scope
- Change `install()` default password from random to `admin`
- Wire `isInitialSetupPending()` check in `log_in_user()` to set `$_SESSION['force_password_change']`
- Remove premature `completeInitialSetup()` calls from `controller/login.php`
- Add `initial_setup` reason handling in `force_password_change` controller
- Update login page messaging to reference `admin/admin`

### Out of Scope
- New controllers or templates (they already exist)
- Password hashing algorithm changes (Argon2id stays)
- Self-service password reset flows
- Multi-factor authentication
- Plugin-level password policies

## Capabilities

### New Capabilities
- `first-login-password-change`: Forced password change flow on first login after install. Covers the session flag wiring, controller messaging, and default credential setup.

### Modified Capabilities
- `user-auth-methods`: Login flow now checks `isInitialSetupPending()` before completing initial setup, setting the force-password-change session flag when pending.

## Approach

Minimal wiring change (~20 lines across 4 files):

1. **`model/core/fs_user.php::install()`**: Replace random password with `'admin'`, remove temp-password flash message, keep `markInitialSetupPending()`
2. **`base/fs_login.php::log_in_user()`**: Before `completeInitialSetupIfPending()`, check `isInitialSetupPending()`. If pending → set `$_SESSION['force_password_change'] = true`
3. **`controller/login.php`**: Remove premature `completeInitialSetup()` calls; update `showInitialSetupMessageIfPending()` to show `admin/admin` credentials
4. **`controller/force_password_change.php`**: Handle reason `'initial_setup'` with welcome messaging

The existing `shouldForcePasswordChange()` interceptor in `fs_controller.php` already redirects ALL page requests when the session flag is set — no changes needed there.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `model/core/fs_user.php` | Modified | `install()` default password + remove flash message |
| `base/fs_login.php` | Modified | Add initial-setup-pending check before completing setup |
| `controller/login.php` | Modified | Remove premature setup completion, update messaging |
| `controller/force_password_change.php` | Modified | Add `initial_setup` reason with welcome messaging |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Existing installs already have `completed` flag in `fs_vars` | Low | `isInitialSetupPending()` returns false; no behavior change for existing installs |
| `admin` password < 8 chars triggers validation | Intended | User is forced to change it; validation requires ≥8 chars |
| Autologin path bypasses force-password flag | Low | Autologin also calls `log_in_user()` or equivalent; flag must be set there too |
| `completeInitialSetup()` called twice (idempotent) | None | Already idempotent; removing duplicate calls is safe cleanup |

## Rollback Plan

Revert the 4-file changes. The `force_password_change` controller/template remain in codebase but unused for initial setup. Existing `fs_vars` flag state is untouched by rollback.

## Dependencies

- None. All infrastructure (`force_password_change` controller, template, session interceptor, `fs_vars` flag mechanism) already exists.

## Success Criteria

- [ ] Fresh install: admin logs in with `admin`/`admin`, is redirected to password change page
- [ ] Admin cannot access any other page until password is changed (≥8 chars)
- [ ] After password change, normal navigation works; flag is cleared
- [ ] Existing installations: no behavior change (flag already `completed`)
- [ ] All existing tests pass; new test covers the initial-setup-pending → force-password flow
