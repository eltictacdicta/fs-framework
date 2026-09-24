# Design: Fix `fs_schema::convertType()` Prefix Match

## Technical Approach

Change one condition in `base/fs_schema.php::convertType()` (`:430`): replace the
prefix scan with strict equality. Loop, regex, length-suffix rule and PostgreSQL
passthrough stay identical. `$baseType` is already normalized (length and
`without time zone` stripped at `:424`), so exact lookup covers 100% of the real
XML corpus and removes the defect class — equality never depends on map order.
Creation path only; no `ALTER`, no migration (`SS-07`, `SS-07a`, `SS-07b`).

## Architecture Decisions

| # | Option | Tradeoff | Decision |
|---|--------|----------|----------|
| D1 | Exact match (`$baseType === $pgType`) | One-line diff; removes root cause; order irrelevant; `addTypeMapping()` becomes exact (no callers → no BC surface) | **Chosen** |
| D1a | Reorder map (A) | Symptom only; prefix mechanism survives; a future key reintroduces the hazard | Rejected |
| D1b | Sort keys longest-first (C) | Provably safe but keeps the vestigial prefix scan the regex made unnecessary | Rejected |
| D1c | Word-boundary prefix (D) | More code, still order-dependent for `character`/`character varying` | Rejected |
| D2 | Keep `:424` regex unchanged | `without time zone` already stripped; `with time zone` unused in all XML | **Chosen** |
| D2a | Also strip `with time zone` | Robustness for a form in 0 XML; outside confirmed scope | Deferred |
| D3 | `tests/Core/FsSchemaConvertTypeTest.php` | Beside `FsSchemaTest.php` (same class) and `TypeNormalizerTest.php`; Core suite owns `fs_schema` | **Chosen** |
| D3a | `tests/Base/` | Directory matches `base/` but no `fs_schema` test lives there | Rejected |

Exact match wins because A/C/D leave prefix matching in place — the bug class
recurs on any future map edit. Exact match makes the result a pure function of
the normalized base type.

## Implementation

Only `:430` changes:

```php
foreach (self::$typeMapping as $pgType => $mysqlType) {
    if ($baseType === $pgType) {                       // was: === || strpos(...) === 0
        if ($length && strpos($mysqlType, '(') === false) {
            return "{$mysqlType}({$length})";
        }
        return $mysqlType;
    }
}
return strtoupper($type);   // unchanged fallback
```

## Order Independence — Mechanism + Full Mapping/Hazard Table

**Mechanism**: `$baseType === $pgType` compares the normalized input to one key
at a time. No key equals a different key, so the loop returns the same value
under every permutation of `$typeMapping` — no "first prefix wins" tie-break
remains. Empirically verified via Reflection (`ddev exec php`); `*` = bug:

| Input | Pre-fix | Post-fix | Hazard |
|---|---|---|---|
| `timestamp` | `*TIME*` | `TIMESTAMP` | `time` prefixed it |
| `datetime` | `*DATE*` | `DATETIME` | `date` prefixed it |
| `timestamp without time zone` | `*TIME*` | `TIMESTAMP` | 4 XML cols |
| `timestamp(6)` | `*TIME(6)*` | `TIMESTAMP(6)` | length preserved |
| `timestamp with time zone` | `*TIME*` | `TIMESTAMP WITH TIME ZONE` | 0 XML |
| `time` / `time without time zone` / `time(3)` | `TIME` | `TIME` | none |
| `character varying` / `character varying(6)` | `VARCHAR` / `VARCHAR(6)` | same | **correct only by order** |
| `character` / `character(10)` | `CHAR` / `CHAR(10)` | same | none |
| `text` | `TEXT` | same | none |
| `integer` | `INT` | same | none |
| `smallint` / `bigint` | `SMALLINT` / `BIGINT` | same | none |
| `boolean` | `TINYINT(1)` | same | none |
| `double precision` | `DOUBLE` | same | `precision` not a key |
| `real` | `FLOAT` | same | none |
| `numeric` / `numeric(12,2)` | `DECIMAL` / `DECIMAL(12,2)` | same | none |
| `date` | `DATE` | same | none |
| `bytea` | `BLOB` | same | none |
| `serial` | `INT AUTO_INCREMENT` | same | none |
| `decimal(5,2)` (not a key → fallback) | `DECIMAL(5,2)` | same | none |
| `precision` (not a key → fallback) | `PRECISION` | same | none |

## `timestamp with time zone`

The regex strips only `without time zone`, so `baseType` stays
`timestamp with time zone`; exact match misses and the fallback yields
`TIMESTAMP WITH TIME ZONE` — **invalid MySQL DDL**. Latent only: 0 XML usages,
and pre-fix it was `TIME` (also wrong), so nothing regresses. Out of scope per
the proposal; D2a is the future fix.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `base/fs_schema.php` | Modify | `:430` condition → exact equality |
| `tests/Core/FsSchemaConvertTypeTest.php` | Create | Reflection + integration coverage |

## Testing Strategy

`convertType()` is `private static`; reach it with
`ReflectionMethod::setAccessible(true)`. No DB: `tests/bootstrap.php` sets
`FS_DB_TYPE = 'MYSQL'` (so `isMySQL()` is true) and requires `base/fs_schema.php`.

| Layer | What | How |
|---|---|---|
| Unit | Full table above | Data provider over every key + non-key input |
| Unit | Hazard pairs | `timestamp`/`datetime`/`timestamp(6)`/`timestamp without time zone`; `character varying(6)`→`VARCHAR(6)`, `character(10)`→`CHAR(10)`; `time`→`TIME`; int family; `double precision` |
| Unit | Postgres passthrough | `convertType($t, false) === $t` |
| Unit | **Order independence** | Genuinely discriminating — see below |
| Integration | `createTable()` emits `TIMESTAMP` | Mirror `FsSchemaTest`: inject fake DB, `createTable('probe', $xml)` with `<tipo>timestamp</tipo>`, assert `end($db->executed)` contains `` `created_at` TIMESTAMP `` and not `TIME` |

**Order-independence test (not a tautology)**: read `fs_schema::$typeMapping` via
`ReflectionProperty`, set it to a **reversed** copy, run `convertType` on a hazard
probe, and assert results equal the original order. Empirically verified this
discriminates: the buggy prefix code under reversed order yields
`TIMESTAMP | DATETIME | CHAR(6)` vs `TIME | DATE | VARCHAR(6)` under the original
order; exact match yields identical correct values under both. Restore the map in
`tearDown()`. Because the implementation reads the swapped map, this proves
order-independence directly.

## Blast Radius

`convertType()` has two callers: `parseColumn()` (→ `createTable`, `generateSql`)
and `resolveLocalFkColumnInfo()` (FK metadata). FK validation short-circuits for
non-collatable `TIME`/`TIMESTAMP`, so that result is never read — **no FK outcome
changes**. The only observable change is the type in **`CREATE TABLE`** for new
columns: no `ALTER`, no migration, no existing column touched. XML affected:
`timestamp` — 59 occurrences / 40 files (core `fs_logs`, `fs_users`; plugins
`api_base`, `OidcProvider`, `tarifario`, `catalogo_core`, `factura_pdf1`);
`timestamp without time zone` — 4 occurrences / `oidc_cliente_profiles.xml`;
`datetime` — 0. Existing installs are unaffected unless a table is recreated;
new/recreated tables get `TIMESTAMP`.

## Deferred Boundary — do not touch `TypeNormalizer::compareDataTypes()`

This change MUST NOT modify `src/Database/TypeNormalizer.php`. `compareDataTypes()`
(`:187-189`) still prefix-matches `time` vs `timestamp` → `true`, so
exists-then-sync emits **no `ALTER`** for a wrong `TIME` column (`SS-07b`).

> **Operational consequence — read before judging the fix.** After the fix,
> `factura_pdf1_settings.created_at`/`.updated_at` on the dev DB **remain `time`**.
> That is expected, not a failed fix: no auto-repair exists. They are not in
> production, so drop and recreate the table (or run a one-off `ALTER`) to see the
> corrected type. Do not mistake the stale dev column for a regression.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file
classification, or process-integration boundary.

## Migration / Rollout

No migration. One-line code change + new test. Rollback = revert `:430` and
delete the test. Optional non-code step: recreate the dev
`factura_pdf1_settings` table to clear the two stale `time` columns.

## Risks / Open Questions

- Map-order fragility: exact match makes order provably irrelevant; the
  reverse-order test locks it in. Residual risk only if a future edit
  reintroduces a prefix scan.
- `timestamp with time zone` → invalid MySQL literal, 0 XML usages, pre-fix also
  wrong; accepted, hardening deferred.
- `addTypeMapping()` is dead API; its contract becomes exact — no caller depends
  on prefix behaviour.
- No production risk: mapping-only, no migration, no data movement.
- [ ] Confirm `tests/Core/` (chosen, matches existing `FsSchemaTest`) vs
      `tests/Base/` for the new test file.
- [ ] This design exceeds the `sdd-design` 800-word budget because the phase
      prompt mandates a full mapping table, hazard enumeration and test plan;
      content was kept, prose was cut.
