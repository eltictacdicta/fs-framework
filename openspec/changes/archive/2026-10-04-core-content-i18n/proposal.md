# Proposal: core-content-i18n

**Change**: `core-content-i18n` (CORE change — lives in the core `openspec/`, not in any plugin openspec)
**Artifact store**: `openspec`
**Core VERSION**: `0.22.7`
**Inputs**: `exploration.md` (canonical, verified current state); catalogo research `plugins/catalogo_core/openspec/changes/archive/2026-09-23-gestion-idiomas-catalogo/research.md` (referenced, not modified); catalogo design D-01/D4/D5/D6 (`.../gestion-idiomas-catalogo/design.md`, referenced, not modified).

---

## Intent

FSFramework has **two independent, incompatible content-translation patterns**, each grown inside a plugin, and **no framework-level mechanism a new plugin can reuse**:

1. **Fixed columns** (`OidcProvider`): one nullable column per language (`subtitle_es`, `subtitle_fr`, `subtitle_en`) plus a hand-written fallback (`oidc_client::getSubtitle()`). Adding a language means an `ALTER TABLE` and a new code branch.
2. **Registry + per-record rows** (`catalogo_core`): a language-registry table (`catalogo_idiomas`) with lifecycle invariants plus per-record translation tables (`articulo_descripciones`, `catalogo_opcional_idiomas`) with a documented read chain and clearing semantics.

A third plugin that needs translated content today must either copy the `catalogo_core` model stack (dragging domain coupling) or invent a fourth variant. This change extracts the reusable mechanism into the **core**: a locale registry, a fallback engine, and a store abstraction. Consumers in scope for **later, separate deliveries** are `catalogo_core` (keeps its tables, delegates registry/fallback semantics), `OidcProvider` (keeps `subtitle_*` untouched, serves it through the fixed-column store), and any future plugin.

**Primary constraint**: the first core release is **ADDITIVE ONLY**. No existing table, model, contract, page, service id, translation key or behavior is renamed, removed, retyped or repointed. A plugin that never migrates behaves exactly as today.

---

## Scope

### In Scope

- New core content-i18n **mechanism**:
  - `LanguageRegistry` on its **own new core `idiomas` table** (NOT `catalogo_idiomas`).
  - `ContentTranslator` fallback engine.
  - `TranslationStoreInterface` + `RowTranslationStore` (per-record rows, **application-level orphan cleanup**, keeps UNIQUE) + `FixedColumnTranslationStore` (language code → column map; serves OIDC `subtitle_es/fr/en` without schema change).
- Namespaced DI registration: `core.language_registry`, `core.content_translator` (registered in `registerCoreServices()`, before `loadPluginServices()`, so the ids cannot be shadowed by plugins).
- One idempotent, `fs_var`-flagged core migration (`ContentI18nMigration`) hooked after `fs_schema::selfHealCoreTables()` in `index.php`, `api.php`, `cron.php`, wrapped in `try/catch`, no-op when the DB is absent.
- `TranslatableControllerTrait` (admin + CSRF guarded mutation helpers) + a Twig macro/partial for consumers to render their **own** section. **No new core page, menu row or `#[AdminOnly]` page.**
- Core unit tests (Base / Core / Traits suites) covering registry invariants, both stores, the fallback chain and the locale-code matrix.
- Additive-only backward-compatibility guarantees (testable).

### Out of Scope

- Any change to `catalogo_core`: `catalogo_idiomas`, `articulo_descripciones`, `catalogo_opcional_idiomas`, `articulo`, `articulo_descripcion`, `catalogo_idioma`, their tests or their specs.
- Any change to `OidcProvider`: `oidc_clients.subtitle_*`, `oidc_client::getSubtitle()`, `LocaleManager`, `OidcProviderMigrationService`, their tests or their specs.
- The `catalogo_idiomas → idiomas` data copy — **plugin-owned, later delivery** with its own openspec.
- Any DB foreign key on the language dimension for existing plugin tables (catalogo design **D-01** rejected it; the core does not reverse that).
- The optional generic `content_translations` table (deferred, see §Open Questions Q3).
- Core per-request locale resolution / `RequestStack` listener.
- `business_data` document-label translations (PHP config constants, no locale dimension).
- No plugin spec delta is authored by this change (see Q7).

---

## Capabilities

### New Capabilities

- `content-i18n`: the framework-native content-translation mechanism — core `LanguageRegistry` over the new `idiomas` table (total default resolution + lifecycle invariants), the `ContentTranslator` resolution engine (`exact → language prefix → configured default → base → ''`), the `TranslationStoreInterface` read/write contract, `RowTranslationStore` (per-record rows, application-level orphan cleanup, keeps UNIQUE) and `FixedColumnTranslationStore` (locale code → column map), the namespaced DI registration, the idempotent `ContentI18nMigration`, and the `TranslatableControllerTrait` + render-only Twig macro consumers use to expose their own admin section.

### Modified Capabilities

- None. This change is additive-only and introduces a new mechanism. No existing `openspec/specs/` capability requirement changes. The core `FSTranslator` message-catalog behavior is unchanged; content i18n is a separate layer that only consumes locale codes.

---

## Approach

**Locked approach: HYBRID** (`exploration.md` §8.1), with the two evidence-driven corrections from §2.3 and §4.

The core owns:

| Piece | Location | Responsibility |
|---|---|---|
| `LanguageRegistry` | `src/Translation/LanguageRegistry.php` | Wraps the core `idioma` model; total, deterministic `defaultCode()`; active-code listing; lifecycle invariants. Independent of `catalogo_idiomas`. |
| Core registry model | `model/idioma.php` + `model/table/idiomas.xml` | Legacy `fs_model` + XML (FSFramework has no Doctrine ORM). |
| `ContentTranslator` | `src/Translation/ContentTranslator.php` | Pure resolution engine; per-store, opt-in, non-destructive normalization. |
| `TranslationStoreInterface` | `src/Translation/Store/TranslationStoreInterface.php` | Read/write contract. |
| `RowTranslationStore` | `src/Translation/Store/RowTranslationStore.php` | Per-record rows; **application-level orphan cleanup** (D-01 correction); keeps `UNIQUE`. |
| `FixedColumnTranslationStore` | `src/Translation/Store/FixedColumnTranslationStore.php` | Locale code → existing nullable column; reads/writes with **no schema change**. |
| `TranslatableControllerTrait` | `src/Traits/TranslatableControllerTrait.php` | POST-only, admin + CSRF guarded mutation helpers. |
| Twig macro/partial | `themes/AdminLTE/view/Macro/` | Render-only; consumer includes it inside its own section. |
| DI registration | `src/DependencyInjection/Container.php` `registerCoreServices()` | `core.language_registry`, `core.content_translator`. |
| Migration | `src/Core/Schema/ContentI18nMigration.php` | `fs_var`-flagged, idempotent, modeled on `AdminOnlyPagesMigration`. |

**Store write contract — first release guarantees read AND write** (a usable mechanism, not a read-only shim). The proposal-level interface:

```php
interface TranslationStoreInterface
{
    /** Raw stored value for a field + locale; null when absent. */
    public function get(string $field, string $locale): ?string;

    /** Write a value; null or '' clears that locale's entry. */
    public function set(string $field, string $locale, ?string $value): bool;

    /**
     * Ordered, NON-DESTRUCTIVE candidate codes this store can match for a caller
     * locale. Each store decides whether prefix reduction applies:
     *  - FixedColumnTranslationStore: reduces region-qualified → 2-letter suffix.
     *  - RowTranslationStore: exact stored code first, prefix only when opt-in.
     * Returned codes never rewrite the caller locale or stored data.
     */
    public function candidates(string $locale): array;
}
```

`ContentTranslator::read(TranslationStoreInterface $store, string $field, ?string $locale, string $base = ''): string` walks the proven chain:

1. exact caller code,
2. language prefix (`substr($locale, 0, 2)`) when the store opts in,
3. the configured default from `LanguageRegistry::defaultCode()`,
4. the caller-supplied base value (the frozen legacy terminal),
5. `''`.

No leg writes and no fallback is materialized (aligns with catalogo design **D4/D5/D6**: requested → configured default → base → `''`; the default pointer is a flag flip that moves no data; clearing is absence, not a copied fallback).

**Orphan cleanup (FK correction).** Per catalogo design **D-01**, a DB FK on the language dimension is unreliable in this framework (`FS_FOREIGN_KEYS` is an operator toggle; `ADD CONSTRAINT` fails on installs with pre-existing orphans and degrades the lazy schema pass; MySQL constraint drift churns FKs). Therefore `RowTranslationStore` owns **application-level cleanup** via `purgeLanguage(string $locale): int` and adds **no FK**. `UNIQUE(record_key, codidioma)` is preserved. The core registry does **not** delete plugin translation rows — core code never touches plugin tables; a consumer invokes `purgeLanguage()` from its own language-delete flow.

---

## Backward-Compatibility Guarantees (testable)

The exploration §3.4 guarantees are restated as assertions the spec/tests must enforce:

1. **No rename/removal/retype/repoint.** No existing core/plugin table, column, model, method, page, service id or translation key is renamed, removed, retyped or repointed. *Assertion*: existing core and plugin test suites pass unchanged; a grep gate finds no edit to the protected identifiers below.
2. **Protected consumers untouched.** `catalogo_idiomas`, `articulo_descripciones`, `catalogo_opcional_idiomas`, `articulo`, `articulo_descripcion`, `catalogo_idioma`, `oidc_client`, `oidc_clients`, `LocaleManager` are not modified by this change.
3. **Separate table.** The core `idiomas` table is new; core code never reads, writes, copies or references `catalogo_idiomas`. *Assertion*: a test/grep gate proves no `catalogo_idiomas` string exists anywhere under `src/Translation/`, `model/idioma.php`, `model/table/idiomas.xml` or `src/Core/Schema/ContentI18nMigration.php`.
4. **Migration blast radius is zero for plugin tables.** `ContentI18nMigration` touches only `idiomas` + its `fs_var` flag; its failure cannot affect plugin tables. *Assertion*: the migration's table-name allowlist is exactly `['idiomas']`; failure paths leave the flag unwritten and the request unaffected.
5. **Namespaced coexistence.** Service ids `core.language_registry` / `core.content_translator` cannot be shadowed because they register in `registerCoreServices()` before `loadPluginServices()`; plugin services registering bare `idioma`-like ids keep working.
6. **Message catalogs unchanged.** `FSTranslator` `trans()`/fallback behavior and `TranslationHelper` are untouched; content i18n only consumes locale codes. *Assertion*: `tests/Translation/` passes unchanged.
7. **A non-migrating plugin behaves exactly as today.** Because the mechanism is opt-in and additive, a plugin that never calls the new services/stores has identical runtime behavior before and after this change.

---

## Resolution of Exploration §5 Open Questions

| # | Question | Proposed resolution | Rationale |
|---|---|---|---|
| Q1 | Core `idiomas` table shape | Single identity axis: `codidioma VARCHAR(5) PRIMARY KEY`, `nombre VARCHAR(50)`, `activo boolean`, `por_defecto boolean` — mirroring `catalogo_idiomas` for familiarity. **No** separate `locale VARCHAR(10)`/`language VARCHAR(2)` pair. | Region-qualified codes fit `VARCHAR(5)` (`es_ES`, `fr_CA`), so the pair adds a second identity axis with no proven consumer. The simple shape serves the 2-letter OIDC columns; region preference is preserved because `RowTranslationStore` matches the exact stored code first and prefix fallback is opt-in. Minimal surface for an additive first release. |
| Q2 | Seed defaults | The migration creates the table and seeds **exactly one** minimal row `es` (`activo = TRUE`, `por_defecto = TRUE`), idempotently and only when the table is empty. Alternative degradation: if the registry ever has zero active rows, `defaultCode()` falls back to the constant `'es'`. | A total default resolution needs ≥1 active row. One row is the minimum; it cannot collide with a plugin's seed because the table is separate (guarantee #3). Seeding `es`+`en` speculates languages the core does not own. A plugin later adds its own codes through the registry/store API, or merges its data in its own migration. |
| Q3 | Optional generic `content_translations` table | **Defer.** Ship only `LanguageRegistry` + `ContentTranslator` + the two proven stores. | Neither target pattern needs it; it adds surface and a third store, contradicting the additive-only, minimal-first-release mandate. A future change can add it without breaking this contract. |
| Q4 | Store write contract | **Read + write from day one.** Interface guarantees `get`, `set` (`null`/`''` clears), `candidates`. `RowTranslationStore` additionally exposes `purgeLanguage(string $locale): int`. | A read-only first release would not be a usable mechanism and would force OIDC to keep hand-rolling writes. Read+write is testable without a consumer page and matches both target patterns' real needs. |
| Q5 | Core per-request locale source | **Explicit caller-supplied locale only.** `ContentTranslator` never reads `Request`. Request-scoping is a separate, later concern. | The core has no per-request locale today; `FSTranslator::$locale` is process-global and only `setLocale()` changes it. OIDC already supplies an explicit `$locale`. Adding request scoping now would expand scope and risk changing resolution for a self-normalizing plugin. |
| Q6 | Class/table naming audit | Core model class `idioma`, table `idiomas`. Verified free today (zero repo matches for a production `idioma`/`LanguageRegistry`/`ContentTranslator`; only test fakes exist). `tarif_idioma` extends `catalogo_idioma` and `tarif_idiomas.xml` exists — both are **distinct identifiers** and do not collide. Declare `idioma`/`idiomas` reserved by the core; add a test asserting `class_exists('idioma')` resolves to the core model and the `idiomas` table name is not used elsewhere. | Prevents a future plugin from silently declaring a global `idioma` class/table. |
| Q7 | Locked-contract impact enumeration | **No plugin spec delta is authored here.** `plugins/catalogo_core/openspec/specs/gestion-idiomas/spec.md` and `plugins/OidcProvider/openspec/specs/oidc-hardening/spec.md` are **referenced, not modified**. Plugin migrations are separate changes with their own openspec. | Respects the plugin-SDD ownership rule: plugin SDDs live in `plugins/{name}/openspec/`; core never tracks plugin changes. |

**Locale-code normalization (per-store, opt-in, non-destructive)** is fixed as `exploration.md` §4 requires: no global `es_ES → es` collapse, no rewrite of stored codes. `FixedColumnTranslationStore.candidates()` reduces region-qualified callers to the 2-letter column suffix; `RowTranslationStore.candidates()` tries the exact code first and optionally the prefix, configured per instance. The OIDC `en_US → en_GB` alias and its prefix fallback stay in the plugin; the core must not "fix" them. The spec must carry the test matrix exact → prefix → default → empty for both store kinds, including `en_US`, `fr_CA`, `pt_BR`.

---

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `src/Translation/LanguageRegistry.php` | New | Core registry wrapper + total default resolution. |
| `src/Translation/ContentTranslator.php` | New | Fallback engine (`exact → prefix → default → base → ''`). |
| `src/Translation/Store/TranslationStoreInterface.php` | New | Read/write store contract. |
| `src/Translation/Store/RowTranslationStore.php` | New | Per-record rows; application-level orphan cleanup; keeps UNIQUE. |
| `src/Translation/Store/FixedColumnTranslationStore.php` | New | Locale code → column map; no schema change. |
| `model/idioma.php`, `model/table/idiomas.xml` | New | Core registry model + XML schema. |
| `src/Traits/TranslatableControllerTrait.php` | New | Admin + CSRF guarded mutation helpers. |
| `themes/AdminLTE/view/Macro/` | New | Render-only translation macro/partial. |
| `src/DependencyInjection/Container.php` (`registerCoreServices()` `:72-123`) | Modified | Register `core.language_registry`, `core.content_translator`. Additive lines only. |
| `src/Core/Schema/ContentI18nMigration.php` | New | Idempotent, `fs_var`-flagged migration. |
| `index.php` `:84-95`, `api.php` `:138-147`, `cron.php` `:69-78` | Modified | Hook the migration after self-heal, in `try/catch`. Additive lines only. |
| `tests/` (Base / Core / Traits, plus `tests/Translation`) | New/Modified | Registry invariants, stores, fallback chain, locale matrix; existing suites stay green. |
| `plugins/catalogo_core/**`, `plugins/OidcProvider/**` | **Unchanged** | Consumers migrate later in separate changes. |
| `model/table/*` existing, `base/*` existing | Unchanged | No existing schema or base class touched. |

---

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| **Locale-code collapse** (`es_ES → es`) silently changing resolution for row stores or dropping region preference. | Med | Per-store, opt-in `candidates()`; no global collapse; explicit test matrix (`en_US`, `fr_CA`, `pt_BR`). |
| **FK assumption regression** — a later contributor adds a language FK to plugin tables and behavior varies with `FS_FOREIGN_KEYS`. | Med | Record catalogo **D-01** rationale as a known constraint in the spec; `RowTranslationStore` owns app-level cleanup and adds no FK. |
| **Registry/table collision** while two registries coexist. | Low | Separate `idiomas` table; namespaced service ids; naming audit (Q6). |
| **Migration blast radius** — a core migration touching the wrong table or failing non-idempotently. | Low | Table allowlist is exactly `['idiomas']`; `fs_var` flag; no-op when DB absent; `try/catch` at all three hooks; no plugin table referenced. |
| **Over-scoping the first release** (generic table, request-scoped locale, UI page) would break additive-only. | Med | Explicit out-of-scope list above and in §6 of the exploration; deferrals recorded in Q3/Q5. |
| **Class-name collision** with `tarif_idioma` / plugin aliases. | Low | Naming audit (Q6); `idioma`/`idiomas` reserved; resolution test. |
| **Seed collision** with a plugin's own language seed. | Low | Core seed lives only in the new separate table and only when empty (Q2). |
| **Existing test/behavior regression** from additive DI/migration lines. | Low | Existing core and plugin suites must pass unchanged; guarantee #7. |

---

## Rollout

- **Core-first, additive-only.** This change ships the mechanism and nothing else. No plugin is modified.
- **Migration behavior**: idempotent, `fs_var`-flagged, hooked after `fs_schema::selfHealCoreTables()` in `index.php`, `api.php`, `cron.php`, `try/catch`, no-op without a DB, table allowlist `['idiomas']`.
- **Later, separate deliveries** (each its own change + openspec, each depending on this core release):
  1. `catalogo_core` consumer migration (delegates registry/fallback to the core; owns the `catalogo_idiomas → idiomas` copy).
  2. `OidcProvider` consumer migration (serves `subtitle_*` through `FixedColumnTranslationStore`).
  3. Optional generic `content_translations` table / per-request locale resolution — only if a real consumer appears.
- **Delivery strategy**: `auto-chain`; review budget 400 changed lines. The first release is expected to be split into chained slices (registry/model+migration, engine+stores, trait+macro, tests) during `sdd-tasks`.

---

## Rollback Plan

- Revert the commits. Because the change is additive, reverting restores the exact prior behavior; **no plugin data is touched** at any point (guarantee #4).
- The only persisted core-side artifacts are the new `idiomas` table, its seeded row and the `fs_var` flag. A revert can optionally `DROP TABLE idiomas` and delete the `fs_var` flag; both are non-destructive to plugin tables.
- Trivially safe rollback: the new service ids simply stop existing; nothing references them until a consumer migrates.
- Operator safety net: a pre-rollout DB dump, standard for any core release.

---

## Dependencies

- Runtime: PHP 8.2+ (8.3 preferred), DDEV for all PHP/Composer/PHPUnit commands.
- Framework: `fs_model` + XML schemas, `fs_db2`, `fs_var`, `Symfony` DI container (already present). No new Composer dependency.
- Prerequisite: none. This is the first of the content-i18n deliveries.
- Referenced (not required to run): catalogo research/design and OIDC locale analysis for the locale-code matrix.

---

## Success Criteria

- [ ] `LanguageRegistry` resolves a total default (`active por_defecto → lowest active codidioma → 'es'`) and exposes active codes; registry invariants are covered by tests.
- [ ] `ContentTranslator` resolves the chain exact → language prefix → configured default → base → `''` for both store kinds, with a passing locale matrix (`en_US`, `fr_CA`, `pt_BR`).
- [ ] `RowTranslationStore` reads/writes per-record rows, keeps `UNIQUE`, and cleans orphans at the application level with **no** language FK.
- [ ] `FixedColumnTranslationStore` reads/writes existing locale-suffixed columns with no schema change.
- [ ] `core.language_registry` and `core.content_translator` resolve from the container and cannot be shadowed by plugin service ids.
- [ ] `ContentI18nMigration` is idempotent, `fs_var`-flagged, hooks all three entry points in `try/catch`, and touches only the `idiomas` table.
- [ ] `TranslatableControllerTrait` + Twig macro let a consumer render and persist a translation section with admin + CSRF enforcement; **no** new core page/menu/`#[AdminOnly]`.
- [ ] All existing core and plugin test suites pass **unchanged**; no protected identifier is edited; no plugin table is referenced by core code.
- [ ] `ddev exec php vendor/bin/phpunit` is green.

---

## Key Learnings

1. The hybrid content-i18n approach is locked because forcing either existing plugin pattern into the other would violate the additive-only backward-compatibility mandate.
2. The core `RowTranslationStore` must own application-level orphan cleanup because a language-dimension FK is unreliable when `FS_FOREIGN_KEYS` is off.
3. Locale-code normalization must be a per-store, opt-in, non-destructive matching strategy; a global `es_ES → es` collapse would break region-qualified row stores.
4. The core `idiomas` table is separate from `catalogo_idiomas`; the data copy is a later plugin-owned migration, and no plugin spec delta is authored in the core change.
