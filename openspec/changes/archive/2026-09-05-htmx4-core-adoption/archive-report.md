# Archive Report: htmx4-core-adoption

**Archived**: 2026-09-05
**Destination**: `openspec/changes/archive/2026-09-05-htmx4-core-adoption/`
**Mode**: openspec (delta specs synced as new main specs before the move)
**Verdict at close**: PASS WITH WARNINGS (verify-report: 0 blockers, 0 CRITICAL; 19/19 requirements, 18/18 scenarios compliant at declared capability layers)

## Final Delivery State

Authority order applied: tasks.md (completion) > orchestrator final-state handoff (2026-09-05, post-verify) > verify-report/apply-progress snapshots.

| Item | State at close | Source |
|------|----------------|--------|
| Tasks | 14/14 complete, 0 unchecked | `tasks.md` (Task Completion Gate passed; no reconciliation needed) |
| Core enablement (unit 1) | **MERGED** — PR #10 to `master` at 2026-09-05T12:11:51Z, merge commit `484fcfc7`; 7/7 GitHub checks SUCCESS (CodeQL, SonarCloud, Socket) | Orchestrator handoff; confirmed by `git log` (484fcfc7 on branch) |
| Plugin pilot (unit 2) | **PENDING MERGE** — commit `e8bb5e7` on `plugins/tarifario` `feat/htmx4-catalog-pilot` (nested repo, remote `eltictacdicta/tarifario`); PR pending with maintainer-authorized `size:exception` (788 changed lines; no smaller green split exists — the contract test cannot be separated from the code it verifies) | Orchestrator handoff; apply-progress "Workload / PR Boundary" |
| Core PR 2 (docs/archive) | **PENDING CREATION** — core branch `feat/htmx4-catalog-pilot` carries the docs/archive commits; to be opened immediately after this archive | Orchestrator handoff |

Note on snapshots: per verify-report's Scope Verification (written at verification time), the core branch then differed from master by only 2 docs files; the archive commits added after verification supersede that snapshot claim — both statements are time-attributed here and not contradictory.

## Spec Sync Record

| Domain | Action | Result |
|--------|--------|--------|
| `htmx-core-support` | Created `openspec/specs/htmx-core-support/spec.md` | Mechanical `cp` of delta spec (no prior main spec → delta is full spec); `diff -r` empty (byte-identical, exit 0) |
| `tarifario-catalog-htmx-pilot` | Created `openspec/specs/tarifario-catalog-htmx-pilot/spec.md` | Mechanical `cp` of delta spec (no prior main spec → delta is full spec); `diff -r` empty (byte-identical, exit 0) |

Both delta specs were already in canonical OpenSpec format (### Requirement / #### Scenario blocks + overview tables; 9 HCS + 10 TCP requirements).

## Verification Summary (at verification time, per verify-report)

- Focused suites (refresh run): `--filter Htmx` 23 tests / 74 assertions, exit 0; `--filter TarifCatalogoHtmx` 12 tests / 58 assertions, exit 0.
- Full suite (prior run, carried): 1284 tests / 3224 assertions; 18 errors — all pre-existing on the plugin master baseline (`Tests\Tarifario\*` permission-listener family, `ArticlePermissionFilterEvent` not found); baseline-excluded run 1261/3203, 0 errors, exit 0; 0 regressions from this change.
- Build: `ddev exec ./build.sh` exit 0; `view/js/htmx.min.js` 36,716 bytes with `4.0.0` marker (npm-provenance path used; HCS-02 fallback not fired).
- TDD: 6/6 checks passed; 12 new pilot tests, strict RED→GREEN per unit.

## Known Gaps at Close

1. **Browser runtime smoke NOT executed** (`e2e: false`): 6 scenarios (HCS-07 POST, TCP-01/02/03/04/07) proven at contract/Twig-render layer only. The manual 6-step smoke list lives in verify-report WARNING-2; an operator should run it after `ddev start` once the plugin PR merges.
2. **No runtime test for the htmx-POST CSRF path** (HCS-07): wiring proven statically; POST flows intentionally remain on jQuery (out of pilot scope).

## Deferred Follow-ups (out of this change's scope)

- Alpine 3, SortableJS, Bootstrap 5 migration.
- htmx POST/JSON flows.
- Browser E2E harness.
- Pre-existing `Tests\Tarifario` permission-listener errors (missing `catalogo_core` `ArticlePermissionFilterEvent`) — belongs to a `catalogo_core` SDD.
- Cosmetic suggestions from verify-report: `document.title` not refreshed on filter swaps; test-method-name typo (`…ishxmxrequest`); pre-existing `colspan=8` vs 11 columns in the normal-mode error row.

## Attempt Ledger

- Both work units (core-enablement, catalog-pilot) complete.
- Unit 1 had one maintainer-authorized objective reset (changed-line accounting included planning docs + vendored asset). No open blockers; no unresolved contradictions.

## Archive Contents

proposal.md, design.md, explore.md, tasks.md (14/14), apply-progress.md, verify-report.md, specs/htmx-core-support/spec.md, specs/tarifario-catalog-htmx-pilot/spec.md, archive-report.md (this file).
