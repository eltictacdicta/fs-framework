# standalone-csrf-hardening Specification

## Purpose

Standalone process scripts (backup, update, restore) must validate CSRF tokens without bootstrapping the Symfony container, preventing `SessionManager` initialization from corrupting `$_SESSION['_sf2_attributes']` and dropping the CSRF token stored by the main framework.

## Requirements

### Requirement: Direct Session-Based CSRF Validation

The system MUST validate CSRF tokens by reading `$_SESSION['_sf2_attributes']['_csrf/fs_form']` directly, without invoking `CsrfManager::isValid()` or any Symfony component that initializes `SessionManager`.

#### Scenario: Valid token via direct session read

- GIVEN a session with `_sf2_attributes._csrf/fs_form` containing a stored CSRF token
- WHEN `ensure_request_csrf()` receives the matching randomized token
- THEN validation succeeds without touching Symfony `SessionManager`
- AND `$_SESSION['_sf2_attributes']` remains unmodified

#### Scenario: Missing token — reject immediately

- GIVEN a session where `_sf2_attributes._csrf/fs_form` is absent
- WHEN `ensure_request_csrf()` attempts direct read
- THEN the system SHALL reject with HTTP 403 and `"Token CSRF ausente"`
- AND SHALL NOT fall back to `CsrfManager::isValid()`

#### Scenario: Mismatched token — reject immediately

- GIVEN a session with a stored CSRF token
- WHEN `ensure_request_csrf()` receives a token that fails `hash_equals` comparison
- THEN the system SHALL reject with HTTP 403 and `"Token CSRF inválido"`
- AND SHALL NOT fall back to `CsrfManager::isValid()`

### Requirement: Session Closure Before Long-Running Operations

After CSRF validation and authentication succeed, the system MUST call `session_write_close()` before any long-running operation begins (backup, update, restore). The session SHALL NOT be reopened or written to disk after this point.

#### Scenario: Session closed after authentication in backup start

- GIVEN a successful CSRF validation and authentication in `process_backup.php?action=start`
- WHEN `ensure_session_ready()` returns the session key
- THEN `session_write_close()` MUST have been called
- AND the session file on disk reflects the state BEFORE the backup job runs

#### Scenario: Session NOT written during background job

- GIVEN a background backup job running after `session_write_close()`
- WHEN the job writes to `$_SESSION` or a Symfony service triggers implicit session save
- THEN no session data is persisted to disk
- AND the original CSRF token remains intact for the next browser request

### Requirement: Preserve Session State Integrity

Standalone scripts SHALL NOT modify `$_SESSION['_sf2_attributes']` during execution. The session state on disk MUST be identical before and after the script's CSRF validation phase.

#### Scenario: Authenticated session survives backup request

- GIVEN a user with an active authenticated session in the main framework
- WHEN the user triggers a backup via AJAX (`process_backup.php?action=start`)
- THEN the user's next browser request SHALL NOT fail with `"Token CSRF inválido"`
- AND `$_SESSION` contents remain unchanged for other concurrent requests

### Requirement: Diagnostic Logging on CSRF Failure

When CSRF validation fails, the system SHALL log structured diagnostic information to `error_log` including session status, session ID prefix, cookie names, `_sf2_attributes` availability, and submitted token length.

#### Scenario: CSRF failure logged with session context

- GIVEN a CSRF validation failure
- WHEN `ensure_request_csrf()` rejects the request
- THEN an `error_log` entry is written with session=active|inactive, sf2_attrs=yes|no, stored_token=yes|no, token_len=N
- AND the diagnostic DOES NOT log the full session ID or raw token values

#### Scenario: CLI mode exits cleanly on failure

- GIVEN the script runs in CLI mode (PHP_SAPI === 'cli')
- WHEN CSRF validation fails
- THEN the system SHALL write the error to STDERR and exit with code 1
- AND SHALL NOT emit HTTP headers or JSON responses
