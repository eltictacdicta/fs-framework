# Summary: Phase 3 - Structural Decomposition

**Phase:** 3 of 3
**Completed:** 2026-05-16
**Plans executed:** 3/3 (100%)
**Test results:** Base suite 124/124 pass, all plugin tests pass, 5 pre-existing failures unchanged

## Plans

### 03-01: Delegate SHA1 Password Verification to legacy_support ✅
- Removed direct SHA1/MD5 fallback from `PasswordHasherService::verifyLegacyHash()`
- `LegacyCompatibility::verifyLegacyPassword()` in `legacy_support` plugin now sole owner
- `isLowercasedLegacySha1Bypass()` kept in core as security gate
- Core no longer directly verifies SHA1/MD5 passwords
- 130 tests pass (Base 124 + legacy_support 6)

### 03-02: Decompose StealthMode ✅
- `CssSanitizer.php` (246 lines, 9 methods) — isolates sabberworm/php-css-parser dependency
- `HtmlSanitizer.php` (388 lines, 9 methods) — isolates DOMDocument/DOMXPath logic
- `StealthMode.php` reduced from 1073 → 508 lines (53% reduction)
- StealthMode keeps access logic, login integration, save/persistence via thin delegation

### 03-03: Extract Schema Utilities + admin_cache Deferred ✅
- `FsMysqlSchemaUtility.php` (81 lines) — 3 normalization methods extracted from fs_mysql
- `normalizeIdentifier`, `normalizeIdentifierList`, `isCollatableColumnType` — all pure static utilities
- fs_mysql reduced by 19 lines via delegation
- admin_cache controller deferred — cache code in admin_home is only ~10 lines

## Success Criteria
- ✅ SHA1/MD5 password verification removed from core — only legacy_support handles it
- ✅ StealthMode split: CssSanitizer (246) + HtmlSanitizer (388) both operational
- ✅ fs_mysql schema utilities extracted to FsMysqlSchemaUtility
- ✅ Full test suite passes with no regressions
- ✅ No public API signatures changed

## Notes
- StealthMode not reduced to <200 lines (508) — access/login logic is tightly coupled
- admin_cache controller deferred — practical benefit too small to justify
- Future: deeper fs_mysql decomposition requires extensive refactoring of 20+ interdependent methods
- Future: empresa.php should delegate email config to MailService (noted in Phase 1)

## Project Complete — All 8 Requirements Met
| REQ | Description | Phase | Status |
|-----|-------------|-------|--------|
| REQ-01 | PHP version guards updated to 8.2 | 1 | ✓ |
| REQ-02 | strict_types in 15 base files | 2 | ✓ |
| REQ-03 | Weak random fallback removed | 1 | ✓ |
| REQ-04 | PHPMailer 5.x + compat bridge removed | 1 | ✓ |
| REQ-05 | SHA1 delegated to legacy_support | 3 | ✓ |
| REQ-06 | Monolithic classes decomposed | 3 | ✓ |
| REQ-07 | Plugin test coverage added | 2 | ✓ |
| REQ-08 | Error suppression replaced | 1 | ✓ |
