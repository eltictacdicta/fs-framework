# Delta for user-auth-methods

## MODIFIED Requirements

### Requirement: UA-01 — login() delegates to fs_login

`fs_user::login(string $nick, string $password): bool` **MUST** autenticar delegando en `fs_login`.

When `log_in_user()` completes credential verification successfully, it **MUST** check `isInitialSetupPending()` **BEFORE** calling `completeInitialSetupIfPending()`. If the initial setup is pending, `log_in_user()` **MUST** set `$_SESSION['force_password_change'] = true` and **MUST NOT** call `completeInitialSetupIfPending()` at that point — the setup completion is deferred to the `force_password_change` controller after the admin chooses a new password.

(Previously: `log_in_user()` called `completeInitialSetupIfPending()` immediately on successful login without checking whether the initial setup was still pending, completing the setup before the user had a chance to change the default password.)

#### Scenario: login() delegates to fs_login

- **GIVEN** `$user->login('admin', 'secret')` invocado
- **WHEN** `fs_login::log_in_user()` verifica credenciales
- **THEN** retorna `true` con `$user->logged_on = true` si son correctas
- **AND** retorna `false` si son inválidas

#### Scenario: login() sets force-password flag when initial setup is pending

- **GIVEN** `isInitialSetupPending()` returns `true`
- **WHEN** `log_in_user()` completes credential verification successfully
- **THEN** `$_SESSION['force_password_change']` is set to `true`
- **AND** `completeInitialSetupIfPending()` is NOT called
- **AND** the user's session will be intercepted by `shouldForcePasswordChange()` on next request

#### Scenario: login() skips force-password flag when initial setup is not pending

- **GIVEN** `isInitialSetupPending()` returns `false`
- **WHEN** `log_in_user()` completes credential verification successfully
- **THEN** the initial-setup branch is skipped entirely
- **AND** normal login flow proceeds without setting the force-password flag
