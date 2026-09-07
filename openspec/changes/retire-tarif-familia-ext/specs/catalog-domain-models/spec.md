# Delta for catalog-domain-models

Change: `retire-tarif-familia-ext`
Base spec: `openspec/specs/catalog-domain-models/spec.md`
Scope: MODIFIED replaces the stale override scenario attached to requirement CDM-06; ADDED records the model disposition of `FSFramework\model\tarif_familia`. No other CDM requirement is changed.

## MODIFIED Requirements

### Requirement: CDM-06 — Model override mechanism

Catalog models MUST remain resolvable through the framework's model mechanism (`fs_model_autoloader` global aliases). For `familia`, the dependent-plugin override no longer exists: since change `2026-09-03-tarifario-catalogo-hook-integration` deleted the `tarifario` alias file `plugins/tarifario/model/familia.php`, `\familia` MUST resolve deterministically to the `catalogo_core` base implementation (`FSFramework\model\familia`), independent of plugin load order, autoloader aliasing, or stale model-class-map caches.
(Previously: the scenario attached to CDM-06 illustrated a dependent-plugin override of `familia` via `class familia extends FSFramework\model\tarif_familia` declared in the now-deleted `plugins/tarifario/model/familia.php`.)

#### Scenario: familia resolves deterministically to the catalogo_core base

- GIVEN the `tarifario` override file `plugins/tarifario/model/familia.php` is deleted
- WHEN any consumer instantiates `new \familia()`
- THEN the instance resolves to the `catalogo_core` base `FSFramework\model\familia` with base behavior

#### Scenario: Stale caches cannot resurrect the override

- GIVEN a stale or cleared `tmp/*model_class_map.php` cache and any plugin load order
- WHEN `\familia` is instantiated
- THEN resolution remains deterministic to the base implementation

## ADDED Requirements

### Requirement: CDM-12 — tarif_familia is a deprecated read-only model

`FSFramework\model\tarif_familia` (declared in `plugins/catalogo_core/model/tarif_familia.php`) MUST remain part of the catalog domain models as a `@deprecated` read-only wrapper over `familias` rows hydrated with historical `tarif_familia_ext` columns. It MUST NOT expose write operations against `tarif_familia_ext`, and no flow MAY use it as a write path (the write contract lives in the `tarifa-familia-hierarchy` capability). Its read methods, including the historical ext LEFT JOINs, MUST be preserved for the verified read-only consumers.

#### Scenario: Read-only consumers unaffected

- GIVEN `catalogo_core` active and the deprecated class loaded
- WHEN a read-only consumer (article edit, opcionales pages, wrapper models) calls a read method
- THEN behavior is unchanged, with historical ext columns present when the ext row exists

#### Scenario: Model carries no write surface

- GIVEN the deprecated class after this change
- WHEN its API is inspected
- THEN no method writes to `tarif_familia_ext`
