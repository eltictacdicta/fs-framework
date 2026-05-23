# Milestones

## v0.13.0 API Plugin Autonomy (Shipped: 2026-05-23)

**Phases completed:** 3 phases (Phases 12-14), 13/13 requirements met

**Key accomplishments:**

- api_base owns swagger-php via isolated Composer vendor loaded at DI registration.
- Root composer slimmed; OpenAPI now resolves exclusively from api_base plugin vendor.
- Core `tests/Api/` removed; documentation and PHPUnit config reflect plugin-owned API tests and dependencies.

**Deferred to v2:** Composer merge-plugin (API-04), JWT in consumer plugins (API-05)

**Archive:** `.planning/milestones/v0.13.0-ROADMAP.md`, `.planning/milestones/v0.13.0-REQUIREMENTS.md`

## v0.12.0 Security Audit & Hardening (Shipped: 2026-05-23)

**Phases completed:** 4 phases (Phases 8-11), 21/21 requirements met

**Key accomplishments:**

- Security baseline audit across core + 5 core plugins (`.planning/security/BASELINE-AUDIT.md`)
- CSRF policy unified: `pre_private_core()` blocks invalid POST; no double token consumption
- Input hardening: `ventas_clientes` migrated from `$_GET` to `fs_filter_input_req()`
- CSP `connect-src` fixed for CDN source maps; DebugBar restricted to local IPs
- `SECURITY.md` published with threat model and REQ traceability
- Security test suite: 140 tests, 0 failures

**Deferred to v2:** CSP strict (no `unsafe-inline`), SHA1/MD5 sunset, SonarQube CI gate

**Archive:** `.planning/milestones/v0.12.0-ROADMAP.md`, `.planning/milestones/v0.12.0-REQUIREMENTS.md`

---

## v0.11.0 Deferred Items Cleanup (Shipped: 2026-05-16)

**Phases completed:** 4 phases, 5 plans, 16 tasks

**Key accomplishments:**

- 3 test isolation/environment failures fixed: static state leaks, incomplete init reset, and hardcoded path assertion
- ResourceTransformer tests gracefully skip when api_base plugin is unavailable — 0 errors in full suite
- empresa mail methods delegated to MailService — new_mail(), mail_connect(), can_send_mail() all use MailService internally
- Plugin management extracted from admin_home into PluginInstaller and PluginActionHandler — admin_home reduced from 1053 to 698 lines
- 3 new classes extracted from 1577-line monolithic fs_mysql — type normalization, schema introspection, and DDL generation now in dedicated classes

---

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
