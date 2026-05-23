# Phase 12: Plugin Composer Setup - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-23
**Phase:** 12-Plugin Composer Setup
**Areas discussed:** Vendor strategy, Package scope, Autoload hook

---

## Vendor strategy

| Option | Description | Selected |
|--------|-------------|----------|
| Vendor aislado | `plugins/api_base/vendor/` + composer install in plugin | ✓ |
| Merge en raíz | wikimedia/composer-merge-plugin | |
| Solo manifest | Deps installed from root composer only | |

**User's choice:** Vendor aislado

| Option | Description | Selected |
|--------|-------------|----------|
| Solo autoload del plugin | require plugin vendor/autoload.php; core unchanged | ✓ |
| Prepend autoload | Plugin classmap priority | |
| Tú decides | Safest conflict avoidance | |

**User's choice:** Solo autoload del plugin

| Option | Description | Selected |
|--------|-------------|----------|
| Commitear composer.lock | Reproducibility in CI/clones | ✓ |
| Ignorar lock y vendor | Current .gitignore behavior | |
| Lock in repo, vendor generated | | |

**User's choice:** Commitear composer.lock (requires updating .gitignore)

| Option | Description | Selected |
|--------|-------------|----------|
| Fail-fast | error_log + degrade OpenAPI without production stack trace | ✓ |
| Graceful degrade only | class_exists pattern only | |
| Hard fail api.php | 503 if vendor missing | |

**User's choice:** Fail-fast

---

## Package scope

| Option | Description | Selected |
|--------|-------------|----------|
| Solo swagger-php | Only API-exclusive package in plugin require | ✓ |
| swagger + phpunit dev | | |
| swagger + symfony/validator copy | | |

**User's choice:** Solo zircote/swagger-php

**Notes (freeform on version):** En el core ya no es necesario mantener swagger-php; el plugin debe mantener compatibilidad con contratos del core; eliminación del core en Phase 13.

| Option | Description | Selected |
|--------|-------------|----------|
| PSR-4 explícito en plugin | `FSFramework\Plugins\api_base\` → `Api/` | ✓ |
| Sin PSR-4 duplicado | require only | |
| Classmap legacy | model/, controller/ | |

**User's choice:** PSR-4 explícito en plugin

| Option | Description | Selected |
|--------|-------------|----------|
| Phase 12 swagger only; JWT Phase 13 | | ✓ |
| Include JWT in plugin now | | |
| Skip JWT forever | | |

**User's choice:** Phase 12 solo swagger-php; firebase/php-jwt en Phase 13

---

## Autoload hook

| Option | Description | Selected |
|--------|-------------|----------|
| config/services.php | Before api.runtime registration | ✓ |
| Init.php | On plugin activation | |
| Both with idempotent guard | | |

**User's choice:** services.php

| Option | Description | Selected |
|--------|-------------|----------|
| file_exists guard | require_once if present; else error_log | ✓ |
| Helper function | api_base_load_composer() | |
| RuntimeException | Visible in FS_DEBUG | |

**User's choice:** file_exists guard

| Option | Description | Selected |
|--------|-------------|----------|
| Before legacy models | vendor → api_base_load_legacy_models() | ✓ |
| After ensure_tables | | |
| Top of closure | | |

**User's choice:** Before legacy models

| Option | Description | Selected |
|--------|-------------|----------|
| Solo AGENTS.md plugin | ddev exec composer install --working-dir=plugins/api_base | ✓ |
| Plugin + root AGENTS.md | | |
| Makefile target | | |

**User's choice:** Solo AGENTS.md del plugin

---

## Claude's Discretion

- Patch version when generating initial composer.lock (^6.0 constraint).
- Optional classmap for legacy non-namespaced classes in plugin composer.

## Deferred Ideas

- Core composer cleanup (swagger-php, jwt) → Phase 13
- Composer merge-plugin → v2 API-04
- Makefile install target → declined
- Root AGENTS.md install note → declined
