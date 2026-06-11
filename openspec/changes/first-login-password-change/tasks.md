# Tasks: First-Login Password Change

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~90–110 (additions + deletions) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | ask-always |
| Chain strategy | N/A |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: N/A
400-line budget risk: Low

## Phase 1: Core Wiring (install → login → force-change)

- [x] 1.1 **`model/core/fs_user.php::install()`** — Replace random password with `'admin'`. Remove `$defaultPassword` generation (L216), `$installMessage` block (L218–222), `$this->new_message()` call (L224), and session flash persistence block (L226–235). Change `password_hash($defaultPassword, ...)` → `password_hash('admin', ...)`. Keep `clean_cache()` and `markInitialSetupPending()`.
  - Verify: `install()` returns INSERT SQL with Argon2id hash of `'admin'`; no flash message or session writes.

- [x] 1.2 **`base/fs_login.php::log_in_user()`** — At L452, replace `$this->completeInitialSetupIfPending()` with: if `isInitialSetupPending()` → set `$_SESSION['force_password_change'] = true` + reason `'initial_setup'`, skip completion; else call `completeInitialSetupIfPending()` as before.
  - Verify: Fresh install login sets both session keys; existing install login does not.

- [x] 1.3 **`controller/login.php`** — Remove `\fs_user::completeInitialSetup()` at L178 and L193. Update `showInitialSetupMessageIfPending()` (L114–125): replace "contraseña temporal que se mostró durante la instalación" with "contraseña por defecto: `admin` / `admin`". Update docblock (L108–112) to remove temp-password references.
  - Verify: No `completeInitialSetup()` calls remain in login.php; login page shows `admin/admin` hint when pending.

- [x] 1.4 **`themes/AdminLTE/view/force_password_change.html.twig`** — Replace the static warning alert (L33–37) with a conditional: when `fsc.change_reason == 'initial_setup'` show a welcome/success alert with `trans('force-password-change-reason-initial-setup')`; otherwise keep the existing insecure-password warning.
  - Verify: Template renders welcome message for `initial_setup`, warning for `insecure_password`.

- [x] 1.5 **Translation keys** — Add `force-password-change-reason-initial-setup` to `translations/messages.es.yaml` and `translations/messages.en.yaml`.
  - ES: `"¡Bienvenido! Por seguridad, elige una nueva contraseña para tu cuenta de administrador."`
  - EN: `"Welcome! For security, please choose a new password for your admin account."`

## Phase 2: Cleanup of Old Temp Password System

- [x] 2.1 **`model/core/fs_user.php`** — Update docblock on `isInitialSetupPending()` (L269): replace "contraseña temporal" with "default password". No logic changes.
  - Verify: Grep for "contraseña temporal" returns zero results in production code.

- [x] 2.2 **Grep audit** — Search entire codebase for `contraseña temporal`, `temp.*password`, `installMessage`, `flash_messages.*install`. Confirm all remaining references are in test files or are false positives. Remove any dead code found.
  - Verify: Zero production-code hits for temp-password display patterns.

## Phase 3: Tests

- [x] 3.1 **Update `tests/Core/LoginInitialCredentialsMessageTest.php`** — Update class docblock (L32–33) to remove "contraseña temporal" references. Tests themselves remain valid (they test flag state, not display).
  - Verify: `ddev exec php vendor/bin/phpunit tests/Core/LoginInitialCredentialsMessageTest.php` passes.

- [x] 3.2 **New test: `tests/Core/FirstLoginForcePasswordTest.php`** — Test that when `isInitialSetupPending()` returns true, the login flow sets `$_SESSION['force_password_change']` and does NOT call `completeInitialSetup()`. Mock `fs_user` static methods via anonymous class or namespace aliasing. Cover: (a) pending → flag set, (b) not pending → flag not set, (c) existing install (`completed`) → no behavior change.
  - Verify: `ddev exec php vendor/bin/phpunit tests/Core/FirstLoginForcePasswordTest.php` passes.

- [x] 3.3 **Run full test suite** — `ddev exec php vendor/bin/phpunit` — confirm no regressions in Base, Core, Components, or Plugins suites.
  - Verify: All tests green (2 pre-existing failures in system_updater unrelated to this change).

## Phase 4: Verification

- [ ] 4.1 **Manual smoke test** (if DDEV available) — Fresh install → login `admin/admin` → redirected to `force_password_change` with welcome message → submit new password (≥8 chars) → redirected to normal flow → flag cleared in `fs_vars`.
  - Verify: Full end-to-end flow works as described in spec scenarios.
