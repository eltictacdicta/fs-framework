# Proposal: Fix fs_schema::convertType() Prefix Match

## Intent

`fs_schema::convertType()` (`base/fs_schema.php:416`) translates PostgreSQL-style XML types to MySQL. It walks `$typeMapping` (`:60-77`) with a **prefix match** (`strpos($baseType, $pgType) === 0`, `:430`) in **declaration order**, where short keys precede the longer keys they prefix (`date` before `datetime`, `time` before `timestamp`). Empirical Reflection result: `timestamp` → `TIME`, `datetime` → `DATE`, `timestamp(6)` → `TIME(6)`, `timestamp without time zone` → `TIME`. The fix is confined to the **creation path**; the 2 wrongly-typed dev columns are not in production, so no migration is needed.

## Scope

### In Scope
- Exact-match fix so `timestamp`→`TIMESTAMP`, `datetime`→`DATETIME`, `timestamp [without time zone]`→`TIMESTAMP`.
- Order-independent correctness for every prefix-hazard pair: `character varying`/`character`, `time`/`timestamp`, `date`/`datetime`, `smallint`/`bigint`, `double precision`, `numeric`, `boolean`, `text`, `bytea`, `serial`. `character varying`/`character` is currently correct *only* by map order (latent) and must become order-independent.
- Unit tests (Reflection — `convertType()` is private): exhaustive map provider, hazard pairs, Postgres passthrough, `createTable()` integration.

### Out of Scope
- Any `ALTER TABLE` / data migration / retroactive repair.
- `src/Database/TypeNormalizer.php:187-189` (`compareDataTypes()` prefix match, `time` ≡ `TIMESTAMP`) — related, deferred; fixing it changes sync behaviour on existing installs.
- `timestamp with time zone`: the exploration concluded it appears in **no** XML → out of scope (optional regex hardening, rec. (b), deferred).
- Postgres path (unaffected passthrough).

## Capabilities

### New Capabilities
None.

### Modified Capabilities
- `schema-sync` (`openspec/specs/schema-sync/spec.md`, requirements `SS-01`…`SS-06`): add requirement **SS-07** — the CREATE-path type translation MUST match mapping keys exactly and be order-independent.

## Approach

**Option B — exact match.** At `base/fs_schema.php:430` replace `if ($baseType === $pgType || strpos($baseType, $pgType) === 0)` with `if ($baseType === $pgType)`. The `:424` regex already strips length and `without time zone`, so `$baseType` is a clean key. Simulating all four options against the 44 real XML types showed **all regression-free**; exact match was chosen because it removes the root cause, makes map order irrelevant, and gives `addTypeMapping()` exact semantics (no callers → no BC surface).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `base/fs_schema.php` | Modified | `convertType()` match strategy (`:430`) |
| `tests/Core/FsSchemaConvertTypeTest.php` | New | Reflection-based coverage |
| `openspec/specs/schema-sync/spec.md` | Modified | Delta `SS-07` (created in change, merged at archive) |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Perceived incomplete fix: the 2 dev columns keep `time` and sync will NOT self-repair them (`compareDataTypes('time','TIMESTAMP')` → `true`) | Med | Documented here and in tasks; recreate the dev table manually (no production data) |
| Map-order fragility (root cause survives if reordered) | Low | Exact match chosen — order becomes irrelevant |
| `datetime` (0 XML) and `timestamp with time zone` (0 XML; under B falls to an invalid literal) change behaviour | Low | Both unused; `with time zone` hardening deferred |
| Production impact | None | Mapping-only: no migration, no `ALTER`, no data movement |

## Rollback Plan

Revert the single condition at `base/fs_schema.php:430` and remove the new test file. No schema or DB state is touched.

## Dependencies

None.

## Success Criteria

- [ ] `convertType('timestamp', true) === 'TIMESTAMP'`; `convertType('datetime', true) === 'DATETIME'`
- [ ] `timestamp without time zone`→`TIMESTAMP`; `timestamp(6)`→`TIMESTAMP(6)`
- [ ] All other mapped types unchanged; hazard pairs explicitly asserted (`character varying`→`VARCHAR`, `character`→`CHAR`, `time`→`TIME`, `smallint`/`bigint`)
- [ ] Postgres passthrough (`$isMySQL = false`) unchanged
- [ ] `createTable()` integration emits `TIMESTAMP`, not `TIME`
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Core` green
