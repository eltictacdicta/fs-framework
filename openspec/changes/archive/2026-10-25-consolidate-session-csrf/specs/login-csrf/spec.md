# Delta for login-csrf

> **Merge note — language drift (deliberate):** the canonical
> `openspec/specs/login-csrf/spec.md` is written in Spanish while this delta is
> in English. This delta intentionally stays in English and the canonical file is
> intentionally left untranslated in this change, so that the merge of `LC-02`
> below is a conscious decision rather than an accidental language switch. Any
> future translation of the canonical file should be its own change.

## MODIFIED Requirements

### Requirement: LC-02 — CSRF validation during login

The login flow **MUST** validate the CSRF token on POST under the unified session name (`FSSESS_xxx`). CSRF token reads **MUST** be consistent regardless of whether the session storage backend is `NativeSessionStorage` or `PhpBridgeSessionStorage`, because the CSRF storage reads the PHP session directly (`$_SESSION['_csrf']`) instead of a Symfony session bag. Legacy `PHPSESSID` cookies **MUST NOT** cause a token mismatch after the legacy cookie is discarded (SC-04).

(Previously: login CSRF validated on POST — no session-storage-backend distinction.)

#### Scenario: CSRF validated under unified session

- GIVEN POST to login form from stealth admin access (`?adminpanel=HASH`)
- WHEN `pre_private_core()` calls `validateCsrf()`
- THEN the token is read from `FSSESS_xxx` session (not `PHPSESSID`)
- AND validation matches the token embedded in the form

#### Scenario: CSRF survives stealth-to-normal transition

- GIVEN user accessed admin via stealth link (`PHPSESSID` cookie)
- WHEN they submit login form (POST with CSRF token)
- THEN the request runs on the `FSSESS_xxx` session opened by `StealthMode`
- AND any stale `PHPSESSID` is discarded without being read
- AND `validateCsrf()` returns true for the submitted token
- AND the user sees no "Token de seguridad inválido" error

#### Scenario: CSRF token mismatch with dual cookies

- GIVEN request has both `PHPSESSID` (stale) and `FSSESS_xxx` cookies
- WHEN `validateCsrf()` reads the token
- THEN only the `FSSESS_xxx` session data is used
- AND `PHPSESSID` is expired in the response

## REMOVED Requirements

(None)

## ADDED Requirements

(None)
