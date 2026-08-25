# Archive Report: schema-sync-defensive-fk

**Change**: schema-sync-defensive-fk
**Archived**: 2026-08-25
**Mode**: openspec (CORE → root `openspec/`)
**Scope**: CORE-LOCAL (root `openspec/`, not a plugin SDD)
**Prior verdict**: PASS WITH WARNINGS (verify-report.md, `verify-result/v1` schema, evidence_revision sha256:a778e03456956956e3cb537fbaf0193f9a105f4959169d36ae98c72baeb9267c)

## Closing Summary

This change made the core schema-sync machinery defensive (omit incompatible FKs, never block table creation) and centralized the sync/ordering classes from `system_updater` into the core so plugins reuse them.

**Cross-repo delivery (final state at close):**

- **Core** released **0.18.0** — 7 commits (`b7df7315`, `f157f74f`, `f441140c`, `91a78331`, `15b83405`, `aac935b6`, `c4469540` release 0.18.0), all pushed (master + tag `v0.18.0`). These ship:
  - `src/Database/FkCompatibilityValidator.php` (new)
  - `SchemaComparator.php` + `base/fs_schema.php` wired to the shared validator
  - `src/Core/Plugin/PluginUpdateOrderer.php` + `PluginSchemaResyncer.php` (centralized)
- **Plugin** (`system_updater`, separate repo) released **2.8.0** — Phase 3 delegation commit `a95b1f9` + version bump `17df343`, both pushed. The plugin now delegates to the core classes (`admin_updater.php` use-imports `FSFramework\Core\Plugin\{PluginUpdateOrderer, PluginSchemaResyncer}`, injects catalog-backed `requirementsFn` at both `order()` and both `withDependencyVisibility()` sites), its local `lib/` duplicates are deleted, and `fsframework.ini` `min_version` is bumped to `"0.18"`.

**SS-06 delivered**: The verify report's SS-06 warning (plugin-side delivery pending core 0.18.0 release) is now RESOLVED at close — Phase 3 completed and the plugin suite passes (124 tests / 222 assertions). No post-verify implementation fixes were needed; the CodeRabbit findings were fixed before verify and the verify report reflects the final state with SS-06 delivered.

## Verification (as recorded by `verify-report.md`, validated at close)

| Metric | Value |
|--------|-------|
| Verdict | PASS WITH WARNINGS |
| CRITICAL findings | 0 (none) |
| Requirements | 6/6 (SS-01..SS-06) |
| Scenarios | 9/9 |
| Build (phpstan level 5, 3 new src files) | exit 0, no errors |
| Change-scoped tests | 35 passed / 71 assertions / exit 0 |
| Root suites (buildable) | Base 180, Core 115, Security 189, Traits 15, Cache 18 — all green |
| Plugin suite (system_updater, Phase 3) | 124 tests / 222 assertions |

**Warnings carried from verify (process-level, not functional):**
1. Missing `apply-progress.md` TDD-evidence artifact — TDD RED/GREEN cycles documented inline in `tasks.md` and independently re-verified at runtime (all test files present, all pass).
2. *(SS-06 plugin-side delivery warning — RESOLVED at close, see Closing Summary.)*

**Suggestions carried (non-blocking):**
1. Add a dedicated SS-04 regression test (FK omitted at CREATE → columns align → `compare_constraints` emits `ADD CONSTRAINT`) — currently static evidence only.
2. `FkCompatibilityValidator::warn()` message format differs cosmetically from the design template — functionally equivalent.
3. `fs_plugin_manager::applySchemaUpdatesOrRollback` (:682) has no covering test — pre-existing, candidate for future hardening.

## Task Completion Gate

`tasks.md` has **22/22 tasks checked `[x]`**, 0 unchecked. Phase 1, 2, 4 and 5 all complete. Phase 3 (3.1-3.7) and 5.2 were delivery-sequenced after core 0.18.0 release and are now complete (tasks marked `[x]` with commit references `a95b1f9` / `17df343`). No stale unchecked implementation tasks.

## Spec Sync

Canonical target `openspec/specs/schema-sync/spec.md` was a NEW domain (no canonical existed). The delta spec IS the canonical source: the ADDED requirements SS-01..SS-06 (with their scenarios) were promoted into the canonical spec in English, with a canonical header `# Spec: schema-sync (canonical)`, consistent with core spec conventions.

| Domain | Action | Details |
|--------|--------|---------|
| schema-sync | Created | 6 requirements (SS-01..SS-06), 9 scenarios promoted from delta ADDED requirements |

## Stray Entry Verification

- **Core active changes**: `openspec/changes/schema-sync-defensive-fk/` is GONE after the move — no stray entry remains in the active changes directory. ✅
- **Plugin `system_updater` openspec**: confirmed NO `schema-sync-defensive-fk` entry exists there. The plugin repo archives its own distinct `schema-sync-dependency-safety` change (unrelated); grep for `schema-sync-defensive-fk` under `plugins/system_updater/openspec/` returns nothing. ✅

## Mechanical Copy Contract

The change folder was moved with `git mv` (fallback `mv`), and the archive tree verified byte-identical to a pre-move recursive snapshot via `diff -r`. Verbatim readback output (empty diff = PASS):

```
=== diff -r readback (source snapshot vs archived) ===
DIFF_STATUS=0 (empty diff = PASS)
```

The `archive-report.md` is additive and excluded from the comparison.

## Archived Artifacts

| Artifact | Present |
|----------|---------|
| proposal.md | ✅ |
| design.md | ✅ |
| specs/schema-sync/spec.md | ✅ |
| tasks.md (22/22 complete) | ✅ |
| verify-report.md (PASS WITH WARNINGS, no CRITICAL) | ✅ |

## SDD Cycle Complete

The change has been fully planned, implemented, verified, and archived. Ready for the next change.
