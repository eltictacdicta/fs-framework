---
phase: 14-test-migration-documentation
plan: 01
subsystem: testing
tags: [phpunit, documentation, api_base, tests]
requires:
  - phase: 13-core-trim-dependency-removal
    provides: core without API-only deps
provides:
  - No stale tests/Api in core
  - Docs aligned with plugin-owned API deps and tests
affects:
  - Milestone v0.13.0 closeout
tech-stack:
  patterns:
    - API tests in plugins/api_base/tests/ only
    - Root Plugins suite + isolated phpunit.xml
key-files:
  modified:
    - phpunit.xml
    - AGENTS.md
    - README.md
    - .cursor/rules/fs-framework-testing.mdc
    - .planning/codebase/STACK.md
    - .planning/codebase/INTEGRATIONS.md
    - .planning/codebase/TESTING.md
    - plugins/api_base/AGENTS.md
  removed:
    - tests/Api/
requirements-completed:
  - TEST-01
  - TEST-02
  - TEST-03
  - TEST-04
  - DOC-01
  - DOC-02
duration: 15min
completed: 2026-05-23
---

# Phase 14: Test Migration & Documentation — Plan 01 Summary

**Core `tests/Api/` removed; documentation and PHPUnit config reflect plugin-owned API tests and dependencies.**

## Accomplishments

- Removed empty `tests/Api/` directory (tests already in `plugins/api_base/tests/`)
- Dropped stale `Api` testsuite from root `phpunit.xml`
- Updated `AGENTS.md`, `README.md`, `fs-framework-testing.mdc` — no core jwt/swagger deps; API tests documented under `api_base`
- Updated `.planning/codebase/STACK.md`, `INTEGRATIONS.md`, `TESTING.md` for v0.13.0 layout
- Updated `plugins/api_base/AGENTS.md` — core swagger-php removal complete; Plugins suite cross-link

## Verification

| Check | Result |
|-------|--------|
| `tests/Api/` absent | ✓ |
| No `Api` testsuite in phpunit.xml | ✓ |
| `ddev exec php vendor/bin/phpunit -c plugins/api_base/phpunit.xml` | 4 tests, 10 assertions, OK |
| `ddev exec php vendor/bin/phpunit` | 407 tests, 789 assertions, 13 skipped, OK |
| Doc spot-checks (swagger/jwt out of core tables) | ✓ |

## Notes

- `ChainedAuthAdapterTest` was documentation drift only — never existed in workspace
- api_base tests run via **Plugins** suite at root and via isolated `-c plugins/api_base/phpunit.xml` (intentional dual entry)
