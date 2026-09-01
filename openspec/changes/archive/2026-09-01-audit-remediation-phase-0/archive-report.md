# Archive Report: audit-remediation-phase-0

**Change**: audit-remediation-phase-0 (Test Harness Determinism Remediation — Phase 0 of the audit remediation program)
**Archived**: 2026-09-01
**Archived to**: `openspec/changes/archive/2026-09-01-audit-remediation-phase-0/`
**SDD store**: openspec (artifact store mode: `openspec`)

## What Was Delivered

Six commits, `9771f57f..a0c011fe` (inclusive):

| Commit | Subject |
|--------|---------|
| `9771f57f` | test: harden bootstrap determinism and test secret (unconditional canonical constants, no `config.php` require, 64-hex `FS_SECRET_KEY`, `SecretManagerValidationTest`) |
| `f73a29cd` | test(deps): add symfony/phpunit-bridge and register SymfonyExtension (dev dependency, atomic `composer.json`/`composer.lock`/`vendor/` commit, 38 committed bridge files) |
| `62234e0a` | test(harness): rescope Plugins suite to `plugins/*/tests` (bounded discovery; `--list-suites` 255 → 0) |
| `170ffcc2` | test: skip legacy SHA1/MD5 tests when legacy_support plugin is absent (6 guarded tests, explicit skip message) |
| `c34539ab` | test(harness): exclude plugin tests incompatible with shared-process suite (23 per-file excludes, each with an inline reason) |
| `a0c011fe` | docs(sdd): record verification and close audit-remediation-phase-0 tasks |

Scope held: only `tests/`, `phpunit.xml`, `composer.json`, `composer.lock`, `vendor/` and this change's SDD trail — no `base/`, `src/`, `model/`, `controller/` changes (scope guard verified at verify time).

## Verification Verdict

**PASS WITH WARNINGS** (`verify-report.md`, verified 2026-09-01 at HEAD `a0c011fe`):

- Blockers: 0 · CRITICAL findings: 0
- Requirements: 6/6 · Scenarios: 11/11 compliant
- Full suite: exit 0 — Tests 1249 / Assertions 3084 / Deprecations 20 / Skipped 10, identical across three consecutive runs (runs #2/#3/#4), exact match to `baseline.md` §5.1; result independent of local `config.php`
- `--list-suites`: exit 0 (was exit 255 pre-change) · `composer install --dry-run`: exit 0
- Strict deprecation spot-run (`SYMFONY_DEPRECATIONS_HELPER=max[direct]=0`) proves the bridge enforcing (exit 1, 17 direct notices listed)
- Deviations ledger: 5 documented deviations (6 legacy tests guarded vs design's 5; 23 per-file excludes; `system_updater_back` absent → spec's −6 delta superseded; weak-vs-strict enforcement proof; pre-existing `scripts/` deletions) — all justified in `baseline.md` and assessed compliant

## Tasks State at Archive

`tasks.md`: 20/20 checked (`[x]`), 0 unchecked. Task Completion Gate passed; no archive-time reconciliation performed.

## Review Gate

Orchestrator-provided approved terminal review receipt (result: allow). The receipt, transaction, and ledger were not read from disk (not present in the repo tree) and were **not modified or touched** — treated as the orchestrator's authoritative gate state per phase contract.

## Spec Sync Summary

Capability `test-harness-determinism` is **new** — no canonical spec existed. The delta's requirements were applied as the canonical spec:

- Delta source: `specs/test-harness-determinism/spec.md` (6 ADDED requirements, 0 MODIFIED, 0 REMOVED, 0 RENAMED)
- Canonical home created: `openspec/specs/test-harness-determinism/spec.md`
- Format normalized to repo convention (as `specs/schema-sync/spec.md`): title `Spec: test-harness-determinism (canonical)`, Purpose, requirements table with IDs **THD-01…THD-06**, full requirement blocks preserving all 13 scenarios verbatim, plus the Verification Commands section
- No existing specs modified; no destructive merge performed

## Archive Contents

- `proposal.md` ✅ · `design.md` ✅ · `tasks.md` ✅ (20/20) · `baseline.md` ✅ · `verify-report.md` ✅ · `specs/test-harness-determinism/spec.md` ✅ (delta, preserved as applied)
- Active changes directory no longer contains `audit-remediation-phase-0`

## Follow-ups (info-level, out of this change's scope)

1. **Untracked native-review leftovers**: `scripts/git-clone-plugins.sh` and `tmp/index.php` remain untracked workspace files — pre-existing review follow-ups explicitly excluded from this change's scope; decide keep/remove in a separate change.
2. **Plugin-repo drift behind the 23 per-file excludes** (commit `c34539ab`): stale `fs_db2` mock signatures (tarifario), missing cross-plugin model/view/service classes, inline-stub collisions, PHP notices under `failOnWarning="true"`, environment-dependent SQL/DNS tests. Root causes live in each plugin repository (owner items); until fixed, those tests keep their per-file excludes in `phpunit.xml` (each exclude has a documented inline reason — no silent loss).
3. **Tarifario HTTP smoke flake** (verify WARNING-1): `Tests\Tarifario\Integration\LegacyImportRegressionTest::importExcelChunkRedirectsToSseEntryPoint` ("Server did not respond.") — boots PHP's built-in web server via `proc_open` under full-suite load; green in isolation and in 3 of 4 runs. Plugin-owned (separate repo); consider a plugin-side exclude or server-boot hardening.
4. **Suggestions carried from verify**: surface the 10-suite-skips breakdown (1 core pre-existing, 1 alias in-suite precondition, 8 plugin-side) as a CI annotation; consider a standalone apply-progress artifact convention for future strict-TDD changes (this change embedded TDD evidence in `tasks.md`/`baseline.md` instead — process note only).

## SDD Cycle Complete

The change was planned, implemented, verified (pass-with-warnings, 0 blockers), and archived with specs synced. Source of truth for `test-harness-determinism` now lives at `openspec/specs/test-harness-determinism/spec.md`.
