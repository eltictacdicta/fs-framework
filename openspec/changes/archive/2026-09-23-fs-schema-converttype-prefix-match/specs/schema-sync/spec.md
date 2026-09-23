# Delta for schema-sync

## ADDED Requirements

Extends `schema-sync` (`SS-01`…`SS-06`) with `SS-07`: the CREATE-path type translator (`fs_schema::convertType()`) MUST resolve mapping keys by exact match, order-independently. Creation path only; no `ALTER TABLE`, no data migration.

| ID | Requirement |
|----|-------------|
| SS-07 | CREATE-path type translation MUST resolve `$typeMapping` keys by exact match, independent of declaration order |
| SS-07a | Every other mapped type MUST keep its current mapping; length suffix and Postgres passthrough MUST be preserved |
| SS-07b | Scope is creation-only; `TypeNormalizer::compareDataTypes()` MUST NOT change (deferred `time` ≡ `TIMESTAMP` boundary) |

### Requirement: SS-07 — Exact-match, order-independent type translation

The CREATE-path translator (`fs_schema::convertType()`) MUST map PostgreSQL XML types to MySQL by **exact** match of the normalized base type against `$typeMapping` keys. The result MUST NOT depend on declaration order: reordering the map MUST NOT change any returned type. A key that is merely a prefix of the base type MUST NOT match. `timestamp` MUST map to `TIMESTAMP`; `datetime` MUST map to `DATETIME`. The method is `private static`; tests reach it via `ReflectionMethod` without a database.

#### Scenario: timestamp maps to TIMESTAMP

- **GIVEN** XML type `timestamp`, MySQL active (`$isMySQL = true`)
- **WHEN** `convertType('timestamp', true)` is invoked
- **THEN** it returns exactly `TIMESTAMP`
- **AND** not `TIME`

#### Scenario: datetime maps to DATETIME

- **GIVEN** XML type `datetime`, MySQL active
- **WHEN** `convertType('datetime', true)` is invoked
- **THEN** it returns exactly `DATETIME`
- **AND** not `DATE`

#### Scenario: timestamp with length keeps the length

- **GIVEN** XML type `timestamp(6)`, MySQL active
- **WHEN** `convertType('timestamp(6)', true)` is invoked
- **THEN** it returns exactly `TIMESTAMP(6)`
- **AND** not `TIME(6)`

#### Scenario: timestamp without time zone maps to TIMESTAMP

- **GIVEN** XML type `timestamp without time zone`, MySQL active
- **WHEN** `convertType('timestamp without time zone', true)` is invoked
- **THEN** it returns exactly `TIMESTAMP`

#### Scenario: Mapping is independent of declaration order

- **GIVEN** inputs where one key prefixes another (`date`/`datetime`, `time`/`timestamp`, `character`/`character varying`)
- **WHEN** converted with the map in current order and again reversed
- **THEN** every input returns the same type in both orders
- **AND** no input resolves to the mapping of a key that is merely its prefix

### Requirement: SS-07a — No regression, length suffix and passthrough preserved

Every other `$typeMapping` key MUST keep its current, correct mysql mapping. When the mapped type has no parentheses and the input carries a length, the length MUST be appended as a suffix. When `$isMySQL` is false (PostgreSQL), the input MUST be returned unchanged.

#### Scenario: character varying maps to VARCHAR, not CHAR

- **GIVEN** XML type `character varying(6)`, MySQL active
- **WHEN** `convertType('character varying(6)', true)` is invoked
- **THEN** it returns exactly `VARCHAR(6)`
- **AND** not `CHAR(6)`

#### Scenario: character maps to CHAR

- **GIVEN** XML type `character(10)`, MySQL active
- **WHEN** `convertType('character(10)', true)` is invoked
- **THEN** it returns exactly `CHAR(10)`

#### Scenario: integer family maps distinctly

- **GIVEN** MySQL active
- **WHEN** `integer`, `smallint`, `bigint` are converted
- **THEN** they return exactly `INT`, `SMALLINT`, `BIGINT` respectively
- **AND** `smallint`/`bigint` do not return `INT`

#### Scenario: plain temporal types keep their mapping

- **GIVEN** MySQL active
- **WHEN** `date`, `time`, `time without time zone` are converted
- **THEN** they return exactly `DATE`, `TIME`, `TIME` respectively

#### Scenario: Remaining mapped types keep their mapping

- **GIVEN** MySQL active
- **WHEN** each is converted
- **THEN** `double precision` → `DOUBLE`, `numeric(12,2)` → `DECIMAL(12,2)`, `boolean` → `TINYINT(1)`, `serial` → `INT AUTO_INCREMENT`, `text` → `TEXT`, `bytea` → `BLOB`, `real` → `FLOAT`

#### Scenario: Length suffix is preserved

- **GIVEN** an input whose base type maps to a mysql type without parentheses
- **WHEN** a length is present (e.g. `character varying(6)`)
- **THEN** the mapped type is returned with the length appended (`VARCHAR(6)`)

#### Scenario: PostgreSQL passthrough is unchanged

- **GIVEN** `$isMySQL = false` (PostgreSQL)
- **WHEN** `convertType('timestamp', false)` and `convertType('datetime', false)` are invoked
- **THEN** each returns its input unchanged (`timestamp`, `datetime`)

#### Scenario: CREATE TABLE integration emits TIMESTAMP

- **GIVEN** an XML table snippet declaring `<tipo>timestamp</tipo>`
- **WHEN** `createTable()` generates the CREATE DDL with the fake-DB harness
- **THEN** the DDL types that column `TIMESTAMP`
- **AND** not `TIME`

### Requirement: SS-07b — Creation-only scope and deferred related defect

The fix MUST cover table CREATION only and MUST NOT add any `ALTER TABLE`, data migration, or retroactive column repair. Column re-typing on exists-then-sync continues to be served by `TypeNormalizer::convertPostgresType`, unchanged by this change. Separately, `src/Database/TypeNormalizer::compareDataTypes()` prefix-matches so `time` is treated as equivalent to `TIMESTAMP`; an existing wrongly-typed `TIME` column is therefore **not** auto-repaired by sync. This change MUST NOT modify that behaviour; the limitation is an explicit deferred boundary, not a fix delivered here.

#### Scenario: No migration or ALTER is introduced

- **GIVEN** the fix applied to the CREATE-path translator
- **WHEN** the change is inspected
- **THEN** no `ALTER TABLE`, data migration, or retroactive repair is added
- **AND** the exists-then-sync path (`TypeNormalizer`) is unchanged

#### Scenario: Existing wrongly-typed columns are not auto-repaired

- **GIVEN** an existing column typed `TIME` whose XML declares `timestamp`
- **WHEN** a plugin sync runs
- **THEN** no `ALTER` is emitted, because `compareDataTypes('time', 'TIMESTAMP')` still returns `true`
- **AND** this documented boundary is not treated as a failed fix
