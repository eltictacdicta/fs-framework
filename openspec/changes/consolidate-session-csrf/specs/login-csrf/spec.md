# Delta for login-csrf

## MODIFIED Requirements

### Requirement: LC-02 — CSRF validation during login

The login flow **MUST** validate the CSRF token on POST under the unified session name (`FSSESS_xxx`). Token reads from the session bag **MUST** be consistent regardless of whether the session storage backend is `NativeSessionStorage` or `PhpBridgeSessionStorage`. Legacy `PHPSESSID` cookies **MUST NOT** cause token mismatch after migration.

(Previously: login CSRF validated on POST — no session-storage-backend distinction.)

#### Scenario: CSRF validated under unified session

- GIVEN POST to login form from stealth admin access (`?adminpanel=HASH`)
- WHEN `pre_private_core()` calls `validateCsrf()`
- THEN the token is read from `FSSESS_xxx` session (not `PHPSESSID`)
- AND validation matches the token embedded in the form

#### Scenario: CSRF survives stealth-to-normal transition

- GIVEN user accessed admin via stealth link (`PHPSESSID` cookie)
- WHEN they submit login form (POST with CSRF token)
- THEN session is migrated to `FSSESS_xxx` per SC-04
- AND `validateCsrf()` returns true for the submitted token
- AND the user sees no "Token de seguridad inválido" error

#### Scenario: CSRF token mismatch with dual cookies

- GIVEN request has both `PHPSESSID` (stale) and `FSSESS_xxx` cookies
- WHEN `validateCsrf()` reads the token
- THEN only the `FSSESS_xxx` session data is used
- AND `PHPSESSID` is expired in the response per SC-04

## REMOVED Requirements

(None)

## ADDED Requirements

(None)
