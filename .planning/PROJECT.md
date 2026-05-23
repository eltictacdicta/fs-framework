# FSFramework — Tech Debt & Security Remediation

## What This Is

Incremental remediation of technical debt and security hardening in the FSFramework codebase. Shipped v0.13.0 (API plugin autonomy) after v0.12.0 (security audit), v0.11.0 (tech debt), and v0.10.8 (initial cleanup).

## Core Value

Fix real issues with minimal risk. Every change must be verifiable by the existing test suite and must not break plugins that depend on current behavior.

## Current Milestone

**None active.** Last shipped: **v0.13.0 API Plugin Autonomy** (2026-05-23).

Start the next cycle with `/gsd-new-milestone`.

## Previous Milestone (v0.13.0)

- **Shipped:** 2026-05-23
- **3 phases (12-14), 13/13 requirements met**
- **23 files changed** in milestone commits (excluding vendor)
- **Root PHPUnit:** 407 tests; **api_base:** 4 tests (isolated suite)
- **Deliverables:** plugin-owned `composer.json` + vendor for swagger-php; core deps trimmed; docs/tests aligned

## Previous Milestone (v0.12.0)

- **Shipped:** 2026-05-23
- **4 phases (8-11), 21/21 requirements met**
- **Security suite:** 140 tests, 0 failures
- **Deliverables:** `SECURITY.md`, `.planning/security/BASELINE-AUDIT.md`, CSRF blocking, CSP/DebugBar hardening

## Previous Milestones

<details>
<summary>v0.11.0 — Deferred Items Cleanup (shipped 2026-05-16)</summary>

- **4 phases, 5 plans, 16 tasks**
- Test suite recovery, MailService delegation, plugin mgmt extraction, fs_mysql decomposition

</details>

<details>
<summary>v0.10.8 — Tech Debt Cleanup (shipped 2026-05-16)</summary>

- **3 phases, 9 plans, 22 tasks**
- PHP guards, PHPMailer removal, strict_types, StealthMode decomposition

</details>

## Requirements

### Validated

- ✓ PHP version guards updated to 8.2 — v0.10.8
- ✓ PHPMailer 5.x + compat bridge eliminated — v0.10.8
- ✓ SHA1/MD5 verification delegated to legacy_support plugin — v0.10.8
- ✓ Security baseline audit (21 REQ-IDs) — v0.12.0
- ✓ CSRF blocking in pre_private_core — v0.12.0
- ✓ Input hardening + SECURITY.md + CSP/DebugBar controls — v0.12.0
- ✓ api_base plugin Composer manifest + isolated vendor — v0.13.0
- ✓ Core composer trimmed (swagger-php, firebase/php-jwt removed) — v0.13.0
- ✓ API tests and docs live in plugin; core contracts-only `src/Api/` — v0.13.0

### Active

- [ ] Database migration system — separate initiative
- [ ] CSP strict mode (remove `unsafe-inline`) — v2 SEC-02
- [ ] Legacy password sunset (SHA1/MD5) — v2 SEC-01
- [ ] Composer merge-plugin for all plugin manifests — v2 API-04
- [ ] JWT in consumer plugins (OidcProvider) — v2 API-05

### Out of Scope

- Queue/job system — infrastructure change, not tech debt
- API versioning beyond v1 — premature until API stabilizes
- Full Twig migration — ongoing, not a debt item
- Moving `src/Api/Attribute/*` into api_base — consumer plugins need core contracts
- Dynamic properties on fs_model/fs_controller — high plugin breakage risk

## Context

- **Version:** 0.13.0 core (see `VERSION`); api_base plugin `2.0.1` when bumped for this release
- **API architecture:** Runtime in `plugins/api_base` (separate git repo); core `api.php` + `src/Api/` contracts
- **API dependencies:** `zircote/swagger-php` only in `plugins/api_base/vendor/`; install via `ddev exec composer install --working-dir=plugins/api_base`
- **Test suite:** Root `ddev exec php vendor/bin/phpunit`; api_base isolated `-c plugins/api_base/phpunit.xml`
- **Philosophy:** Core stays thin, plugins extend. Domain models live in plugins.
- **Legacy compatibility:** `legacy_support` plugin is the designated compatibility layer.
- **Security docs:** `SECURITY.md`, `.planning/security/`

## Constraints

- **Backward compatibility:** Plugins depending on current class names, method signatures, and global state must continue to work
- **No breaking changes to public API:** `fs_model`, `fs_controller`, `fs_db2` interfaces are sacred
- **Test-first:** Add tests before refactoring; run full suite after each change

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Plugin-owned swagger-php vendor | API runtime already in api_base; core should not ship unused deps | ✓ Good — v0.13.0 |
| Keep `src/Api/Attribute` in core | Consumer plugins annotate models with `#[ApiResource]` | ✓ Good — v0.13.0 |
| CSRF block in pre_private_core | Invalid tokens must not run private_core mutations | ✓ Good — v0.12.0 |
| CSP unsafe-inline deferred | AdminLTE inline JS migration required first | ⚠️ v2 SEC-02 |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-05-23 after v0.13.0 milestone shipped*
