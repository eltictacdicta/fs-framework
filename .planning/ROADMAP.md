# Roadmap: FSFramework — Tech Debt & Security Remediation

## Milestones

- ✅ **v0.13.0 API Plugin Autonomy** — Phases 12-14 (shipped 2026-05-23) — [Archive](milestones/v0.13.0-ROADMAP.md)
- ✅ **v0.12.0 Security Audit & Hardening** — Phases 8-11 (shipped 2026-05-23) — [Archive](milestones/v0.12.0-ROADMAP.md)
- ✅ **v0.11.0 Deferred Items Cleanup** — Phases 4-7 (shipped 2026-05-16) — [Archive](milestones/v0.11.0-ROADMAP.md)
- ✅ **v0.10.8 Tech Debt Cleanup** — Phases 1-3 (shipped 2026-05-16) — [Archive](milestones/v0.10.8-ROADMAP.md)

## Phases

<details>
<summary>✅ v0.13.0 API Plugin Autonomy (Phases 12-14) — SHIPPED 2026-05-23</summary>

- [x] Phase 12: Plugin Composer Setup — api_base owns swagger-php vendor + autoload hook
- [x] Phase 13: Core Trim & Dependency Removal — jwt/swagger removed from root composer
- [x] Phase 14: Test Migration & Documentation — tests/Api removed; docs aligned

</details>

<details>
<summary>✅ v0.12.0 Security Audit & Hardening (Phases 8-11) — SHIPPED 2026-05-23</summary>

- [x] Phase 8: Security Baseline Audit — BASELINE-AUDIT.md, 21 REQ-IDs scoped
- [x] Phase 9: CSRF & Auth Fixes — pre_private_core blocking, no double validation
- [x] Phase 10: Injection & Input Hardening — fs_filter_input_req in ventas_clientes
- [x] Phase 11: Headers, API & Verification — CSP connect-src, DebugBar IP gate, SECURITY.md

</details>

<details>
<summary>✅ v0.11.0 Deferred Items Cleanup (Phases 4-7) — SHIPPED 2026-05-16</summary>

- [x] Phase 4: Test Suite Recovery (2/2 plans)
- [x] Phase 5: MailService Delegation (1/1 plan)
- [x] Phase 6: Plugin Management Extraction (1/1 plan)
- [x] Phase 7: fs_mysql Decomposition (1/1 plan)

</details>

<details>
<summary>✅ v0.10.8 Tech Debt Cleanup (Phases 1-3) — SHIPPED 2026-05-16</summary>

- [x] Phase 1: PHP Guards & PHPMailer Removal
- [x] Phase 2: strict_types & Plugin Tests
- [x] Phase 3: StealthMode Decomposition

</details>

## Next Milestone

Run `/gsd-new-milestone` to define the next version and requirements.

---
*Last updated: 2026-05-23 after v0.13.0 milestone shipped*
