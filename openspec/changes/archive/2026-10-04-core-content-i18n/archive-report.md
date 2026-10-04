# Archive Report: core-content-i18n

**Archived**: 2026-10-04
**Change type**: CORE (touches `base/`-adjacent core surfaces, `src/`, `model/`, `themes/`, entry points)
**Location**: `openspec/changes/archive/2026-10-04-core-content-i18n/`
**Artifact store**: `openspec`
**Delivery strategy**: `auto-chain` / `feature-branch-chain`
**Branch at close**: `feat/core-content-i18n-s6`

## Closing Summary

FSFramework had two independent, incompatible content-translation patterns, each
grown inside a plugin — fixed nullable columns (`OidcProvider`:
`subtitle_es/fr/en` + `oidc_client::getSubtitle()`) and a registry plus
per-record rows (`catalogo_core`: `catalogo_idiomas` + `articulo_descripciones`)
— and no framework-level mechanism a new plugin could reuse. This change
extracts the reusable **mechanism** into the core as an entirely **additive**
layer over the existing `fs_model` + XML-schema stack:

- `model/idioma.php` + `model/table/idiomas.xml` — a new, separate core `idiomas`
  registry (NOT `catalogo_idiomas`), with total deterministic `defaultCode()`
  resolution (active `por_defecto` → lowest active `codidioma` → `'es'`) and
  lifecycle invariants.
- `src/Translation/LanguageRegistry.php` — a facade over the model (sorted
  `activeCodes()`, delegation).
- `src/Translation/ContentTranslator.php` — a read-only, non-materializing
  resolution engine (`exact → language prefix → configured default → base → ''`)
  driven by each store's declared `candidates()` list and a caller-supplied locale.
- `src/Translation/Store/` — `TranslationStoreInterface` plus
  `RowTranslationStore` (per-record rows, application-level `purgeLanguage()`,
  no language FK) and `FixedColumnTranslationStore` (locale code → existing
  nullable column, no schema change; plugin owns aliases).
- `src/DependencyInjection/Container.php` — namespaced, non-shadowable core
  services `core.language_registry` / `core.content_translator`, registered
  before `loadPluginServices()` and re-asserted after.
- `src/Core/Schema/ContentI18nMigration.php` — idempotent, `fs_var`-flagged,
  table allowlist exactly `['idiomas']`, hooked after
  `fs_schema::selfHealCoreTables()` in `index.php`, `api.php`, `cron.php` inside
  `try/catch`.
- `src/Traits/TranslatableControllerTrait.php` + a render-only
  `themes/AdminLTE/view/Macro/Translation.html.twig` macro. No new core page,
  menu row or `#[AdminOnly]` declaration.

The first core release is additive-only: no existing table, column, model,
method, page, service id or translation key is renamed, removed, retyped or
repointed. A plugin that never migrates behaves exactly as before.

## Delivered Artifacts

| Artifact | Status |
|---|---|
| `exploration.md` | present |
| `proposal.md` | present |
| `specs/content-i18n/spec.md` (delta) | present |
| `design.md` | present |
| `tasks.md` | present — **36/36 checked, 0 unchecked** (31 implementation + 5 Phase-7 verification) |
| `verify-report.md` | **NOT present** — verification results were recorded in `tasks.md` Phase 7 (commit `98d8803b`), not as a standalone artifact |
| `archive-report.md` | this file |

Change footprint at close: 33 files, +7081 lines (7 feature/docs commits + 1
fix commit; see below).

## Spec Sync

### Source of truth updated

| Domain | Action | Details |
|---|---|---|
| `content-i18n` | **Created** `openspec/specs/content-i18n/spec.md` | New capability. No prior main spec existed. 26 requirements (CI-01…CI-26), all `MUST`, with 75 scenarios preserved. |

### Promotion mechanics (project convention, per the `admin-only-pages` precedent)

1. **Mechanical shell copy** per the sdd-archive Mechanical Copy Contract:
   `cp` of `openspec/changes/core-content-i18n/specs/content-i18n/spec.md` to a
   `mktemp` file under `openspec/specs/content-i18n/`, followed by a mandatory
   `diff -r` readback that returned **empty (exit 0)**, then an atomic `mv` into
   place. Source and promoted file sha256 both
   `8a4b2ed31badd20c22bc78ec9a4ee974d47afe31e87abca243de2f17f9888643`. No
   requirement content passed through the model's Read/Write path.
2. **House-header composition** (the only model-authored edit): the delta wrapper
   (`# Delta for content-i18n` + `## ADDED Requirements` + the delta preamble)
   was replaced with the canonical capability header
   (`# content-i18n Specification`, `## Purpose`, and the
   `| ID | Requirement | Strength |` requirements table with 26 rows). This
   matches the dominant `openspec/specs/` convention.
3. **Body byte-identity proof**: after the header edit, a section-scoped diff
   from `### Requirement: CI-01` to end-of-file against the delta was **empty
   (exit 0)** — all 26 requirement bodies and all 75 scenarios are byte-identical
   to the delta. Both extracted bodies sha256
   `2e4ddeab1d9fe2ec3c2cbcd404e195566213b4e9cbd63ca0c3cdda912b2401ff`.

Counts at close: **26 requirements, 26 ID-table rows, 75 scenarios.**

## Final Status

**Complete and archived.** All 36 tasks checked. No unfinished tasks and no open
blockers at close.

## Commits

Chain commits on branch `feat/core-content-i18n-s6`:

| Commit | Message |
|---|---|
| `4ee3b699` | `feat(i18n): add core idiomas registry model and schema` |
| `1ec0f677` | `feat(i18n): add LanguageRegistry facade` |
| `5598fa6a` | `feat(i18n): add ContentI18nMigration and bootstrap hooks` |
| `a1e5eb88` | `feat(i18n): add translation store contract and implementations` |
| `1a1f9aad` | `feat(i18n): add ContentTranslator engine and namespaced DI services` |
| `b70a8176` | `feat(i18n): add translatable controller trait and render macro` |
| `98d8803b` | `docs(i18n): record core-content-i18n verification results` |
| `2af92920` | `fix(i18n): propagate write failures from RowTranslationStore` (CodeRabbit finding, fixed with TDD) |

Nothing was merged, tagged or pushed. Commit/push/PR remain orchestrator/human
decisions under ordinary repository policy.

## Verified Test Evidence (final state — supersedes the `tasks.md` snapshot)

| Suite | Result |
|---|---|
| `Base` | `OK (311 tests, 844 assertions)` |
| `Core` | `OK (405 tests, 1141 assertions)` |
| `Traits` | `OK (20 tests, 55 assertions)` |
| `tests/Translation` | unchanged / passing (CI-25) |
| `tests/Core/ContentTranslatorTest.php` | `19/19` |
| Full suite | **3625 tests, 26 failures, 27 skipped** |

- The 26 full-suite failures are **pre-existing on `master`** and confined to
  `plugins/OidcProvider`. Verified by running `--testsuite Plugins` on `master`
  **without** this change: identical 26 failures. Zero failures are attributable
  to this change.
- **Final-State Authority note**: `tasks.md` Phase 7 records the pre-fix snapshot
  `Core OK (402/1138)`. The CodeRabbit fix (`2af92920`) later added 3 tests /
  3 assertions to `ContentTranslatorTest`, producing the final
  `Core OK (405/1141)`. The launch's explicit final-state facts outrank the
  intermediate `tasks.md` snapshot; the final numbers are reported here.

## Backward-Compatibility Gates — Verified (read-only re-run at archive)

| Gate | Command | Result |
|---|---|---|
| Plugin consumers untouched | `git diff --stat master..HEAD -- plugins/catalogo_core plugins/OidcProvider` | **empty** (exit 0) |
| No plugin-table reference in new core files | `grep -rn "catalogo_idiomas"` over `src/Translation/LanguageRegistry.php`, `src/Translation/ContentTranslator.php`, `src/Translation/Store/`, `model/idioma.php`, `model/table/idiomas.xml`, `src/Core/Schema/ContentI18nMigration.php` | **zero occurrences** |
| Migration single-table allowlist | `grep -n TABLE_ALLOWLIST src/Core/Schema/ContentI18nMigration.php` | `['idiomas']` (line 56) |
| Bootstrap hooks after self-heal | `grep -n ContentI18nMigration::run index.php api.php cron.php` | `index.php:99`, `api.php:152`, `cron.php:83` — each inside `try { … } catch (\Throwable $e)` |
| `FSTranslator` message layer untouched | `git diff --stat master..HEAD -- src/Translation/FSTranslator.php src/Translation/TranslationHelper.php src/Translation/FS2025JsonLoader.php` | **empty** (exit 0) |

## CodeRabbit Outcome

- Tool: CodeRabbit CLI `--agent --base master -t committed`.
- One finding was raised and fixed with TDD in `2af92920` (propagate write
  failures from `RowTranslationStore` so a failed persistence is not silently
  reported as success).
- **Final re-review returned 0 findings.**

## Delivery / Review-Budget Decision

- The change exceeds the default 400-line review budget; delivery used
  `auto-chain` (`feature-branch-chain`, 6 slices S1–S6).
- The maintainer explicitly accepted a **`size:exception`** for the chain.
- Review policy at session preflight: 400 changed lines.

## Review (RDD/Native) — Explicit Non-Action

This candidate was **explicitly left unreviewed** under a candidate-scoped
opt-out. The selectorless preflight only observed the unrelated pre-existing
`opencode.json` worktree change. The RDD global switch remains on. **No native
review action was attempted during archive, and none should be.** This note is
recorded for audit completeness only.

## Deferred — Explicitly NOT part of this change

1. **Core VERSION bump + `vX.Y.Z` tag.** Handled later by the
   `fsframework-core-release` skill. This change does not bump `VERSION` and does
   not create a tag.
2. **Plugin consumer migrations** — separate future changes with their own
   openspec:
   - `catalogo_core`: delegate registry/fallback semantics to the core and own
     the `catalogo_idiomas → idiomas` data copy.
   - `OidcProvider`: serve `subtitle_*` through `FixedColumnTranslationStore`
     without a schema change.
3. **Optional generic `content_translations` table** — deferred per proposal Q3.
4. **Core per-request locale resolution / `RequestStack` listener** — deferred
   per proposal Q5 (explicit caller-supplied locale only in this release).

## Source of Truth Updated

- `openspec/specs/content-i18n/spec.md` — **new**; 26 requirements / 75 scenarios.

## Archive Method & Mechanical Readback

- Spec promotion: `cp` → `diff -r` (**empty, exit 0**) → atomic `mv`; body
  byte-identity proven by section-scoped `diff -r` (**empty, exit 0**).
- Change folder move: pre-move recursive snapshot → `git mv` → **`diff -r`
  snapshot vs destination: empty (exit 0)**. The active
  `openspec/changes/core-content-i18n/` directory no longer exists; the archived
  folder preserves `proposal.md`, `exploration.md`, `design.md`, `tasks.md` and
  `specs/content-i18n/spec.md` with original bytes (36/36 task checkboxes
  intact).
- `archive-report.md` is additive and did not exist in the source snapshot.

## SDD Cycle Complete

All 36 tasks complete. Implementation complete across 6 chained slices plus a
TDD CodeRabbit fix. Verification is recorded (Phase 7 in `tasks.md`; no separate
`verify-report.md` was persisted — reported honestly). The `content-i18n`
capability now lives in the baseline spec as the source of truth. The only
remaining follow-ups are the explicitly deferred, separate deliveries above.
