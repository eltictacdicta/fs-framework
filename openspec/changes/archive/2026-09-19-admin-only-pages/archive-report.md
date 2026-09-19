# Archive Report: admin-only-pages

**Archived**: 2026-09-19
**Destination**: `openspec/changes/archive/2026-09-19-admin-only-pages/`
**Mode**: openspec (core `openspec/` — the change touches `base/`, `model/`, `src/`,
`controller/` and the instruction/skill docs, so it is owned by the core tree, never a
plugin SDD)
**Verdict at close**: **PASS** (`verify-report.md`: 0 CRITICAL, 4 WARNINGs, 6
SUGGESTIONs), with **W-1 and S-1 remediated after the verify snapshot** (see Final-State
Authority below).
**Task Completion Gate**: `tasks.md` **28/28 checked, 0 unchecked** — gate passed with no
reconciliation needed.

## Final-State Authority (snapshots vs. close state)

`apply-progress.md` and `verify-report.md` are intermediate snapshots. Both were written
before the last remediation work. Where they disagree with the close state, the
orchestrator's explicit final-state facts and repository evidence win. Both corrections
are recorded explicitly rather than silently overwritten:

| Finding | Snapshot claim | Close state | Evidence |
|---|---|---|---|
| **W-1** — migration must abort before writing the success flag on a failed step | `verify-report.md` (at verification time): **OPEN** WARNING | **FIXED after the snapshot** | `src/Core/Schema/AdminOnlyPagesMigration.php:93` aborts when a step returns falsy; `adoptColumn()` verifies the adopted column via a schema read (`hasAdminOnlyColumn()`); `backfill()`/`purgeStaleGrants()` return their `exec()` result. Proven by `Tests\Core\AdminOnlyPagesMigrationTest::aFailingSqlStepAbortsBeforeTheFlagIsWritten` (3 data sets), falsified by temporarily removing the abort. |
| **S-1** — tautological `__clone()` test | `verify-report.md` (at verification time): **OPEN** SUGGESTION | **RESOLVED after the snapshot** | The tautological `FsPageAdminOnlyTest::clonePreservesTheFlag` test was **removed**; the PHP shallow-copy limitation is documented in that test class docblock (`tests/Base/FsPageAdminOnlyTest.php:90`). There is no clone test by design. |

The `2355 tests / 7855 assertions` figure quoted in `verify-report.md` is the
**pre-remediation snapshot**. The close-state numbers are in *Final numbers* below.

### Inaccurate snapshot claim (recorded, not resolved in either direction)

`apply-progress.md` reported "2 pre-existing environment-dependent failures in
`plugins/system_updater/tests/MaintenanceModeCompatTest.php`". This **did not reproduce**
during verification: `verify-report.md` records `MaintenanceModeCompatTest` running
**3/3 green**, and the full suite exits `0` with zero failures. The claim was inaccurate.
**Nothing was broken by it** and no test was modified to make it pass. Whether those
failures ever existed cannot be established from the current working tree without
restoring the pre-change state; it is out of scope for archive.

## What Shipped

An explicit, co-located declaration for administrator-only pages plus enforcement at
three layers, closing the long-standing gap where any role grant could reach
`admin_users` / `admin_rol`.

- **Declaration**: `FSFramework\Attribute\AdminOnly` (`src/Attribute/AdminOnly.php`,
  `#[\Attribute(\Attribute::TARGET_CLASS)]`), valid on legacy `fs_controller` and modern
  `FSFramework\Core\Base\Controller` subclasses. Detected by attribute **name string**
  (`ReflectionClass::getAttributes()` + `getName()`), never `newInstance()`. The 4th
  `$admin` constructor parameter stays **dead** and is not a declaration source.
- **Persistence**: `fs_pages.admin_only` boolean `NOT NULL DEFAULT false`, read by the
  `fs_page` constructor, written by `save()` (UPDATE + INSERT), copied by `__clone()`,
  and exposed by the cached `all()` list — queryable without instantiating any
  controller.
- **Effective value**: `attribute OR getPageData()['admin_only']` (OR-escalation,
  fail-closed). Neither source → `false` (BC preserved). Dropping the attribute
  downgrades the persisted flag to `false` — a **deliberate revocation**, documented in
  `AGENTS.md`.
- **Listing**: `admin_rol::all_pages()`, `admin_users::all_pages()` and
  `admin_user::all_pages()` always exclude admin-only pages — including for
  administrators. They are not role-grantable by definition.
- **Saving**: `fs_rol_access::save()` returns `false` unconditionally for an admin-only
  page, closing the direct writer
  `plugins/factura_pdf1/Services/LegacyRolePermissionsGateway.php`.
- **Access**: `fs_user::get_menu()`'s non-admin branch skips admin-only pages regardless
  of `fs_rol_access`; propagates to `have_access_to()`, `select_default_page()`, the
  modern `enforcePageAccessOrExit()` gate and the `admin_user` default-page check.
- **Scope**: exactly 9 core pages declare the attribute (`admin_users`, `admin_user`,
  `admin_rol`, `admin_info`, `admin_email`, `admin_system_branding`, `admin_stealth`,
  `admin_orden_menu`, `admin_agentes`). `admin_home` stays accessible.
- **Migration**: `FSFramework\Core\Schema\AdminOnlyPagesMigration`, a one-shot idempotent
  migration invoked right after `fs_schema::selfHealCoreTables()` in `index.php`,
  `api.php` and `cron.php`. It calls `fs_model::forgetCheckedTables(['fs_pages'])`,
  triggers lazy column adoption (verified by a schema read-back), backfills the explicit
  9-name allowlist (no `LIKE 'admin_%'`), deletes stale `fs_roles_access` rows, clears
  `m_fs_page_all`, and writes the `admin_only_pages_migrated` `fs_var` flag **only after
  every SQL-executing step succeeds**.
- **Tests**: 62 new hermetic, no-DB tests across 10 files (attribute, `fs_page`
  persistence/resolution, construction paths, menu composition, role-access guard,
  listing matrices, scope scan, migration ordering/failure).
- **Docs + cross-IDE parity** (completed during apply, present in the working tree):
  `AGENTS.md`, `.github/copilot-instructions.md`,
  `.cursor/rules/fs-framework-general.mdc`, `.cursor/rules/fs-framework-plugins.mdc`,
  `.cursor/rules/fs-framework-security.mdc`, and the `fsframework-plugin-scaffold` /
  `fsframework-security-review` `SKILL.md` mirrors under **both** `.opencode/skills/`
  and `.cursor/skills/`.

### Known deviation from the design's file list

`cron.php` never loaded Composer's autoloader, so the namespaced migration class would
have been unloadable there (and the pre-existing `selfHealCoreTables()` call already
risked a fatal). The fix requires `vendor/autoload.php` before the migration and adds a
regression test. This repairs a latent bug; it does not change intent.

## Spec Merge Summary

| Domain | Action | Details |
|---|---|---|
| `page-authorization` | **Created** `openspec/specs/page-authorization/spec.md` | New capability. The change folder carried a `page-authorization` delta with 10 requirements (PA-01…PA-10) and 21 scenarios. No prior main spec existed, so it was promoted to the source of truth. |

### Promotion mechanics

1. **Mechanical shell copy** per the sdd-archive Mechanical Copy Contract:
   `cp` of `openspec/changes/admin-only-pages/specs/page-authorization/spec.md` to a
   `mktemp` file under `openspec/specs/page-authorization/`, followed by a mandatory
   `diff -r` readback that returned **empty (exit 0)**, then an atomic `mv` into place.
   No requirement content passed through the model's Read/Write path.
2. **House-header composition** (the only model-authored edit): the delta wrapper
   (`# Delta for page-authorization` + `## ADDED Requirements`) was replaced with the
   canonical capability header (`# page-authorization Specification`, `## Purpose`, and
   the `| ID | Requirement | Strength |` requirements table). This matches the dominant
   `openspec/specs/` convention and the recent `tarifa-familia-hierarchy` archive
   precedent for header adjustments.
3. **Body byte-identity proof**: after the header edit, a section-scoped diff from
   `### Requirement: PA-01` to end-of-file against the delta was **empty (exit 0)** —
   all 10 requirement bodies and all 21 scenarios are byte-identical to the delta.

Counts at close: **10 requirements, 21 scenarios, 10 ID-table rows.**

## Final numbers (close state — supersede intermediate snapshots)

| Command | Result |
|---|---|
| `ddev exec php vendor/bin/phpunit` | **2357 tests, 7863 assertions, 0 failures**, 20 PHPUnit deprecations, 24 skipped, **exit 0** |
| `ddev exec php vendor/dev-tools/bin/phpstan analyse --memory-limit=1G` | `[OK] No errors` (**206 files**) |

The `2355 tests / 7855 assertions` in `verify-report.md` is the pre-remediation snapshot.
The delta is explained by the W-1/S-1 remediation: **+2 tests, +8 assertions** (3 new
data-provider cases for the abort proof, minus the removed tautological clone test).

The 20 deprecations and 24 skips are pre-existing and unrelated to this change.

## Accepted Open Findings (not blocking)

The following were verified as robustness/coverage gaps, not authorization bypasses, and
are **accepted as documented coverage limits of the no-DB test harness**. None blocks
archive.

| ID | Kind | Accepted disposition |
|---|---|---|
| W-2 | `AdminOnlyListingTest` advertises admin/non-admin actors but never uses the provider parameter | Accepted. The production filter is unconditional, so PA-04 still holds; the provider is misleading but harmless. |
| W-3 | PA-06 propagation sites (`select_default_page`, `enforcePageAccessOrExit`, `admin_user` default page) covered only transitively | Accepted. They all consume `have_access_to()`, which is tested; source-verified for the named sites. |
| W-4 | Migration's real step SQL is never executed by a test (recorder overrides every step) | Accepted. Ordering/flag/abort logic is covered; live-DB SQL is out of the no-DB harness scope. |
| S-2 | PA-01/PA-03 "modern" path approximated by a plain fixture | Accepted coverage limit. |
| S-3 | `FS_DEMO` constant-driven branch not executed end-to-end (`FS_DEMO` is `false` in the bootstrap) | Accepted. `compose_menu(..., demo: true)` is tested. |
| S-4 | `isApplied()` boolean cast is fragile for a stored `'FALSE'`/`'0'` (never written by this code) | Accepted. |
| S-5 | `markApplied()` ignores `simple_save()`'s result (retry is idempotent) | Accepted. |
| S-6 | `LegacyRolePermissionsGateway` covered structurally (call chain + guard test), not instantiated | Accepted. |
| S-7 | `fsframework-plugin-scaffold` skill mirrors diverge (**pre-existing**) | Accepted. Confirmed at close: `.opencode/skills/.../SKILL.md` = 746 lines vs `.cursor/skills/.../SKILL.md` = 386 lines. Both received the `#[AdminOnly]` Step 6 updates; the divergence predates this change and belongs to a separate mirror-sync task. |

`admin_home` staying accessible and the `FS_DEMO` all-pages branch are **documented,
deliberate exceptions**, not open findings.

## Delivery Decision (recorded, NOT executed)

- Strategy: **`single-pr`** with maintainer-approved **`size:exception`**.
- **Actual size: ~2554 changed lines** (source + tests + docs) vs the proposal's
  **~925-line forecast** — roughly 2.8× the estimate and well over the 800-line review
  budget. The `size:exception` was required because chained PRs would split an
  authorization invariant across units that cannot independently ship a green suite.
- **No commit, branch or PR was created by this archive phase.** Everything remains in
  the working tree, per instruction.

## Mechanical Archive Verification

Command and verbatim readback (per the Mechanical Copy Contract):

```
=== MANDATORY readback: diff -r snapshot vs destination (expect empty) ===
--- readback diff exit: 0 ---

=== body-only diff (from '### Requirement: PA-01' onward) — expect empty ===
--- body diff exit: 0 ---

=== source gone? ===
source removed OK

=== tasks completion ===
28
0
```

- Delta → main-spec copy: `diff -r` **empty (exit 0)**.
- Main-spec requirement bodies vs delta: section-scoped `diff` **empty (exit 0)**.
- Change-folder move: `git mv` to `openspec/changes/archive/2026-09-19-admin-only-pages/`
  succeeded; pre-move recursive snapshot vs destination `diff -r` **empty (exit 0)**.
  The untracked `apply-progress.md` and `verify-report.md` were carried into the
  destination. This report is the only additive file and is excluded from the
  comparison.
- Active changes directory no longer contains `admin-only-pages`.
- Archived `tasks.md`: **28 checked, 0 unchecked**.

## Archive Contents

- `proposal.md` — intent, scope, enforcement model, migration, risks, rollback, BC
- `exploration.md` — current-state evidence (`file:line`), approach comparison, migration caveats
- `design.md` — D1–D8 decisions, migration hook, column adoption, cache invalidation, testability seam
- `tasks.md` — **28/28 complete**
- `apply-progress.md` — apply record (7 phases) + W-1/S-1 remediation section
- `verify-report.md` — independent verification (PASS; 0 CRITICAL / 4 WARNING / 6 SUGGESTION)
- `specs/page-authorization/spec.md` — delta spec (source of the promoted main spec)
- `archive-report.md` — this file

## Sources of Truth Updated

- `openspec/specs/page-authorization/spec.md` — **new**; 10 requirements / 21 scenarios.

## SDD Cycle Complete

The change has been fully explored, proposed, specified, designed, implemented (strict
TDD), independently verified, remediated, and archived. The `page-authorization`
capability now lives in the baseline spec as the source of truth.
