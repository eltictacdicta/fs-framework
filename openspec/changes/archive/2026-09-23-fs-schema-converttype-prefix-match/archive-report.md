# Archive Report: fs-schema-converttype-prefix-match

**Archived**: 2026-09-23
**Change type**: CORE (touches `base/fs_schema.php`)
**Location**: `openspec/changes/archive/2026-09-23-fs-schema-converttype-prefix-match/`

## Closing Summary

Fixed a prefix-matching defect in `fs_schema::convertType()` that translated
PostgreSQL-style XML types to the wrong MySQL type. Because the type mapping was
scanned with `strpos($baseType, $pgType) === 0` in declaration order, and short
prefixes are declared before the longer keys they prefix, `timestamp` resolved to
`TIME` (losing the date) and `datetime` resolved to `DATE` (losing the time).

The fix is a single condition change at `base/fs_schema.php:430`:
`$baseType === $pgType` instead of the prefix scan. The loop, the regex, the
length-suffix rule and the PostgreSQL passthrough are unchanged. Matching is now
exact, so declaration order can never influence a result.

## Delivered Artifacts

| Artifact | Status |
|---|---|
| `exploration.md` | present |
| `proposal.md` | present |
| `specs/schema-sync/spec.md` (delta) | present |
| `design.md` | present |
| `tasks.md` | 20/20 checked |
| `archive-report.md` | this file |

## Final State

### Code

| File | Change |
|---|---|
| `base/fs_schema.php` | 1 line + 1 comment (`:430`, exact match) |
| `tests/Core/FsSchemaConvertTypeTest.php` | NEW — 29 tests |
| `VERSION` | `0.22.1` → `0.22.2` |

### Verified behaviour (measured by the orchestrator against the real code)

| Input | Before | After |
|---|---|---|
| `timestamp` | `TIME` | `TIMESTAMP` |
| `datetime` | `DATE` | `DATETIME` |
| `timestamp(6)` | `TIME(6)` | `TIMESTAMP(6)` |
| `date` / `time` | `DATE` / `TIME` | `DATE` / `TIME` (unchanged) |
| `character varying(6)` | `VARCHAR(6)` | `VARCHAR(6)` (unchanged) |
| `smallint` | `SMALLINT` | `SMALLINT` (unchanged) |
| `double precision` | `DOUBLE` | `DOUBLE` (unchanged) |

### Commits

Core repo `panel-ab`, branch `fix/fs-schema-converttype-exact-match` (created from
`master` — note: the core default branch is `master`, not `main`).

| Commit | Message |
|---|---|
| `2d516d56` | `fix(schema): match fs_schema::convertType() mapping keys exactly` |
| `cd2f08f9` | `chore(release): bump core VERSION to 0.22.2` |

Nothing was merged, tagged or pushed. Branches are local; commit/push/tag remain
human decisions under ordinary repository policy (see the `fsframework-core-release`
skill for the core release flow).

### Tests

| Suite | Result |
|---|---|
| `--testsuite Core` | `OK (291 tests, 719 assertions)` |
| root suite (executor baseline) | `3 failures` — all `Tests\OidcProvider\...` |
| root suite (orchestrator re-measure) | `11 failures` — all `Tests\OidcProvider\...` |

**The root-suite failure count is NOT stable and is NOT caused by this change.**
It moved from 3 to 11 across two runs minutes apart, entirely inside
`Tests\OidcProvider\...` (notably `admin_oidc_customer_access_htmxTest`, absent
from the earlier run). This is the pre-existing, DB-state-dependent,
non-deterministic flakiness documented repeatedly during this session, aggravated
by other sessions running against the same MySQL instance. Zero failures are
attributable to `fs_schema` or to any core file.

## Spec Sync

`SS-07`, `SS-07a` and `SS-07b` were merged into the source of truth
`openspec/specs/schema-sync/spec.md` (requirements table + full requirement blocks
with all 15 scenarios). The existing `SS-01`…`SS-06` blocks were preserved intact.

## Scope Guard — Verified

`git diff --name-only` against `master` shows ONLY:
```
VERSION
base/fs_schema.php
tests/Core/FsSchemaConvertTypeTest.php
```

- `src/Database/TypeNormalizer.php` — **NOT modified** (verified).
- No `ALTER TABLE`, migration, or retroactive repair anywhere in the diff.
- Scope was NOT expanded to `timestamp with time zone` (stays unmapped/fallback
  per the proposal; it appears in 0 XML files).
- No entries created in any plugin `openspec/`, and `openspec/specs/schema-sync/spec.md`
  was only updated at archive time (not during earlier phases).

## ⚠️ Operational Follow-up — required, NOT part of this change

After this fix, the two wrongly-typed columns on the **dev** database,
`factura_pdf1_settings.created_at` and `factura_pdf1_settings.updated_at`, **remain
`TIME` and are NOT auto-repaired.**

This is expected, not a failed fix. `src/Database/TypeNormalizer::compareDataTypes()`
returns `true` for `time` ≡ `TIMESTAMP`, so the schema sync emits no `ALTER`. That
defect was deliberately left untouched (see the Deferred Boundary below).

Remedy is manual and out-of-band: drop/recreate the dev table, or run a one-off
`ALTER`. The human confirmed those columns are **not in production**, so no
production data is affected and nothing needs to be recovered.

## Deferred, Related Defect (documented, explicitly NOT fixed)

`src/Database/TypeNormalizer::compareDataTypes()` also prefix-matches
(`substr($dbLower, 0, 4) == 'time' && substr($xmlLower, 0, 4) == 'time'`), which
makes `time` and `TIMESTAMP` equivalent for schema comparison. This is why an
existing wrongly-typed column is never healed. Fixing it would change sync
behaviour on existing installs — outside the agreed no-risk scope. It remains a
known, separate follow-up.

## Un-automated Surfaces

- The real `CREATE TABLE` against a live database is covered by a fake-`fs_db2`
  harness, not by a real DDL execution. The generated DDL string is asserted.
- No integration test exercises a full install boot with the corrected mapping.
  The verification is unit-level on a pure string-mapping function.

## Method Notes

- The design was validated by a fresh-context validator that **executed** the
  designed implementation against the real code, in normal AND inverted map order,
  confirming the order-independence claim is genuine and non-tautological.
- Two factual errors in `design.md` were caught by that validation and corrected
  in `tasks.md` (the bootstrap does not load `fs_schema`; the blast-radius file
  count was 39, not 40).
- A substring trap was caught before implementation: `TIME` is a substring of
  `TIMESTAMP`, so a naive negative DDL assertion would fail on the correct output.
  The tasks mandate `preg_match('/`col`\s+TIME(?!STAMP)/', $sql) === 0`.
