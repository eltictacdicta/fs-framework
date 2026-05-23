# Security Baseline Audit — v0.12.0

**Date:** 2026-05-23  
**Scope:** `base/`, `controller/`, `src/`, `themes/AdminLTE/`, `plugins/business_data`, `catalogo_core`, `clientes_core`, `api_base`, `legacy_support`  
**Method:** FSFramework security-review skill (9 categories) + reconciliation with 02-REVIEW.md and CONCERNS.md

## Executive Summary

| Severity | Open | Fixed in milestone | Deferred |
|----------|------|-------------------|----------|
| CRITICAL | 1 | 2 | 0 |
| HIGH | 3 | 1 | 0 |
| MEDIUM | 5 | 2 | 3 |
| INFO | 4 | 0 | 4 |

Primary gaps: CSRF validation did not block `private_core()` on invalid tokens; double CSRF re-validation broke `requireCsrf()` after `pre_private_core()`; CSP `connect-src` too restrictive; DebugBar exposed on any host when `FS_DEBUG=true`.

## Prior Findings Reconciliation

| ID | Source | Status | Notes |
|----|--------|--------|-------|
| CR-01 | 02-REVIEW | **Fixed** | `SafeRedirect::redirect()` exists at `src/Security/SafeRedirect.php:152` |
| CR-02 | 02-REVIEW | **Fixed** | `ventas_clientes` uses `requireMutationCsrf()` (no double `CsrfManager::isValid`) |
| CR-03 | 02-REVIEW | **Open → Phase 9** | `pre_private_core()` validated but did not block mutations |
| CR-04 | 02-REVIEW | **Fixed** | `nuevo_cliente()` propagates model errors to controller |
| WR-01 | 02-REVIEW | **Open → Phase 11** | CSP `connect-src 'self'` blocks CDN source maps |
| WR-02 | 02-REVIEW | **Deferred** | CSP `unsafe-inline` — requires JS migration (documented in SECURITY.md) |
| WR-03 | 02-REVIEW | **Partial → Phase 9** | `admin_home` used `requireCsrf()` after token consumed |
| WR-04 | 02-REVIEW | **Open → Phase 10** | `ventas_clientes` direct `$_GET` for offset/orden |
| WR-05 | 02-REVIEW | **Deferred** | FK sync order — schema design, not exploitable |
| CONCERNS DebugBar | CONCERNS | **Open → Phase 11** | No IP restriction when FS_DEBUG=true |
| CONCERNS legacy passwords | CONCERNS | **Accepted** | Migration on login via legacy_support; documented |
| API core delegation | Note 2026-05-23 | **Review → Phase 11** | Runtime in `plugins/api_base/`; `api.php` safe 404 OK |

## Findings by Category

### 1. SQL Injection

| Sev | File | Issue | Phase |
|-----|------|-------|-------|
| INFO | Scoped models | Models use `var2str()` in save paths | — |
| INFO | `ventas_clientes` | `orden` whitelisted before SQL ORDER BY | Phase 10 harden input layer |

No CRITICAL SQL injection patterns found in scoped code (manual review of dynamic query builders).

### 2. XSS

| Sev | File | Issue | Phase |
|-----|------|-------|-------|
| MEDIUM | `themes/AdminLTE/view/login/default.html.twig` | `get_errors()\|raw` — framework messages | Accept (escaped at source) |
| MEDIUM | `themes/AdminLTE/view/admin_home_plugins.html.twig` | `error_msg\|raw` — plugin compatibility msgs | Audit plugin source |
| INFO | Multiple templates | `fsc.url()\|raw` — internal URL helper | Accept |

### 3. CSRF

| Sev | File | Issue | Phase |
|-----|------|-------|-------|
| **CRITICAL** | `base/fs_controller.php:907` | `validateCsrf()` did not skip `private_core()` on failure | **Phase 9** |
| **HIGH** | `controller/admin_home.php:341` | `requireCsrf()` re-validated consumed token | **Phase 9** |
| HIGH | `plugins/clientes_core/extras/clientes_controller.php:88` | `requireMutationCsrf()` called `requireCsrf()` twice | **Phase 9** |

### 4. Password Handling

| Sev | File | Issue | Phase |
|-----|------|-------|-------|
| INFO | `plugins/legacy_support/` | SHA1/MD5 verification isolated | Accept — AUTH-02 documents migration |
| INFO | `src/Security/PasswordHasherService.php` | Uses bcrypt/argon2id | Pass |

### 5. File Uploads

| Sev | File | Issue | Phase |
|-----|------|-------|-------|
| INFO | `controller/admin_home.php` | Plugin ZIP upload via `PluginActionHandler` | Review in plugin mgmt (existing service) |

### 6. Open Redirects

| Sev | File | Issue | Phase |
|-----|------|-------|-------|
| INFO | `controller/admin_agentes.php` | Uses `SafeRedirect::redirect()` | Pass |
| INFO | `src/Security/SafeRedirect.php` | Validates internal paths | Pass |

### 7. Session Security

| Sev | File | Issue | Phase |
|-----|------|-------|-------|
| INFO | `src/Security/SessionManager.php` | Regeneration on login tested | Pass — AUTH-01 |
| MEDIUM | `base/fs_maintenance_mode.php` | `@session_start` suppression | Deferred (tech debt) |

### 8. Input Validation

| Sev | File | Issue | Phase |
|-----|------|-------|-------|
| HIGH | `plugins/clientes_core/controller/ventas_clientes.php:50-64` | Direct `$_GET` access | **Phase 10** |

### 9. Error Exposure

| Sev | File | Issue | Phase |
|-----|------|-------|-------|
| **HIGH** | `src/Core/DebugBar.php:87` | Renders SQL/logs when FS_DEBUG=true on any IP | **Phase 11** |
| INFO | `api.php:101-104` | Stack trace only when FS_DEBUG=true | Accept |

### API Surface (api_base)

| Sev | File | Issue | Phase |
|-----|------|-------|-------|
| INFO | `plugins/api_base/Api/Middleware/AuthMiddleware.php` | Bearer token required | Pass |
| INFO | `plugins/api_base/Api/Middleware/CorsMiddleware.php` | Configurable CORS | Review Phase 11 |
| INFO | `plugins/api_base/Api/Middleware/RateLimitMiddleware.php` | Rate limiting present | Pass |
| INFO | `api.php:81-90` | Safe JSON 404 without stack | Pass |

## Remediation Backlog

| Phase | REQ-IDs | Focus |
|-------|---------|-------|
| 9 | CSRF-01..04, AUTH-03 | Block mutations, fix double-validation |
| 10 | INJ-01..03 | Input helpers, `|raw` audit |
| 11 | HDR/API/AUTH/DOC | CSP, DebugBar, SECURITY.md |

---
*Generated: Phase 8 — Security Baseline Audit*
