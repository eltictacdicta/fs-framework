# first-login-password-change Specification

## Purpose

Forced password change flow on first login after a fresh install. The system creates the admin user with a known default credential (`admin`/`admin`), sets a session flag on login when initial setup is pending, and redirects the admin to the existing `force_password_change` controller until a valid password (≥8 chars) is chosen.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| FP-01 | `install()` **MUST** create the admin user with password `'admin'` (hashed via Argon2id) | MUST |
| FP-02 | `install()` **MUST NOT** display the temporary password in a flash message | MUST NOT |
| FP-03 | `install()` **MUST** call `markInitialSetupPending()` to register the pending flag in `fs_vars` | MUST |
| FP-04 | `log_in_user()` **MUST** set `$_SESSION['force_password_change'] = true` when `isInitialSetupPending()` returns true | MUST |
| FP-05 | The `shouldForcePasswordChange()` interceptor **MUST** redirect to `force_password_change` controller when the session flag is set, regardless of the requested page | MUST |
| FP-06 | `force_password_change` controller **MUST** display a welcome message when the reason is `'initial_setup'` | MUST |
| FP-07 | `force_password_change` controller **MUST** reject passwords shorter than 8 characters | MUST |
| FP-08 | On successful password change, the controller **MUST** clear `$_SESSION['force_password_change']` and call `completeInitialSetupIfPending()` | MUST |
| FP-09 | The login page **SHOULD** display `admin/admin` as default credentials when initial setup is pending | SHOULD |
| FP-10 | `controller/login.php` **MUST NOT** call `completeInitialSetupIfPending()` before the user has authenticated | MUST NOT |

### Scenario: Fresh install — admin logs in with default credentials

- **GIVEN** a fresh installation where `install()` created admin with password `'admin'`
- **AND** `isInitialSetupPending()` returns `true`
- **WHEN** admin submits `admin`/`admin` on the login page
- **THEN** `log_in_user()` sets `$_SESSION['force_password_change'] = true`
- **AND** the user is redirected to `force_password_change` with reason `'initial_setup'`
- **AND** a welcome message is displayed prompting the password change

### Scenario: Forced password change blocks all navigation

- **GIVEN** `$_SESSION['force_password_change']` is `true`
- **WHEN** the user requests any page other than `force_password_change`
- **THEN** `shouldForcePasswordChange()` intercepts the request
- **AND** redirects to the `force_password_change` controller

### Scenario: Password shorter than 8 characters is rejected

- **GIVEN** the user is on the `force_password_change` page
- **WHEN** they submit a new password with fewer than 8 characters
- **THEN** the controller displays a validation error
- **AND** the session flag remains set; the user is not redirected away

### Scenario: Successful password change clears flag and completes setup

- **GIVEN** the user is on the `force_password_change` page
- **WHEN** they submit a valid password (≥8 characters)
- **THEN** the password is hashed with Argon2id and persisted
- **AND** `$_SESSION['force_password_change']` is unset
- **AND** `completeInitialSetupIfPending()` is called, clearing the `fs_vars` flag
- **AND** the user is redirected to the normal post-login destination

### Scenario: Existing installation — no behavior change

- **GIVEN** an existing installation where `completeInitialSetupIfPending()` was already called
- **AND** `isInitialSetupPending()` returns `false`
- **WHEN** any user logs in
- **THEN** `$_SESSION['force_password_change']` is NOT set by the initial-setup logic
- **AND** normal login flow proceeds without forced password change

### Scenario: Login page shows default credentials hint

- **GIVEN** a fresh install where `isInitialSetupPending()` is `true`
- **WHEN** the login page is rendered
- **THEN** the page displays a message indicating default credentials are `admin`/`admin`
