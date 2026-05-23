# Requirements: FSFramework — Security Audit & Hardening

**Defined:** 2026-05-23
**Milestone:** v0.12.0
**Core Value:** Fix real issues with minimal risk. Every change must be verifiable by the existing test suite and must not break plugins that depend on current behavior.

## v1 Requirements

### Audit — Baseline

- [ ] **AUDIT-01**: Automated scan (9 FSFramework security categories) over core + 5 core plugins; output in `.planning/security/BASELINE-AUDIT.md`
- [ ] **AUDIT-02**: Consolidate prior findings (02-REVIEW, CONCERNS) with open/fixed/deferred status
- [ ] **AUDIT-03**: Classify each finding as CRITICAL / HIGH / MEDIUM / INFO with file:line reference

### CSRF — Mutation Protection

- [ ] **CSRF-01**: `pre_private_core()` blocks invalid POST mutations (not only `$csrf_valid = false`)
- [ ] **CSRF-02**: Remove double-validation that consumes token (e.g. `ventas_clientes`)
- [ ] **CSRF-03**: `admin_home` and core controllers with state-changing POST verify `isCsrfValid()` before acting
- [ ] **CSRF-04**: Regression tests for unified CSRF policy

### Injection & Input

- [ ] **INJ-01**: Zero direct SQL concatenation with user input without `var2str`/prepared statements in scope
- [ ] **INJ-02**: Replace direct `$_GET`/`$_POST`/`$_REQUEST` in scoped controllers with Request/filter helpers
- [ ] **INJ-03**: Audit `|raw` in scoped Twig; justify or remove uses with untrusted data

### Headers & Production

- [ ] **HDR-01**: CSP: plan to remove `unsafe-inline` (nonce-first); minimum: fix `connect-src` for CDNs
- [ ] **HDR-02**: DebugBar restricted by IP or disabled outside local even when `FS_DEBUG=true`
- [ ] **HDR-03**: Verify security headers in `src/Security/SecurityHeaders.php` with tests

### API — api_base Surface

- [ ] **API-01**: Audit auth middleware, rate limit, and CORS in `plugins/api_base/` after core delegation
- [ ] **API-02**: Verify `api.php` returns safe 404 when plugin inactive (no stack leak)
- [ ] **API-03**: Review token/API key management in plugin admin models

### Authentication & Session

- [ ] **AUTH-01**: Verify session regeneration post-login and cookie policy (httponly, secure, samesite)
- [ ] **AUTH-02**: Document and validate legacy password flow in `legacy_support` (argon2id migration)
- [ ] **AUTH-03**: Confirm `SafeRedirect` used correctly in post-action redirects

### Documentation

- [ ] **DOC-01**: Publish `SECURITY.md` with threat model, phase mitigations, and REQ status
- [ ] **DOC-02**: Run `/gsd-secure-phase` at closure of each remediation phase

## v2 Requirements

Deferred to future release.

### Security Hardening

- **SEC-01**: Hard deadline to drop SHA1/MD5 legacy password support
- **SEC-02**: CSP strict mode without legacy inline JS
- **SEC-03**: SonarQube quality gate in CI pipeline

## Out of Scope

| Feature | Reason |
|---------|--------|
| Non-core plugins (tarifario, system_updater, etc.) | Milestone scoped to versioned core plugins only |
| DDEV/CI infrastructure audit | Out of scope except production header behavior |
| Full Twig migration | Ongoing initiative, not security milestone |
| Database migration system | Separate initiative already tracked |
| OAuth/OIDC deep audit | OidcProvider plugin not in core scope |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| AUDIT-01 | Phase 8 | Pending |
| AUDIT-02 | Phase 8 | Pending |
| AUDIT-03 | Phase 8 | Pending |
| CSRF-01 | Phase 9 | Pending |
| CSRF-02 | Phase 9 | Pending |
| CSRF-03 | Phase 9 | Pending |
| CSRF-04 | Phase 9 | Pending |
| AUTH-03 | Phase 9 | Pending |
| INJ-01 | Phase 10 | Pending |
| INJ-02 | Phase 10 | Pending |
| INJ-03 | Phase 10 | Pending |
| HDR-01 | Phase 11 | Pending |
| HDR-02 | Phase 11 | Pending |
| HDR-03 | Phase 11 | Pending |
| API-01 | Phase 11 | Pending |
| API-02 | Phase 11 | Pending |
| API-03 | Phase 11 | Pending |
| AUTH-01 | Phase 11 | Pending |
| AUTH-02 | Phase 11 | Pending |
| DOC-01 | Phase 11 | Pending |
| DOC-02 | Phase 11 | Pending |

**Coverage:**
- v1 requirements: 21 total
- Mapped to phases: 21
- Unmapped: 0 ✓

---
*Requirements defined: 2026-05-23*
*Last updated: 2026-05-23 after milestone v0.12.0 definition*
