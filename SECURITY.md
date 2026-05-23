# FSFramework Security

**Last updated:** 2026-05-23 (milestone v0.12.0)  
**Scope:** Core framework + versioned core plugins (`business_data`, `catalogo_core`, `clientes_core`, `api_base`, `legacy_support`)

## Threat Model

| Threat | Surface | Mitigation |
|--------|---------|------------|
| CSRF on admin mutations | Legacy controllers, Twig forms | `CsrfManager` + `validateCsrf()` in `pre_private_core()` blocks invalid POST; `requireCsrf()` / `requireMutationCsrf()` for soft-mode guards |
| SQL injection | `fs_model`, plugins | Prepared/`var2str()` patterns; static analysis in `tests/Security/SqlInjectionPreventionTest.php` |
| XSS | Twig templates | Default auto-escape; `\|raw` only for trusted framework output |
| Session fixation | Login flow | `SessionManager::regenerateId()` after authentication |
| Weak passwords | Legacy installs | `PasswordHasherService` (argon2id/bcrypt); legacy SHA1/MD5 only in `legacy_support` with migration on login |
| Open redirect | Post-action redirects | `SafeRedirect::validate()` / `SafeRedirect::redirect()` |
| API abuse | `api.php` / `api_base` | Bearer auth, rate limiting, CORS middleware |
| Information disclosure | DebugBar, error handlers | DebugBar local-IP only; stack traces only when `FS_DEBUG=true` |

## Security Controls

### CSRF

- Tokens via Symfony CSRF (`src/Security/CsrfManager.php`)
- Forms: `{{ csrf_field() }}` in Twig
- Controllers: `pre_private_core()` validates POST; invalid tokens skip `private_core()` in strict mode
- Soft migration: `FS_CSRF_SOFT=true` logs warnings; mutations must still call `requireCsrf()` or `requireMutationCsrf()`

### Content Security Policy

- Default policy in `src/Security/SecurityHeaders.php`
- `connect-src` includes required CDNs for source maps
- `unsafe-inline` retained temporarily for legacy inline JS — removal tracked as v2 requirement SEC-02
- Override via `FS_CSP_POLICY` or disable via `FS_DISABLE_CSP`

### DebugBar

- Renders only when `FS_DEBUG=true` **and** client IP is local (`fs_is_local_ip()`)
- Override: `FS_DEBUGBAR_ALLOW_REMOTE=true` (not recommended in production)

### API (`plugins/api_base`)

- Entry: `api.php` → `api.runtime` service
- Disabled response: HTTP 404 JSON without stack trace
- Middleware chain: Auth → RateLimit → CORS (plugin-owned)

### Legacy Passwords

- Core: `PasswordHasherService` — no MD5/SHA1 verification
- Plugin: `legacy_support/LegacyCompatibility.php` — verifies legacy hashes, migrates to modern hash on successful login
- Offline remediation: `scripts/remediate-legacy-passwords.php`

## Milestone v0.12.0 Requirement Status

| REQ | Status | Phase |
|-----|--------|-------|
| AUDIT-01..03 | Complete | 8 |
| CSRF-01..04 | Complete | 9 |
| AUTH-03 | Complete | 9 |
| INJ-01..03 | Complete | 10 |
| HDR-01..03 | Complete | 11 |
| API-01..03 | Complete | 11 |
| AUTH-01..02 | Complete | 11 |
| DOC-01 | Complete | 11 |
| DOC-02 | Complete | 11 |

## Audit Artifacts

- Baseline: [`.planning/security/BASELINE-AUDIT.md`](.planning/security/BASELINE-AUDIT.md)
- Prior review: [`.planning/phases/02-code-review-command/02-REVIEW.md`](.planning/phases/02-code-review-command/02-REVIEW.md)
- Agent checklist: [`.cursor/skills/fsframework-security-review/SKILL.md`](.cursor/skills/fsframework-security-review/SKILL.md)

## Reporting Vulnerabilities

Report security issues privately to the project maintainer. Do not open public issues for unpatched vulnerabilities.

## Deferred Hardening (v2)

- Remove CSP `unsafe-inline` after AdminLTE inline JS migration (SEC-02)
- Drop SHA1/MD5 legacy password support with deadline (SEC-01)
- SonarQube gate in CI (SEC-03)
