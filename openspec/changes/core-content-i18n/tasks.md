# Tasks: core-content-i18n

**Change**: `core-content-i18n` (CORE change — `openspec/changes/core-content-i18n/`)
**Artifact store**: `openspec`
**Core VERSION at planning time**: `0.22.7`
**Inputs**: `proposal.md`, `exploration.md`, `specs/content-i18n/spec.md` (CI-01…CI-26, 75 scenarios), `design.md` (D1–D12, interfaces, data flow, file changes, testing strategy, slicing).
**Delivery strategy**: `auto-chain` (locked by the runtime preflight).
**Session review budget**: **800** changed lines.
**Strict TDD**: `true` — runner `ddev exec php vendor/bin/phpunit`.

---

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines (additions + deletions, total) | **~2,260–2,870** |
| 400-line budget risk | **High** (single PR and most slices exceed the default 400) |
| Session 800-line budget risk | **Low per slice** (largest slice ~620; every slice fits 800) |
| Chained PRs recommended | **Yes** |
| Suggested split | PR1 (S1) → PR2 (S2) → PR3 (S3) → PR4 (S4) → PR5 (S5) → PR6 (S6) |
| Delivery strategy | `auto-chain` |
| Chain strategy | `feature-branch-chain` (design §Chained-delivery slicing; child N targets child N-1, tracker merges to main) |

```text
Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High
```

**Reading of the forecast.** The change is additive but spreads across a legacy model
plus XML schema, a resolution engine, two stores, DI wiring, a migration, three bootstrap
hooks, a trait, a Twig macro and ~14 new test files — so a single PR is unambiguously over
budget. The design performed one honest slicing pass; this file **refines** it from five to
**six** slices by moving the DI registration out of S2 into the engine slice (S5). Rationale:
`CI-17` requires *both* `core.language_registry` and `core.content_translator` to resolve and
be non-shadowable; registering only the registry in S2 would leave `CI-17` half-verifiable and
force a second edit of the same `$reservedDefinitions` block. S2 therefore ships the
`LanguageRegistry` facade and its tests only; S5 wires both services once both classes exist.

Every slice stays under the **800** line session budget, so no per-slice reviewer-burden stop
is required. Against the **default 400**, S2 is under budget and S1/S3/S4/S5/S6 are over; the
session's explicit 800 review budget is the maintainer's accepted ceiling for those slices.

| Slice | Scope | Est. changed lines |
|-------|-------|--------------------|
| S1 Registry persistence | `model/table/idiomas.xml`, `model/idioma.php`, fakes, model + reserved-name tests | 480–620 |
| S2 Registry facade | `src/Translation/LanguageRegistry.php` + test | 240–320 |
| S3 Migration + hooks | `src/Core/Schema/ContentI18nMigration.php`, `index.php`, `api.php`, `cron.php`, 2 tests | 420–520 |
| S4 Stores | `src/Translation/Store/**`, `tests/Support/FakeRowStoreDb.php`, store test | 380–480 |
| S5 Engine + DI | `src/Translation/ContentTranslator.php`, `src/DependencyInjection/Container.php`, 2 tests | 360–460 |
| S6 Consumer surface + gates | `src/Traits/TranslatableControllerTrait.php`, macro, 3 tests | 380–470 |
| **Total** | | **~2,260–2,870** |

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| S1 | New `idiomas` table + global `idioma` model with total default resolution, lifecycle guards and idempotent seed | PR 1 | `ddev exec php vendor/bin/phpunit tests/Base/IdiomaModelTest.php && ddev exec php vendor/bin/phpunit tests/Base/ContentI18nReservedNameTest.php` | N/A — DB-free model tests over an injected in-memory `fs_db2` double; no page or entry point exercises the registry yet | Delete `model/idioma.php`, `model/table/idiomas.xml`, the two support doubles and the two tests; optional `DROP TABLE idiomas` (no plugin data involved) |
| S2 | `LanguageRegistry` facade: total `defaultCode()`, deterministic sorted `activeCodes()`, delegation | PR 2 | `ddev exec php vendor/bin/phpunit tests/Core/LanguageRegistryTest.php` | N/A — facade unit tests over the S1 fake; service is not container-registered yet | Delete `src/Translation/LanguageRegistry.php` + test; no persisted state |
| S3 | Idempotent `ContentI18nMigration` + bootstrap hooks in the three entry points | PR 3 | `ddev exec php vendor/bin/phpunit tests/Core/ContentI18nMigrationTest.php && ddev exec php vendor/bin/phpunit tests/Core/ContentI18nHookTest.php` | `ddev exec php cron.php` with a connected DB, then assert `fs_var content_i18n_migrated` and the seeded `es` row; boot `index.php`/`api.php` without a DB to confirm a contained no-op | Remove the hook lines from `index.php`/`api.php`/`cron.php`, delete the migration + tests; flag/table drop optional |
| S4 | Store contract + `RowTranslationStore` + `FixedColumnTranslationStore` (opt-in prefix reduction, app-level purge, no FK) | PR 4 | `ddev exec php vendor/bin/phpunit tests/Core/TranslationStoresTest.php` | N/A — DB-free store tests with an injected db double; stores are plain classes with no live entry point | Delete `src/Translation/Store/**`, `tests/Support/FakeRowStoreDb.php` and the store test; no persisted state |
| S5 | `ContentTranslator` resolution engine + namespaced DI registration with reserved-id re-assert | PR 5 | `ddev exec php vendor/bin/phpunit tests/Core/ContentTranslatorTest.php && ddev exec php vendor/bin/phpunit tests/Core/ContentI18nDiContractTest.php` | N/A — engine/DI tests run in-process; container resolution is exercised by `ContentI18nDiContractTest` | Delete `src/Translation/ContentTranslator.php` + its two tests; revert the additive `Container.php` lines; no persisted state |
| S6 | `TranslatableControllerTrait` (POST/admin/CSRF) + render-only Twig macro + change-wide isolation gates | PR 6 | `ddev exec php vendor/bin/phpunit tests/Traits/TranslatableControllerTraitTest.php && ddev exec php vendor/bin/phpunit tests/Core/TranslationMacroContractTest.php && ddev exec php vendor/bin/phpunit tests/Core/ContentI18nCoreIsolationTest.php` | N/A — the macro is rendered in isolation via a `FilesystemLoader`; no consumer page ships in this change | Delete the trait, the macro and the three tests; no runtime consumer exists yet |

### Chained PR boundaries (commits → PR)

`feature-branch-chain`: one draft/no-merge tracker branch `feature/core-content-i18n`
accumulates the final integration; **only the tracker merges to `main`**. PR #1 targets the
tracker; PR #N targets PR #N-1's branch so each child diff shows only its own work unit.

| PR | Branch (base) | Commit(s) in the slice | Slice |
|----|---------------|------------------------|-------|
| PR 1 | `feat/core-content-i18n-s1` ← base `feature/core-content-i18n` | One Conventional Commit, e.g. `feat(i18n): add core idiomas registry model and schema` | S1 |
| PR 2 | `feat/core-content-i18n-s2` ← base PR 1 | `feat(i18n): add LanguageRegistry facade` | S2 |
| PR 3 | `feat/core-content-i18n-s3` ← base PR 2 | `feat(i18n): add ContentI18nMigration and bootstrap hooks` | S3 |
| PR 4 | `feat/core-content-i18n-s4` ← base PR 3 | `feat(i18n): add translation store contract and implementations` | S4 |
| PR 5 | `feat/core-content-i18n-s5` ← base PR 4 | `feat(i18n): add ContentTranslator engine and namespaced DI services` | S5 |
| PR 6 | `feat/core-content-i18n-s6` ← base PR 5 | `feat(i18n): add translatable controller trait and render macro` | S6 |

Each PR keeps **tests and docs with the behavior** (work-unit rule). Each PR body must
include the dependency diagram below, marking its own node with `📍`; if a child PR shows
previous-slice changes, the base is wrong and must be retargeted/rebased before review.

```text
feature/core-content-i18n  (tracker — draft / no-merge, merges to main last)
        ▲
        │  PR1  S1 registry persistence
        │        ▲
        │        │  PR2  S2 registry facade
        │        │        ▲
        │        │        │  PR3  S3 migration + hooks
        │        │        │        ▲
        │        │        │        │  PR4  S4 stores
        │        │        │        │        ▲
        │        │        │        │        │  PR5  S5 engine + DI
        │        │        │        │        │        ▲
        │        │        │        │        │        │  PR6  S6 surface + gates   ← 📍 current PR
```

---

## Strict TDD Contract

`strict_tdd: true` (from `openspec/config.yaml`). For every behavior task in every slice:

1. **RED** — write the focused test first with the exact CI scenario(s) named in the task; run
   it and observe the expected failure (missing class/method, or an assertion failure).
   Record the failing command and output.
2. **GREEN** — implement the minimum production change that makes the focused test pass; run
   the same command and observe pass.
3. **REFACTOR** — clean up without changing behavior; re-run the focused test and the full
   suite remains green.

Runner for every test: `ddev exec php vendor/bin/phpunit`. No test may be written after its
production code. The Phase-7 verification runs the whole suite and the grep gates.

The design's Threat Matrix has exactly one applicable boundary — **Process integration
(bootstrap entry points)**. Its planned RED test is `tests/Core/ContentI18nHookTest.php` plus
the no-DB/no-throw migration case; both are authored **before** the production hook/migration
tasks in Slice 3. Every other threat-matrix row is `N/A` (no shell, subprocess, VCS/PR or
executable-classification boundary) and is intentionally omitted.

---

## Phase 1: Slice 1 — Registry persistence (PR 1)

Depends on: none. Implements D1, D2, D8, D12. Satisfies CI-01…CI-05, CI-24 (schema shape),
and Q6 (reserved name).

- [x] 1.1 Create `model/table/idiomas.xml` with `codidioma VARCHAR(5)` primary key,
      `nombre VARCHAR(50) NOT NULL`, `activo boolean` (default true) and `por_defecto boolean`
      (default false). Add **no** foreign key on `codidioma` (D12 / CI-13). Use the standard
      FSFramework XML schema format. *(CI-13, CI-18, CI-24; D12)*
- [x] 1.2 Create a minimal `model/idioma.php` skeleton: global `class idioma extends \fs_model`
      with `public const TABLE = 'idiomas'`, `public const DEFAULT_CODE = 'es'`, the four
      public properties and the method signatures from `design.md` §Interfaces, with bodies that
      throw `\LogicException('not implemented')`. This lets the test suite autoload the class and
      fail on behavior rather than syntax. *(D1)*
- [x] 1.3 Create `tests/Support/IdiomaModelFakeDb.php` — an in-memory `fs_db2`-compatible double
      that answers the `idiomas` statements used by the model (select/insert/update/delete/escape).
      *(testing strategy)*
- [x] 1.4 Create `tests/Support/FakeIdioma.php` — a DB-free `idioma` subclass that skips the
      `fs_model` constructor and injects `IdiomaModelFakeDb`. *(testing strategy)*
- [x] 1.5 **RED**: create `tests/Base/IdiomaModelTest.php` covering CI-01, CI-02 (model side),
      CI-03, CI-04 and CI-05: explicit default wins, lowest-active fallback, inactive flag ignored,
      zero-active degrades to `'es'`, permutation independence, `set_default` flag flip with no
      translation write, normalization collapsing duplicate defaults, deactivation/deletion guards
      (default / last row refused; non-default deletable) and the empty→single `es` seed /
      non-empty→unchanged / second-run→no-op states. Run
      `ddev exec php vendor/bin/phpunit tests/Base/IdiomaModelTest.php` and observe RED.
      *(CI-01…CI-05; D2, D8)*
- [x] 1.6 **GREEN**: implement `model/idioma.php` bodies — `install()` single-row `es` seed SQL,
      `ensure_defaults()` insert only when empty, `get()`, `get_default()`, total deterministic
      `defaultCode()`, `set_default()` flag flip, `normalize_default()`, `test()` (`codidioma`
      2–5 chars, `nombre` 1–50), `save()`/`delete()` guards, `all()`, `all_activos()`. Re-run the
      focused command until GREEN. *(CI-01…CI-05; D2, D8)*
- [x] 1.7 **REFACTOR**: remove duplication between `defaultCode()`/`get_default()`/`all_activos()`
      without changing behavior; re-run `tests/Base/IdiomaModelTest.php` and keep it green.
- [x] 1.8 **RED→GREEN**: create `tests/Base/ContentI18nReservedNameTest.php` (Q6) asserting
      `(new \ReflectionClass('idioma'))->getFileName()` ends with `/model/idioma.php` and that no
      plugin declares `class idioma` or a conflicting `idiomas` table. Run it, then fix only if it
      exposes a real collision (expected: pass once the autoloader finds `model/idioma.php`).
      *(Q6; D1)*

## Phase 2: Slice 2 — LanguageRegistry facade (PR 2)

Depends on: S1. Implements D2. Satisfies CI-01, CI-02, CI-06 leg 3 (consumed by S5),
CI-15's "registry does not purge plugin rows" boundary is asserted here structurally.

- [ ] 2.1 **RED**: create `tests/Core/LanguageRegistryTest.php` over `FakeIdioma` asserting
      `defaultCode()` delegation is total, `activeCodes()` excludes inactive codes and is
      deterministically sorted ascending by `codidioma` in PHP (CI-02), and that `all()`,
      `get()`, `defaultRow()`, `save()`, `setDefault()`, `delete()` delegate to the model without
      side effects. Run `ddev exec php vendor/bin/phpunit tests/Core/LanguageRegistryTest.php`
      and observe RED. *(CI-01, CI-02; D2)*
- [ ] 2.2 **GREEN**: create `src/Translation/LanguageRegistry.php` (`namespace FSFramework\Translation`)
      as a `final class` with an optional `?\idioma` constructor seam, delegating to the model and
      implementing the sorted `activeCodes()`. Re-run until GREEN. *(CI-01, CI-02; D2)*
- [ ] 2.3 **REFACTOR**: keep the model the single source of truth; the facade holds no cached state.
      Re-run the focused command.

## Phase 3: Slice 3 — Migration + bootstrap hooks (PR 3)

Depends on: S1 (and S2 for a linear chain). Implements D7, D8. Satisfies CI-05 (migration seed
path), CI-18, CI-19, CI-20. This slice owns the only applicable Threat-Matrix boundary
(Process integration).

- [ ] 3.1 **RED** (threat-matrix RED test): create `tests/Core/ContentI18nMigrationTest.php`
      modeled on `tests/Core/AdminOnlyPagesMigrationTest.php` (read-only reference). Use a no-DB
      recorder that overrides the `protected` steps to assert step order, the `fs_var` flag written
      only after all steps succeed, retry on partial failure, the allowlist being exactly
      `['idiomas']`, and the no-DB/no-throw path. Run
      `ddev exec php vendor/bin/phpunit tests/Core/ContentI18nMigrationTest.php` and observe RED.
      *(CI-18, CI-20; D7)*
- [ ] 3.2 **RED** (threat-matrix RED test): create `tests/Core/ContentI18nHookTest.php` that reads
      the source of `index.php`, `api.php` and `cron.php` and asserts
      `strpos(selfHealCoreTables) < strpos(ContentI18nMigration::run)` and that each call sits
      inside a `try { … } catch (` block. This test is authored before the hooks exist and must
      fail. *(CI-19; D7)*
- [ ] 3.3 **GREEN**: create `src/Core/Schema/ContentI18nMigration.php` with
      `FLAG = 'content_i18n_migrated'`, `TABLE_ALLOWLIST = ['idiomas']`, `run()`, `execute()`
      (`isApplied() ? true : (adoptTable() && seed() && markApplied())`), `adoptTable()` (lazy
      `new \idioma()` + `db()->get_columns('idiomas')` guard), `seed()` (`ensure_defaults()` +
      ≥1-row check), `markApplied()` (`fs_var::simple_save`), `loadDependencies()` guarded by
      `class_exists($class, false)`, and the allowlist assertion before any work. Re-run
      `tests/Core/ContentI18nMigrationTest.php` until GREEN. *(CI-18, CI-20; D7, D8)*
- [ ] 3.4 **GREEN**: add the additive hook `try { \FSFramework\Core\Schema\ContentI18nMigration::run(); } catch (\Throwable $e) { … }` immediately after the existing `AdminOnlyPagesMigration` block in `index.php` (after line 95), `api.php` (after line 148) and `cron.php` (after line 79, inside the connected branch). Re-run `tests/Core/ContentI18nHookTest.php` until GREEN. *(CI-19; D7)*
- [ ] 3.5 **REFACTOR**: de-duplicate the migration's boolean-step helpers; re-run both focused
      commands and keep them green.

## Phase 4: Slice 4 — Translation stores (PR 4)

Depends on: S1, S2. Implements D3, D4, D5, D6, D12. Satisfies CI-08…CI-16 (stores), CI-13,
CI-15.

- [ ] 4.1 **RED**: create `tests/Support/FakeRowStoreDb.php` (in-memory db double for
      `RowTranslationStore`) and `tests/Core/TranslationStoresTest.php` covering: exact stored
      code wins for a row store (CI-08), the opt-in pair over identical data via two constructor
      instances (CI-09), region-distinctness `pt_BR`/`pt_PT` (CI-10), no alias / `en_US` selects
      `en` (CI-11), one row per record+locale and update-not-duplicate (CI-12), `purgeLanguage()`
      count/scope/no-op and no language FK (CI-13), `set(null|'')` clears without copying a
      fallback (CI-14), generic table scoping and no plugin identifier (CI-15), and
      `FixedColumnTranslationStore` map read/write with no schema change (CI-16). Run
      `ddev exec php vendor/bin/phpunit tests/Core/TranslationStoresTest.php` and observe RED.
      *(CI-08…CI-16; D3, D4, D5, D6, D12)*
- [ ] 4.2 **GREEN**: create `src/Translation/Store/TranslationStoreInterface.php` (`get`, `set`,
      `candidates`) and `src/Translation/Store/RowTranslationStore.php` (constructor
      `bool $prefixFallback = false`; exact `get`/`set`; `candidates` exact-then-optional-prefix;
      per-field clear + conditional row delete when all mapped columns are null;
      `purgeLanguage(string $locale): int`; `tableName()`). Re-run until GREEN.
      *(CI-08…CI-15; D3, D4, D6, D12)*
- [ ] 4.3 **GREEN**: create `src/Translation/Store/FixedColumnTranslationStore.php` (constructor
      `array $localeColumnMap`, `\Closure $reader`, `\Closure $writer`,
      `bool $prefixFallback = true`; `candidates` returns `[$locale, substr($locale,0,2)]` deduped;
      no alias table; no `ALTER TABLE`). Re-run until GREEN. *(CI-09, CI-11, CI-16; D3, D4, D5)*
- [ ] 4.4 **REFACTOR**: factor the shared candidate dedup; re-run the focused command and keep it
      green.

## Phase 5: Slice 5 — Resolution engine + DI wiring (PR 5)

Depends on: S2, S4. Implements D9. Satisfies CI-06, CI-07, CI-17.

- [ ] 5.1 **RED**: create `tests/Core/ContentTranslatorTest.php` covering the full chain — exact
      match wins (CI-06), prefix leg when the store opted in, configured-default leg, caller
      `base` leg, `''` terminal, two-letter caller no double-match — for both store kinds, plus
      the locale matrix `en_US`, `fr_CA`, `pt_BR`, `es`, `en`. Assert CI-07: store rows are
      unchanged after reads, no fallback is materialized, and a request-scoped locale is never
      consulted when an explicit locale is passed. Run
      `ddev exec php vendor/bin/phpunit tests/Core/ContentTranslatorTest.php` and observe RED.
      *(CI-06, CI-07; D3)*
- [ ] 5.2 **RED**: create `tests/Core/ContentI18nDiContractTest.php` asserting
      `core.language_registry` and `core.content_translator` resolve to the core classes, a plugin
      registering a colliding `core.content_translator` cannot replace the core instance, and a
      plugin's own bare id still resolves. Run it and observe RED. *(CI-17; D9)*
- [ ] 5.3 **GREEN**: create `src/Translation/ContentTranslator.php` (`final class`, optional
      `?LanguageRegistry` constructor, `read(store, field, ?locale, base = '')` iterating
      `$store->candidates($locale)` then the configured default then base then `''`,
      `defaultCode()`). Re-run `tests/Core/ContentTranslatorTest.php` until GREEN.
      *(CI-06, CI-07; D3)*
- [ ] 5.4 **GREEN**: modify `src/DependencyInjection/Container.php` — add
      `registerContentI18nServices()` registering `core.language_registry` and
      `core.content_translator` (the latter taking a `Reference` to the former), call it after
      `self::registerLegacyModels()` and before `self::loadPluginServices()`, capture the resulting
      `Definition` objects in `private static array $reservedDefinitions`, and call
      `restoreReservedServices()` immediately after `loadPluginServices()` returns. Additive lines
      only. Re-run `tests/Core/ContentI18nDiContractTest.php` until GREEN. *(CI-17; D9)*
- [ ] 5.5 **REFACTOR**: verify the reserved-id re-assert covers exactly the two `core.` ids and
      leaves plugin ids untouched; re-run both focused commands.

## Phase 6: Slice 6 — Consumer surface + isolation gates (PR 6)

Depends on: S1–S5. Implements D10, D11. Satisfies CI-21, CI-22, CI-23, CI-24, and closes
CI-15's "no plugin identifier in core" gate.

- [ ] 6.1 **RED**: create `tests/Traits/TranslatableControllerTraitTest.php` with an anonymous
      trait user overriding the three guard seams (`translationRequestIsPost`,
      `translationActorIsAdmin`, `translationCsrfIsValid`) and an in-memory store double; cover
      the four CI-21 cases (GET rejected, non-admin denied, invalid CSRF rejected, authorized POST
      persists). Run `ddev exec php vendor/bin/phpunit tests/Traits/TranslatableControllerTraitTest.php`
      and observe RED. *(CI-21; D10)*
- [ ] 6.2 **RED**: create `tests/Core/TranslationMacroContractTest.php` rendering
      `Macro/Translation.html.twig` through a `FilesystemLoader` rooted at `/themes/AdminLTE/view`
      (pattern of `tests/Core/HtmxMacroContractTest.php`, read-only reference) and asserting one
      escaped input per locale, no `<form`, no `<script`, no `csrf`. Run it and observe RED.
      *(CI-22; D11)*
- [ ] 6.3 **RED→GREEN**: create `tests/Core/ContentI18nCoreIsolationTest.php` asserting zero
      `catalogo_idiomas` occurrences under the new core files (`src/Translation/LanguageRegistry.php`,
      `src/Translation/ContentTranslator.php`, `src/Translation/Store/**`, `model/idioma.php`,
      `model/table/idiomas.xml`, `src/Core/Schema/ContentI18nMigration.php`), that
      `ContentI18nMigration::TABLE_ALLOWLIST === ['idiomas']`, and that the protected consumer
      identifiers are not modified by this change's new file set. *(CI-15, CI-23, CI-24; D12)*
- [ ] 6.4 **GREEN**: create `src/Traits/TranslatableControllerTrait.php` with `persistTranslation()`
      calling `translationRequestIsPost()`, `translationActorIsAdmin()`, `translationCsrfIsValid()`
      in order, then `$store->set(...)`, with the three guards as `protected` overridable seams
      defaulting to the `fs_controller` surface. Re-run the trait test until GREEN. *(CI-21; D10)*
- [ ] 6.5 **GREEN**: create `themes/AdminLTE/view/Macro/Translation.html.twig` with a render-only
      `fields(field, locales, values, opts = {})` macro emitting one escaped labelled
      `<input>`/`<textarea>` per locale and nothing else. Re-run the macro contract test until
      GREEN. *(CI-22; D11)*
- [ ] 6.6 **REFACTOR**: keep the trait guards expression-only and the macro free of `<form>`/CSRF/
      script; re-run all three focused commands and keep them green.

## Phase 7: Verification & release note (post-chain)

- [ ] 7.1 Run the full suite: `ddev exec php vendor/bin/phpunit` — must be green, with the
      existing `tests/Translation/` and every plugin suite passing **unchanged** (CI-23, CI-25,
      CI-26).
- [ ] 7.2 Run the backup-compat grep gates: zero `catalogo_idiomas` occurrences under
      `src/Translation/LanguageRegistry.php` (read-only), `src/Translation/ContentTranslator.php`
      (read-only), `src/Translation/Store/**` (read-only), `model/idioma.php` (read-only),
      `model/table/idiomas.xml` (read-only) and `src/Core/Schema/ContentI18nMigration.php`
      (read-only); `TABLE_ALLOWLIST === ['idiomas']` (CI-24).
- [ ] 7.3 Confirm no protected consumer identifier was edited: `plugins/catalogo_core/**`
      (read-only) and `plugins/OidcProvider/**` (read-only) show no diff in this change; existing
      suites were not modified (CI-23).
- [ ] 7.4 Confirm the three bootstrap hooks are present and ordered after self-heal in
      `index.php` (read-only), `api.php` (read-only) and `cron.php` (read-only) (CI-19).
- [ ] 7.5 **Release note only — do not perform here.** After `sdd-archive`, the core VERSION
      bump (from `0.22.7`) and the `vX.Y.Z` tag are handled by the `fsframework-core-release`
      skill. This change does not bump `VERSION` or create a tag.

---

## Spec Coverage Matrix

| Requirement | Tasks | Design decision |
|-------------|-------|-----------------|
| CI-01 total deterministic default | 1.5, 1.6, 2.1, 2.2 | D2 |
| CI-02 active-language listing | 1.5, 1.6, 2.1, 2.2 | D2 |
| CI-03 exactly one active default | 1.5, 1.6 | D2 |
| CI-04 deactivation/deletion guards | 1.5, 1.6 | D2 |
| CI-05 idempotent single-row seed | 1.5, 1.6, 3.1, 3.3 | D8 |
| CI-06 resolution chain | 5.1, 5.3 | D3 |
| CI-07 read-only, explicit locale | 5.1, 5.3 | D3 |
| CI-08 exact stored code wins | 4.1, 4.2 | D3 |
| CI-09 per-store prefix opt-in | 4.1, 4.2, 4.3 | D3, D4 |
| CI-10 no global collapse | 4.1, 4.2 | D4 |
| CI-11 plugin-owned aliases | 4.1, 4.3 | D5 |
| CI-12 per-record rows + UNIQUE | 4.1, 4.2 | D6 |
| CI-13 app-level purge, no FK | 1.1, 4.1, 4.2 | D12 |
| CI-14 clear on null/empty | 4.1, 4.2 | D6 |
| CI-15 generic, core-scoped store | 4.1, 4.2, 6.3 | D6 |
| CI-16 fixed-column store | 4.1, 4.3 | D5 |
| CI-17 namespaced services non-shadowable | 5.2, 5.4 | D9 |
| CI-18 idempotent single-table migration | 3.1, 3.3 | D7 |
| CI-19 bootstrap hook after self-heal | 3.2, 3.4, 7.4 | D7 |
| CI-20 failure leaves flag unwritten | 3.1, 3.3 | D7 |
| CI-21 POST/admin/CSRF guards | 6.1, 6.4 | D10 |
| CI-22 render-only macro, no UI | 6.2, 6.5 | D11 |
| CI-23 no protected surface change | 6.3, 7.1, 7.3 | — |
| CI-24 separate table, no plugin ref | 6.3, 7.2 | D12 |
| CI-25 FSTranslator unchanged | 7.1 | — |
| CI-26 non-migrating plugin identical | 7.1 | — |

---

## Release / Versioning Note

This change is **additive-only** and ships only the core mechanism. It does **not** bump
`VERSION`, does **not** create a git tag, and does **not** modify any plugin. The core
`VERSION` bump (base `0.22.7`) and the `vX.Y.Z` tag are handled by the
`fsframework-core-release` skill **after** `sdd-archive` completes. Consumer migrations
(`catalogo_core`, `OidcProvider`) are separate future changes with their own openspec and are
out of scope here.
