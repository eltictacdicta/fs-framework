# Exploration: core-content-i18n

**Change**: `core-content-i18n` (CORE change — lives in the core `openspec/`, not in a plugin openspec)
**Artifact store**: `openspec`
**Core VERSION**: `0.22.7`
**Status of prior decisions**: the hybrid pattern, backward-compatibility mandate,
registry ownership, service namespacing, migration pattern and "no new UI page"
constraint are **locked inputs**, not open questions. This document verifies the
codebase claims behind them and corrects what the evidence contradicts.

---

## 1. Problem & intent

FSFramework has **two independent, incompatible content-translation patterns**, each
built inside a plugin, and no framework-level mechanism that a new plugin can reuse:

1. **Fixed columns** (`OidcProvider`): one nullable column per language
   (`subtitle_es`, `subtitle_fr`, `subtitle_en`) plus a hand-written fallback method
   (`oidc_client::getSubtitle()`). Adding a language means an `ALTER TABLE` and a new
   code branch.
2. **Registry + per-record rows** (`catalogo_core`): a language-registry table
   (`catalogo_idiomas`) with lifecycle invariants, plus per-record translation tables
   (`articulo_descripciones`, `catalogo_opcional_idiomas`) with a documented read
   chain and clearing semantics.

Neither is reusable: a third plugin that needs translated content must either copy
the `catalogo_core` model stack (which drags domain coupling) or invent a fourth
variant. The intent of this change is to **extract the framework-native mechanism
into the core** — a locale registry, a fallback engine, and a store abstraction —
so plugins consume it instead of re-implementing it.

**Consumers targeted by this change (core-first rollout):**

- **`catalogo_core`** (later, separate delivery): keeps its tables but delegates
  registry/fallback semantics to the core engine.
- **`OidcProvider`** (later, separate delivery): keeps its `subtitle_*` schema
  untouched and serves it through the core's fixed-column store.
- **Future plugins**: consume the core service instead of copying either pattern.

**Primary constraint**: the first core release is **ADDITIVE ONLY**. No plugin table,
model, contract or behavior is renamed, removed or repointed. `catalogo_idiomas`,
`articulo_descripciones`, `articulo`, `oidc_client`, `LocaleManager` and every
existing plugin test keep working unchanged. A plugin that never migrates must
behave exactly as today.

---

## 2. Current state (verified, with `path:line` evidence)

### 2.1 OidcProvider — fixed-column pattern

- **Schema** (`plugins/OidcProvider/model/table/oidc_clients.xml:82-96`): three
  nullable `character varying(255)` columns `subtitle_es`, `subtitle_fr`,
  `subtitle_en`. No `subtitle_*` for any other locale.
- **Model** (`plugins/OidcProvider/model/oidc_client.php`):
  - properties `:44-49`; constructor null-normalizes empty to `null` `:84-89,112-114`;
  - `test()` sanitizes and caps each subtitle at 250 chars `:247-258`;
  - `addMissingColumns()` runs a lazy `ALTER TABLE ... ADD COLUMN` for `subtitle_es/fr/en` `:455-457` (inside `:445-470`);
  - **`getSubtitle(string $locale)` `:579-613`** implements exactly the fallback the
    brief describes: exact locale map (`es_ES`/`fr_FR`/`en_GB`) → language-prefix
    (`strtolower(substr($locale,0,2))` → `subtitle_es/fr/en`) → first non-empty among
    `[subtitle_es, subtitle_fr, subtitle_en]` (implicitly preferring Spanish) → `''`.
- **Runtime consumer**: `plugins/OidcProvider/Controller/OidcAccountController.php:385`
  calls `$client->getSubtitle($locale)` for the "Mi cuenta" service cards;
  rendered at `plugins/OidcProvider/view/oidc_account.html.twig:95-96` and
  `.../oidc_account_home_sections.html.twig:38-39`. `getSubtitle()` has **no
  production caller besides this one family** (all other matches are tests).
- **Editing surface**: `plugins/OidcProvider/controller/admin_oidc_clients.php:84-89`
  reads `subtitle_es/fr/en` from the POST; view fields at
  `plugins/OidcProvider/view/admin_oidc_clients.html.twig:61-73` (and a second form at
  `:312-...`).
- **Migration**: `plugins/OidcProvider/Service/OidcProviderMigrationService.php`
  - ledger `SCHEMA_VERSION_VAR = 'oidc_provider_schema_version'`,
    `CURRENT_SCHEMA_VERSION = 17` `:24-25`;
  - `migration012Subtitles()` `:1140-1199` (lock `subtitles_migration_12` `:56`,
    invoked at `:165`) adds the three columns idempotently. **Confirmed.**
- **Locale set + resolution**: `plugins/OidcProvider/Service/LocaleManager.php`
  - `SUPPORTED_LOCALES = ['es_ES','en_GB','fr_FR']` `:36`,
    `DEFAULT_ENABLED_LOCALES = ['es_ES','en_GB']` `:43`;
  - legacy alias `en_US => en_GB` `:62-64`, applied by `normalizeLocale()` `:503-515`;
  - per-request resolution order query `lang` → cookie → session → default
    `:87-109`; default resolution constant → `fs_var` → first enabled `:378-410`;
  - boot push into the translator: `plugins/OidcProvider/Init.php:81-92`
    (`FSTranslator::setDefaultLocale()` + `setLocale()`).
  - **This is region-qualified locale space. `codidioma` in catalogo is 2-letter.**
    This mismatch is the core of §4.

### 2.2 catalogo_core — registry + per-record rows pattern

- **Registry model** `plugins/catalogo_core/model/core/catalogo_idioma.php`:
  - table `catalogo_idiomas`, `DEFAULT_CODE = 'es'` `:18-19`;
  - `install()` / `ensure_defaults()` seed `es` (default, active) + `en` `:43-63`;
  - **`get_effective_default_code()` is total and deterministic** `:97-112`:
    active `por_defecto` → lowest active `codidioma` → `DEFAULT_CODE`;
  - `set_default()` `:118-143` is a **flag flip** — it issues no description
    statement; `normalize_default()` `:151-171` restores "exactly one active default";
  - `save()` `:200-244` refuses to deactivate the default `:206-212`;
  - `delete()` `:246-293` refuses deleting the last language `:248-252` and the default
    `:254-257`, removes the language's `articulo_descripciones` rows with **raw SQL**
    in one transaction `:263-281`, then invalidates the search cache if it removed rows
    `:288-290`;
  - `test()` validates `codidioma` 2–5 chars and `nombre` 1–50 `:187-198`.
- **Per-record rows**:
  - `plugins/catalogo_core/model/table/articulo_descripciones.xml`: `id` PK,
    `referencia VARCHAR(18)`, `codidioma VARCHAR(5)`, `descripcion text NOT NULL`,
    `descripcion_corta VARCHAR(150) NULL`, `UNIQUE(referencia, codidioma)`, and
    `FOREIGN KEY(referencia) REFERENCES articulos(referencia) ON DELETE CASCADE`.
    **There is NO `codidioma` FK** — deliberate.
  - `plugins/catalogo_core/model/table/catalogo_opcional_idiomas.xml`: `UNIQUE(codigo,
    codidioma)` + `FK(codigo) REFERENCES catalogo_opcionales ON DELETE CASCADE`.
    Again **no `codidioma` FK**.
  - `plugins/catalogo_core/model/core/articulo_descripcion.php` owns the write/clear
    semantics: `save()` `:131-177` deletes the row when both fields are empty, and
    calls `articulo::invalidate_search_cache()` on every write `:171`; `delete()`
    `:179-190` also invalidates; `test()` accepts an empty `descripcion` `:96-124`.
- **Read/write API** `plugins/catalogo_core/model/core/articulo.php`:
  - DB seam `language_registry()` returns `new catalogo_idioma()` `:1437-1440`;
    `description_model()` returns `new articulo_descripcion()` `:1448-1451`;
  - `get_descripciones()` caches rows `:1457-1467` (cache reset in
    `set_descripcion_idioma()` `:1567`);
  - **`get_descripcion_idioma($codidioma = null)` `:1478-1503`** is the locked chain:
    requested language → configured default (`get_effective_default_code()`) →
    base `articulos.descripcion` → `''`. Pure reads, **no materialization**, and the
    base column is never mirrored on write.
  - `descripcion_idioma($codidioma, $len)` truncates `:1508-1516`;
  - `get_descripcion_corta_idioma()` `:1527-1544` is **same-language only, no
    inheritance** (D-08 local decision);

### 2.3 Prior catalogo decision that CONTRADICTS the brief's FK assumption

`plugins/catalogo_core/openspec/changes/archive/2026-09-23-gestion-idiomas-catalogo/design.md`:

- **D-01 (`:66-99`, `:558`, `:593`): application-level orphan cleanup in
  `catalogo_idioma::delete()`, NO `codidioma` FK in the XML.** Rationale, verbatim:
  a DB FK is **unreliable in this framework** because `base/fs_mysql.php:259`, `:483`
  and `:1455` apply XML `FOREIGN KEY` constraints only when `FS_FOREIGN_KEYS` is
  truthy; `FS_FOREIGN_KEYS` is **not defined by default in `base/config2.php`** (only
  set in `tests/bootstrap.php:56` and a few call sites), so with the toggle off a
  language FK would silently not exist. Adding the FK on an install with pre-existing
  orphan rows makes `ADD CONSTRAINT` fail and degrades the lazy schema pass; MySQL
  constraint drift (`compare_constraints()`) churns FKs. **Consequence for this
  change: the core `RowTranslationStore` MUST own application-level cleanup and MUST
  NOT rely on `ON DELETE CASCADE` for the language dimension.** `UNIQUE(referencia,
  codidioma)` is kept. (The *_record_* FKs on `referencia`/`codigo` are separate and
  remain plugin-owned.)

### 2.4 Existing core translation stack (message catalogs only)

- `src/Translation/FSTranslator.php:48` — singleton wrapper over
  `Symfony\Component\Translation\Translator`. Active locale `:62`, default locale `:65`,
  `getInstance()` `:81-88`, `initialize()` `:96-123`, `trans()` `:134-150`,
  `getLocale()`/`setLocale()` `:157-176`, `getAvailableLanguages()` `:280-297`,
  fallback chain `es_AR → es → default → en` `:338-369`, `reset()` `:304-313`.
- `src/Translation/FS2025JsonLoader.php:47` — flat/nested JSON loader for
  `Plugin/Translation/{locale}.json`.
- `src/Translation/TranslationHelper.php:37` — facade; `initializeFromConfig()`
  `:97-108` calls `FSTranslator::initialize()` and sets locale **only from the
  `FS_LANG` constant** (`detectLocale()` `:115-129` returns `null` otherwise).
- `src/Twig/TranslationExtension.php:49` — registers `trans`/`__`/`trans_choice`/
  `getLocale`/`getAvailableLanguages` (`:64-83`, `:155-170`).
- Core init happens lazily on render: `src/Core/Html.php:224-225`
  (`FSTranslator::initialize(FS_FOLDER); FSTranslator::loadAllPluginTranslations();`).
- **There is NO per-request locale resolution in the core.** `FSTranslator::$locale`
  is process-global; only `setLocale()` changes it (`:168-176`). Confirmed by grep:
  the only production callers are `OidcProvider\Init::configureDefaultLocale()`
  (`plugins/OidcProvider/Init.php:86,90`) and `TranslationHelper::initializeFromConfig()`.
  `OidcProvider` compensates with `LocaleResetRequestListener` (an event listener that
  resets the translator per request). The core has no `RequestStack`/`LocaleListener`
  equivalent.
- **No core content-translation table or class exists.** `model/table/` contains only
  `agentes`, `fs_access`, `fs_extensions2`, `fs_logs`, `fs_pages`, `fs_roles*`,
  `fs_users`, `fs_vars` — no `idioma(s)`/`language`/`translation` table.
- **No production `IdiomaRegistry`/`LanguageRegistry`/`ContentTranslator`/
  `TranslationStoreInterface` class exists anywhere** (grep over the whole repo,
  excluding `vendor/`: zero matches). The only "registry" symbols are **test doubles**:
  `plugins/catalogo_core/tests/Support/IdiomaRegistryFake.php:16` (an in-memory
  `fs_db2` double) and `tests/Support/FakeCatalogoIdioma.php:18`, consumed by
  `ArticuloMultiidiomaTest`, `CatalogoIdioma*Test`, `ConsumidoresIdiomaDefaultTest`,
  etc. The brief's claim is **confirmed**: test fakes only.

### 2.5 DI container pattern

`src/DependencyInjection/Container.php`:
- `registerCoreServices()` `:72-123` registers `event_dispatcher`, `csrf_manager`,
  `password_hasher`, `encryption`, `cache`, `session`, `request`, `router`, `db`, plus
  legacy models `:129-147`;
- `loadPluginServices()` `:153-167` loads `plugins/{name}/config/services.php` and
  calls the returned callable with the `ContainerBuilder`;
- `get()` `:188-209` resolves by service id, else auto-wires any existing class;
- `set()/singleton()/factory()` `:226-258` and shortcuts `db()/request()/...`.

Service-id collision is real: two plugins may both register a bare `idioma`-like id.
The locked namespacing (`core.language_registry`, `core.content_translator`) is the
right mitigation.

### 2.6 Migration pattern (`AdminOnlyPagesMigration`)

`src/Core/Schema/AdminOnlyPagesMigration.php`:
- flag constant `FLAG = 'admin_only_pages_migrated'` `:45`;
- `run()` `:71-74`; idempotent `execute()` `:85-100` (`isApplied()` `:110-116`
  compares strictly to the stored `'TRUE'`); every fallible step returns bool and
  aborts before the flag is written; `markApplied()` `:202-207` writes via
  `fs_var::simple_save()`;
- `loadDependencies()` `:224-241` `require_once`s legacy classes guarded by
  `class_exists($class, false)`.

Bootstrap hook sequence (all three entry points, immediately after self-heal):
- `index.php:84-95` — `fs_schema::selfHealCoreTables()` then
  `\FSFramework\Core\Schema\AdminOnlyPagesMigration::run()` in a `try/catch`;
- `api.php:138-147` — same;
- `cron.php:69-78` — same, logging through `$core_log`.

### 2.7 Adjacent mechanism that must stay out of scope

`plugins/business_data` has a "Traducciones de documentos" UI
(`view/block/admin_empresa_traducciones.html.twig`) persisted as **PHP constants in
`config2.php`** (`controller/admin_empresa.php:677-724`, `persistTraducciones()`),
keyed by document type, **with no locale dimension**. It is a config/message-catalog
concern, not content i18n. Explicitly out of scope.

---

## 3. Integration points / constraints

### 3.1 Where the new core pieces live (proposed placement)

| Piece | Proposed location | Notes |
|---|---|---|
| `LanguageRegistry` (core service) | `src/Translation/LanguageRegistry.php` | Owns the core `idiomas` table; independent of `catalogo_idiomas`. |
| Core registry model | `model/idioma.php` + `model/table/idiomas.xml` | Legacy `fs_model` + XML, matching framework conventions. Class name `idioma` is currently free. |
| `ContentTranslator` (fallback engine) | `src/Translation/ContentTranslator.php` | Pure locale resolution: `requested → default → base → ''`, plus fixed-column and row-store strategies. |
| `TranslationStoreInterface` | `src/Translation/Store/TranslationStoreInterface.php` | Read/write contract for a translatable field. |
| `RowTranslationStore` | `src/Translation/Store/RowTranslationStore.php` | Per-record rows; app-level cleanup (see §2.3 correction); preserves UNIQUE. |
| `FixedColumnTranslationStore` | `src/Translation/Store/FixedColumnTranslationStore.php` | Maps language code → column (e.g. `subtitle_es`); reads/writes without schema change. |
| `TranslatableControllerTrait` | `src/Traits/TranslatableControllerTrait.php` | Reusable admin+CSRF-guarded mutation helpers for consumer controllers. |
| Twig macro/partial | `themes/AdminLTE/view/Macro/` | Render-only; consumers include it in their own sections. |
| Service registration | `src/DependencyInjection/Container.php` `registerCoreServices()` | Register `core.language_registry` and `core.content_translator` (namespaced). |
| Migration | `src/Core/Schema/ContentI18nMigration.php` | `fs_var`-flagged, idempotent, modeled on `AdminOnlyPagesMigration`. |

### 3.2 Service registration constraint

Core services are registered inside `registerCoreServices()`, **before**
`loadPluginServices()` (`:72-123` then `:153-167`). Registering
`core.language_registry`/`core.content_translator` there guarantees they exist for
plugin `services.php` consumers regardless of load order. Plugin services must not
shadow core ids.

### 3.3 Bootstrap hook constraint

The new migration must hook **after** `fs_schema::selfHealCoreTables()` in all three
entry points (`index.php`, `api.php`, `cron.php`), wrapped in `try/catch`, writing its
`fs_var` flag only after every step succeeds. It must be a no-op if the DB is not
connected (the `AdminOnlyPagesMigration` pattern) and must never throw into the
request path.

### 3.4 Backward-compatibility guarantees (must be in the spec)

1. No existing core/plugin table, column, model, method, page, service id or translation
   key is renamed, removed, retyped or repointed.
2. `catalogo_idiomas`, `articulo_descripciones`, `catalogo_opcional_idiomas`,
   `articulo`, `articulo_descripcion`, `catalogo_idioma`, `oidc_client`,
   `oidc_clients`, `LocaleManager` are **not touched by the core release**.
3. The core `idiomas` table is **new and separate**; no `catalogo_idiomas` data is
   read, written, copied or referenced by core code (project rule: core carries no
   plugin remnants). The future `catalogo_idiomas → idiomas` copy is the plugin's own
   migration, in a later delivery.
4. The core migration never runs on plugin tables and its failure cannot affect them.
5. Service ids are namespaced to avoid collisions while both registries coexist.
6. `FSTranslator` message-catalog behavior is unchanged; content i18n is a separate
   layer that only *consumes* locale codes.

### 3.5 UI constraint

No new core page, menu row, or `#[AdminOnly]` page. Core exposes a trait + macro;
each consumer renders the section inside its own controller/view and enforces admin +
CSRF on mutations. This mirrors the prior catalogo decision D9
(`plugins/catalogo_core/openspec/.../proposal.md:56`).

---

## 4. Locale-code interop issue (the highest-risk correctness area)

Two incompatible code spaces exist today:

| Source | Shape | Example | Validator |
|---|---|---|---|
| `catalogo_idiomas.codidioma` | 2-letter, `VARCHAR(5)`, lowercased | `es`, `en` | `catalogo_idioma::test()` `:184-190` (2–5 chars) |
| `LocaleManager` (OIDC) | region-qualified `lang_REGION`, alias-mapped | `es_ES`, `en_GB`, `fr_FR`, `en_US→en_GB` | `LocaleManager::normalizeLocale()` `:503-515` |
| `OidcProvider` columns | 2-letter suffixes only | `subtitle_es`, `subtitle_fr`, `subtitle_en` | none |
| `FSTranslator` (core messages) | region-qualified + base fallback | `es_ES → es → en` | `configureFallbacks()` `:338-369` |

**Where normalization must happen**: in the **core store layer**, as a *matching
strategy*, never as a data rewrite.

- The `ContentTranslator` resolution should attempt, in order: (1) exact caller code,
  (2) language prefix (`substr($locale,0,2)`), (3) configured default, (4) base/empty —
  matching the already-proven `getSubtitle()` and catalogo chains.
- Normalization must be **non-destructive**: it must not rewrite the caller's locale,
  not rewrite stored `codidioma`, and not change the resolution result for a plugin
  that currently self-normalizes. In particular the OIDC `en_US → en_GB` alias and its
  prefix fallback live in the plugin; the core must not "fix" them (doing so could
  change which column is read).
- Each store decides how much normalization it applies. `FixedColumnTranslationStore`
  is inherently 2-letter (column suffix), so it should accept a region-qualified
  caller and reduce to the base for column lookup. `RowTranslationStore` must respect
  the exact stored code first, because row stores may legitimately store region codes
  (`VARCHAR(5)` fits `es_ES`/`fr_CA`).

**Risk**: a global collapse `es_ES → es` at the core boundary would (a) break a row
store that stored `es_ES` as the effective default and (b) silently drop region
preference (`pt_BR` vs `pt_PT`). The mitigation is a **per-store, opt-in** base
fallback plus an explicit test matrix: exact → prefix → default → empty for both
store kinds, including `en_US`, `fr_CA`, `pt_BR`.

---

## 5. Open questions / gaps (must be resolved before proposal)

1. **Core `idiomas` table shape.** Candidate:
   `codidioma VARCHAR(5) PK`, `nombre VARCHAR(50)`, `activo boolean`, `por_defecto
   boolean` — mirroring `catalogo_idiomas` for familiarity. Open: whether to add a
   `locale VARCHAR(10)` / `language VARCHAR(2)` pair to natively represent
   region-qualified codes without collapsing them, or keep a single `codidioma` and
   treat `_REGION` as an opt-in extension. The 2-letter OIDC columns argue for keeping
   the simple shape; the row-store region example argues for the pair. **Decision
   needed in proposal/design.**
2. **Should the core `LanguageRegistry` seed defaults?** `catalogo_idioma` seeds
   `es`+`en` on install (`:43-63`). For a total `get_effective_default_code()` the
   core registry needs at least one active language. Options: seed `es`+`en`
   (consistency) or seed nothing and degrade to a constant. Must not collide with the
   plugin's own seed.
3. **Optional generic `content_translations` table.** A generic
   `(entity_type, entity_id, field, codidioma, value)` table could back a third store,
   but it is **not required** by either target pattern and would add surface to the
   "first core release is additive-only" constraint. Recommendation: **defer** it;
   ship only `LanguageRegistry` + `ContentTranslator` + the two proven stores.
   Confirm in proposal.
4. **Store write contract.** Whether `TranslationStoreInterface` is read-only first
   (serving OIDC's read path) with writes added later, or read+write from day one.
   The hybrid is safe either way, but the spec must state which store methods the first
   release guarantees.
5. **Core per-request locale source.** The core has no per-request locale today. Does
   the core `ContentTranslator` accept an explicit locale (caller-supplied, as OIDC
   does) only, or also read a request-scoped locale from `Request`? Recommendation:
   accept an explicit locale now; request-scoping is a separate concern.
6. **Class/table naming collision audit.** `idioma` is currently free, but `tarif_idioma`
   (`plugins/tarifario/model/tarif_idioma.php`) is a legacy alias extending
   `catalogo_idioma`, and `tarif_idiomas.xml` exists. Confirm no future core class name
   (`idioma`) collides with an autoloaded plugin class in a shipping install.
7. **Locked-contract impact enumeration.** The prior catalogo and OIDC specs
   (`plugins/catalogo_core/openspec/specs/gestion-idiomas/spec.md`,
   `plugins/OidcProvider/openspec/specs/oidc-hardening/spec.md`) must be **referenced,
   not modified** by the core change. Proposal must state that no plugin spec delta is
   authored here.

---

## 6. Scope boundary

### In scope (this core change)

- New core content-i18n **mechanism**: `LanguageRegistry` (new `idiomas` table),
  `ContentTranslator` fallback engine, `TranslationStoreInterface`, `RowTranslationStore`,
  `FixedColumnTranslationStore`.
- Namespaced DI registration (`core.language_registry`, `core.content_translator`).
- One idempotent, `fs_var`-flagged core migration, hooked into `index.php`, `api.php`,
  `cron.php` after self-heal.
- `TranslatableControllerTrait` + a Twig macro/partial for consumers to render their
  own section (admin + CSRF enforced by the trait; no new core page/menu).
- Core unit tests (Base/Core/Traits suites) covering registry invariants, both stores,
  the fallback chain, and the locale-code matrix.
- Additive-only backward compatibility guarantees.

### Out of scope (LATER, separate deliveries)

- Any change to `catalogo_core`: `catalogo_idiomas`, `articulo_descripciones`,
  `catalogo_opcional_idiomas`, `articulo`, `catalogo_idioma`, their tests or specs.
- Any change to `OidcProvider`: `oidc_clients.subtitle_*`, `oidc_client::getSubtitle()`,
  `LocaleManager`, `OidcProviderMigrationService`, their tests or specs.
- The `catalogo_idiomas → idiomas` data copy (plugin-owned later).
- Any DB FK on the language dimension for existing plugin tables (prior catalogo
  design D-01 rejected it; the core does not reverse that).
- The optional generic `content_translations` table (deferred per §5.3).
- Core per-request locale resolution / `RequestStack` listener.
- `business_data` document-label translations (config constants; no locale dimension).

---

## 7. Verification findings & corrections to the brief

| Brief claim | Verdict | Evidence |
|---|---|---|
| OIDC subtitle columns + `getSubtitle()` fallback | **Confirmed exactly** | `oidc_client.php:44-49,579-613`; `oidc_clients.xml:82-96` |
| Migration added subtitles (`OidcProviderMigrationService`) | **Confirmed** | `OidcProviderMigrationService.php:56,165,1140-1199` |
| `catalogo_idioma` API/invariants | **Confirmed** | `catalogo_idioma.php:97-112,118-143,200-244,246-293` |
| `articulo` read/write API + fallback chain | **Confirmed** | `articulo.php:1478-1503,1508-1516,1527-1544,1554-1570` |
| No production `IdiomaRegistry` (only test fakes) | **Confirmed** | zero repo matches; fakes at `tests/Support/IdiomaRegistryFake.php:16`, `tests/Support/FakeCatalogoIdioma.php:18` |
| Core translator stack exists; NO per-request locale in core | **Confirmed** | `FSTranslator.php:157-176`; no core caller; `OidcProvider` compensates via `LocaleResetRequestListener` |
| `AdminOnlyPagesMigration` flag/migration + bootstrap hook | **Confirmed** | `AdminOnlyPagesMigration.php:45,85-116,202-207`; `index.php:84-95`, `api.php:138-147`, `cron.php:69-78` |
| `RowTranslationStore` "keeps FK CASCADE" | **CORRECTED** | catalogo design **D-01** rejected a language FK (unreliable under `FS_FOREIGN_KEYS`; `fs_mysql.php:259,483,1455`); neither table has a `codidioma` FK. Core must own app-level cleanup. `UNIQUE` is kept. |
| Locale spaces: catalogo 2-letter vs OIDC region-qualified | **Confirmed, and broader** | `LocaleManager.php:36,62-64,503-515`; `catalogo_idioma.php:184-190`; plus `FSTranslator` base-prefix fallback `:338-369` |

---

## 8. Approaches

1. **Hybrid (LOCKED)** — core `LanguageRegistry` on its own new `idiomas` table +
   `ContentTranslator` engine + `TranslationStoreInterface` with `RowTranslationStore`
   and `FixedColumnTranslationStore`.
   - Pros: serves both real patterns without touching their schemas; additive-only;
     namespaced coexistence; each plugin migrates on its own schedule.
   - Cons: a store abstraction with two shapes is more surface than a single pattern;
     locale-code normalization must be carefully scoped.
   - Effort: **High** (but the only option compatible with the mandate).

2. **Row-store only (rejected)** — force OIDC into a translation table.
   - Pros: one shape.
   - Cons: **violates backward compatibility** — OIDC schema, `getSubtitle()` and its
     `ALTER TABLE` migration would all change; not additive.
   - Effort: Medium, but disqualified.

3. **Fixed-column only (rejected)** — force catalogo into extra columns.
   - Pros: simplest read path.
   - Cons: **violates backward compatibility** and cannot scale languages; breaks
     `articulo_descripciones` source-of-truth and UNIQUE semantics.
   - Effort: Low, but disqualified.

4. **Use Symfony's translator for content (rejected)** — Symfony's Translation
   component covers message catalogs, not entity/content translation; the docs point to
   Doctrine Translatable, which is unusable because FSFramework uses `fs_model` + XML
   schemas, not Doctrine ORM. Follow Symfony *conventions* (locale codes, fallback
   hierarchy, DI, Request scope) without using the message translator for content.

## 9. Recommendation

Proceed with the **locked hybrid** (approach 1), with two evidence-driven adjustments:
(a) `RowTranslationStore` owns **application-level orphan cleanup**, not FK CASCADE
(correcting the brief per catalogo design D-01); (b) locale-code normalization is a
**per-store, opt-in, non-destructive matching strategy** owned by the store layer, not
a global collapse. Keep the first core release minimal: registry + engine + two stores +
trait + macro + migration + tests; defer the generic `content_translations` table and
core per-request locale resolution.

## 10. Risks

- **Locale-code collapse** (`es_ES → es`) silently changing resolution for row stores
  or dropping region preference. Mitigation: per-store opt-in fallback + explicit test matrix.
- **Registry/table collision** while two registries coexist; mitigated by the separate
  `idiomas` table and namespaced service ids.
- **Migration blast radius**: a core migration touching the wrong table or failing
  non-idempotently could affect plugin data. Mitigation: no plugin table referenced,
  `fs_var` flag, no-op when DB absent, `try/catch` at all three hooks.
- **Over-scoping the first release** (generic table, request-scoped locale, UI page)
  would break additive-only. Mitigation: explicit out-of-scope list in §6 and the spec.
- **FK assumption regression**: if a later contributor adds a language FK to plugin
  tables, cross-platform behavior would vary with `FS_FOREIGN_KEYS`. The spec should
  record the D-01 rationale as a known constraint.
- **Class-name collision** with `tarif_idioma` / plugin aliases if core class naming is
  careless. Mitigation: naming audit in design.

## 11. Ready for Proposal

**Yes.** The two target patterns, the core stack, the DI/container, the migration
pattern, the bootstrap hooks and the absence of per-request locale are all verified
with line evidence; the only correction to the locked brief (FK CASCADE →
application-level cleanup) is documented and does not change the hybrid decision.
Proposal must resolve the six open questions in §5 (registry shape, seeding, generic
table deferral, store write contract, per-request locale, naming audit) and state the
additive-only backward-compatibility guarantees of §3.4.

---

## 12. Affected areas (for downstream phases)

- `src/Translation/` — new `LanguageRegistry`, `ContentTranslator`, `Store/*`; existing
  `FSTranslator`/`TranslationHelper`/`FS2025JsonLoader` remain untouched.
- `model/idioma.php`, `model/table/idiomas.xml` — new core registry model/schema.
- `src/DependencyInjection/Container.php:72-123` — register namespaced services.
- `src/Core/Schema/ContentI18nMigration.php` — new idempotent migration.
- `index.php:84-95`, `api.php:138-147`, `cron.php:69-78` — hook the migration.
- `src/Traits/TranslatableControllerTrait.php` — consumer mutation helpers.
- `themes/AdminLTE/view/` — render macro/partial.
- `tests/` (Base/Core/Traits) — new coverage.
- **Consumers (later, not modified now)**: `plugins/catalogo_core/...` and
  `plugins/OidcProvider/...` remain exactly as-is.
