# Roadmap: FSFramework — Security Audit & Hardening (v0.12.0)

## Milestones

- 🔄 **v0.12.0 Security Audit & Hardening** — Phases 8-11 (in progress)
- ✅ **v0.11.0 Deferred Items Cleanup** — Phases 4-7 (shipped 2026-05-16) — [Archive](milestones/v0.11.0-ROADMAP.md)
- ✅ **v0.10.8 Tech Debt Cleanup** — Phases 1-3 (shipped 2026-05-16) — [Archive](milestones/v0.10.8-ROADMAP.md)

## Current Milestone Phases

### Phase 8: Security Baseline Audit

**Goal:** Complete prioritized inventory of security findings across core and core plugins.

**Requirements:** AUDIT-01, AUDIT-02, AUDIT-03

**Success Criteria:**
1. Automated scans (9 categories) executed on full scope
2. `.planning/security/BASELINE-AUDIT.md` published with CRITICAL/HIGH/MEDIUM/INFO classification
3. Prior findings from 02-REVIEW and CONCERNS reconciled with current code state
4. Remediation backlog mapped to Phases 9-11

---

### Phase 9: CSRF & Auth Fixes

**Goal:** Unified CSRF blocking policy and secure redirect usage.

**Requirements:** CSRF-01, CSRF-02, CSRF-03, CSRF-04, AUTH-03

**Success Criteria:**
1. Invalid CSRF tokens block POST mutations in `fs_controller`
2. No double-validation token consumption in scoped controllers
3. `admin_home` and `ventas_clientes` enforce CSRF before state changes
4. CSRF regression tests pass in Security suite
5. 02-REVIEW items CR-02, CR-03, WR-03 closed

---

### Phase 10: Injection & Input Hardening

**Goal:** Eliminate CRITICAL/HIGH injection and input risks in scope.

**Requirements:** INJ-01, INJ-02, INJ-03

**Success Criteria:**
1. No open CRITICAL/HIGH SQL injection findings in scoped code
2. Scoped controllers use Request/filter helpers instead of raw superglobals
3. Twig `|raw` usages in scope documented or removed for untrusted data
4. Security test suite passes with new coverage where applicable

---

### Phase 11: Headers, API & Verification

**Goal:** Harden production controls, audit api_base, publish SECURITY.md.

**Requirements:** HDR-01, HDR-02, HDR-03, API-01, API-02, API-03, AUTH-01, AUTH-02, DOC-01, DOC-02

**Success Criteria:**
1. CSP `connect-src` allows required CDNs; unsafe-inline removal plan documented
2. DebugBar restricted outside local environments
3. api_base auth/CORS/rate-limit reviewed; `api.php` safe 404 verified
4. Session/cookie policy verified with tests
5. `SECURITY.md` published with threat model and REQ traceability
6. `/gsd-secure-phase` verification complete for Phases 8-11

## Progress

| Phase | Milestone | Plans | Status | Completed |
|-------|-----------|-------|--------|-----------|
| 8. Security Baseline Audit | v0.12.0 | 0/1 | Pending | — |
| 9. CSRF & Auth Fixes | v0.12.0 | 0/1 | Pending | — |
| 10. Injection & Input Hardening | v0.12.0 | 0/1 | Pending | — |
| 11. Headers, API & Verification | v0.12.0 | 0/1 | Pending | — |

## Requirement Traceability

See [REQUIREMENTS.md](REQUIREMENTS.md) for full REQ-ID mapping.

---
*Roadmap created: 2026-05-23 — milestone v0.12.0*
