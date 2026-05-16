# Requirements: FSFramework Tech Debt Remediation

## Validated (Already Done)

- ✓ V-01: Proxy pattern for domain models (almacen, pais, divisa) — `business_data` delegates to `catalogo_core`
- ✓ V-02: `legacy_support` plugin handles SHA1/MD5 password migration on login
- ✓ V-03: `PasswordHasherService` auto-migrates legacy hashes to bcrypt/argon2id
- ✓ V-04: Composer enforces PHP >=8.2 at install time
- ✓ V-05: PHPUnit 11 test suite covers base/ and src/ components

## Active (v1)

| ID | Requirement | Phase | Status |
|----|-------------|-------|--------|
| REQ-01 | PHP version guards updated to 8.2 in entry points | Phase 1 | Complete |
| REQ-02 | `declare(strict_types=1)` added incrementally to base files | Phase 2 | Pending |
| REQ-03 | `install.php` weak random fallback removed | Phase 1 | Complete |
| REQ-04 | PHPMailer 5.x vendored code removed, compat bridge eliminated | Phase 1 | Complete |
| REQ-05 | Legacy SHA1 password support delegated entirely to `legacy_support` plugin | Phase 3 | Pending |
| REQ-06 | Monolithic classes decomposed (StealthMode, fs_mysql, admin_home) | Phase 3 | Pending |
| REQ-07 | Untested plugins receive basic test coverage (business_data, catalogo_core models) | Phase 2 | Pending |
| REQ-08 | Error suppression (`@` operator) replaced with proper error handling | Phase 1 | Complete |

## Out of Scope

- Database migration system — too large, separate initiative
- Queue/job system — infrastructure change, not tech debt
- API versioning beyond v1 — premature until API stabilizes
- Full Twig migration — ongoing, not a debt item
- Dynamic properties on fs_model/fs_controller — high plugin breakage risk

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| REQ-01 | Phase 1 | Complete |
| REQ-02 | Phase 2 | Pending |
| REQ-03 | Phase 1 | Complete |
| REQ-04 | Phase 1 | Complete |
| REQ-05 | Phase 3 | Pending |
| REQ-06 | Phase 3 | Pending |
| REQ-07 | Phase 2 | Pending |
| REQ-08 | Phase 1 | Complete |
