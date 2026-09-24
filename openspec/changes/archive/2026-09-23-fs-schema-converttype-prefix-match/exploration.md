# Exploration: `fs_schema::convertType()` prefix-match bug

**Change:** `fs-schema-converttype-prefix-match`
**Owner:** core (`base/fs_schema.php`)
**Status:** explored, ready for proposal
**Constraint from the human (binding):** the two wrongly-typed columns
(`factura_pdf1_settings.created_at`, `.updated_at`) are **not in production**.
Scope is the **creation path only** — no data-remediation migration, no
`ALTER TABLE` on existing installs.

---

## 1. Routing decision — core `openspec/`, not a plugin openspec

Per the routing table in `.opencode/skills/fsframework-plugin-sdd/SKILL.md`
(lines 24-32):

| If the change… | SDD location |
|---|---|
| Only touches `plugins/{name}/` | `plugins/{name}/openspec/` |
| **Touches `base/`, `src/`, `controller/`, `model/` root, or cross-plugin conventions** | **`openspec/` (core)** |

This change touches exactly one file, `base/fs_schema.php`, a **core** file.
The defect lives in the framework's Postgres→MySQL type translator; it is not
owned by any plugin. `factura_pdf1` is merely the *carrier of the symptom*
(its XML declares `<tipo>timestamp</tipo>`), not the beneficiary. Opening a
`plugins/factura_pdf1/openspec/` entry for this would be the explicit
anti-pattern ("~~crear `openspec/changes/{name}/` en el core para trackear un
change que solo toca el plugin~~" — here inverted: a core fix tracked in a
plugin).

**Decision:** the full SDD lives in the core
`openspec/changes/fs-schema-converttype-prefix-match/`. The eventual delta spec
targets the existing canonical domain `openspec/specs/schema-sync/spec.md`
(requirements `SS-01`…`SS-06`; a new requirement `SS-07` is the natural slot).

---

## 2. Current state

### 2.1 The defect

`base/fs_schema.php:416-441` — `convertType()` translates PostgreSQL-style XML
types to MySQL dialect. When MySQL is active it normalizes the input with a
regex (`:424`), extracts `$baseType` and `$length`, then walks
`self::$typeMapping` with a **prefix match**:

```php
// base/fs_schema.php:429-436
foreach (self::$typeMapping as $pgType => $mysqlType) {
    if ($baseType === $pgType || strpos($baseType, $pgType) === 0) {   // :430
        if ($length && strpos($mysqlType, '(') === false) {
            return "{$mysqlType}({$length})";
        }
        return $mysqlType;
    }
}
```

Two independent defects combine:

1. **Prefix matching** — `strpos($baseType, $pgType) === 0` treats any key that
   is a *prefix* of the input as a match.
2. **Declaration order** — the map (`base/fs_schema.php:60-77`) lists the short
   prefix **before** the longer type it prefixes:

```php
'date'      => 'DATE',        // :71
'time'      => 'TIME',        // :72
'timestamp' => 'TIMESTAMP',   // :73
'datetime'  => 'DATETIME',    // :74
```

So `timestamp` matches `time` first → `TIME`; `datetime` matches `date` first →
`DATE`. Both silently **truncate the temporal component** of the column type.

### 2.2 Exact call graph of `convertType()`

`convertType()` is `private static` and has exactly **two** call sites
(verified: `grep -rn convertType` returns only these two callers plus the
declaration and `addTypeMapping`).

```
convertType($type, $isMySQL)                base/fs_schema.php:416   [private]
├── :244  resolveLocalFkColumnInfo()        → FK compatibility metadata ONLY
│            └── collectConstraints() :206  → addForeignKeyConstraint() :207
│                  └── FkCompatibilityValidator::isFkCompatible()  (src/Database/…:58)
│
└── :317  parseColumn()                     → ACTUAL SQL column type  ← BUG MATERIALISES HERE
     └── collectColumns() :168
          ├── createTable() :140             ← CREATE TABLE
          │    ├── createFromXml() :127
          │    │    └── installCoreTables() :779, :792   (core tables)
          │    └── syncTable() :632          ← ONLY when table does NOT exist (:631)
          │         └── collectSyncTableChanges() :695
          │              └── syncPluginTables() :677, :683
          │                   └── PluginSchemaSynchronizer::syncXmlTables() :110
          │                        └── PluginSchemaSynchronizer::synchronize() :31
          │                             └── fs_plugin_manager.php:628  (plugin enable/update)
          └── generateSql() :925             ← SQL preview / export, no execution
```

**Creation vs sync/ALTER — precisely:**

| Path | Entry | Converter used | Materialises a column type? |
|---|---|---|---|
| Core table install | `installCoreTables()` :758 → `createFromXml` :114 → `createTable` :137 | **`convertType` (buggy)** | **YES** — `CREATE TABLE` |
| Plugin table create | `syncPluginTables()` :656 → `syncTable` :617, `!tableExists` :631 → `createTable` | **`convertType` (buggy)** | **YES** — `CREATE TABLE` |
| Plugin table **sync** (table exists) | `syncTable` :638-641 → `syncColumns` :711 → `compare_columns` :725 | `TypeNormalizer::convertPostgresType` (exact match) | YES, but via `ALTER … MODIFY` |
| SQL preview | `generateSql()` :912 | `convertType` (buggy) | No (returns a string) |
| FK metadata | `resolveLocalFkColumnInfo()` :225 | `convertType` (buggy) | No (validation input only) |

The sync/ALTER path is **not** `convertType`. `fs_mysql::compare_columns`
(`base/fs_mysql.php:130-133`) delegates to
`FSFramework\Database\SchemaComparator::compareColumns`
(`src/Database/SchemaComparator.php:42`), which calls
`TypeNormalizer::convertPostgresType` (`src/Database/TypeNormalizer.php:53`).
That converter uses an **exact** comparison
(`src/Database/TypeNormalizer.php:70-73`: `if ($baseType !== $pgType) continue;`)
and is therefore **correct** — proven below (§3.4). The bug is confined to the
**creation path**.

### 2.3 The PostgreSQL path is unaffected

`base/fs_schema.php:418-420`:

```php
if (!$isMySQL) {
    return $type;          // PostgreSQL installs: type returned verbatim
}
```

On Postgres, `convertType()` is a pure passthrough and the CREATE path emits the
original XML type. Independently, the Postgres ALTER path
(`base/fs_postgresql.php:83-123`) also uses the raw `$xml_col['tipo']` directly
(`:93`, `:101`). **Postgres installs are provably unaffected** — confirmed
empirically in §3.1 (the "Postgres passthrough" column returns every input
unchanged, including `timestamp` and `datetime`).

### 2.4 `addTypeMapping()` is dead public API

`base/fs_schema.php:938-941` exposes `addTypeMapping($pgType, $mysqlType)` to
extend the map. A repository-wide `grep` finds **only its definition** — no
caller in core or any plugin. Therefore **no consumer relies on the prefix
behaviour**, and changing the match strategy has no backward-compatibility
surface.

---

## 3. Empirical verification

All results produced with `ddev exec php` + Reflection on the private method
(no files written), mirroring the orchestrator's method.

### 3.1 Current behaviour (`$isMySQL = true`)

```
INPUT XML type                     | MySQL (current)      | Postgres passthrough
----------------------------------------------------------------------------------
timestamp                          | TIME                 | timestamp      ← WRONG
datetime                           | DATE                 | datetime       ← WRONG
date                               | DATE                 | date
time                               | TIME                 | time
timestamp without time zone        | TIME                 | timestamp without time zone  ← WRONG
time without time zone             | TIME                 | time without time zone
timestamp with time zone           | TIME                 | timestamp with time zone     ← WRONG
timestamp(6)                       | TIME(6)              | timestamp(6)   ← WRONG
character varying(6)               | VARCHAR(6)           | character varying(6)
character                          | CHAR                 | character
character varying                  | VARCHAR              | character varying
smallint                           | SMALLINT             | smallint
bigint                             | BIGINT               | bigint
integer                            | INT                  | integer
double precision                   | DOUBLE               | double precision
real                               | FLOAT                | real
numeric(12,2)                      | DECIMAL(12,2)        | numeric(12,2)
boolean                            | TINYINT(1)           | boolean
serial                             | INT AUTO_INCREMENT   | serial
text                               | TEXT                 | text
bytea                              | BLOB                 | bytea
```

Regex normalization also verified: `timestamp without time zone` and
`time without time zone` are reduced to `baseType` `"timestamp"` / `"time"`
(the optional `\s+without\s+time\s+zone` group at `:424` strips the suffix).
`timestamp with time zone` is **not** stripped → `baseType` is
`"timestamp with time zone"` and the `time` prefix match still fires.

### 3.2 Exhaustive prefix-hazard enumeration in `$typeMapping`

`$typeMapping` order: `character varying`, `character`, `text`, `integer`,
`smallint`, `bigint`, `boolean`, `double precision`, `real`, `numeric`, `date`,
`time`, `timestamp`, `datetime`, `bytea`, `serial` (`base/fs_schema.php:60-77`).

A "hazard" exists when an earlier key is a prefix of a later key (or of an
input). Complete enumeration — every pair checked empirically:

| # | Pair | Earlier key is prefix of later? | CURRENT result | CORRECT expected | Hazard? |
|---|---|---|---|---|---|
| 1 | `time` → `timestamp` | **YES** (`time`) | `timestamp` → **TIME** | `TIMESTAMP` | **BUG** |
| 2 | `date` → `datetime` | **YES** (`date`) | `datetime` → **DATE** | `DATETIME` | **BUG** |
| 3 | `time` → `timestamp without time zone` | **YES** (baseType `timestamp`) | → **TIME** | `TIMESTAMP` | **BUG** (4 XML cols) |
| 4 | `time` → `timestamp with time zone` | **YES** (baseType keeps suffix) | → **TIME** | `TIMESTAMP` (MySQL) | **BUG** (unused in XML) |
| 5 | `time` → `time without time zone` | n/a (baseType `time`) | → `TIME` | `TIME` | OK |
| 6 | `character` → `character varying` | **YES** — but `character varying` is declared **first** | `character varying(N)` → `VARCHAR(N)` | `VARCHAR(N)` | OK *by order* |
| 7 | `integer` → `smallint`/`bigint` | NO (`integer` is not a prefix of either) | `SMALLINT` / `BIGINT` | same | OK |
| 8 | `double precision` → `precision` | `precision` is not a key | `double precision` → `DOUBLE` | `DOUBLE` | OK |
| 9 | `real`, `numeric`, `boolean`, `text`, `bytea`, `serial` | NO prefix relationships | unchanged | unchanged | OK |

Note on #6: `character`/`character varying` is *latent* — it is correct today
only because the map happens to declare `character varying` first. A fix must
preserve that (all four options below do).

### 3.3 The buggy conversion is the ONLY difference — the corpus

Complete distinct `<tipo>` corpus (core `model/table/` + all
`plugins/*/model/table/`, 44 distinct values). Simulated against the four
candidate fixes:

```
INPUT                            | CURRENT   | A reorder | B exact   | C len-desc | D wordbound
------------------------------------------------------------------------------------------------
timestamp                        | *TIME*    | TIMESTAMP | TIMESTAMP | TIMESTAMP  | TIMESTAMP
timestamp without time zone      | *TIME*    | TIMESTAMP | TIMESTAMP | TIMESTAMP  | TIMESTAMP
datetime                         | *DATE*    | DATETIME  | DATETIME  | DATETIME   | DATETIME
date                             | DATE      | DATE      | DATE      | DATE       | DATE
time                             | TIME      | TIME      | TIME      | TIME       | TIME
time without time zone           | TIME      | TIME      | TIME      | TIME       | TIME
character varying(N)             | VARCHAR(N)| VARCHAR(N)| VARCHAR(N)| VARCHAR(N) | VARCHAR(N)
double precision                 | DOUBLE    | DOUBLE    | DOUBLE    | DOUBLE     | DOUBLE
integer                          | INT       | INT       | INT       | INT        | INT
numeric(12,2)                    | DECIMAL(12,2) | same  | same      | same       | same
decimal(5,2) (fallback)          | DECIMAL(5,2)  | same  | same      | same       | same
boolean / serial / text          | unchanged | unchanged | unchanged | unchanged  | unchanged
(*value* = differs from expected)
```

**All four candidate fixes repair the bug with zero regressions across the
entire real XML corpus.** The options differ only on unused edge cases (§3.4).

### 3.4 Edge cases that differentiate the options

```
HAZARD/EDGE INPUT            | CURRENT                   | A reorder | B exact                   | C len-desc | D wordbound
---------------------------------------------------------------------------------------------------------------------------
timestamp with time zone     | TIME                      | TIMESTAMP | TIMESTAMP WITH TIME ZONE  | TIMESTAMP  | TIMESTAMP
timestamp(6)                 | TIME(6)                   | TIMESTAMP(6) | TIMESTAMP(6)           | TIMESTAMP(6) | TIMESTAMP(6)
time(3)                      | TIME(3)                   | TIME(3)   | TIME(3)                   | TIME(3)    | TIME(3)
character                    | CHAR                      | CHAR      | CHAR                      | CHAR       | CHAR
character varying            | VARCHAR                   | VARCHAR   | VARCHAR                   | VARCHAR    | VARCHAR
smallint / bigint            | SMALLINT / BIGINT         | same      | same                      | same       | same
precision (not a key)        | PRECISION (fallback)      | same      | same                      | same       | same
```

- **Option D remains order-dependent** for `character`/`character varying`:
  with the wrong order, `character varying` → `CHAR` (verified). It is safe only
  while the current order is preserved.
- **Option B is the only one that leaves `timestamp with time zone` unmapped**
  (fallback → `TIMESTAMP WITH TIME ZONE`, invalid MySQL). That form appears in
  **no** XML file today.
- Options A, C, D all map `timestamp with time zone` → `TIMESTAMP`.

### 3.5 The sync path cannot repair an already-wrong column (related finding)

Even though the ALTER-path converter (`TypeNormalizer`) is correct, a **second,
independent prefix comparison** masks the difference. In
`src/Database/TypeNormalizer.php:187-189`:

```php
if (substr($dbLower, 0, 4) == 'time' && substr($xmlLower, 0, 4) == 'time') {
    return true;   // "types are the same"
}
```

Empirically: `compareDataTypes('time', 'TIMESTAMP')` → **`true`** (treated as
identical ⇒ no `ALTER`). `compareDataTypes('date', 'DATETIME')` → `false`
(so a `datetime`→`DATE` mistake *would* be repaired by sync, but a
`timestamp`→`TIME` one would not).

Consequence: **an existing wrong `TIME` column is not retroactively corrected
by `syncPluginTables()`.** This is reported as a related, *out-of-scope*
finding (§8) — it does not change the recommendation and must not be fixed with
a migration.

---

## 4. Blast radius

### 4.1 XML declaration corpus

| Type declared in XML | Occurrences | Files | Affected by this bug? | Must not regress |
|---|---|---|---|---|
| `timestamp` | **59** | 40 files | **YES → currently TIME** | — |
| `timestamp without time zone` | **4** | `OidcProvider/model/table/oidc_cliente_profiles.xml` | **YES → currently TIME** | — |
| `datetime` | **0** | none | n/a (map entry only) | — |
| `time` | **1** | `model/table/fs_users.xml` (`last_login_time`) | no | **YES** |
| `time without time zone` | **7** | `clientes_facturacion` (×4), `catalogo_core` (×3) | no | **YES** |
| `character varying(N)` | 33 distinct widths, ~500 occurrences | core + plugins | no (latent, §3.2 #6) | **YES** |
| `double precision` | 166 | many | no | **YES** |
| `integer` / `boolean` / `serial` / `text` / `date` | 125 / 121 / 71 / 64 / 42 | many | no | **YES** |
| `numeric(12,2)` | 1 | — | no | **YES** |
| `decimal(5,2)` | 8 | — | no (fallback path) | **YES** |

**Files declaring `timestamp` (40), by ownership:**

- **Core (2):** `model/table/fs_logs.xml`, `model/table/fs_users.xml`
- **Plugin (38):**
  - `plugins/api_base/model/table/` — 11 files
  - `plugins/OidcProvider/model/table/` — 17 files
  - `plugins/tarifario/model/table/` — 7 files
  - `plugins/catalogo_core/model/table/` — 2 files (`tarif_articulo_imagenes`, `tarif_opcional_precio_historial`)
  - `plugins/factura_pdf1/model/table/factura_pdf1_settings.xml` — 1 file

**Which are NEW / at risk going forward:** every table whose `CREATE` runs after
this fix and that declares `timestamp`/`timestamp without time zone`. Core
`fs_logs`/`fs_users` already exist in dev with correct `timestamp` columns
(29 `timestamp` columns in the live DB), so the fix protects **future** creates
— new plugins, new tables, fresh installs, and any table recreated after being
dropped.

### 4.2 Live dev-DB impact (verified)

```
DATA_TYPE   COUNT
varchar     549
double      162
int         161
tinyint     103
text         43
date         35
timestamp    29
time         10      ← only 2 are the bug
decimal       9
datetime      1      (oidc_rate_limit.created_at — correct, created via a non-fs_schema path)
```

All 10 `time` columns, each cross-checked against its XML source:

| Column | XML `<tipo>` | Verdict |
|---|---|---|
| `albaranescli.hora` | `time without time zone` | legitimate |
| `facturascli.hora` | `time without time zone` | legitimate |
| `pedidoscli.hora` | `time without time zone` | legitimate |
| `presupuestoscli.hora` | `time without time zone` | legitimate |
| `lineasregstocks.hora` | `time without time zone` | legitimate |
| `transstock.hora` | `time without time zone` | legitimate |
| `stocks.horaultreg` | `time without time zone` | legitimate |
| `fs_users.last_login_time` | `time` | legitimate |
| **`factura_pdf1_settings.created_at`** | **`timestamp`** | **BUG** |
| **`factura_pdf1_settings.updated_at`** | **`timestamp`** | **BUG** |

Confirmed directly:
`factura_pdf1_settings.created_at` and `.updated_at` are `time` in the DB
(`COLUMN_TYPE = time`) while `factura_pdf1_settings.xml:24-33` declares
`timestamp`. **Only these 2 columns are wrong**, and per the human they are not
in production.

---

## 5. Fix options with tradeoffs

All options change **only** `base/fs_schema.php::convertType()` (or its map).
All are empirically verified (§3.3, §3.4).

### Option A — Reorder the map (specific keys first)
Move `timestamp` before `time`, `datetime` before `date` (and keep
`character varying` before `character`).
- **Correctness:** ✅ all 44 corpus types + all hazards, incl. `timestamp with time zone`.
- **Risk:** low diff, but **order-fragile** — the root cause (prefix matching) survives; a future map addition can re-introduce the hazard.
- **New-column impact:** none beyond the intended fix.
- **Effort:** Low.

### Option B — Exact match only
Replace `if ($baseType === $pgType || strpos($baseType, $pgType) === 0)` with
`if ($baseType === $pgType)`. The regex already strips length and
`without time zone`, so `baseType` is a clean key.
- **Correctness:** ✅ all 44 corpus types + every hazard **except** `timestamp with time zone` (unused) → fallback `TIMESTAMP WITH TIME ZONE`.
- **Risk:** **lowest conceptual risk** — eliminates prefix matching entirely, so ordering becomes irrelevant and the defect class cannot recur. Makes `addTypeMapping()` semantics unambiguous (exact keys).
- **New-column impact:** only the intended fix; verified no regression.
- **Effort:** Low (one condition).

### Option C — Sort keys by length descending before matching
Keep prefix matching, iterate longest-first.
- **Correctness:** ✅ all corpus types + all hazards incl. `timestamp with time zone`. Provably safe: a prefix is never longer than the string it prefixes, so longest-first always prefers the specific key.
- **Risk:** low; ordering of the source map becomes cosmetic. Retains prefix matching (vestigial now that the regex strips `without time zone`).
- **New-column impact:** none beyond the intended fix.
- **Effort:** Low.

### Option D — Word-boundary / anchored prefix match
Match only `$baseType === $pgType || strpos($baseType, $pgType . ' ') === 0`.
- **Correctness:** ✅ corpus + hazards, **but remains order-dependent** for `character` vs `character varying` (verified: wrong order → `CHAR`).
- **Risk:** medium — more code, still order-sensitive.
- **Effort:** Low/Medium.

### Recommendation

**Option B (exact match), optionally extended so the regex also strips a
`with time zone` suffix.**

Rationale:
1. **It removes the root cause**, not the symptom. Prefix matching is the defect;
   ordering is only how it manifests. Option C/A leave the flawed mechanism in
   place.
2. `baseType` is already normalized (length stripped at `:424-426`,
   `without time zone` stripped at `:424`), so exact matching covers **100 % of
   the real corpus** — empirically proven across all 44 distinct types and every
   hazard pair, with zero regressions.
3. It makes the public `addTypeMapping()` contract exact and predictable (no
   caller depends on prefix behaviour, §2.4).
4. The single downside — `timestamp with time zone` (appears in **no** XML file)
   falling through to an invalid literal — is either (a) accepted, because it
   currently maps to `TIME` anyway (also wrong) and MySQL has no exact analogue,
   or (b) closed by adding `with time zone` to the existing optional regex
   group (a one-token change, Option D-style hardening). Recommend (b) for
   robustness; it does not touch any used type.

**If the team prefers the smallest possible diff and wants to keep prefix
matching, Option C is the safe alternative** (provably correct longest-first
ordering, handles `with time zone`). Option A is not recommended (fragile);
Option D is not recommended (order-dependent, more code).

---

## 6. Test strategy

### 6.1 Existing coverage (accurate picture)

- `tests/Core/FsSchemaTest.php` exists and **already exercises the creation
  path** (`createTable`) with an anonymous fake-DB object and injects the
  private `$db` via Reflection (`:110-116`). It covers FK collation gating, not
  type translation.
- `tests/Core/TypeNormalizerTest.php` covers the **ALTER-path** converter
  (`convertPostgresType`, `normalizeDefault`) and is the correct **pattern to
  mirror** for the CREATE-path converter. It already asserts
  `timestamp`→`TIMESTAMP`, `timestamp(6)`→`TIMESTAMP(6)`,
  `timestamp without time zone`→`TIMESTAMP`.
- `convertType()` itself has **no direct test** (codegraph: "no covering tests").
  It is `private`, so tests must reach it via `ReflectionMethod` (exactly as the
  exploration did), or assert through the public `generateSql()` / `createTable()`
  surface.

### 6.2 Proposed tests (strict TDD, `tests/Core/`)

Add to `tests/Core/FsSchemaTest.php` (or a focused new
`tests/Core/FsSchemaConvertTypeTest.php`):

1. **Exhaustive map coverage** — data-provider over every `$typeMapping` key
   asserting the exact MySQL type. This is the regression guard: any future key
   that breaks the strategy fails immediately.
2. **Prefix-hazard pairs** (the core of this change), one assertion each:
   - `timestamp` → `TIMESTAMP` (regression: was `TIME`)
   - `datetime` → `DATETIME` (regression: was `DATE`)
   - `timestamp without time zone` → `TIMESTAMP`
   - `timestamp(6)` → `TIMESTAMP(6)`
   - `time` → `TIME`, `time without time zone` → `TIME` (must not change)
   - `character varying(6)` → `VARCHAR(6)`, `character` → `CHAR` (latent pair)
   - `smallint` → `SMALLINT`, `bigint` → `BIGINT`, `double precision` → `DOUBLE`
3. **Postgres passthrough** — `convertType($type, false)` returns `$type`
   unchanged for `timestamp`/`datetime`.
4. **Creation-path integration** (mirrors existing `FsSchemaTest` style): feed an
   XML snippet with `<tipo>timestamp</tipo>` through `createTable()` and assert
   the generated `CREATE TABLE` string contains `` `created_at` TIMESTAMP ``
   and **not** `TIME`. This proves the fix reaches the materialisation point
   without a DB.
5. **No-DB preference**: use the existing anonymous fake-DB + `generateSql()`
   (no execution) wherever possible; `tests/bootstrap.php` defines the
   constants and no DB is required.

### 6.3 Regression guard for this bug class

The exhaustive map data-provider (#1) plus the hazard-pair provider (#2) together
form the guard: any reintroduction of prefix matching, or any new map key that is
a prefix of another, breaks a test. Recommended additionally: a **property test**
asserting that for every pair `(a, b)` of map keys, if `a` is a prefix of `b`
then `convertType(b)` equals the mapping of `b`, never of `a`.

### 6.4 Run command

```bash
ddev exec php vendor/bin/phpunit --testsuite Core
# or focused:
ddev exec php vendor/bin/phpunit tests/Core/FsSchemaTest.php
```

---

## 7. Is fixing only the creation path sufficient? (honest assessment)

**Yes — under the stated constraint, and provably so.**

1. **The bug materialises only on CREATE.** §2.2 proves the ALTER path uses
   `TypeNormalizer` (exact match, correct), not `convertType`. The only other
   caller (`resolveLocalFkColumnInfo`, `:244`) feeds FK metadata, and
   `FkCompatibilityValidator::isFkCompatible` short-circuits for non-collatable
   types (`src/Database/FkCompatibilityValidator.php:72-74`): `TIME`/`TIMESTAMP`
   are non-collatable, so the temporal conversion result is **never even read**
   on that path. Fixing `convertType` therefore changes **no FK outcome**.
2. **Nothing in production is wrong.** Live DB shows exactly 2 wrongly-typed
   columns, both `factura_pdf1_settings`, both confirmed not in production. The
   other 8 `time` columns are legitimately `time`. There is no production data
   in a wrong column to lose → **no data-remediation migration is required**.
3. **The fix is complete for the future.** After fixing `convertType`, every
   subsequent `CREATE TABLE` maps `timestamp` → `TIMESTAMP`,
   `timestamp without time zone` → `TIMESTAMP`, `datetime` → `DATETIME`, with no
   regression on the other 40+ corpus types (§3.3).

**What is still at risk / not covered (explicitly out of scope):**

- **Existing wrong columns are not retroactively repaired.** In the dev DB the
  two `factura_pdf1_settings` columns stay `time`. Worse, a normal plugin sync
  will **not** fix them: `TypeNormalizer::compareDataTypes('time','TIMESTAMP')`
  returns `true` (§3.5), so no `ALTER` is emitted. If a developer cares about the
  dev row, the non-migration remedies are to drop/recreate the table or run a
  one-off manual `ALTER` — deliberately **not** designed here, per the human's
  "no big migrations" constraint.
- **The related prefix comparison at `src/Database/TypeNormalizer.php:187-189`**
  (`time` ≡ `timestamp`) is a separate, latent defect of the same class. It is
  not required for this fix and is left for a future change (§8).
- **Any `with time zone` variant** remains unhandled unless the optional regex
  hardening in the recommendation is applied; it appears in no XML today.

**Bottom line:** with only the creation path fixed, and the 2 columns not yet in
production, **no further action is required for correctness going forward**. The
unrepaired dev columns are cosmetic and their repair is explicitly excluded.

---

## 8. Related findings (out of scope, do not fix here)

1. **`TypeNormalizer::compareDataTypes()` time/timestamp masking**
   (`src/Database/TypeNormalizer.php:187-189`) — a prefix comparison that treats
   `time` and `timestamp` as the same type, preventing the ALTER path from
   repairing a wrong `TIME` column. Same bug *class*; different function and
   different path. Candidate for a separate core change (it touches `src/`).
2. **`convertType()` fallback** (`:440`) uppercases unknown types verbatim
   (`precision` → `PRECISION`). Pre-existing, unrelated, not a regression of any
   option.
3. **`addTypeMapping()` is dead public API** (`:938`) — no callers; the fix
   should not remove it, but its semantics become exact.

---

## 9. Risks

- **Regression risk of the fix itself:** mitigated by the exhaustive map +
  hazard test matrix (§6) and the empirical corpus simulation (§3.3) showing zero
  regressions across all 44 real types for every option.
- **Order fragility** if Option A is chosen instead of B/C.
- **`timestamp with time zone`** edge (unused) — see recommendation (b).
- **No production risk** from this change: it is a pure mapping correction with
  no migration, no `ALTER`, and no data movement.
- **Perception risk:** a reader may expect the dev DB's 2 wrong columns to
  self-heal after the fix. They will not (§3.5); this must be stated in the
  proposal/tasks so it is not mistaken for a failed fix.

---

## 10. Ready for proposal

**Yes.** The defect is diagnosed, empirically proven, its call graph and blast
radius are fully mapped, all prefix-hazard pairs are enumerated and verified, and
the fix options are compared with a concrete recommendation (Option B: exact
match, optionally with `with time zone` regex hardening). Scope is correctly
confined to the creation path; no migration is needed.
