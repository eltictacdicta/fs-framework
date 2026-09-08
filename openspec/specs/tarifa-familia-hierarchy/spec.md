# tarifa-familia-hierarchy Specification

## Purpose

The per-tariff hierarchy table `tarif_tarifa_familia` is the single write target for every familia-creating flow. The legacy table `tarif_familia_ext` stops receiving writes and remains read-only historical migration data (no schema change, no data deletion). Imported familias become visible on the tarifa families page, which lists exclusively from the per-tariff table.

## Requirements

### Requirement: Two-write contract for familia creation

Every familia-creating flow — the `tarif_articulos.php` JSON import, the `tarif_articulos.php` batch import, the `tarif_catalogo_view.php` batch, and the `ExcelHierarchyService` Excel wizard — MUST persist exactly two rows when creating or updating a familia: the base catalog row in `familias` (base `familia` model) and the per-tariff hierarchy row in `tarif_tarifa_familia` (its model `save()`). `capitulo` MUST land on the per-tariff row, never on the base familia object; `madre` is legitimately set on the base familia object as the native `familias.madre` column by every writer, and MUST also be carried on the per-tariff row. `nivel` MUST be computed by the per-tariff model, not copied from the payload.

#### Scenario: Legacy-only writer persists both rows

- GIVEN a JSON import through `tarif_articulos.php` introducing a new familia
- WHEN the familia entry is processed
- THEN a `familias` row and a `tarif_tarifa_familia` row exist for it, with the imported `madre`/`capitulo` on the per-tariff row

#### Scenario: Dual writer drops the legacy write

- GIVEN the `tarif_catalogo_view.php` batch flow, which previously wrote both the ext table and the per-tariff table
- WHEN it processes a familia entry
- THEN only `familias` and `tarif_tarifa_familia` are written, and the tarifa-side flags and etiquetas behavior is unchanged

### Requirement: Zero new writes to tarif_familia_ext

No familia-creating flow MUST issue any INSERT, UPDATE or DELETE against `tarif_familia_ext`. This change MUST NOT alter the table's schema or FK and MUST NOT delete existing rows: the ext table stays read-only historical data, its row count unchanged by the migrated writers. (Operator-triggered destructive maintenance flows are out of scope — design AD-8.)

#### Scenario: Imports leave ext row counts untouched

- GIVEN a row-count probe of `tarif_familia_ext` taken before running the import flows
- WHEN the import flows run to completion
- THEN the probe returns the same count afterwards

#### Scenario: Historical ext data remains readable

- GIVEN familias with pre-existing ext rows
- WHEN read-only consumers of the legacy hierarchy run
- THEN the historical `capitulo`/`nivel` values are still returned

### Requirement: Imported familias appear on the tarifa families page

Familias created by the `tarif_articulos.php` import flows MUST acquire a `tarif_tarifa_familia` row for the import's target tarifa, and therefore MUST appear on the tarifa families page for that tarifa.

#### Scenario: Imported familia is listed for the imported tarifa

- GIVEN a JSON import targeting tarifa `X` with a new familia
- WHEN the import completes
- THEN the familia appears on the tarifa families page of tarifa `X`

#### Scenario: Unlinked base familias remain available for linking

- GIVEN a familia present in `familias` with no per-tariff row (e.g. the `VARI` orphan)
- WHEN the tarifa families page is used
- THEN the familia is offered by the "add familia to tarifa" flow rather than shown as a linked row

### Requirement: Tier matching reads the per-tariff table with a fallback leg

The Excel wizard's create-vs-match resolution (tier-1 collision and tier-2 match) MUST resolve primarily against `tarif_tarifa_familia` scoped to the import's tarifa, MAY additionally consult the historical ext `capitulo` as a read-only legacy leg, and MUST keep a fallback leg matching (`madre`, `descripcion`) for familias that have neither a per-tariff row in this tarifa nor an ext row. Single-match semantics and the self-exclusion clause (a familia never matches itself) MUST be preserved. Tier-3 (description-only) matching MUST be unchanged. (AD-5.)

#### Scenario: Per-tariff capitulo match avoids duplicates

- GIVEN a familia already linked to the import tarifa with capitulo `C`
- WHEN the wizard imports a row carrying capitulo `C`
- THEN the existing familia is matched and no new `familias` row is created

#### Scenario: Fallback leg matches an unlinked familia

- GIVEN a familia with no per-tariff row in the import tarifa and no ext row
- WHEN the wizard imports a row whose madre and descripcion match it
- THEN the existing familia is matched instead of a duplicate being created

### Requirement: Per-flow flag defaults are preserved, not normalized

Each flow MUST keep its current flag defaults on the per-tariff row; the change MUST NOT silently normalize them (AD-6):

| Flow | activa | en_tarifa | en_catalogo | orden |
|---|---|---|---|---|
| JSON imports (all three JSON-processing sites) | JSON value or true | JSON value or true | JSON value or true | JSON value or 0 |
| Excel wizard | current behavior | true (hard-coded) | current behavior | current behavior |
| Historical migration rows | frozen | false (frozen) | frozen | frozen |

#### Scenario: JSON defaults apply to the previously legacy-only writers

- GIVEN a JSON import entry without explicit flags
- WHEN the `tarif_articulos.php` writers persist the per-tariff row
- THEN `activa`, `en_tarifa` and `en_catalogo` default to true and `orden` defaults to 0

#### Scenario: Excel divergence is preserved

- GIVEN the Excel wizard creates a familia row
- WHEN the per-tariff row is persisted
- THEN `en_tarifa` remains true, as today

### Requirement: Target tarifa resolution for import writers

The `tarif_articulos.php` import writers MUST resolve the target tarifa from the posted `codtarifa`, falling back to the framework default tarifa and then to `DEF`, and MUST record the resolved value in the import session so the batch step uses the same tarifa. The batch step MUST consume the session-resolved value. (AD-7.)

#### Scenario: Posted codtarifa is honored

- GIVEN an import request posting `codtarifa = X`
- WHEN a familia is imported
- THEN its per-tariff row belongs to tarifa `X` and the session records `X`

#### Scenario: Missing codtarifa falls back to the default

- GIVEN an import request without a posted `codtarifa`
- WHEN a familia is imported
- THEN its per-tariff row belongs to the default tarifa, ultimately `DEF`

### Requirement: tarif_familia is a deprecated read-only API

The `tarif_familia` model MUST remain as a `@deprecated` read-only wrapper. Its own write surface — the `save` and `delete` overrides plus the extension helpers (`save_extension`, `delete_extension`, `load_extension`, `ext_exists`, `calcular_nivel`, `actualizar_niveles_hijas`) — MUST be removed, so the class declares NO write methods of its own targeting `tarif_familia_ext`. `save()`/`delete()` inherited from base `familia` remain callable but write only the `familias` table; `tarif_familia_ext` is unreachable as a write target through this class. All read methods (`get`, `get_madre`, `get_hijas`, `hijas`, `all`, `all_simple`, `search`, `all_by_capitulo`, `suggest_capitulo`) MUST be preserved unchanged, including the historical ext LEFT JOINs (AD-1, AD-2). Write-needing callers MUST use the base `familia` model plus `tarif_tarifa_familia`.

#### Scenario: No own write surface, ext unreachable

- GIVEN the deprecated class after this change
- WHEN its API is inspected, or a stale caller invokes one of the removed extension helpers (`save_extension`, `delete_extension`, `load_extension`, `ext_exists`, `calcular_nivel`, `actualizar_niveles_hijas`) or the removed `save`/`delete` overrides
- THEN the class adds no write methods of its own targeting `tarif_familia_ext` — a stale caller of a removed method fails loudly (undefined method)
- AND the inherited base `familia` `save()`/`delete()` affect only the `familias` table, never `tarif_familia_ext`
- AND `tarif_familia_ext` is unreachable as a write target through this class

#### Scenario: Read surface unchanged for verified consumers

- GIVEN the verified read-only consumers (article edit, opcionales pages, wrapper models)
- WHEN they instantiate `tarif_familia` and call read methods
- THEN behavior is unchanged, hydrated from `familias` rows with historical ext columns when the ext row exists
