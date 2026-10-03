# Design: core-content-i18n

**Change**: `core-content-i18n` (CORE change — `openspec/changes/core-content-i18n/`)
**Artifact store**: `openspec`
**Core VERSION**: `0.22.7`
**Inputs**: `proposal.md` (locked hybrid approach, resolved Q1–Q7), `exploration.md`
(verified current state with `path:line` evidence), `specs/content-i18n/spec.md`
(CI-01…CI-26, 75 scenarios).
**Product decisions are locked. This document resolves only the technical HOW.**

---

## Technical Approach

The change introduces a framework-native content-translation mechanism as an
**entirely additive** layer on top of the existing `fs_model` + XML-schema
persistence stack. It does not touch `FSTranslator`, any existing table, any
plugin, or any page/menu.

Three orthogonal pieces, each with one clear owner:

1. **Registry layer** — a new core `idiomas` table (`model/table/idiomas.xml`),
   a global legacy model `idioma` (`model/idioma.php`, matching the
   `model/fs_page.php` / `model/fs_var.php` convention), and a thin service
   facade `LanguageRegistry` (`src/Translation/LanguageRegistry.php`). The model
   owns durable lifecycle invariants; the facade exposes total default
   resolution and active-code listing to consumers.
2. **Resolution engine** — `ContentTranslator`
   (`src/Translation/ContentTranslator.php`) walks
   `exact → language prefix → configured default → base → ''`, driven entirely by
   each store's declared, ordered `candidates()` list and a caller-supplied
   locale. It never reads `Request`, never writes, never materializes a fallback.
3. **Store abstraction** — `TranslationStoreInterface`
   (`src/Translation/Store/TranslationStoreInterface.php`) plus two concrete
   implementations: `RowTranslationStore` (per-record rows, generic, application
   level orphan cleanup, no language FK) and `FixedColumnTranslationStore`
   (locale code → existing nullable column, no schema change). The per-store
   prefix-reduction opt-in lives on the **constructor** so two instances can be
   built over identical data with different matching behavior.

Supporting pieces: namespaced DI registration inside `registerCoreServices()`
with a reserved-id re-assert after `loadPluginServices()`; a `fs_var`-flagged,
idempotent `ContentI18nMigration` hooked after `fs_schema::selfHealCoreTables()`
in the three entry points; a POST/admin/CSRF-guarded
`TranslatableControllerTrait`; and a render-only Twig macro. No new core page,
menu row, or `#[AdminOnly]` declaration.

This maps directly to `proposal.md` §Approach and satisfies the two
evidence-driven corrections: application-level orphan cleanup instead of a
language FK (`exploration.md` §2.3, §7), and per-store opt-in normalization
instead of a global `es_ES → es` collapse (`exploration.md` §4).

---

## Architecture Decisions

### Decision D1: Core registry model is a **global** `idioma` class at `model/idioma.php`, not a namespaced `FSFramework\model\idioma`

**Choice**: `model/idioma.php` declares `class idioma extends \fs_model` in the
global namespace, exactly like `model/fs_page.php`, `model/fs_var.php` and
`model/agente.php`. `model/table/idiomas.xml` carries the schema. The method
surface mirrors `catalogo_idioma` (`install`, `ensure_defaults`, `get`,
`get_default`, `test`, `save`, `delete`, `exists`, `all`, `all_activos`,
`set_default`, `normalize_default`) plus the total `defaultCode()`.

**Alternatives considered**:
1. `namespace FSFramework\model;` like
   `plugins/catalogo_core/model/core/catalogo_idioma.php:11`. This is the exact
   convention of the model the task references.
2. Place it under `model/core/idioma.php` and rely on
   `fs_model_autoloader`'s `class_alias` (`base/fs_model_autoloader.php:256,278`).

**Rationale**: `exploration.md` §5.6 / proposal Q6 require a resolution test that
`class_exists('idioma')` resolves to the core model and that `idioma`/`idiomas`
are reserved. A global class makes that test direct and independent of the
namespaced autoloader's alias path. `base/fs_autoload.php:157-162`
(`findModelClassFile`) already resolves a global `idioma` to
`model/core/idioma.php` first and `model/idioma.php` second; the file lives at
`model/idioma.php` so `require_all_models()` (`base/fs_functions.php:809`, scans
`FS_FOLDER . '/model'`) also loads it. The namespace deviation from
`catalogo_idioma` is deliberate: the **behavioral** conventions are copied, the
namespace follows core-model convention. `tarif_idioma`
(`plugins/tarifario/...`) and `tarif_idiomas.xml` are distinct identifiers and
do not collide.

### Decision D2: The model owns the durable invariants; `LanguageRegistry` is a facade with total `defaultCode()`

**Choice**: `idioma::defaultCode(): string` implements the total, deterministic
resolution (CI-01). `LanguageRegistry::defaultCode()` delegates to it.
`idioma::save()` / `delete()` / `set_default()` / `normalize_default()` enforce
CI-03/CI-04. `LanguageRegistry` adds `activeCodes()` (deterministically sorted by
`codidioma` ascending in PHP, not by `ORDER BY nombre`), `all()`, `get()`,
`defaultRow()`, `save()`, `setDefault()`, `delete()`.

**Alternatives considered**: put the resolution algorithm only in
`LanguageRegistry` and keep the model a dumb repository.

**Rationale**: `catalogo_idioma::get_effective_default_code()`
(`plugins/catalogo_core/model/core/catalogo_idioma.php:97-112`) proves the
algorithm belongs with the persistence layer and can be unit tested DB-free.
Putting it in the model keeps a single source of truth and lets the facade
remain a stable service id. Sorting active codes in PHP by code guarantees
CI-02 determinism even when two rows share a `nombre`.

### Decision D3: `candidates()` is the only reduction boundary; `get`/`set` are exact

**Choice**: `TranslationStoreInterface` exposes exactly
`get(field, locale): ?string`, `set(field, locale, ?value): bool`, and
`candidates(locale): string[]`. `candidates()` is the store's **declared,
ordered reduction policy**; `get`/`set` perform an **exact** lookup/write for the
code they receive. `ContentTranslator::read()` iterates
`$store->candidates($locale)` and calls `$store->get($field, $candidate)` for
each; the configured default and base legs follow.

**Alternatives considered**:
1. `get()` performs reduction internally (exact-then-prefix) and
   `ContentTranslator` just calls `get` once. Rejected: it hides the policy and
   makes the per-store opt-in invisible to callers/tests.
2. Add a `supportsPrefixFallback(): bool` method. Rejected: redundant — the
   presence of the prefix code in `candidates()` already encodes the opt-in and
   matches the locked interface in `proposal.md` §Approach.

**Rationale**: One method decides reduction, so CI-08/CI-09/CI-10 become direct
assertions on `candidates()` output and `read()` results. Two `RowTranslationStore`
instances built over identical data with `prefixFallback: false|true` differ only
in `candidates()`, satisfying the explicit requirement that CI-09/CI-10 be
testable **without mocking internals**.

### Decision D4: Per-store prefix opt-in is a constructor parameter

**Choice**:
- `RowTranslationStore::$prefixFallback` (constructor `bool $prefixFallback = false`,
  fail-safe default: no reduction).
- `FixedColumnTranslationStore::$prefixFallback` (constructor
  `bool $prefixFallback = true`, because the store is inherently 2-letter and a
  region-qualified caller must reduce to find its column; the column map itself
  is the alias-free boundary).

**Rationale**: Satisfies `proposal.md` §Approach ("Each store decides whether
prefix reduction applies") and gives the CI-09 pair its construction seam.

### Decision D5: Alias boundary (CI-11) — plugin owns aliases, core owns only mechanical suffix reduction

**Choice**: The boundary is exactly at `candidates()`:

- **Plugin side (ends here)**: request-locale resolution and every alias. OIDC's
  `LocaleManager::normalizeLocale()` (`plugins/OidcProvider/Service/LocaleManager.php:503-515`,
  alias `en_US → en_GB` at `:62-64`) runs first and hands the core an
  already-normalized locale.
- **Core side (begins here)**: `FixedColumnTranslationStore::candidates($locale)`
  returns `[$locale, substr($locale, 0, 2)]` (deduped) and the store looks each
  up in the caller-supplied `locale → column` map. It **never** consults an alias
  table and never redirects `en_US` to `en_GB`.
- `RowTranslationStore::candidates($locale)` returns `[$locale]`, or
  `[$locale, substr($locale, 0, 2)]` only when opted in. Exact stored code always
  wins.
- No shared boundary collapses codes; no read or construction rewrites a caller
  locale or a stored code.

`en_US` matrix (unambiguous): the map is suffix-keyed
(`es → subtitle_es`, `fr → subtitle_fr`, `en → subtitle_en`).
`candidates('en_US') = ['en_US','en']`; `get('subtitle','en_US')` is `null` (no
map entry); `get('subtitle','en')` reads `subtitle_en`. No `en_GB` column is ever
consulted. When a plugin has already normalized `en_GB`, the same reduction
yields `en`, so both paths read `subtitle_en` — identical to
`oidc_client::getSubtitle('en_GB')` (`exploration.md` §2.1).

**Rationale**: CI-11 requires the core to select the column the caller locale
selects. `exploration.md` §4 documents why a global collapse is unsafe.

### Decision D6: `RowTranslationStore` clears per field and deletes the row only when all mapped fields are empty

**Choice**: `set(field, locale, null|'')` sets that field's column to `NULL`
(absence semantics) and then runs a conditional
`DELETE ... WHERE record = key AND locale = code AND <all mapped columns> IS NULL`.
A value write updates the existing `(record_key, codidioma)` row or inserts it;
`UNIQUE(record_key, codidioma)` is never touched.

**Alternatives considered**: delete the entire locale row on any empty `set`.
Rejected: it would nuke sibling fields of the same record/locale. Mirrors
`articulo_descripcion::save()` (`exploration.md` §2.2), which deletes the row only
when both fields are empty.

**Rationale**: CI-12 (one row per record+locale, update-not-duplicate), CI-14
(clearing returns `null`, no copied fallback), and multi-field tables coexist
without data loss.

### Decision D7: `ContentI18nMigration` is table-guarded, flag-guarded, and fully overridable

**Choice**: `src/Core/Schema/ContentI18nMigration.php` mirrors
`AdminOnlyPagesMigration` (`src/Core/Schema/AdminOnlyPagesMigration.php:40-241`):
- `public const FLAG = 'content_i18n_migrated';`
- `public const TABLE_ALLOWLIST = ['idiomas'];`
- `public static function run(): bool` → `(new self())->execute();`
- `execute()` = `isApplied() ? true : (adoptTable() && seed() && markApplied())`.
- `adoptTable()` instantiates `new \idioma()` (lazy schema creation; returns
  `false` when `db()->get_columns('idiomas')` reports no such table — the no-DB
  path).
- `seed()` calls `$model->ensure_defaults()` and verifies the table has ≥1 row.
- `markApplied()` writes `fs_var::simple_save(self::FLAG, 'TRUE')`.
- Every step returns `bool`; the first `false` aborts before `markApplied()`, so
  the flag is unwritten and the next request retries (CI-20).
- `execute()` asserts `in_array($model->table_name, self::TABLE_ALLOWLIST, true)`
  before any work, so core code structurally cannot touch a plugin table (CI-18).
- Steps are `protected` so a no-DB recorder test can assert ordering and failure
  (the `AdminOnlyPagesMigrationTest` pattern).

**Alternatives considered**: reuse `fs_model::seed_if_empty()` only (no explicit
migration). Rejected: it has no `fs_var` flag, no retry semantics, and no
allowlist guarantee, which CI-18/CI-19/CI-20 require.

**Rationale**: The existing migration is the proven, testable template.

### Decision D8: Migration seeding is defense-in-depth but observationally single

**Choice**: `idioma::install()` returns the single-row `es` seed SQL (so a table
created by the lazy schema pass is never left empty), and
`idioma::ensure_defaults()` inserts the same single `es` row **only when the
table is empty** (catalogo convention,
`plugins/catalogo_core/model/core/catalogo_idioma.php:43-63`).
`ContentI18nMigration::seed()` calls `ensure_defaults()`.

**Rationale**: CI-05 is phrased observationally ("empty → exactly one `es` row;
non-empty → unchanged; second run → no-op"). Both paths converge on that state
idempotently. Whichever layer seeded, the migration's seed step is safe and the
observable result and flag are identical. Tests assert the observable state and
the flag, never which layer performed the insert.

### Decision D9: Namespaced core services with a reserved-id re-assert after plugin load

**Choice**: In `Container::registerCoreServices()`
(`src/DependencyInjection/Container.php:72-123`), after
`self::registerLegacyModels()` (`:119`) and **before**
`self::loadPluginServices()` (`:122`), call
`self::registerContentI18nServices()`, which registers:

```php
$container->register('core.language_registry', \FSFramework\Translation\LanguageRegistry::class)
    ->setPublic(true);
$container->register('core.content_translator', \FSFramework\Translation\ContentTranslator::class)
    ->setPublic(true)
    ->setArguments([new \Symfony\Component\DependencyInjection\Reference('core.language_registry')]);
```

`registerContentI18nServices()` stores the resulting `Definition` objects in a
new `private static array $reservedDefinitions = [];`. Immediately after
`self::loadPluginServices()` returns, call `self::restoreReservedServices()`,
which re-`setDefinition()`s those exact definitions.

**Alternatives considered**: register only after plugins load. Rejected: CI-17
requires registration *before* `loadPluginServices()`.

**Rationale**: Symfony's `ContainerBuilder::register()` overwrites
unconditionally (`vendor/symfony/dependency-injection/ContainerBuilder.php:959-961`
→ `setDefinition` at `:1022-1033`). Registering before and re-asserting after is
the minimal way to satisfy both "registered before plugins" and "a plugin
registering a colliding id MUST NOT replace either core instance" (CI-17).
Only the two `core.` ids are reserved; plugin ids (e.g. OIDC's `oidc.*`,
`plugins/OidcProvider/config/services.php:18-49`) are untouched.

### Decision D10: `TranslatableControllerTrait` guards are overridable protected seams

**Choice**: `src/Traits/TranslatableControllerTrait.php` provides
`persistTranslation(TranslationStoreInterface $store, string $field, ?string $locale, ?string $value): bool`
which calls, in order, `translationRequestIsPost()`,
`translationActorIsAdmin()`, `translationCsrfIsValid()`, and only then
`$store->set(...)`. The three guards default to the `fs_controller` surface
(`$this->getRequest()->isMethod('POST')`, `$this->user->admin`,
`$this->isCsrfValid()` — `base/fs_controller.php:343,432,921`) and are
`protected`/overridable so the CI-21 matrix is testable with an anonymous trait
user, no full controller.

**Alternatives considered**: hard-code `requireCsrf()`. Rejected: `requireCsrf()`
returns `true` for non-POST (`base/fs_controller.php:449-453`), so it cannot
enforce the POST-only rule alone; the seam approach enforces all three
independently.

**Rationale**: CI-21 needs four independent outcomes (non-POST, non-admin, bad
CSRF, authorized). Overridable seams give one test per outcome and reuse real
controller behavior in production.

### Decision D11: Render-only Twig macro, no core UI surface

**Choice**: `themes/AdminLTE/view/Macro/Translation.html.twig` exposes
`fields(field, locales, values, opts = {})` that renders one labelled input per
locale. It emits no `<form>`, no CSRF field, no `<script>`, and performs no
write. No controller, route, page, menu row or `#[AdminOnly]` is added.

**Rationale**: CI-22. The `HtmxMacroContractTest` pattern
(`tests/Core/HtmxMacroContractTest.php:29-52`) shows how to render a theme macro
in isolation.

### Decision D12: No language foreign key anywhere (D-01 preserved)

**Choice**: `model/table/idiomas.xml` declares only the `codidioma` primary key.
Neither `RowTranslationStore` nor the core XML adds a `codidioma` FK.
`RowTranslationStore::purgeLanguage(string $locale): int` performs application
level cleanup. The core registry never deletes plugin translation rows.

**Rationale**: catalogo design **D-01** (referenced in `exploration.md` §2.3)
established that an FK on the language dimension is unreliable because
`FS_FOREIGN_KEYS` is an operator toggle and is off by default. `UNIQUE` is kept.
CI-13.

---

## Data Flow

### Registry default resolution (CI-01…CI-05)

    idioma model ──defaultCode()──▶ active por_defecto? ──▶ code
          │                              │ no
          │                              ▼
          │                    lowest active codidioma ──▶ code
          │                              │ none
          │                              ▼
          └─────────────────────────── 'es' (constant)

### Content read chain (CI-06…CI-11, CI-16)

    caller locale ──▶ store.candidates(locale)         (per-store reduction policy)
                              │
                              ▼  for each candidate (exact first, then prefix)
                        store.get(field, candidate) ── hit ──▶ return value
                              │ all miss
                              ▼
              LanguageRegistry.defaultCode() ──▶ store.get(field, default)
                              │ miss
                              ▼
                        base (caller-supplied) ──▶ non-empty? return it
                              │
                              ▼
                             ''   (terminal)

Read-only: no `set`, no materialization, no `Request` consult (CI-07).

### RowTranslationStore write/clear (CI-12, CI-14, CI-15)

    set(field, locale, value)
      value !== null && value !== ''  ──▶ UPDATE/INSERT row (record_key, locale)
      value === null || value === ''  ──▶ SET field column = NULL
                                          ──▶ DELETE row IF all mapped columns IS NULL

    purgeLanguage(locale) ──▶ COUNT(*) for locale ──▶ DELETE rows for locale ──▶ return count

### Migration (CI-18…CI-20)

    index.php / api.php / cron.php
      fs_schema::selfHealCoreTables()           (existing)
      try { AdminOnlyPagesMigration::run() }    (existing)
      try { ContentI18nMigration::run() }       (new, additive)
            │
            ▼
      isApplied()? ── yes ──▶ no-op
            │ no
            ▼
      adoptTable()  (new \idioma() → lazy schema create)
            ▼
      seed()        (ensure_defaults: insert 'es' only if empty)
            ▼
      markApplied() (fs_var 'content_i18n_migrated' = 'TRUE')
      any step false ──▶ abort, flag unwritten, next request retries

---

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `model/table/idiomas.xml` | Create | `codidioma VARCHAR(5)` PK, `nombre VARCHAR(50) NOT NULL`, `activo boolean default true`, `por_defecto boolean default false`. No FK. |
| `model/idioma.php` | Create | Global `class idioma extends \fs_model`; seed/ensure_defaults, defaultCode(), lifecycle guards. |
| `src/Translation/LanguageRegistry.php` | Create | Service facade over `idioma`; total `defaultCode()`, sorted `activeCodes()`, `all()`, `get()`, `defaultRow()`, `save()`, `setDefault()`, `delete()`. |
| `src/Translation/ContentTranslator.php` | Create | Resolution engine `exact → prefix → default → base → ''`; read-only, explicit locale. |
| `src/Translation/Store/TranslationStoreInterface.php` | Create | `get`/`set`/`candidates` contract. |
| `src/Translation/Store/RowTranslationStore.php` | Create | Generic per-record rows; ctor prefix opt-in; `purgeLanguage()`; no FK. |
| `src/Translation/Store/FixedColumnTranslationStore.php` | Create | locale→column map; closure reader/writer; suffix reduction; no schema change. |
| `src/Core/Schema/ContentI18nMigration.php` | Create | `fs_var`-flagged, idempotent, allowlist `['idiomas']`, overridable steps. |
| `src/Traits/TranslatableControllerTrait.php` | Create | POST-only + admin + CSRF guarded `persistTranslation()` with overridable guard seams. |
| `themes/AdminLTE/view/Macro/Translation.html.twig` | Create | Render-only `fields(...)` macro. |
| `src/DependencyInjection/Container.php` | Modify | Register `core.language_registry` / `core.content_translator`; add `$reservedDefinitions` + `registerContentI18nServices()` + `restoreReservedServices()`; call around `loadPluginServices()`. Additive lines only. |
| `index.php` | Modify | Additive `try/catch { ContentI18nMigration::run(); }` after the admin-only block (`:90-95`). |
| `api.php` | Modify | Same additive hook after `:143-148`. |
| `cron.php` | Modify | Same additive hook after `:74-79` (inside the connected branch). |
| `tests/Support/FakeIdioma.php` | Create | DB-free `idioma` subclass (skips `fs_model` ctor, injects fake db). |
| `tests/Support/IdiomaModelFakeDb.php` | Create | In-memory `fs_db2` double for the `idiomas` statements. |
| `tests/Support/FakeRowStoreDb.php` | Create | In-memory db double for `RowTranslationStore`. |
| `tests/Base/IdiomaModelTest.php` | Create | CI-01…CI-05 model invariants. |
| `tests/Base/ContentI18nReservedNameTest.php` | Create | Q6: `class_exists('idioma')` resolves to `model/idioma.php`; no plugin collides. |
| `tests/Core/LanguageRegistryTest.php` | Create | Facade delegation + sorted `activeCodes()`. |
| `tests/Core/ContentTranslatorTest.php` | Create | CI-06/CI-07 chain for both stores + locale matrix. |
| `tests/Core/TranslationStoresTest.php` | Create | CI-08…CI-16 store behavior and opt-in pair. |
| `tests/Core/ContentI18nMigrationTest.php` | Create | CI-18/CI-20 ordering, flag, retry, allowlist. |
| `tests/Core/ContentI18nHookTest.php` | Create | CI-19 structural hook assertions in the three entry points. |
| `tests/Core/ContentI18nCoreIsolationTest.php` | Create | CI-23/CI-24 grep gates (`catalogo_idiomas` absent; reserved names). |
| `tests/Core/TranslationMacroContractTest.php` | Create | CI-22 macro render-only contract. |
| `tests/Core/ContentI18nDiContractTest.php` | Create | CI-17 resolve + plugin-shadow denial. |
| `tests/Traits/TranslatableControllerTraitTest.php` | Create | CI-21 guard matrix. |
| `plugins/catalogo_core/**`, `plugins/OidcProvider/**` | Unchanged | Consumers migrate in later, separate changes. |
| existing `model/table/*`, `base/*`, `src/Translation/FSTranslator.php` | Unchanged | No protected surface edited. |

---

## Interfaces / Contracts

### `model/idioma.php` (global class)

```php
class idioma extends \fs_model
{
    public const TABLE = 'idiomas';
    public const DEFAULT_CODE = 'es';

    public $codidioma;      // ?string
    public $nombre;         // string
    public $activo;         // bool
    public $por_defecto;    // bool

    public function __construct($data = false);
    protected function install();                       // single-row es seed SQL
    public function ensure_defaults(): void;            // insert 'es' only when empty
    public function get($cod);                          // ?static
    public function get_default();                      // ?static
    public function defaultCode(): string;              // CI-01 total, deterministic
    public function set_default(string $codidioma): bool; // CI-03 flag flip
    public function exists();
    public function test(): bool;                       // codidioma 2..5, nombre 1..50
    public function save(): bool;                       // CI-04 guards
    public function delete(): bool;                     // CI-04 guards
    public function all(): array;                       // static[]
    public function all_activos(): array;               // static[]
    protected function normalize_default(): void;
}
```

`defaultCode()` algorithm (CI-01): active `por_defecto` → lowest active
`codidioma` (`ORDER BY codidioma ASC LIMIT 1`) → `self::DEFAULT_CODE`.
`save()` refuses to set the current default inactive; `delete()` refuses the
default, the last row, and returns `false` without touching the table otherwise.

### `src/Translation/LanguageRegistry.php`

```php
namespace FSFramework\Translation;

final class LanguageRegistry
{
    public function __construct(?\idioma $model = null);   // default: new \idioma()
    public function defaultCode(): string;
    public function activeCodes(): array;                  // list<string>, sorted ASC
    public function all(): array;                          // list<\idioma>
    public function get(string $codidioma): ?\idioma;
    public function defaultRow(): ?\idioma;
    public function save(\idioma $idioma): bool;
    public function setDefault(string $codidioma): bool;
    public function delete(\idioma $idioma): bool;
    public function model(): \idioma;                      // test/inspection seam
}
```

### `src/Translation/ContentTranslator.php`

```php
namespace FSFramework\Translation;

use FSFramework\Translation\Store\TranslationStoreInterface;

final class ContentTranslator
{
    public function __construct(?LanguageRegistry $registry = null);

    /**
     * Ordered resolution: exact caller locale -> language prefix (when the store
     * opted in) -> configured default -> caller base -> ''.
     * Read-only and non-materializing; never consults Request/session/cookie.
     */
    public function read(
        TranslationStoreInterface $store,
        string $field,
        ?string $locale,
        string $base = ''
    ): string;

    public function defaultCode(): string;
}
```

`read()` returns the first non-null store value found while iterating
`$store->candidates($locale)`; with `$locale === null || $locale === ''` the
caller/prefix legs are skipped. Then it tries
`$store->get($field, $this->registry->defaultCode())`, then `$base` when
non-empty, then `''`.

### `src/Translation/Store/TranslationStoreInterface.php`

```php
namespace FSFramework\Translation\Store;

interface TranslationStoreInterface
{
    /** Exact stored value for field + code; null when absent. */
    public function get(string $field, string $locale): ?string;

    /** Write a value; null or '' clears that code's entry. */
    public function set(string $field, string $locale, ?string $value): bool;

    /** Ordered, non-destructive candidate codes for a caller locale. */
    public function candidates(string $locale): array;   // list<string>
}
```

### `src/Translation/Store/RowTranslationStore.php`

```php
namespace FSFramework\Translation\Store;

final class RowTranslationStore implements TranslationStoreInterface
{
    /**
     * @param array<string,string> $fieldColumns  logical field => column
     */
    public function __construct(
        string $table,
        array $fieldColumns,
        string $recordColumn,
        string $recordKey,
        string $localeColumn = 'codidioma',
        ?object $db = null,                 // fs_db2-compatible; default new \fs_db2()
        bool $prefixFallback = false
    );

    public function get(string $field, string $locale): ?string;
    public function set(string $field, string $locale, ?string $value): bool;
    public function candidates(string $locale): array;   // [locale] or [locale, prefix]
    public function purgeLanguage(string $locale): int;
    public function tableName(): string;
}
```

### `src/Translation/Store/FixedColumnTranslationStore.php`

```php
namespace FSFramework\Translation\Store;

final class FixedColumnTranslationStore implements TranslationStoreInterface
{
    /**
     * @param array<string,string> $localeColumnMap  e.g. ['es'=>'subtitle_es', ...]
     * @param \Closure(string): ?string $reader
     * @param \Closure(string, ?string): bool $writer
     */
    public function __construct(
        array $localeColumnMap,
        \Closure $reader,
        \Closure $writer,
        bool $prefixFallback = true
    );

    public function get(string $field, string $locale): ?string;   // exact map key only
    public function set(string $field, string $locale, ?string $value): bool;
    public function candidates(string $locale): array;             // [locale, prefix] deduped
}
```

`field` is accepted for interface symmetry; this store is single-field because
the column map fixes the field.

### `src/Core/Schema/ContentI18nMigration.php`

```php
namespace FSFramework\Core\Schema;

class ContentI18nMigration
{
    public const FLAG = 'content_i18n_migrated';
    public const TABLE_ALLOWLIST = ['idiomas'];

    public static function run(): bool;
    public function execute(): bool;
    protected function isApplied(): bool;
    protected function loadDependencies(): void;
    protected function adoptTable(): bool;
    protected function seed(): bool;
    protected function markApplied(): bool;
    private function db(): \fs_db2;
}
```

### `src/Traits/TranslatableControllerTrait.php`

```php
namespace FSFramework\Traits;

use FSFramework\Translation\Store\TranslationStoreInterface;

trait TranslatableControllerTrait
{
    protected function persistTranslation(
        TranslationStoreInterface $store,
        string $field,
        ?string $locale,
        ?string $value
    ): bool;

    protected function translationRequestIsPost(): bool;   // $this->getRequest()->isMethod('POST')
    protected function translationActorIsAdmin(): bool;    // !empty($this->user->admin)
    protected function translationCsrfIsValid(): bool;     // $this->isCsrfValid()
}
```

### Twig macro contract

```twig
{% import 'Macro/Translation.html.twig' as i18n %}
{{ i18n.fields('subtitle', locales, values, {'multiline': false}) }}
```

`locales`: list of `{code, name}`. `values`: map `code => string`. The macro
renders one escaped `<input name="subtitle[<code>]">` (or `<textarea>`) per
locale, with no `<form>`, no CSRF field and no script.

---

## Testing Strategy

Runner: `ddev exec php vendor/bin/phpunit` (PHPUnit 11, `phpunit.xml`,
`tests/bootstrap.php`). All new tests are DB-free: they reuse the
`AdminOnlyPagesMigration` overridable-step pattern and the catalogo
in-memory-db pattern (`plugins/catalogo_core/tests/Support/FakeCatalogoIdioma.php`,
`IdiomaRegistryFake.php`).

| Layer | What to Test | Approach |
|-------|--------------|----------|
| Unit / Base | `idioma` CI-01…CI-05 invariants, guards, determinism, permutation independence | `tests/Base/IdiomaModelTest.php` with `FakeIdioma` + `IdiomaModelFakeDb`; `require_once FS_FOLDER.'/model/idioma.php'` in `setUp()` |
| Unit / Base | Reserved name Q6 | `tests/Base/ContentI18nReservedNameTest.php`: `(new \ReflectionClass('idioma'))->getFileName()` ends with `/model/idioma.php`; scan plugins for `class idioma` / `idiomas.xml` collisions |
| Unit / Core | `LanguageRegistry` delegation + sorted `activeCodes()` (CI-02) | `tests/Core/LanguageRegistryTest.php` over `FakeIdioma` |
| Unit / Core | `ContentTranslator` chain (CI-06/CI-07) for both stores; locale matrix `en_US`, `fr_CA`, `pt_BR`, `es`, `en` | `tests/Core/ContentTranslatorTest.php` with a stub store double and the real registry over `FakeIdioma`; assert store state unchanged after reads |
| Unit / Core | Stores: CI-08 (exact), CI-09 (opt-in pair over identical data via two ctors), CI-10 (region distinctness), CI-11 (no alias), CI-12/CI-14/CI-15 (`RowTranslationStore`), CI-16 (`FixedColumnTranslationStore`) | `tests/Core/TranslationStoresTest.php`; opt-in pair constructed from the same `FakeRowStoreDb` |
| Unit / Core | `ContentI18nMigration` order/flag/retry/allowlist (CI-18/CI-20) | `tests/Core/ContentI18nMigrationTest.php` with a no-DB recorder extending the migration and overriding the protected steps (mirrors `tests/Core/AdminOnlyPagesMigrationTest.php`) |
| Unit / Core | Bootstrap hooks (CI-19) | `tests/Core/ContentI18nHookTest.php` reads `index.php`, `api.php`, `cron.php` source and asserts `strpos(selfHealCoreTables) < strpos(ContentI18nMigration::run)` and presence of `try { ... } catch (` in each |
| Unit / Core | DI resolve + plugin shadow denial (CI-17) | `tests/Core/ContentI18nDiContractTest.php`; reset container, register a colliding `core.content_translator`, assert the resolved instance is the core class |
| Unit / Core | Isolation grep gates (CI-23/CI-24) | `tests/Core/ContentI18nCoreIsolationTest.php`: zero `catalogo_idiomas` under the listed files; `TABLE_ALLOWLIST === ['idiomas']`; protected identifiers untouched by scanning the change's new file set |
| Unit / Core | Macro render-only (CI-22) | `tests/Core/TranslationMacroContractTest.php` renders the macro with a `FilesystemLoader` rooted at `/themes/AdminLTE/view` (pattern of `tests/Core/HtmxMacroContractTest.php`); asserts N inputs, no `<form`, no `<script`, no `csrf` |
| Unit / Traits | CI-21 guard matrix | `tests/Traits/TranslatableControllerTraitTest.php` with an anonymous trait user overriding the three guard seams and an in-memory store double; four cases (GET, non-admin, bad CSRF, authorized POST) |
| Regression | CI-23/CI-25/CI-26 | run `tests/Translation/` and the whole `Base`+`Core`+`Traits`+`Plugins` suites unchanged; `ddev exec php vendor/bin/phpunit` green |

**Spec coverage map**: CI-01…CI-05 → `idioma` + migration seed; CI-06/07 →
`ContentTranslator`; CI-08/09/10/11 → stores `candidates`/opt-in/alias boundary;
CI-12…CI-15 → `RowTranslationStore`; CI-16 → `FixedColumnTranslationStore`;
CI-17 → `Container`; CI-18/19/20 → migration + hooks; CI-21 → trait;
CI-22 → macro/no-UI test; CI-23/24/25/26 → isolation + regression gates.

---

## Threat Matrix

The matrix applies only to routing, shell commands, subprocesses, VCS/PR
automation, executable-file classification or process integration.

| Boundary | Applicability | Design response | Planned RED tests |
|---|---|---|---|
| Documentation-like paths | N/A: no path classification or executable-file handling | — | None |
| Git repository selection | N/A: no `git` invocation | — | None |
| Commit state | N/A: no VCS automation | — | None |
| Push state | N/A: no push automation | — | None |
| PR commands | N/A: no PR automation | — | None |
| Process integration (bootstrap entry points) | Applicable: the change adds a hook to `index.php`, `api.php`, `cron.php` | Hook runs after self-heal, wrapped in `try/catch`; migration is a no-op without a DB and never throws into the request path | `tests/Core/ContentI18nHookTest.php` (ordering + contained try/catch) and `ContentI18nMigrationTest` no-DB/no-throw case (CI-19 scenario 4/5, CI-20) |

No shell, subprocess, VCS/PR or executable-classification boundary exists.

---

## Migration / Rollout

- **Core-first, additive-only.** This change ships only the mechanism; no plugin
  is modified.
- **Bootstrap**: `ContentI18nMigration::run()` is added, inside `try/catch`,
  immediately after the existing `AdminOnlyPagesMigration` block in:
  - `index.php` after `:95` (before the `base/fs_controller.php` require at `:103`);
  - `api.php` after `:148`;
  - `cron.php` after `:79` (inside the `if ($db->connect())` branch).
  Non-DB entry paths are safe no-ops; any failure leaves the flag unwritten so
  the next request retries.
- **Persisted artifacts**: only the new `idiomas` table, its seeded `es` row and
  the `fs_var` flag `content_i18n_migrated`. No plugin table is read, written or
  referenced.
- **Rollback**: revert the commits; the new services simply stop existing and no
  plugin data changed. Optionally `DROP TABLE idiomas` and delete the `fs_var`
  flag. Operator safety net: a pre-rollout DB dump, standard for any core
  release.

### Chained-delivery slicing (`auto-chain`, review budget 800; default 400)

The delivery exceeds the default 400-line review budget, so it is sliced into
five autonomous work units. Each slice has its own start/finish, verification
and rollback; PR #1 targets the feature branch, later PRs target the previous
slice's branch.

| Slice | Scope | Depends on | Est. authored lines | Rollback |
|---|---|---|---|---|
| S1 Registry persistence | `model/table/idiomas.xml`, `model/idioma.php`, `tests/Support/FakeIdioma.php`, `tests/Support/IdiomaModelFakeDb.php`, `tests/Base/IdiomaModelTest.php`, `tests/Base/ContentI18nReservedNameTest.php` | — | ~450 | Revert slice files; table/flag drop optional |
| S2 Registry service + DI | `src/Translation/LanguageRegistry.php`, `src/DependencyInjection/Container.php` (registry + reserved guard), `tests/Core/LanguageRegistryTest.php`, `tests/Core/ContentI18nDiContractTest.php` | S1 | ~380 | Revert service id; no data impact |
| S3 Migration + hooks | `src/Core/Schema/ContentI18nMigration.php`, `index.php`, `api.php`, `cron.php`, `tests/Core/ContentI18nMigrationTest.php`, `tests/Core/ContentI18nHookTest.php` | S1 | ~450 | Revert hooks; flag/table drop optional |
| S4 Engine + stores | `src/Translation/Store/*`, `src/Translation/ContentTranslator.php`, `src/DependencyInjection/Container.php` (translator id), `tests/Support/FakeRowStoreDb.php`, `tests/Core/ContentTranslatorTest.php`, `tests/Core/TranslationStoresTest.php` | S1, S2 | ~500 | Revert engine/services; no data impact |
| S5 Consumer surface + gates | `src/Traits/TranslatableControllerTrait.php`, `themes/AdminLTE/view/Macro/Translation.html.twig`, `tests/Traits/TranslatableControllerTraitTest.php`, `tests/Core/TranslationMacroContractTest.php`, `tests/Core/ContentI18nCoreIsolationTest.php` | S1–S4 | ~400 | Revert trait/macro/tests; no runtime consumer yet |

`Decision needed before apply: Yes` (confirm the five-slice chain vs a single
`size:exception` PR). `Chained PRs recommended: Yes`. `400-line budget risk: High`
for a single PR, Medium per slice.

---

## Open Questions

- [ ] Confirm the S1–S5 chained slicing (or accept a single oversized PR with
  `size:exception`). This is a delivery decision, not a design blocker.
- [ ] `idioma::install()` seed ownership is documented as defense-in-depth
  (Decision D8); confirm the migration seed step should call
  `ensure_defaults()` (chosen) rather than an explicit `INSERT`, so both seed
  paths stay convergent.
- [ ] No other blocking technical question. Product decisions Q1–Q7 are locked
  and not reopened.

---

## Key Learnings

1. Symfony's `ContainerBuilder::register()` always overwrites an existing id, so
   a namespaced core service registered before plugin services must be re-asserted
   after `loadPluginServices()` to be truly non-shadowable.
2. Encoding per-store prefix reduction in `candidates()` makes the opt-in a
   constructor seam and lets the CI-09 pair be built over identical data without
   mocking internals.
3. The alias boundary belongs entirely to the plugin; the core store performs only
   mechanical `substr(locale, 0, 2)` suffix reduction, so `en_US` reads
   `subtitle_en` and is never redirected to `en_GB`.
4. `RowTranslationStore` must clear per field and delete the row only when every
   mapped column is null, matching the proven `articulo_descripcion::save()`
   semantics and preserving sibling fields.
