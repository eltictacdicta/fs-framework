# htmx-crud Specification

## Purpose

Reusable, opt-in HTMX-first CRUD methodology for list screens: a declarative controller base, fragment endpoint conventions with an explicit CSRF rule, a server-authoritative reorder contract, SortableJS sibling-only ordering, byte-identical opt-in guarantees with graceful degradation, and native confirm dialogs. Builds on `htmx-core-support` (HCS-06/07 and HCS-13..16). Pilot consumer: `tarif_familias` (`catalogo_core`); Excel import/export flows are untouched.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| CRD-01 | Declarative base API: `HtmxCrudController` + `HtmxCrudConfig`, dual parent-chain usability (legacy + PSR-4). | MUST |
| CRD-02 | Fragment endpoint conventions + CSRF token-source rule; mutations POST-only, GET mutations retired. | MUST |
| CRD-03 | Server-authoritative reorder: flat codes in, permutation validation, server recompute, minimal saves. | MUST |
| CRD-04 | SortableJS sibling-only ordering rules (guard, handle, flat-code serializer, cancel). | MUST |
| CRD-05 | Opt-in/byte-identical guarantees + graceful degradation (real forms, missing-macro no-op). | MUST |
| CRD-06 | `hx-confirm` intercepted into the core native `<dialog>`; bootbox confined to untouched Excel flows. | MUST |

### Requirement: CRD-01 — Declarative base API

`FSFramework\Controller\HtmxCrudController` MUST extend `\fs_controller`, MUST NOT be final, and MUST be usable as a parent class by both legacy no-namespace plugin controllers and PSR-4 controllers (dual parent-chain). `HtmxCrudConfig` MUST be a final, fluent, side-effect-free configuration object exposing `rowPartial`, `addOrderBy`, `addSearchFields`, `addFilterCheckbox`, `addButton`, `addRowAction`, `sortable`, `oobFlash`, `rowSwap`; its effective options MUST be consumable by the theme macro through the controller (`$fsc->crud()`).

#### Scenario: legacy controller extends the base

- **GIVEN** a legacy plugin controller without namespace declaring `extends \FSFramework\Controller\HtmxCrudController`
- **WHEN** the controller is instantiated and dispatched
- **THEN** construction, action dispatch and fragment emission work through the parent chain

#### Scenario: PSR-4 controller extends the base

- **GIVEN** a namespaced PSR-4 controller extending the base
- **WHEN** the controller is instantiated
- **THEN** the same protected API (`crud()`, `requireHtmx()`, fragment emitters) is available

#### Scenario: config is fluent and side-effect-free

- **GIVEN** a controller chaining multiple config calls (e.g. `rowPartial`, `addOrderBy`, `sortable`)
- **WHEN** the configuration completes
- **THEN** each method returns the config instance, no I/O occurs during configuration
- **AND** the macro reads the effective options through the getters

### Requirement: CRD-02 — Fragment endpoint conventions and the CSRF token-source rule

Mutation endpoints MUST be POST-only: a GET mutation endpoint MUST NOT exist (the legacy GET delete is retired and replaced by an hx-post button with confirm). Every htmx mutation MUST be a real `<form method="post" action="…">` carrying the hx attributes, so without htmx the browser performs a normal full POST and the server responds with a PRG redirect to the list URL. Mutation forms embed `csrf_field()` solely for the no-htmx fallback and MUST carry `data-fs-csrf-strip`; `htmx-crud.js` strips embedded CSRF inputs at init and on `htmx:afterSettle`, so on every htmx request the `X-CSRF-TOKEN` header is the token source and the field-first `validateCsrf()` never sees an embedded token. Rapid duplicate submissions MUST NOT produce double mutations (petition guard). Success responses follow the HCS-14 contract; modal saves respond with a tbody fragment plus `fs:modal-close`, and failures respond `204` with a flash error leaving the modal open or row untouched.

#### Scenario: htmx mutation authenticates via the header only

- **GIVEN** a settled form with `data-fs-csrf-strip`
- **WHEN** htmx posts the mutation
- **THEN** the request body contains no `_csrf_token` field
- **AND** `validateCsrf()` passes on the `X-CSRF-TOKEN` header

#### Scenario: no-htmx fallback posts the real form

- **GIVEN** htmx absent or JavaScript disabled
- **WHEN** the user submits the mutation form
- **THEN** the browser performs a full POST whose embedded CSRF field validates
- **AND** the server responds with a PRG redirect to the list URL

#### Scenario: GET mutation retired

- **GIVEN** the ported controller's action dispatch
- **WHEN** its GET handlers are inspected
- **THEN** no GET delete handler exists
- **AND** delete is an hx-post with confirm responding with a tbody fragment

#### Scenario: duplicate submissions are guarded

- **GIVEN** the same `petition_id` submitted twice in rapid succession
- **WHEN** the second request arrives
- **THEN** it is ignored and no second mutation occurs

#### Scenario: modal failure keeps state

- **GIVEN** a save action failing model validation
- **WHEN** the response is emitted
- **THEN** it is 204 with a flash error
- **AND** the modal stays open and no row changed

### Requirement: CRD-03 — Server-authoritative reorder

Reorder MUST accept ONLY a flat JSON array of codes in top-to-bottom visual order — no per-row madre/posicion payload and no client-computed levels. `TarifaFamiliaReorder::plan($flatCodes, $madreByCode)` MUST be a pure, DB-free function (unit-testable without a model instance) that first validates the payload is an exact permutation of the current codes (non-empty, no missing, no extra, no duplicates) and rejects invalid payloads with `ok:false` planning nothing; on acceptance it MUST compute the complete chapter map server-side (roots numbered sequentially; `chapter(child) = chapter(madre).i`), cascading to untouched descendants. Persistence via `apply_chapter_map` MUST save only rows whose `capitulo` actually changed. The server recomputes madre/nivel/capitulo from the accepted order — the client never sends structure data.

#### Scenario: valid permutation produces the full map with minimal saves

- **GIVEN** a flat codes array matching the current code set exactly
- **WHEN** `plan()` runs and the returned map is applied via `apply_chapter_map`
- **THEN** every familia, including untouched descendants, receives its recomputed `capitulo`
- **AND** only rows whose `capitulo` changed are written

#### Scenario: non-permutation payloads save nothing

- **GIVEN** a payload missing a code, carrying an extra code, or duplicating a code
- **WHEN** `plan()` runs
- **THEN** it returns `ok:false` with an error reason, nothing is planned and nothing is saved
- **AND** the client receives 204 with a flash error and the DOM is untouched

#### Scenario: structure sanity is enforced

- **GIVEN** a structure map containing an unknown madre reference or a cycle
- **WHEN** `plan()` runs
- **THEN** it rejects with `ok:false` and plans nothing

#### Scenario: plan() is DB-free testable

- **GIVEN** any input arrays
- **WHEN** `plan()` is unit-tested
- **THEN** no database, model instance or framework boot is required

### Requirement: CRD-04 — SortableJS sibling-only ordering

Drag MUST be sibling-only: the `onMove` guard MUST reject — and SortableJS revert — any move whose dragged and related rows have different `data-madre`; descendants never move implicitly (reparenting happens only through promote/demote/edit). Rows MUST be draggable only via the handle (`.drag-handle`) and MUST carry `data-codfamilia`. The serializer MUST produce the flat codes array in top-to-bottom DOM order (the CRD-03 input). "Guardar orden" MUST issue exactly one POST with the serialized order; "Cancelar" MUST restore the order captured at init and MUST issue no request. Group drag (parent + descendants) is a declared non-goal: a future follow-up MUST NOT change the flat-codes contract.

#### Scenario: cross-madre moves are reverted

- **GIVEN** a drag whose dragged and hovered rows have different `data-madre`
- **WHEN** `onMove` evaluates the pair
- **THEN** the move is rejected and reverted by SortableJS
- **AND** no dirty state or request results

#### Scenario: same-madre reorder activates the save bar

- **GIVEN** a same-madre drag ending
- **WHEN** `onEnd` marks the tbody dirty
- **THEN** the save bar appears
- **AND** "Guardar orden" issues exactly one POST carrying the flat codes JSON

#### Scenario: cancel restores without a request

- **GIVEN** a dirty tbody after one or more drags
- **WHEN** "Cancelar" is clicked
- **THEN** the initial DOM order captured at init is restored
- **AND** no HTTP request is issued

#### Scenario: handle-only dragging

- **GIVEN** the sortable tbody
- **WHEN** the user drags from the row body instead of the handle
- **THEN** the row does not move

### Requirement: CRD-05 — Opt-in adoption, byte-identical guarantee, graceful degradation

The methodology MUST be opt-in per view: only views importing `Macro/HtmxCrud.html.twig` acquire its assets and behavior; `header.html.twig` and `footer.html.twig` MUST NOT reference the macro, its assets or its config. A non-importing view MUST render byte-identically to pre-change output (HCS-09 extended to the new assets). Every htmx mutation keeps a real form action/method (CRD-02) so the no-htmx path works. A view importing the macro but rendered without its config JSON (or the boot line's expected markup) MUST NOT break: the module no-ops and the page renders normally.

#### Scenario: non-opted views are byte-identical

- **GIVEN** a view that does not import the macro
- **WHEN** the page renders
- **THEN** output is identical to pre-change output: no htmx-crud/Sortable/fs-dialogs scripts, no boot config, no hx-driven behavior
- **AND** `header.html.twig` and `footer.html.twig` reference none of the new assets

#### Scenario: degraded path works end-to-end

- **GIVEN** htmx not running
- **WHEN** a mutation form is submitted
- **THEN** the full POST processes with the embedded CSRF field
- **AND** the server responds with a PRG redirect to the list URL showing current state

#### Scenario: missing config degrades to no-op

- **GIVEN** the boot line loads the module but `data-fs-crud-config` is absent
- **WHEN** the module initializes
- **THEN** it binds nothing and the page renders and behaves normally

### Requirement: CRD-06 — Native confirm dialogs for hx-confirm

`hx-confirm` prompts in CRUD views MUST be intercepted by `htmx-crud.js` (document-level `htmx:confirm` in capture phase): the default browser prompt MUST be prevented, the question presented through the core native-`<dialog>` shim (`view/js/fs-dialogs.js`, bootbox-compatible API, loaded by the boot line), and the request issued (`evt.detail.proceed()`) only on acceptance — rejection cancels the request. If the shim is unavailable the module MUST fall back to `window.confirm`. Migrated CRUD flows MUST NOT invoke bootbox dialogs; bootbox-compatible calls persist only in untouched Excel flows.

#### Scenario: acceptance proceeds exactly once

- **GIVEN** a row action with `hx-confirm`
- **WHEN** the user confirms in the fs-dialogs native dialog
- **THEN** the htmx request is issued exactly once

#### Scenario: rejection cancels the request

- **GIVEN** the same action
- **WHEN** the user cancels the dialog
- **THEN** no request is issued

#### Scenario: shim fallback

- **GIVEN** `fs-dialogs.js` failed to load
- **WHEN** a confirm event fires
- **THEN** `window.confirm` is used with the same proceed/cancel semantics

#### Scenario: bootbox confined to Excel flows

- **GIVEN** the ported CRUD view
- **WHEN** its markup and scripts are inspected
- **THEN** no bootbox dialog call remains in migrated flows
- **AND** untouched Excel modals keep their existing dialogs
