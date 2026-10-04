# content-i18n Specification

## Purpose

A framework-native content-translation mechanism: a core `LanguageRegistry` over
the new `idiomas` table with total deterministic default resolution and lifecycle
invariants; a `ContentTranslator` resolution engine
(`exact → language prefix → configured default → base → ''`); a
`TranslationStoreInterface` read/write contract with `RowTranslationStore`
(per-record rows, application-level orphan cleanup, no language foreign key) and
`FixedColumnTranslationStore` (locale code to existing nullable column, no schema
change); namespaced, non-shadowable core DI services; an idempotent
`fs_var`-flagged single-table migration; and a `TranslatableControllerTrait` plus
a render-only Twig macro consumers use to expose their own section. The capability
is additive only: no existing table, column, model, method, page, service id or
translation key is renamed, removed, retyped or repointed.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| CI-01 | Total deterministic default language | MUST |
| CI-02 | Active-language listing | MUST |
| CI-03 | Exactly one active default | MUST |
| CI-04 | Deactivation and deletion guards | MUST |
| CI-05 | Idempotent single-row seed | MUST |
| CI-06 | ContentTranslator resolution chain | MUST |
| CI-07 | Read-only, non-materializing, explicit locale | MUST |
| CI-08 | Exact stored code wins first (non-destructive matching) | MUST |
| CI-09 | Prefix reduction is per-store opt-in | MUST |
| CI-10 | No global collapse, no stored-code rewrite | MUST |
| CI-11 | Plugin-owned aliases stay plugin-side | MUST |
| CI-12 | RowTranslationStore per-record rows with UNIQUE | MUST |
| CI-13 | Application-level orphan cleanup, no language FK | MUST |
| CI-14 | Set clears on null or empty | MUST |
| CI-15 | RowTranslationStore is generic and core-scoped | MUST |
| CI-16 | FixedColumnTranslationStore maps locale to existing columns | MUST |
| CI-17 | Namespaced core services cannot be shadowed | MUST |
| CI-18 | Idempotent, flagged, single-table migration | MUST |
| CI-19 | Bootstrap hook after self-heal in all entry points | MUST |
| CI-20 | Failure leaves the flag unwritten, zero plugin blast radius | MUST |
| CI-21 | Trait mutations are POST-only, admin and CSRF guarded | MUST |
| CI-22 | Render-only macro, no new core UI surface | MUST |
| CI-23 | No rename, removal, retype or repoint of protected surface | MUST |
| CI-24 | Separate `idiomas` table, no plugin reference in core | MUST |
| CI-25 | `FSTranslator` message behavior unchanged | MUST |
| CI-26 | Non-migrating plugin behavior is identical | MUST |

### Requirement: CI-01 — Total deterministic default language

`LanguageRegistry` MUST expose a total, deterministic default resolution over the
core `idiomas` registry:

1. an active row with `por_defecto = TRUE` wins;
2. otherwise the active row with the lowest `codidioma` (ascending string order) wins;
3. when no active row exists, the resolution MUST fall back to the constant `'es'`.

`defaultCode()` MUST always return a non-empty code and MUST NOT depend on row
insertion order, query result order, or cache state.

#### Scenario: An active explicit default wins

- GIVEN `idiomas` contains active `fr` (no flag) and active `es` (`por_defecto = TRUE`)
- WHEN the default code is resolved
- THEN it returns `es`

#### Scenario: No explicit default falls back to the lowest active code

- GIVEN `idiomas` contains active `pt` and active `de`, neither flagged as default
- WHEN the default code is resolved
- THEN it returns `de`

#### Scenario: Deactivated flags are ignored

- GIVEN an inactive row `en` carries `por_defecto = TRUE` and an active row `es` does not
- WHEN the default code is resolved
- THEN it returns `es`

#### Scenario: Zero active rows degrade to the constant

- GIVEN every row in `idiomas` has `activo = FALSE` (or the table is empty)
- WHEN the default code is resolved
- THEN it returns `es`

#### Scenario: Resolution is permutation-independent

- GIVEN the same active rows are inserted in two different orders
- WHEN the default code is resolved for both registries
- THEN both resolve to the identical code

### Requirement: CI-02 — Active-language listing

`LanguageRegistry` MUST expose the set of active language codes. Inactive rows
MUST be excluded, and the listing MUST be deterministic so repeated calls over
unchanged data yield the same ordered result.

#### Scenario: Only active codes are listed

- GIVEN active `es`, active `en` and inactive `fr`
- WHEN the active codes are listed
- THEN the result contains `es` and `en`
- AND the result does not contain `fr`

#### Scenario: Listing is deterministic

- GIVEN unchanged registry data
- WHEN the active codes are listed twice
- THEN both listings are identical in content and order

### Requirement: CI-03 — Exactly one active default

When at least one active row exists, exactly one active row MUST carry
`por_defecto = TRUE`. `set_default()` MUST be a flag flip that changes no
content/translation row and materializes no data. Normalization MUST restore the
invariant after any state where multiple active rows are flagged.

#### Scenario: Setting the default moves the flag

- GIVEN active `es` (`por_defecto = TRUE`) and active `en`
- WHEN `en` is set as default
- THEN `en` has `por_defecto = TRUE`
- AND `es` has `por_defecto = FALSE`
- AND no translation-store row was inserted, updated or deleted

#### Scenario: Normalization collapses duplicate defaults

- GIVEN two active rows both flagged `por_defecto = TRUE`
- WHEN normalization runs
- THEN exactly one active row remains flagged
- AND the resolved default code is unchanged from the pre-normalization resolution

### Requirement: CI-04 — Deactivation and deletion guards

The registry model MUST enforce:
- saving the current default with `activo = FALSE` MUST be refused (returns
  `false`, persists nothing);
- deleting the current default row MUST be refused;
- deleting the last remaining language row MUST be refused.

Every refusal MUST leave the `idiomas` table byte-for-byte unchanged.

#### Scenario: Cannot deactivate the default

- GIVEN active `es` is the current default
- WHEN a save attempts to set `es.activo = FALSE`
- THEN the save is refused
- AND `es` remains active and flagged as default

#### Scenario: Cannot delete the default

- GIVEN active `es` is the current default and other active languages exist
- WHEN deletion of `es` is attempted
- THEN the deletion is refused
- AND the `es` row still exists

#### Scenario: Cannot delete the last language

- GIVEN `idiomas` contains exactly one row, `es`, which is the default
- WHEN deletion of `es` is attempted
- THEN the deletion is refused
- AND one row remains

#### Scenario: A non-default language can be deleted

- GIVEN active `es` (default) and active `fr` (non-default)
- WHEN deletion of `fr` is attempted
- THEN the deletion succeeds
- AND `idiomas` still contains `es`

### Requirement: CI-05 — Idempotent single-row seed

The core migration MUST seed **exactly one** row when — and only when — the
`idiomas` table is empty: `codidioma = 'es'`, `activo = TRUE`,
`por_defecto = TRUE`. It MUST NOT seed if the table already contains any row,
and a repeated run MUST NOT add, duplicate or modify rows.

#### Scenario: Empty table is seeded once

- GIVEN the `idiomas` table exists and is empty
- WHEN the migration runs
- THEN exactly one row exists: `es`, active, flagged as default

#### Scenario: Non-empty table is not seeded

- GIVEN `idiomas` already contains a `de` row
- WHEN the migration runs
- THEN no `es` row is added
- AND the existing `de` row is unchanged

#### Scenario: Second run is a no-op

- GIVEN the migration completed a first successful run
- WHEN the migration runs again
- THEN the row count is unchanged
- AND no row content changes

### Requirement: CI-06 — ContentTranslator resolution chain

`ContentTranslator::read(store, field, ?locale, base = '')` MUST resolve, in this
exact order, returning the first available non-null value:

1. the exact caller locale in the store;
2. the language prefix `substr(locale, 0, 2)` in the store — only when that store opts in to prefix matching;
3. the configured default code from `LanguageRegistry::defaultCode()`;
4. the caller-supplied `base` value;
5. the empty string `''`.

#### Scenario: Exact match wins over every other leg

- GIVEN the store holds a value for the caller locale and also a value for the configured default
- WHEN `read` is invoked with that locale
- THEN it returns the caller-locale value

#### Scenario: Prefix leg when the store opts in

- GIVEN a store that opted in to prefix matching holds a value for `es` only
- WHEN `read` is invoked with `es_ES` and a different configured default
- THEN it returns the `es` value

#### Scenario: Configured default leg

- GIVEN the store holds no value for the caller locale or its prefix, and the configured default is `en`
- AND the store holds `en`
- WHEN `read` is invoked
- THEN it returns the `en` value

#### Scenario: Caller-supplied base leg

- GIVEN the store holds no value for the caller locale, prefix or configured default
- WHEN `read` is invoked with `base = 'legacy text'`
- THEN it returns `legacy text`

#### Scenario: Empty terminal

- GIVEN no store value matches any leg and `base = ''`
- WHEN `read` is invoked
- THEN it returns `''`

#### Scenario: Two-letter caller does not double-match

- GIVEN the caller locale is `es` and the store opted in to prefix matching
- WHEN `read` is invoked
- THEN the exact leg and the prefix leg resolve to the same key
- AND resolution returns the `es` value once without error

### Requirement: CI-07 — Read-only, non-materializing, explicit locale

`read` MUST NOT write to the store or the registry, MUST NOT materialize a
fallback row or copy the default/base value into a stored entry, and MUST NOT
read a locale from `Request`, session or cookie. The locale is always
caller-supplied.

#### Scenario: Read leaves store state unchanged

- GIVEN a store with a known set of rows
- WHEN `read` resolves through one or more fallback legs
- THEN the store contains exactly the same rows it had before the read

#### Scenario: No fallback is materialized

- GIVEN the caller locale is absent and the configured default supplies the value
- WHEN `read` returns the default value
- THEN no row or column entry was created for the caller locale

#### Scenario: Request locale is ignored

- GIVEN a request-scoped locale `fr_FR` exists in the environment and a configured default `en`
- WHEN `read` is invoked with an explicit locale `es_ES`
- THEN resolution uses `es_ES` and never consults the request-scoped locale

### Requirement: CI-08 — Exact stored code wins first (non-destructive matching)

For a row store, a stored code MUST match exactly before any prefix reduction.
The store MUST NOT rewrite the caller locale or the stored `codidioma`.

#### Scenario: Region-qualified stored code matches exactly

- GIVEN a row store holds a translation under `codidioma = 'fr_CA'`
- WHEN `read` is invoked with `fr_CA`
- THEN it returns that stored value
- AND the stored `codidioma` is still `fr_CA`

#### Scenario: Region-qualified caller does not degrade when no exact row exists

- GIVEN a row store holds `es_ES` and `es` is not stored, and the store did not opt in to prefix matching
- WHEN `read` is invoked with `es_ES`
- THEN it does not return any `es` value
- AND it proceeds to the configured-default leg

### Requirement: CI-09 — Prefix reduction is per-store opt-in

Prefix reduction (`xx_REGION → xx`) MUST apply only when the specific store
instance opts in. A store that does not opt in MUST NOT reduce a
region-qualified caller to its language prefix.

#### Scenario: Opted-in store reduces

- GIVEN a row store configured with prefix matching enabled holds only `pt`
- WHEN `read` is invoked with `pt_BR`
- THEN it returns the `pt` value

#### Scenario: Non-opted-in store does not reduce

- GIVEN the same stored data with prefix matching disabled
- WHEN `read` is invoked with `pt_BR`
- THEN it does not return the `pt` value

#### Scenario: Fixed-column store reduces to its column suffix

- GIVEN a fixed-column store maps suffixes `es`, `fr`, `en` to `subtitle_es`, `subtitle_fr`, `subtitle_en`
- WHEN `read` is invoked with `fr_FR`
- THEN it reads the `subtitle_fr` column

### Requirement: CI-10 — No global collapse, no stored-code rewrite

The core MUST NOT globally collapse region-qualified codes (`es_ES → es`,
`pt_BR → pt`) at any shared boundary. Stored codes MUST NEVER be rewritten by a
read or by store construction. Distinct regional codes MUST remain distinct.

#### Scenario: `pt_BR` and `pt_PT` remain distinct

- GIVEN a row store holds distinct rows for `pt_BR` and `pt_PT`
- WHEN each is read with its exact caller locale
- THEN each returns its own stored value

#### Scenario: `pt_BR` is not silently served `pt_PT`

- GIVEN a row store holds only `pt_PT` and prefix matching is disabled
- WHEN `read` is invoked with `pt_BR`
- THEN the `pt_PT` value is never returned for `pt_BR`

#### Scenario: Stored codes survive reads

- GIVEN a store holding `es_ES`, `fr_CA` and `en`
- WHEN a series of arbitrary reads and fallback resolutions runs
- THEN every stored `codidioma` value is unchanged

### Requirement: CI-11 — Plugin-owned aliases stay plugin-side

The core MUST NOT implement any locale alias such as `en_US → en_GB`. For a
given caller locale, the core MUST read the column/row that the caller locale
selects; it MUST NOT change which column is read in the name of normalization.
Alias mapping and its prefix fallback remain the responsibility of the consuming
plugin.

#### Scenario: Core does not apply the OIDC alias

- GIVEN the core fixed-column store is used with the locale `en_US`
- WHEN resolution runs
- THEN the core selects the `en` column by the store's reduction rule
- AND the core does not redirect `en_US` to `en_GB`

#### Scenario: Caller-normalized locale is honored as-is

- GIVEN a plugin has already normalized a request to `en_GB`
- WHEN the core store reads `en_GB`
- THEN it reads the same column the plugin expects for `en_GB`

### Requirement: CI-12 — RowTranslationStore per-record rows with UNIQUE

`RowTranslationStore` MUST read and write per-record translation rows keyed by
`record_key` + `codidioma`, and MUST preserve the `UNIQUE(record_key,
codidioma)` constraint — it MUST NOT drop, rename or repoint it.

#### Scenario: One row per record and locale

- GIVEN a record key and locale
- WHEN a value is written
- THEN at most one row exists for that `(record_key, codidioma)` pair

#### Scenario: Writing the same pair updates, not duplicates

- GIVEN an existing row for `(record, 'en')`
- WHEN a new value is written for the same pair
- THEN the row value is updated
- AND the row count for that pair stays one

#### Scenario: UNIQUE constraint is preserved

- GIVEN the store's schema definition
- WHEN the constraint set is inspected
- THEN `UNIQUE(record_key, codidioma)` is present

### Requirement: CI-13 — Application-level orphan cleanup, no language FK

`RowTranslationStore` MUST expose `purgeLanguage(string $locale): int` that
deletes all of that store's rows for the given locale at the application level
and returns the number of removed rows. The store MUST NOT rely on a database
foreign-key `ON DELETE CASCADE` for the language dimension, and MUST NOT add a
`codidioma` foreign key.

#### Scenario: Purge removes only the target locale

- GIVEN rows exist for `es` and `fr`
- WHEN `purgeLanguage('fr')` runs
- THEN the removed count is the number of `fr` rows
- AND all `es` rows remain

#### Scenario: Purge on an absent locale is a no-op

- GIVEN no rows exist for `de`
- WHEN `purgeLanguage('de')` runs
- THEN it returns `0`
- AND no other row is affected

#### Scenario: No language foreign key is introduced

- GIVEN the store's schema definition
- WHEN its foreign keys are inspected
- THEN no foreign key references the language registry or `codidioma`

### Requirement: CI-14 — Set clears on null or empty

`RowTranslationStore::set(field, locale, null)` and
`RowTranslationStore::set(field, locale, '')` MUST clear that locale's entry
(absence semantics): a subsequent `get` for that field and locale MUST return
`null`. Clearing MUST NOT copy a fallback value into the entry.

#### Scenario: Setting null clears the entry

- GIVEN a stored value for `(record, 'en')`
- WHEN `set(field, 'en', null)` runs
- THEN `get(field, 'en')` returns `null`

#### Scenario: Setting an empty string clears the entry

- GIVEN a stored value for `(record, 'fr')`
- WHEN `set(field, 'fr', '')` runs
- THEN `get(field, 'fr')` returns `null`

#### Scenario: Clearing is absence, not a copied fallback

- GIVEN `en` is the configured default with a stored value
- WHEN the caller locale `es` is explicitly cleared
- THEN no `es` entry holds the `en` value
- AND a read for `es` falls back through the normal chain at read time

### Requirement: CI-15 — RowTranslationStore is generic and core-scoped

`RowTranslationStore` MUST be generic over `record_key` and field, MUST NOT
reference, read or write any plugin-owned translation table, and MUST NOT
contain any reference to `catalogo_idiomas`, `articulo_descripciones`,
`catalogo_opcional_idiomas` or any plugin identifier. The core registry MUST NOT
delete plugin translation rows; a consumer invokes `purgeLanguage()` from its
own language-delete flow.

#### Scenario: Store operates on its own injected table

- GIVEN a `RowTranslationStore` configured for a table name and column map
- WHEN values are written and read
- THEN only that configured table is touched

#### Scenario: Core language deletion does not purge plugin rows

- GIVEN a language is deleted in the core registry and a plugin translation table holds rows for that code
- WHEN the core deletion completes
- THEN the core makes no change to the plugin translation table
- AND the plugin must call its own `purgeLanguage()` to clean orphans

### Requirement: CI-16 — FixedColumnTranslationStore maps locale to existing columns

`FixedColumnTranslationStore` MUST map a locale code to an existing nullable
column through a caller-supplied map (for example `es → subtitle_es`,
`fr → subtitle_fr`, `en → subtitle_en`) and MUST read and write those columns
with **no schema change**. Adding a mapping entry MUST NOT by itself issue any
`ALTER TABLE`.

#### Scenario: Read maps to the right column

- GIVEN the map `{es: subtitle_es, fr: subtitle_fr, en: subtitle_en}` and `fr` stored data
- WHEN `read` is invoked with `fr_FR`
- THEN it returns the `subtitle_fr` value

#### Scenario: Write maps to the right column

- GIVEN the same map
- WHEN `set('subtitle', 'en', 'Hello')` runs
- THEN the `subtitle_en` column holds `Hello`
- AND no other column is modified

#### Scenario: No schema change

- GIVEN an existing table with the mapped columns
- WHEN the store performs reads and writes
- THEN no column is added, dropped, renamed or retyped

### Requirement: CI-17 — Namespaced core services cannot be shadowed

The DI container MUST resolve `core.language_registry` and
`core.content_translator`, registered in `registerCoreServices()` **before**
`loadPluginServices()`. A plugin registering a colliding service id MUST NOT
replace either core instance. Plugin services registering their own
non-namespaced ids MUST continue to work.

#### Scenario: Both core services resolve

- GIVEN a booted container
- WHEN `core.language_registry` and `core.content_translator` are resolved
- THEN each returns its expected core instance

#### Scenario: Plugin cannot shadow the core ids

- GIVEN a plugin `config/services.php` registers `core.content_translator`
- WHEN the container resolves `core.content_translator`
- THEN it returns the core instance, not the plugin override

#### Scenario: Existing bare plugin ids still resolve

- GIVEN a plugin registers its own service id
- WHEN that id is resolved
- THEN it resolves normally

### Requirement: CI-18 — Idempotent, flagged, single-table migration

`ContentI18nMigration` MUST be idempotent, use an `fs_var` flag written only
after every step succeeds, and use a table allowlist of exactly `['idiomas']`.
It MUST NOT read, write, alter or reference any plugin table.

#### Scenario: First run applies and flags

- GIVEN an empty `idiomas` table and no migration flag
- WHEN the migration runs successfully
- THEN the seed of exactly one `es` row is applied
- AND the `fs_var` flag is written

#### Scenario: Flagged run is a no-op

- GIVEN the migration flag is already set
- WHEN the migration runs
- THEN it performs no schema or data change

#### Scenario: Allowlist is exactly idiomas

- GIVEN the migration's table allowlist
- WHEN it is inspected
- THEN it contains exactly `['idiomas']`
- AND it contains no plugin table name

### Requirement: CI-19 — Bootstrap hook after self-heal in all entry points

`ContentI18nMigration` MUST be invoked immediately after
`fs_schema::selfHealCoreTables()` in `index.php`, `api.php` and `cron.php`, each
call wrapped in `try/catch`. When no database connection is available the
migration MUST be a no-op and MUST NOT throw into the request path.

#### Scenario: Web entry point hooks the migration

- GIVEN the `index.php` bootstrap
- WHEN the bootstrap runs
- THEN the migration is invoked after `fs_schema::selfHealCoreTables()`

#### Scenario: API entry point hooks the migration

- GIVEN the `api.php` bootstrap
- WHEN the bootstrap runs
- THEN the migration is invoked after `fs_schema::selfHealCoreTables()`

#### Scenario: Cron entry point hooks the migration

- GIVEN the `cron.php` bootstrap
- WHEN the bootstrap runs
- THEN the migration is invoked after `fs_schema::selfHealCoreTables()`

#### Scenario: No database is a safe no-op

- GIVEN no database connection is available
- WHEN the migration runs from any entry point
- THEN it returns without throwing
- AND the request continues unaffected

#### Scenario: A thrown error is contained

- GIVEN the migration raises an error
- WHEN the entry point runs
- THEN the `try/catch` contains it
- AND the request is not interrupted

### Requirement: CI-20 — Failure leaves the flag unwritten, zero plugin blast radius

If any migration step fails, the `fs_var` flag MUST remain unwritten so the next
request retries, and the failure MUST NOT affect any plugin table or plugin
behavior.

#### Scenario: Partial failure does not write the flag

- GIVEN the migration fails after some steps
- WHEN the run aborts
- THEN the `fs_var` flag is not written
- AND the next run retries the migration

#### Scenario: Plugin tables are untouched by failure

- GIVEN a failure occurs during the core migration
- WHEN the run aborts
- THEN no plugin table was read, written or altered

### Requirement: CI-21 — Trait mutations are POST-only, admin and CSRF guarded

`TranslatableControllerTrait` MUST provide consumer mutation helpers that:
- accept state changes only over `POST` (non-POST attempts MUST be rejected);
- deny non-administrator actors;
- reject requests with an invalid or missing CSRF token.

#### Scenario: Non-POST is rejected

- GIVEN a GET request carrying mutation parameters
- WHEN the helper runs
- THEN the mutation is refused and nothing is persisted

#### Scenario: Non-admin is denied

- GIVEN an authenticated non-administrator
- WHEN a mutation is attempted
- THEN it is refused and nothing is persisted

#### Scenario: Invalid CSRF is rejected

- GIVEN a POST request with an invalid or missing CSRF token
- WHEN a mutation is attempted
- THEN it is refused and nothing is persisted

#### Scenario: Authorized POST succeeds

- GIVEN an administrator sending a valid POST with a valid CSRF token
- WHEN the mutation runs
- THEN the value is persisted

### Requirement: CI-22 — Render-only macro, no new core UI surface

The Twig macro/partial MUST be render-only; a consumer includes it inside its own
section. This change MUST NOT introduce a new core page, a new menu row, or a new
`#[AdminOnly]` page.

#### Scenario: Macro renders inside a consumer section

- GIVEN a consumer template includes the macro within its own view section
- WHEN the page renders
- THEN translation fields are rendered
- AND the macro performs no mutation by itself

#### Scenario: No new core page is registered

- GIVEN the core page/menu registry after this change
- WHEN it is inspected
- THEN no page or menu row was added by this change
- AND no new `#[AdminOnly]` declaration exists

### Requirement: CI-23 — No rename, removal, retype or repoint of protected surface

The change MUST NOT rename, remove, retype or repoint any existing core/plugin
table, column, model, method, page, service id or translation key. Existing core
and plugin test suites MUST pass unchanged.

#### Scenario: Existing test suites pass unchanged

- GIVEN the pre-change test suites
- WHEN the full suite runs after this change
- THEN every pre-existing test passes with no modification to its assertions

#### Scenario: Protected consumer identifiers are untouched

- GIVEN the identifiers `catalogo_idiomas`, `articulo_descripciones`,
  `catalogo_opcional_idiomas`, `articulo`, `articulo_descripcion`,
  `catalogo_idioma`, `oidc_client`, `oidc_clients`, `LocaleManager`
- WHEN the change diff is inspected
- THEN none of them was modified by this change

### Requirement: CI-24 — Separate `idiomas` table, no plugin reference in core

The core `idiomas` table MUST be new and separate from `catalogo_idiomas`. Core
code in the new content-i18n surface MUST NOT read, write, copy or reference
`catalogo_idiomas`. A grep gate MUST find no `catalogo_idiomas` occurrence under
the new core files:

- `src/Translation/LanguageRegistry.php`
- `src/Translation/ContentTranslator.php`
- `src/Translation/Store/**`
- `model/idioma.php`
- `model/table/idiomas.xml`
- `src/Core/Schema/ContentI18nMigration.php`

#### Scenario: Grep gate is clean

- GIVEN the listed new core files
- WHEN they are searched for the string `catalogo_idiomas`
- THEN zero occurrences are found

#### Scenario: Core registry is its own table

- GIVEN a core registry instance
- WHEN its backing table is inspected
- THEN it is `idiomas`, not `catalogo_idiomas`

### Requirement: CI-25 — `FSTranslator` message behavior unchanged

`FSTranslator` `trans()`/fallback behavior and `TranslationHelper` MUST be
untouched. Content i18n is a separate layer that only consumes locale codes and
MUST NOT alter message-catalog resolution.

#### Scenario: Translation tests pass unchanged

- GIVEN `tests/Translation/` at the pre-change state
- WHEN it runs after this change
- THEN every test passes with no modification

#### Scenario: Message fallback is identical

- GIVEN a message missing in the active locale
- WHEN `FSTranslator::trans()` resolves it
- THEN the result follows the same fallback chain as before this change

### Requirement: CI-26 — Non-migrating plugin behavior is identical

Because the mechanism is opt-in and additive, a plugin that never calls the new
services or stores MUST behave exactly as before this change.

#### Scenario: A plugin ignoring content-i18n is unaffected

- GIVEN a plugin that does not reference any new service or store
- WHEN it runs before and after this change
- THEN its observable behavior is identical
- AND no new table, column, service id or page is required for it to run
