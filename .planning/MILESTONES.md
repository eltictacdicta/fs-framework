# Milestones

## Completed

### v0.10.8 — Tech Debt Cleanup
- **Shipped:** 2026-05-16
- **Phases:** 3 (9 plans, 22 tasks)
- **8/8 requirements met**
- **107 files changed** (2055 insertions, 10039 deletions)

**Key accomplishments:**
1. PHP version guards 5.6 → 8.2
2. PHPMailer 5.x + compat bridge deleted (61 files)
3. @ error suppression → proper guards (11 files, ~35 ops)
4. strict_types in 15 base files
5. 14 new plugin tests (business_data + catalogo_core)
6. SHA1/MD5 delegated to legacy_support plugin
7. StealthMode decomposed: CssSanitizer + HtmlSanitizer

**Deferred items:** 4 (see STATE.md Deferred Items)

**Archive:** `.planning/milestones/v0.10.8-ROADMAP.md`
