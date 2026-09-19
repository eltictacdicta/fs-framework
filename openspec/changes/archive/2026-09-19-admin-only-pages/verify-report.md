# Verify Report: `admin-only-pages`

> **SUPERSEDED SNAPSHOT — read `archive-report.md` for the final state.**
>
> This report records the state at verification time. Work landed **after** it
> and is NOT reflected below:
>
> - **W-1 was FIXED**: `AdminOnlyPagesMigration::execute()` now aborts before
>   `markApplied()` when a step returns falsy, so a silent SQL failure can no
>   longer write the success flag. The PA-08 assessment below therefore reads
>   more severely than the shipped code.
> - **S-1 was RESOLVED**: the tautological clone test was removed and the PHP
>   shallow-copy limitation documented instead.
> - **S-7 was RESOLVED**: the scaffold-skill mirrors were synced.
> - The suite has since grown; the counts below are the snapshot's.
>
> Everything else below is the historical record and has been left unedited.

- **Change**: `admin-only-pages` (CORE FSFramework change)
- **Artifact store**: openspec
- **Phase**: verify (independent)
- **Verifier**: SDD executor — verification performed by reading the implementation and
  running the commands/scripts directly. `apply-progress.md` was treated as claims, not
  evidence. No implementation file was modified; the one scratch script used for the
  independent behavioral checks was removed before this report was written.
- **Date**: 2026-09-19

## Verdict

**The implementation satisfies the specification.** All 10 requirements (PA-01…PA-10)
hold in the source, the 9-page allowlist matches the 9 controllers that actually declare
the attribute, both documented exceptions (`admin_home`, `FS_DEMO`) are preserved, and
the full test suite plus the PHPStan gate are green.

*(As of this snapshot PA-08 carried the W-1 gap described below — `markApplied()` could
run after an unthrown SQL failure. It was fixed afterwards; see the banner above. The
PASS below is therefore qualified for PA-08 and unconditional for the other nine.)*

No CRITICAL issue was found. 4 WARNINGs and 7 SUGGESTIONs are recorded; the most
important is a robustness gap in the migration's success flag (PA-08). None of them
enables an authorization bypass.

**Issue counts: CRITICAL 0 · WARNING 4 · SUGGESTION 7.**

## Commands run (via `ddev exec` only)

| Command | Exact result |
|---|---|
| `ddev exec php vendor/bin/phpunit` | `OK, but there were issues!` — **2355 tests, 7855 assertions, 0 failures, 20 PHPUnit deprecations, 24 skipped**, exit code `0` |
| `ddev exec php vendor/bin/phpstan analyse --memory-limit=1G` | `[OK] No errors` (206/206 files) |
| `ddev exec php vendor/bin/phpunit tests/Base/AdminOnlyAttributeTest.php tests/Base/FsPageAdminOnlyTest.php tests/Base/FsPageConstructionTest.php tests/Base/FsRolAccessAdminOnlyTest.php tests/Base/FsUserComposeMenuTest.php tests/Controller/AdminOnlyListingTest.php tests/Controller/AdminOnlyScopeTest.php tests/Core/AdminOnlyPagesMigrationTest.php` | `OK (62 tests, 135 assertions)` **at snapshot time**; the same command reports more now, because tests were added after this report (W-2/W-3/S-3 and the access-gate test) |
| `ddev exec php vendor/bin/phpunit --filter MaintenanceModeCompatTest` | `3/3` pass (6 deprecations) — **the 2 allegedly pre-existing failures did not manifest** |
| Independent behavioral script (16 checks: PA-01/PA-03/PA-05/PA-06/PA-09) | `16 passed, 0 failed` |

The full-suite run contains **no new failures**. The two environment-dependent
`MaintenanceModeCompatTest` failures described in `apply-progress.md` did not reproduce
in this environment (they are DB/fixture-dependent). Because they do not fail here, I
could neither "confirm them as pre-existing" nor "confirm them as fixed" — see
*Could not verify*. No test was modified by this verification.

## Compliance table

| Requirement | Status | Evidence (`file:line` / test) | Scenario coverage |
|---|---|---|---|
| **PA-01** Declaration via `#[AdminOnly]` | **COMPLIANT** | `src/Attribute/AdminOnly.php:35` (`#[\Attribute(\Attribute::TARGET_CLASS)]`); `model/fs_page.php:221-240` compares `getAttributes()->getName()` and never calls `newInstance()`/`getArguments()`; 4th `$admin` is never read (`base/fs_controller.php:191` docblock) | 3/3: `AdminOnlyAttributeTest::legacyAndModernControllersResolveToTrue`, `::nonLoadableAttributeResolvesByNameString`, `::constructorThrowingAttributeIsNeverInstantiated`, `::constructorFlagAloneIsNotADeclaration`. Independently re-proven with an exploding attribute + non-loadable FQCN (script, 4/4). *Modern scenario approximated by a plain fixture — see S-2.* |
| **PA-02** Persisted `admin_only` mirror | **COMPLIANT** | `model/table/fs_pages.xml:43` (`boolean NOT NULL DEFAULT false`); `model/fs_page.php:73` (property), `:90` (ctor read via `str2bool`), `:122` (`__clone`), `:181`/`:185-192` (UPDATE/INSERT), `:262-281` (`all()` exposes it) | 3/3: `FsPageAdminOnlyTest::defaultIsFalseWhenNoAdminOnlyKeyIsPresent`, `::roundTripReadsTrueFromPersistedValue`, `::clonePreservesTheFlag`, `::allExposesTheFlagForMixedRows`, `::insertSqlPersistsTheFlag`, `::updateSqlPersistsTheFlag`. *Clone test is tautological w.r.t. the added line — S-1.* |
| **PA-03** Effective value rule (OR-escalation, fail-closed) | **COMPLIANT** | `model/fs_page.php:253-256` (`$attribute \|\| filter_var(..., FILTER_VALIDATE_BOOLEAN)`); modern wiring `src/Core/Base/Controller.php:161-164`; legacy payload `base/fs_controller.php:825,832` | 3/3: `FsPageAdminOnlyTest::resolveAdminOnlyOrEscalates` (8-case truth table incl. attr-over-false-string and data-only). Modern wiring is source-pinned (`FsPageConstructionTest::modernResolveOrCreatePageEscalatesTheFlag`) rather than behaviorally executed — S-2. |
| **PA-04** Listing excludes admin-only for every actor | **COMPLIANT** | `controller/admin_rol.php:65`, `controller/admin_users.php:176`, `controller/admin_user.php:137` — unconditional `if ($m->admin_only === TRUE) continue;` | 1/1: `AdminOnlyListingTest::adminRolMatrixExcludesAdminOnlyPages`, `::adminUsersMatrixExcludesAdminOnlyPages`, `::adminUserMatrixExcludesAdminOnlyPagesEvenWithAStaleGrant` — all three matrices, ordinary page kept, admin-only dropped. *The actor provider parameter is unused — W-2.* |
| **PA-05** Saving refuses admin-only grants unconditionally | **COMPLIANT** | `model/fs_rol_access.php:68-73` (refusal is the first statement in `save()`), `:119` (`findPage`), `:134-142` (`is_admin_only_page`, missing row → allow per D5). Direct writer: `plugins/factura_pdf1/Services/LegacyRolePermissionsGateway.php:66` calls `$access->save()`. Repo-wide grep found no other literal writer of `fs_roles_access` except the migration's purge. | 2/2: `FsRolAccessAdminOnlyTest::saveRefusesAnAdminOnlyPageWithoutExecutingSql`, `::saveAllowsAnOrdinaryPage`, `::saveAllowsWhenThePageRowIsMissing`, `::saveAllowsAnEmptyPageName`. Independently re-proven (refuse + zero SQL; ordinary and missing-row save proceed). *Gateway covered structurally, not directly — S-6.* |
| **PA-06** Access enforcement at the menu source | **COMPLIANT** | `model/core/fs_user.php:332-345` (`get_menu` → `compose_menu`), `:361-379` (non-admin skips `admin_only`; admin/demo keep all), `:418-429` (`have_access_to` iterates `get_menu`). Propagation: `base/fs_controller.php:641-670` (`select_default_page`), `:253` (legacy gate), `src/Core/Base/Controller.php:195-203` (`enforcePageAccessOrExit`), `controller/admin_user.php:255` (default-page check) — all consume `have_access_to()`. | Stale grant denied + admin keeps list: tested (`FsUserComposeMenuTest`). `have_access_to` propagation: tested. *The three named propagation sites are source-verified only — W-3.* |
| **PA-07** Core page scope | **COMPLIANT** | Exactly 9 files carry `#[\FSFramework\Attribute\AdminOnly]` and no other file does (repo-wide grep, incl. plugins): `admin_users`, `admin_user`, `admin_rol`, `admin_info`, `admin_email`, `admin_system_branding`, `admin_stealth`, `admin_orden_menu`, `admin_agentes`. `admin_home.php` has none. Migration allowlist `src/Core/Schema/AdminOnlyPagesMigration.php:53-63` is exactly those 9 names (no `LIKE`). Persistence path: `base/fs_controller.php:825` → `:871` and `:884`. | 1/1: `AdminOnlyScopeTest::scopedControllersResolveAdminOnly` (9-class provider), `::adminHomeIsNotAdminOnly`, `::attributeIsDeclaredOnExactlyTheScopedFiles`, `::adminHomeSourceDoesNotDeclareTheAttribute`; allowlist asserted in `AdminOnlyPagesMigrationTest::allowlistIsExplicitAndExcludesNonScopedPages`. *No live-DB persistence assertion (harness has no DB) — W-4.* |
| **PA-08** Idempotent one-shot migration | **COMPLIANT** (source) / coverage **PARTIAL** | `src/Core/Schema/AdminOnlyPagesMigration.php:80-94` (ordered steps, flag last), `:100-106` (`isApplied` no-op), `:53-63` (fixed allowlist), `:112-116` (`forgetCheckedTables`), `:122-126` (lazy adoption), `:132-142` (backfill), `:147-152` (purge), `:158-162` (`m_fs_page_all`), `:167-171` (flag). Bootstrap: `index.php:90-96`, `api.php:136-142`, `cron.php:65-71`, all **after** `selfHealCoreTables()`; `cron.php:35-39` now loads Composer's autoloader. | 3/3 scenarios: `AdminOnlyPagesMigrationTest::firstRunPerformsAllStepsInOrder`, `::alreadyAppliedIsANoOp`, `::failingStepSkipsTheFlagAndTheNextRunRetries`, `::bootstrapRunsTheMigrationAfterSelfHeal`, `::cronLoadsTheComposerAutoloaderBeforeTheMigration`. *Real step SQL never executed (recorder overrides) and non-throwing failures still write the flag — W-1, W-4.* |
| **PA-09** `FS_DEMO` exception acknowledged | **COMPLIANT** | `model/core/fs_user.php:337,340,363-364` preserves the all-pages branch when `FS_DEMO` is true | 1/1: `FsUserComposeMenuTest::demoModeKeepsTheFullList`. `FS_DEMO` is `false` in the test bootstrap, so only `compose_menu`'s demo semantics are exercised, not the constant-driven branch — S-3. |
| **PA-10** Backwards compatibility | **COMPLIANT** | `model/fs_page.php:90,103` (absent → `false`), `:253-256` (`false || null` → `false`); `admin_home` and all undeclared controllers resolve `false`; full suite green with the pre-existing 2293 tests unchanged | 1/1: `FsPageConstructionTest::absentAttributeAndValueResolveToFalse`, `FsPageAdminOnlyTest` default/absent cases, `AdminOnlyScopeTest::adminHomeIsNotAdminOnly`. |

All 21 scenarios from the delta spec are mapped above.

## Adversarial checks on the migration

- **Allowlist is exact**: `PAGE_ALLOWLIST` (`AdminOnlyPagesMigration.php:53-63`) equals the 9
  controllers that declare the attribute; no `LIKE 'admin_%'`; `admin_custom`/`admin_home`
  excluded (asserted).
- **Idempotent**: `execute()` returns early when `isApplied()` is true; the flag is read
  fresh from `fs_var` each call (no caching in `simple_get`, `model/fs_var.php:115-123`).
- **Flag only on success** (letter): `markApplied()` is the last step and a throwing step
  propagates before it (recorder test proves the order and the retry). **Gap:** the steps
  do not inspect return values — `backfill()`/`purgeStaleGrants()` ignore `exec()`'s bool
  and `adoptColumn()` does not verify the column exists, so a *non-throwing* SQL failure
  still reaches `markApplied()` (W-1).
- **`fs_checked_tables` cleared**: `forgetCheckedTables(['fs_pages'])` removes `fs_pages`
  from the static array and rewrites the `fs_checked_tables` cache key
  (`base/fs_model.php:444-461`), after which `new \fs_page()` re-enters `check_table()`.
- **`m_fs_page_all` cleared**: `clearPageCache()` deletes the exact key that
  `fs_page::all()`/`save()` use (`model/fs_page.php:207,265,277` → `fs_cache` → same
  `CacheManager` key). It runs **after** the raw UPDATE/DELETE, so the cache cannot
  resurrect pre-backfill rows.
- **Static connection reuse**: `fs_db2` holds a static engine (`base/fs_db2.php:39,47-59`);
  the connection opened by `selfHealCoreTables()` is reused by the migration even though
  it constructs its own `fs_db2`. No "runs disconnected" bug.
- **Cross-engine reads**: `str2bool` accepts `'t'`/`'1'` (`base/fs_model.php:236-239`), so
  the guard and the menu filter behave the same on MySQL and PostgreSQL.

## Issues

### CRITICAL
None.

### WARNING

**W-1 — Migration writes the success flag after a silent step failure (PA-08 "only on success").**
`src/Core/Schema/AdminOnlyPagesMigration.php:132-142` (`backfill`), `:147-152`
(`purgeStaleGrants`) and `:122-126` (`adoptColumn`) discard the boolean/return of
`$this->db()->exec(...)` and never verify the column was adopted. `execute()` (`:86-93`)
therefore always reaches `markApplied()` unless a step throws, and `markApplied()` (`:167-171`)
does not check `simple_save()`. The spec requires the flag "only after all steps succeed".
Impact is bounded — enforcement is layered (`fs_rol_access::save()` re-reads the flag;
`get_menu()` filters; controllers self-heal on first visit) so this is not a bypass, but a
failed backfill/purge would be permanently skipped.
*Remediation*: make each step `throw` (or return `false` and have `execute()` abort) when
its SQL/return fails, verify `admin_only` exists after `adoptColumn()`, and have
`markApplied()` throw when `simple_save()` returns `false`.

**W-2 — `AdminOnlyListingTest` never uses the actor it advertises.**
`tests/Controller/AdminOnlyListingTest.php:113-119` provides `administrator actor` /
`non-admin actor`, but `$isAdmin` is unused in all three test bodies (`:164, :178, :192`);
the same code path runs twice. Because the production filter is unconditional, PA-04 still
holds, but the test would also pass if an actor-dependent regression were introduced
(e.g. an `if ($controller->user->admin) continue;` guard).
*Remediation*: drop the misleading provider, or wire the actor through `get_menu()` so the
admin-full-list + unconditional-filter composition is actually exercised.

**W-3 — PA-06 propagation scenarios have no direct test.**
`select_default_page` (`base/fs_controller.php:641-670`), `enforcePageAccessOrExit`
(`src/Core/Base/Controller.php:195-203`) and the `admin_user` default-page check
(`controller/admin_user.php:255`) are only covered transitively: they call
`have_access_to()`, which the tests exercise with a pre-injected menu. The requirement is
satisfied in source, but the named propagation sites and the "Propagation to default-page
selection" scenario have no executable evidence.
*Remediation*: add a focused test that seeds a non-admin with an admin-only default page
and asserts the default-page selection falls through to an ordinary page.

**W-4 — The migration's real steps and SQL are never executed by a test.**
`AdminOnlyPagesMigrationTest` overrides every step (`:73-101`) with a recorder, so the
actual `UPDATE fs_pages … WHERE name IN (…)`, the `DELETE FROM fs_roles_access …`, the
`m_fs_page_all` deletion, the `admin_only_pages_migrated` key and the `forgetCheckedTables`
call are not asserted. The ordering/flag logic is well covered; the data operations are
covered only by source review.
*Remediation*: add a variant that overrides only `isApplied()`/`markApplied()`, injects a
recording `fs_db2` double, and asserts the emitted SQL and cache key.

### SUGGESTION

**S-1 — Clone test is tautological w.r.t. the added line.**
`model/fs_page.php:113-124`: `__clone()` builds a throwaway `new fs_page()` and assigns to
a local `$page` that is discarded; PHP's shallow copy already carries `admin_only` before
`__clone()` runs. `FsPageAdminOnlyTest::clonePreservesTheFlag` therefore passes with or
without line `:122`. The observable requirement (clone keeps the flag) holds, but the test
does not protect the added line. Consider documenting shallow-copy semantics or asserting
the discarded-local pattern is intentional.

**S-2 — PA-01/PA-03 "modern" path is approximated.**
`AdminOnlyAttributeTest`'s "modern" fixture is a plain class; no real
`FSFramework\Core\Base\Controller` subclass with the attribute is exercised.
`modernResolveOrCreatePageEscalatesTheFlag` is a source-string pin. A constructor-free
modern fixture would close the gap.

**S-3 — `FS_DEMO` branch is not executed end-to-end.**
`FS_DEMO` is `false` in `tests/bootstrap.php:59`, so only `compose_menu(..., demo: true)` is
tested. The requirement (exception preserved) is met in source; a test that defines the
constant path or calls `get_menu()` with a demo `fs_user` would be stronger.

**S-4 — `isApplied()` boolean cast is fragile.**
`src/Core/Schema/AdminOnlyPagesMigration.php:105` returns `(bool) $fsvar->simple_get(...)`.
If the stored value were ever `'FALSE'`/`'0'` (not written by this code today),
`(bool) 'FALSE'` is `true` and the migration would never re-run. Prefer an explicit
`=== 'TRUE'` comparison.

**S-5 — `markApplied()` ignores `simple_save()`'s result.**
`src/Core/Schema/AdminOnlyPagesMigration.php:170`: a failed flag write is silently
swallowed and the migration re-runs next request (idempotent, low impact). Throwing on
failure would make the "retry" semantics explicit.

**S-6 — `LegacyRolePermissionsGateway` is covered structurally only.**
`plugins/factura_pdf1/Services/LegacyRolePermissionsGateway.php:66` delegates to
`fs_rol_access::save()`, which is where the refusal lives, so PA-05's "blocks the direct
writer" holds. No test instantiates the gateway; the evidence is the call chain plus the
guard test.

**S-7 — Scaffold-skill mirrors remain divergent (pre-existing).**
`.opencode/skills/fsframework-plugin-scaffold/SKILL.md` vs
`.cursor/skills/fsframework-plugin-scaffold/SKILL.md` differed by 480 lines before this
change and by 511 after (both received the `#[AdminOnly]` Step 6 updates; the pre-existing
divergence is unrelated to this change). Only the `fsframework-security-review` mirrors are
byte-identical (verified with `diff -q`). Consider syncing the scaffold mirrors separately.

## Could not verify

- **The two "pre-existing" `MaintenanceModeCompatTest` failures.** They did not reproduce:
  the test runs `3/3` green here and the full suite exits `0` with zero failures. Whether
  they ever failed, and whether this change "fixed" them, cannot be established from the
  current working tree without restoring the pre-change state (out of scope for a
  read-only verify). What *is* established: **no new failure exists**.
- **The 20 PHPUnit deprecations and 24 skips as "pre-existing".** They are counted
  (`phpunit.xml` sets `failOnWarning`/`failOnRisky`, yet exit is `0`), but classifying each
  as pre-existing requires a baseline run that would mutate the working tree.
- **Live-database behavior** of column adoption, backfill, purge, and the `admin_only`
  round-trip. The project's test harness intentionally has no DB; all DB-path evidence is
  source + static analysis + the recorder test. This is why PA-08's real SQL is W-4.
- **`fs_page::__clone()`'s production relevance.** No call sites were found (matching the
  design's note); its behavior is only observable via the unit test.
- **A "mutation test" of the implementation** (temporarily breaking production code to
  confirm the tests fail). The verify phase forbids modifying implementation files, so the
  mutation reasoning is done analytically per test above rather than executed.

## Conclusion

**PASS.** The implementation fulfils PA-01…PA-10, preserves both documented exceptions
(`admin_home` stays `false`; `FS_DEMO` keeps the all-pages branch), matches the 9-page
allowlist to the 9 real declarations, and closes the direct-writer path through
`fs_rol_access::save()`. The full suite is green (2355/7855, 0 failures) and the project
PHPStan gate reports `[OK] No errors`. The WARNINGs are robustness/coverage gaps, not
authorization bypasses; W-1 (migration flag on silent failure) is the only letter-of-spec
gap and should be fixed in a follow-up.
