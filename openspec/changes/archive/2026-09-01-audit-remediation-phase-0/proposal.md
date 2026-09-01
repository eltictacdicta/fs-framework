# Proposal: Test Suite Determinism Remediation (Audit Phase 0)

## Intent

External audit (code-verified) found the test suite is nondeterministic and partially broken: the bootstrap loads a machine-local `config.php`, ships an invalid `FS_SECRET_KEY`, the Symfony PHPUnit bridge is a decorative no-op, five Security tests depend on an absent plugin, and the Plugins suite glob ingests vendored third-party tests breaking `--list-suites` (exit 255). A green, deterministic CI suite is the prerequisite for all later security remediation phases — without it we cannot distinguish regressions from baseline noise.

## Scope

### In Scope

- `tests/bootstrap.php`: define canonical test constants unconditionally; never require `config.php`; fix `FS_SECRET_KEY` to ≥32 chars (required by `src/Security/SecretManager.php:96-99`); register `symfony/phpunit-bridge` autoload.
- `composer.json` / `composer.lock`: add `symfony/phpunit-bridge` as dev dependency (commit `vendor/` together per project policy).
- `phpunit.xml`: replace unbounded `plugins` directory glob with a deterministic discovery targeting real plugin test dirs (e.g. `plugins/*/tests/`), excluding vendored trees and `_back` directories.
- `tests/Security/*`: conditionally skip legacy SHA1 password tests when the `legacy_support` plugin is not active (test misalignment, not a product bug — `base/fs_login.php:426-434` correctly delegates to the plugin).
- Run full suite before/after to catch expectation shifts caused by constant changes.

### Out of Scope

- CSRF fixes, decoupling `base/` from plugins, OpenSpec governance migration.
- Symfony HTTP lifecycle, PostgreSQL coverage, `Container::compile`.
- Any production code change (`base/`, `src/`, `model/`, `controller/`) — tests and harness only.

## Capabilities

### New Capabilities

- `test-harness-determinism`: requirements for deterministic bootstrap constants, plugin-independent SecretManager in tests, bridge-enforced deprecation reporting, and bounded plugin test discovery.

### Modified Capabilities

None.

## Approach

1. Harden `tests/bootstrap.php`: unconditional constants, remove `config.php` require, register bridge autoload.
2. `ddev exec composer require --dev symfony/phpunit-bridge`; commit `composer.json`, `composer.lock`, and `vendor/` changes atomically.
3. Scope the Plugins suite glob to `plugins/*/tests` with `suffix="Test.php"`; document per-plugin isolated execution (`-c plugins/<name>/phpunit.xml`) as the fallback for plugins lacking a `tests/` dir. Simplest deterministic option wins; no new tooling.
4. Add `markTestSkipped` guard to the 5 legacy SHA1 tests in `tests/Security/` keyed on `legacy_support` plugin presence.
5. Verify: full suite green, `--list-suites` exit 0, repeat run produces identical result.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `tests/bootstrap.php` | Modified | Canonical constants, no config.php, bridge autoload, valid FS_SECRET_KEY |
| `composer.json` / `composer.lock` | Modified | Add symfony/phpunit-bridge (dev) |
| `vendor/` | Modified | Versioned per policy; commit with lockfile |
| `phpunit.xml` | Modified | Scoped Plugins suite glob |
| `tests/Security/PasswordHasherServiceTest.php` (and related) | Modified | Conditional skip for legacy SHA1 tests |
| `tests/Base/*` | Verified only | Confirm no expectation drift from constant changes |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| `composer.lock` drift → broken fresh clone | Medium | Commit `vendor/` + lock together (project policy); verify `ddev exec composer install --dry-run` |
| Constant changes alter test expectations | Medium | Full suite run before/after; diff failures |
| Glob change silently drops plugin tests | Medium | Compare test counts before/after (42 plugin test files expected); document per-plugin execution |
| Bridge makes hidden deprecations fail suite | Low | `SYMFONY_DEPRECATIONS_HELPER=weak` retained initially |

## Rollback Plan

Single revert of the change commit restores bootstrap, phpunit.xml, composer files and `vendor/`. No DB or runtime data touched; no production code affected.

## Dependencies

- DDEV running; Composer inside container (`ddev exec composer ...`).
- `symfony/phpunit-bridge ^7.4` from packagist (network access during `composer require`).

## Success Criteria

- [ ] Full suite (`ddev exec php vendor/bin/phpunit`) green: 770+ tests, deterministic across repeated runs and machines without local `config.php`
- [ ] `--list-suites` exits 0
- [ ] `FS_SECRET_KEY` in test env passes `SecretManager` ≥32-char validation
- [ ] Legacy SHA1 tests skip cleanly when `legacy_support` is absent, pass when present
- [ ] `symfony/phpunit-bridge` installed and autoloaded; deprecation helper is functional
- [ ] `vendor/`, `composer.json`, `composer.lock` committed together
