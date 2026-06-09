# Delta for session-integrity

## MODIFIED Requirements

### Requirement: SI-01 — CSRF token after session regeneration

The CSRF token **MUST** survive `session->migrate(true)` in BOTH session backends: `NativeSessionStorage` (normal flow) and `PhpBridgeSessionStorage` (stealth flow). After `PhpBridgeSessionStorage` wraps the session, `CsrfManager` **MUST** verify token presence in the bag; if absent, it **MUST** refresh the token.

(Previously: CSRF token refreshed after `session->migrate(true)` in `save_session_data()` — implicit NativeSessionStorage-only assumption.)

#### Scenario: CSRF token survives NativeSessionStorage migration

- GIVEN login successful where `save_session_data()` regenerates via `NativeSessionStorage`
- WHEN `session->migrate(true)` yields a new session ID
- THEN CSRF token in session bag is readable after regeneration
- AND forms loaded post-redirect have a valid CSRF token

#### Scenario: CSRF token survives PhpBridgeSessionStorage migration

- GIVEN login via stealth mode where `PhpBridgeSessionStorage` wraps the session
- WHEN `session->migrate(true)` is called post-login
- THEN `CsrfManager` verifies token is present in the bag
- AND if absent, a fresh token is generated and stored immediately
- AND subsequent `validateCsrf()` calls match the synchronized token

#### Scenario: Token refresh guard caches per-request

- GIVEN `CsrfManager::getManager()` was already called this request
- WHEN called again within the same request cycle
- THEN the token presence check is NOT repeated
- AND the cached manager is returned directly

## REMOVED Requirements

(None)

## ADDED Requirements

(None)
