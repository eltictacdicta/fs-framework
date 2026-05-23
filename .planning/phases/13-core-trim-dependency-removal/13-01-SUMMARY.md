---
phase: 13-core-trim-dependency-removal
plan: 01
subsystem: api
tags: [composer, core-trim, swagger-php, jwt]
requires:
  - phase: 12-plugin-composer-setup
    provides: plugin vendor with swagger-php
provides:
  - Core composer without API-only packages
  - Verified contracts-only src/Api/
affects:
  - Phase 14 (docs/test cleanup)
tech-stack:
  removed:
    - zircote/swagger-php (root)
    - firebase/php-jwt (root)
  patterns:
    - OpenApi via plugins/api_base/vendor only
key-files:
  modified:
    - composer.json
    - composer.lock
key-decisions:
  - src/Api audit clean — no runtime removal needed
  - firebase/php-jwt removed unused from core
requirements-completed:
  - DEPS-03
  - CORE-01
  - CORE-02
  - CORE-03
duration: 10min
completed: 2026-05-23
---

# Phase 13: Core Trim & Dependency Removal — Plan 01 Summary

**Root composer slimmed; OpenAPI now resolves exclusively from api_base plugin vendor.**

## Accomplishments

- Audited `src/Api/` — only `Attribute/`, `Auth/Contract/`, `Exception/` (13 files); no orphaned API runtime in `src/`
- Removed `zircote/swagger-php` and `firebase/php-jwt` from root `composer.json`
- Regenerated `composer.lock` (6 packages removed from root vendor)
- Confirmed `api.php` delegates to `api.runtime` with no swagger/JWT references

## Verification

| Check | Result |
|-------|--------|
| `composer validate` | OK |
| `composer show zircote/swagger-php` (root) | not found ✓ |
| `OpenApi\Generator` via plugin vendor | OK |
| Root PHPUnit | 407 tests, 789 assertions, 13 skipped, OK |
| api_base PHPUnit | 4 tests, 10 assertions, OK |

## Audit notes (Task 01)

No code deletions required — prior milestones already moved API runtime to `plugins/api_base/`.

## Deviations

- Created empty `.artifacts/` directory locally so `composer update` succeeds (artifact repository in composer.json); not committed (operational fix only).

## Commits

- `a4f56fb0` — refactor(deps): remove API-only packages from core composer
