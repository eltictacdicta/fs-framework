---
phase: 12-plugin-composer-setup
plan: 01
subsystem: api
tags: [composer, swagger-php, api_base, dependencies]
requires: []
provides:
  - Plugin composer.json with zircote/swagger-php ^6.0
  - Isolated vendor autoload in config/services.php
  - Documented DDEV install path in AGENTS.md
affects:
  - Phase 13 (core composer trim)
tech-stack:
  added:
    - zircote/swagger-php 6.1.2 (plugin vendor)
  patterns:
    - api_base_load_composer_vendor() before legacy model requires
key-files:
  created:
    - plugins/api_base/composer.json
    - plugins/api_base/composer.lock
  modified:
    - plugins/api_base/.gitignore
    - plugins/api_base/config/services.php
    - plugins/api_base/AGENTS.md
key-decisions:
  - Vendor aislado en plugins/api_base/vendor/
  - composer.lock versionado; vendor/ gitignored
  - Autoload hook en services.php, no Init.php
requirements-completed:
  - DEPS-01
  - DEPS-02
  - DEPS-04
duration: 15min
completed: 2026-05-23
---

# Phase 12: Plugin Composer Setup — Plan 01 Summary

**api_base owns swagger-php via isolated Composer vendor loaded at DI registration.**

## Accomplishments

- Created `plugins/api_base/composer.json` with `zircote/swagger-php` ^6.0 and PSR-4 for `Api/`
- Generated and committed `composer.lock` (swagger-php 6.1.2 + transitive deps)
- Updated `.gitignore` to allow lockfile while ignoring `vendor/`
- Added `api_base_load_composer_vendor()` in `config/services.php` (fail-fast `error_log` if missing)
- Documented install command in `plugins/api_base/AGENTS.md`

## Verification

```bash
ddev exec composer install --working-dir=plugins/api_base
ddev exec php -r "require 'plugins/api_base/vendor/autoload.php'; exit(class_exists('OpenApi\\Generator') ? 0 : 1);"
ddev exec php vendor/bin/phpunit -c plugins/api_base/phpunit.xml
```

Results: OpenApi\Generator available; 4 tests, 10 assertions, OK.

## Deviations

None — root `composer.json` left unchanged per phase boundary (Phase 13 removes swagger-php from core).

## Commits

- api_base repo: plugin composer manifest + services autoload hook
