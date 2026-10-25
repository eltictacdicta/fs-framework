# Delta for session-integrity

## MODIFIED Requirements

### Requirement: SI-01 — CSRF token after session regeneration

The CSRF token **MUST** remain valid after `session->migrate(true)` in BOTH session backends: `NativeSessionStorage` (normal flow) and `PhpBridgeSessionStorage` (stealth flow). CSRF storage (`NativeSessionCsrfStorage`) reads and writes the PHP session directly at `$_SESSION['_csrf']`, deliberately bypassing Symfony session bags, and `session_regenerate_id(true)` preserves `$_SESSION` — so the token survives regeneration by construction, not by re-synchronisation. `CsrfManager::getManager()` **MUST** run a token-presence guard once per request that obtains the default token via `getToken()` (which creates it when absent; the guard never calls `refreshToken()`), and the outcome of that check **MUST** be cached for the remainder of the request.

(Previously: CSRF token refreshed after `session->migrate(true)` in `save_session_data()` — implicit NativeSessionStorage-only assumption.)

#### Scenario: CSRF token survives NativeSessionStorage migration

- GIVEN login successful where `save_session_data()` regenerates via `NativeSessionStorage`
- WHEN `session->migrate(true)` yields a new session ID
- THEN the token stored under `$_SESSION['_csrf']` is readable after regeneration
- AND forms loaded post-redirect have a valid CSRF token

#### Scenario: CSRF token survives PhpBridgeSessionStorage migration

- GIVEN login via stealth mode where `PhpBridgeSessionStorage` wraps the session
- WHEN `session->migrate(true)` is called post-login
- THEN `CsrfManager` obtains the default token via `getToken()`, which creates it when absent
- AND the token is persisted directly in `$_SESSION['_csrf']`
- AND subsequent `validateCsrf()` calls match the stored token

#### Scenario: Token refresh guard caches per-request

- GIVEN `CsrfManager::getManager()` was already called this request
- WHEN called again within the same request cycle
- THEN the token presence check is NOT repeated
- AND the cached manager is returned directly

## REMOVED Requirements

(None)

## ADDED Requirements

(None)
