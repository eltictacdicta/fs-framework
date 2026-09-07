# Design: HTMX CRUD Methodology (core + `tarif_familias` pilot)

## Technical Approach

Controller-owned fragment responses in a new PSR-4 base `FSFramework\Controller\HtmxCrudController extends \fs_controller` (usable from legacy no-namespace plugin controllers — verified: `composer.json` maps `FSFramework\` → `src/`, and `base/fs_controller.php` is required before plugin controller discovery at `index.php:166`). Fragments are rendered with `Html::render($partial, $params)`, `$this->template = false`, and direct `echo` — exactly how today's JSON endpoints bypass the pipeline (`tarif_familias.php:159-160`, `index.php:347-361` echoes nothing when template is false). Zero `index.php` changes. Messages ride `HX-Trigger` (`fs:flash`); debug stats ride `X-FS-*` headers plus an OOB tier. The pilot port swaps all 8 `$.ajax` JSON endpoints (`tarif_familias.html.twig:185, 233, 264, 289, 312, 335, 363, 448`) for fragment endpoints, kills `location.reload()` (view:197, 242, 273, 211), replaces jQuery-UI group drag with sibling-only SortableJS, and replaces bootbox confirms with `hx-confirm` intercepted into the core native-`<dialog>` shim (`view/js/fs-dialogs.js`, which exposes the bootbox-compatible `global.bootbox.{alert,confirm,prompt}` API — 390 lines verified).

```text
Browser (htmx 4)                    Server
────────────────────────────────────────────────────────────────────────────
hx-post/hx-get ──── HX-Request + X-CSRF-TOKEN ──▶ fs_controller::pre_private_core()
                                                  → validateCsrf() (header source, HCS-07)
                                                  → private_core() → action dispatch
                                                  → HtmxCrudController::renderFragment()
                                                       ├ Html::render(partial, params)
                                                       ├ flashPayload() ← fs_core_log get_*()
                                                       ├ headers: HX-Trigger, X-FS-*, Content-Type
                                                       └ echo html   (template=false)
◀── fragment + headers ────────────────────────────
htmx swaps target; htmx-crud.js: fs:flash toast,
footer X-FS-* update, htmx:confirm dialogs
```

## Architecture Decisions

### Decision D1: Reorder-service placement — plugin-local pure service + model persistence adapter

**Choice**: `plugins/catalogo_core/Services/TarifaFamiliaReorder.php` — `final class` in `FSFramework\Plugins\catalogo_core\Services` (autoloaded by the root composer `FSFramework\Plugins\` → `plugins/` mapping; exact precedent: `Services/ArticuloSearchQueryBuilder.php`, same namespace pattern, `final` + static pure helpers). One public entry point:

```php
/**
 * @param list<string>                $flatCodes   top-to-bottom visual order (drag payload)
 * @param array<string, string|null>  $madreByCode current structure map (cod => madre|null)
 * @return array{ok: bool, error: ?string, chapters: array<string, string>}
 *         chapters maps EVERY familia (incl. untouched descendants) to its new capitulo
 */
public static function plan(array $flatCodes, array $madreByCode): array;
```

`plan()` performs, in order: (1) **permutation validation** — flat codes non-empty, set-equal to `madreByCode` keys, no duplicates (missing/extra ⇒ `ok:false`, nothing planned); (2) structure sanity — every non-null madre resolves inside the set, **cycle detection** on madre chains; (3) **chapter computation** — pure BFS from roots: group children by madre in first-appearance order within the flat list, `chapter(child_i) = chapter(madre).''.i`, `chapter(root_i) = (string)i`. This is the extraction of `renumber_siblings_group` (`tarif_familias.php:459-501`, prefix + sequential numbering) merged with `renumber_children_recursive` (508-526) — the client-side stack algorithm from the view (`recalculateLevels`, view:414-440) dies with the jQuery code.

Persistence is a thin model adapter on `tarif_tarifa_familia`: `public function apply_chapter_map(string $codtarifa, array $chapters): bool` — saves only rows whose `capitulo` actually changed (mirrors the change-guard already present in `renumber_children`, model:729).

| Option | Tradeoff | Verdict |
|---|---|---|
| Core service (`src/`) | "Reusable" for other plugins, but `capitulo` prefixing and madre semantics are tarif-domain; a generic core reorder abstraction is speculative; violates the plugin-ownership rule (core must not accumulate plugin remnants) | Rejected |
| Pure statics on `tarif_tarifa_familia` | No new file; but the 838-line model is persistence-first legacy style, and planning purity is easier to govern in a dedicated service with the established `Services/` pattern | Rejected |
| Validation inside the controller action | Status quo shape; requires a DB-backed model instance to test, which is exactly what made the current code untestable | Rejected |
| **Plugin service + model adapter** | Pure logic unit-testable without DB; persistence stays in the model; controller shrinks | **Chosen** |

Consequences: controller `renumber_siblings_group`, `renumber_children_recursive`, and `recalculate_children_capitulos` (370-381) are **deleted**; promote/demote re-derive `(flatOrder, madreMapAfterChange)` server-side (`all_by_capitulo` + `sort_by_capitulo_natural`) and reuse `plan()`. The model's existing single-mover `renumber_siblings`/`move_family` (670, 783) are untouched (no current callers in the new path; removal is out of scope). `suggest_capitulo` remains for `add_familia_to_tarifa` and the capitulo preview.

### Decision D2: OOB secondary flash channel — opt-in, `<template>`-wrapped, primary channel header-based

**Choice**: Two channels, same payload, different lifecycles.

- **Primary (always on)**: `HX-Trigger: {"fs:flash": {"errors": [...], "messages": [...], "advices": [...]}}`. `htmx-crud.js` renders transient Bootstrap-3 toasts (parity with today's `showMessage`, TarifarioComponents:383-388) into a JS-created fixed-position layer.
- **Secondary (opt-in)**: declarative config `oobFlash(true)`. When the payload is non-empty, `renderFragment()` appends `<template><div id="fs-htmx-flash" hx-swap-oob="true">…server-rendered alert markup…</div></template>` after the main fragment. The persistent container `<div id="fs-htmx-flash"></div>` is emitted by the new `crud.flashContainer()` macro in the opting-in view (reserved spot under the toolbar). Missing target ⇒ htmx raises `htmx:oobErrorNoTarget` (console warning), **main swap unaffected** (documented htmx behavior; covered by a verify scenario).

Why the secondary channel exists at all: HX-Trigger payloads are header-bound (size limits, no rich markup) while the OOB block is server-rendered HTML — full parity with the `header.html.twig:263-277` alert blocks, including messages that contain links. The pilot ships with `oobFlash` **off** (toasts only); the channel is part of the reusable API for later screens.

| Option | Tradeoff | Verdict |
|---|---|---|
| OOB-only | Needs a stable DOM target in every screen; the header alert block is frozen by HCS-03/09 (no `id=` may be added) | Rejected |
| Always-emit OOB | Adds markup + missing-target noise to every response of every fragment view | Rejected |
| **Header primary + opt-in OOB secondary** | Zero DOM dependency for the default path; rich markup available when opted in | **Chosen** |

**Sanitization/escaping invariants (verified)**: the payload is built **exclusively** from `fs_core_log::get_errors()/get_messages()/get_advices()` — these route through `read()` which applies `sanitizeForDisplay` (DOM allowlist a/b/strong/i/em/br/code/span, `fs_core_log.php:527-539`) before returning; the same trust model under which `header.html.twig` renders them with `|raw`. Additional rules: the serialized `HX-Trigger` value has CRLF stripped before `header()` (header-injection guard, unit-tested); `json_encode(..., JSON_UNESCAPED_UNICODE)` UTF-8; OOB blocks are **always** `<template>`-wrapped (verified htmx rule: top-level table-child main fragments mixed with non-table siblings break browser parsing — htmx docs "Troublesome Tables", issues #1198/#1900/#3203).

### Decision D3: Per-browser `<tr>` swap parsing — three fragment types with explicit emission rules

**Choice**: fixed emission rules per fragment type (macro and helper agree on them):

| Fragment type | Main content emitted | Client attributes | OOB allowed alongside |
|---|---|---|---|
| Row (toggles) | Bare `<tr id="fam-{cod}" data-…>` | `hx-target="closest tr" hx-swap="outerHTML"` | Only if OOB is `<template>`-wrapped (never mixed by default) |
| Tbody (reorder/save/add/delete) | `<tbody id="familias-tbody" data-…>` | `hx-target="#familias-tbody" hx-swap="outerHTML"` | Only `<template>`-wrapped |
| Capitulo preview (GET) | `<span id="capitulo-preview">{n}</span>` | `hx-target="#capitulo-preview" hx-swap="outerHTML"` | n/a |

Rationale (verified against htmx docs and issue tracker): htmx parses responses inside a `<template>` and internally re-wraps restricted top-level tags, so a **single top-level `<tr>` as main content is safe cross-browser**. The actual parsing hazard is **mixed responses** (top-level `<tbody>`/`<tr>` next to non-table siblings, or `table`+`tbody` siblings) — hence rule 3: any OOB content is always `<template>`-wrapped, and the helper never emits a top-level table child adjacent to a non-table sibling outside a template.

| Option | Tradeoff | Verdict |
|---|---|---|
| tbody-level swaps everywhere | Maximally safe parsing, but a toggle re-renders every row (visible churn, scroll/state flicker, N+1 `count_articulos` re-runs) | Fallback only |
| `<template>`-wrapping all row fragments | Unnecessary for main-only fragments; adds noise | Rejected |
| **Row fragments for toggles + tbody for structural changes; OOB always templated** | Precise updates, verified-safe under documented htmx parsing rules | **Chosen** |

**Explicit fallback rule**: if the verify-phase browser matrix (Chromium/Firefox/WebKit on this theme) shows a row-level `outerHTML` regression, the pilot flips one config flag — `rowSwap: false` in the crud config — making toggle actions answer with the tbody fragment and the macro emit `hx-target="#familias-tbody"` on toggle forms. Contract unchanged; no renumbering logic touched.

## Class / File Layout

### `src/Controller/HtmxCrudController.php` (new, ~200 lines) + `src/Controller/HtmxCrudConfig.php`

```php
namespace FSFramework\Controller;

final class HtmxCrudConfig          // fluent, no I/O
{
    public function rowPartial(string $partial): self;
    public function addOrderBy(array $orderBy): self;          // ['capitulo' => 'asc']
    public function addSearchFields(array $fields): self;
    public function addFilterCheckbox(string $field): self;    // e.g. 'b_solo_activas'
    public function addButton(string $name, array $options): self;
    public function addRowAction(string $name, array $options): self;
    public function sortable(?array $options): self;           // {tbody, url, guard, handle}
    public function oobFlash(bool $on): self;                  // default false
    public function rowSwap(bool $on): self;                   // default true (D3 fallback flag)
    // getters used by Macro/HtmxCrud.html.twig via $fsc->crud()->…
}

class HtmxCrudController extends \fs_controller   // NOT final (legacy + PSR-4 children)
{
    protected HtmxCrudConfig $crud;

    protected function crud(string $view): HtmxCrudConfig;            // fluent config root

    protected function isHtmxRequest(): bool;                          // inherited, now consumed
    protected function requireHtmx(): bool;     // false → caller performs degradation (PRG redirect)

    protected function renderPartial(string $partial, array $params): string; // seam: Html::render($partial, ['fsc'=>$this] + $params)
    protected function buildFragment(string $html, array $options = []): array; // → ['html', 'headers']; PURE, unit-tested
    protected function renderFragment(string $partial, array $params = [], array $options = []): void;
    protected function renderRowFragment(object $row, array $params = []): void;   // rowPartial + ['row' => $row]
    protected function renderTbodyFragment(array $rows): void;
    protected function noContentWithFlash(): void;                                  // 204 + HX-Trigger only

    protected function flashPayload(): array;          // get_errors()/get_messages()/get_advices() only
    protected function sendHtmxHeaders(array &$headers): void;      // X-FS-Duration/Queries/Transactions
    protected function listUrl(): string;              // $this->url() + codtarifa-style persistent params from config
}
```

Fragment response internals (single code path in `emit()`; `buildFragment()` returns the same array so PHPUnit never needs Twig):

| Element | Value |
|---|---|
| Status | `200` normal; `204` for error-only responses (`noContentWithFlash()`) — htmx swaps nothing on 204, DOM untouched |
| `Content-Type` | `text/html; charset=UTF-8` (+ `X-Content-Type-Options: nosniff`, parity with `index.php:355-356`) |
| `HX-Trigger` | `{"fs:flash":{"errors":[…],"messages":[…],"advices":[…]}}` — set iff payload non-empty; CRLF-stripped; set on 200 and 204 |
| `X-FS-Duration` | `$this->duration()` string (`fs_app:93`) |
| `X-FS-Queries` / `X-FS-Transactions` | `$this->selects()` / `$this->transactions()` (`fs_controller:633/733`) |
| OOB block | iff `oobFlash` && payload non-empty: `<template><div id="fs-htmx-flash" hx-swap-oob="true">…alerts…</div></template>` appended after main |
| Output | `$this->template = false;` then `echo $html` — `index.php:347` skips its own render; the echoed bytes are the response |

## Twig Macro Design — `themes/AdminLTE/view/Macro/HtmxCrud.html.twig` (new)

Twig macros cannot call sibling-file macros at runtime, so the consuming view imports each macro file **once** and the pairing is documented + scenario-tested (HCS-04/ABS-02 preserved — `header.html.twig`/`footer.html.twig` untouched).

| Macro | Signature | Emits |
|---|---|---|
| `boot` | `boot(config = {})` | One line: `<script type="application/json" data-fs-crud-config>{{ config|json_encode|e('html_attr') }}</script>` then **ordered, deferred, nonce'd** tags: `view/js/Sortable.min.js` (verified filename, capital S) → `view/js/fs-dialogs.js` → `view/js/htmx-crud.js`. Zero-argument output is the byte-stable baseline (HCS-05 culture). |
| `table` | `table(id, options)` where options = `{rows, columns, rowPartial, sortable}` | `<table>` shell + `<thead>` from `columns` + `<tbody id="{{ id }}-tbody">` iterating `{% include rowPartial with {row: fam, fsc: fsc} %}` — the **same partial** the fragment endpoints render (single row definition). |
| `toolbar` | `toolbar(fsc, options)` | Generic shape (title, buttons, filter links). The pilot keeps its richer `TarifarioComponents.toolbar` for Excel buttons; core macro is the reusable floor. |
| `flashContainer` | `flashContainer()` | `<div id="fs-htmx-flash" aria-live="polite"></div>` — persistent OOB target (used only with `oobFlash(true)`; absent ⇒ `htmx:oobErrorNoTarget` no-op). |
| `saveBar` | `saveBar(id, options)` | The "Has modificado el orden / Guardar / Cancelar" bar (view:507-522 markup, migrated: `hx-post` driven by the serializer via `htmx.ajax()`, `hx-vals` cannot carry dynamic codes). |

**Byte-identical opt-in guarantee**: no macro is referenced by `header.html.twig`/`footer.html.twig`; a view that does not import `HtmxCrud.html.twig` renders exactly as before (HCS-09); every hx-* attribute is opt-in per element.

**Graceful degradation (real action/method)**: every `hx-post` mutation is a **real `<form method="post" action="…">`** with a submit button carrying the hx attributes; without htmx the browser performs a normal full POST. Consequence — CSRF: `validateCsrf()` resolves the token **field-first, header-last** (`fs_controller.php:391-397`), so a form-embedded `csrf_field()` would shadow the inherited `X-CSRF-TOKEN` header. Resolution: forms embed `csrf_field()` **solely for the no-htmx fallback** and carry `data-fs-csrf-strip`; `htmx-crud.js` strips embedded CSRF inputs at init and on every `htmx:afterSettle` (fragments re-introduce forms), guaranteeing **every htmx request authenticates via the header** (HCS-07 intent intact; `CsrfManager` untouched). This refines the proposal's "MUST NOT embed" rule: the field exists for degradation only and never wins on the htmx path.

## `view/js/htmx-crud.js` Module Design (new, core, ~150-200 lines)

IIFE, `'use strict'`, external file loaded nonce'd + deferred by `crud.boot()`; no inline handlers, no `eval` (CSP-compliant by construction).

| Concern | Design |
|---|---|
| Config | Reads `data-fs-crud-config` JSON at `DOMContentLoaded`; no-op when absent (module never acts globally). |
| `htmx:confirm` (capture, document) | If `evt.detail.question` present: `evt.preventDefault()` → `window.bootbox.confirm({message, callback: ok => ok && evt.detail.proceed()})` — bootbox = the core native-`<dialog>` shim (`fs-dialogs.js`, loaded by `crud.boot()`); if the shim is absent, falls back to `window.confirm`. **Verify at apply**: confirm `htmx:confirm` still exposes `detail.proceed()` in the vendored htmx 4.0.0; fallback if not: re-issue via `htmx.ajax('POST'/'GET', elt.getAttribute('hx-post'/'hx-get'))`. |
| `fs:flash` (document) | Renders transient toasts (errors→`alert-danger`, messages→`alert-success`, advices→`alert-info`), auto-dismiss 3s — markup parity with current `showMessage`. |
| `fs:modal-close` (document) | `$(evt.target).closest('.modal').modal('hide')` (Bootstrap 3 JS + jQuery already global on every screen; external nonced file ⇒ CSP-safe). Server sets it as a **second event** in the same `HX-Trigger` JSON: `{"fs:flash": {…}, "fs:modal-close": {}}`. |
| `htmx:afterRequest` | Reads `X-FS-Duration/X-FS-Queries/X-FS-Transactions` from `evt.detail.xhr` and updates the three `footer.main-footer span.label` text nodes by suffix replacement (footer markup frozen — no ids may be added; label prefix preserved verbatim). |
| `htmx:afterSettle` | Strips `[data-fs-csrf-strip] input[name="_csrf_token"]` from swapped content (see CSRF degradation above). |
| OOB | Native htmx swap into `#fs-htmx-flash`; module additionally listens `htmx:oobErrorNoTarget` → `console.warn` (diagnostic only). |
| SortableJS init | `Sortable.create(tbody, { handle: '.drag-handle', animation: 150, draggable: 'tr[data-codfamilia]', onMove: sameMadreGuard, onEnd: markDirty })` from `config.sortable`. |
| Same-madre `onMove` guard | `return (evt.dragged.dataset.madre ?? '') === (evt.related.dataset.madre ?? '')` — cross-madre moves rejected and reverted by SortableJS; descendants never move implicitly (reparenting = promote/demote/edit only). Group drag is a non-goal that cannot change the contract (flat codes still suffice). |
| Flat-code serializer | On dirty end: `tbody.querySelectorAll('tr[data-codfamilia]')` → codes array → save bar appears; "Guardar orden" → `htmx.ajax('POST', url, {values: {order: JSON.stringify(codes)}, target: config.sortable.tbody, swap: 'outerHTML'})`; "Cancelar" → `sortable.sort(initialIds)` captured at init (no request). |
| CSRF strip | At init + `htmx:afterSettle` (above). |
| Alpine | **None, deliberately.** Every behavior is htmx-event- or DOM-driven vanilla JS; registering `Alpine.data` would add an `alpine:init`-timing risk and an Alpine import for zero functionality. If a future view needs reactive components, the existing `Macro/Alpine.html.twig` boots independently (ABS-02) and `htmx-crud.js` stays Alpine-free. |

## Controller Port Design — `plugins/catalogo_core/controller/tarif_familias.php`

Parent chain: `class tarif_familias extends \FSFramework\Controller\HtmxCrudController` (dual-parent-chain usage verified in explore §4.1). `fbase_controller` is dropped by this controller **only** — its sole used feature is `allow_delete` (controller:690; `multi_almacen`/`fbase_*` unused here), so the pilot sets `$this->allow_delete = $this->user->allow_delete_on($this->class_name);` in `private_core()`. Other `fbase_controller` consumers are unaffected (it stays in `extras/`).

| Current endpoint (line) | Current contract | New endpoint & flow | Response |
|---|---|---|---|
| `action=reorder` (163; 386-452) | JSON `{success,message}`; client sends `{codfamilia, madre, posicion}` groups + legacy fallback | `action=reorder`: `$_POST['order']` = JSON array of flat codes **only** → `TarifaFamiliaReorder::plan()` → `apply_chapter_map()` → tbody fragment. Non-permutation ⇒ **nothing saved**, 204 + flash error. `duplicated_petition` guard on `petition_id` (hx-vals). | `<tbody id="familias-tbody">` + flash; **no `location.reload()`** |
| `action=get_next_capitulo` (166-172; view:363, 448) | JSON `{capitulo}` | hx-GET on madre select change; `hx-target="#capitulo-preview" hx-swap="outerHTML"` (HTML-first, no JSON envelope) | `<span id="capitulo-preview">N</span>` |
| `action=promote` / `demote` (173/176; 196-273) | JSON + reload | POST + confirm (hx-confirm→fs-dialogs) → madre change saved → server re-derives `(flatOrder, madreMap)` → `plan()` + `apply_chapter_map()` (replaces `recalculate_children_capitulos`) | tbody fragment + flash |
| `action=toggle_catalogo/en_tarifa/activa` (179/182/185; 278-348) | JSON; client class-juggles (view:288-358) | Row forms (`hx-target="closest tr" hx-swap="outerHTML"`); private `ajax_toggle_*()` logic **kept intact** (existing tests keep passing), wrapped by fragment emitters | Bare `<tr>` fragment (D3); failure ⇒ 204 + flash error, row untouched |
| `POST save_familia` (123; 594-651) | Plain POST, no PRG, full reload | Modal form: real `action`/`method` + `hx-post`, `hx-target="#familias-tbody"`, `hx-swap="outerHTML"`. Success ⇒ tbody fragment + `fs:modal-close` event. Failure (model/validation) ⇒ 204 + flash error, modal stays open. Non-htmx fallback ⇒ process + PRG redirect to `listUrl()` (fixes the missing-PRG debt). | Success: `<tbody id="familias-tbody">`; Failure: 204 |
| `POST add_familia` (125; 656+) | Plain POST, no PRG | Same pattern as save (new row appears in server-computed chapter position) | Same as save |
| `GET delete` (127; view:214-224 bootbox → `window.location`) | **GET mutation** (no CSRF) | **Retired.** `hx-post` delete button + `hx-confirm` (fs-dialogs) + `duplicated_petition`; responds with tbody fragment (delete cascades `madre=null` on hijas, model:294-296 — chapters of remaining rows must re-render) | `<tbody id="familias-tbody">` + flash |
| `export_excel*` / `import_excel_chunk` (146-157; 852-1217) | File/JSON responses | **Untouched** (early-return before the fragment branch; Excel modals keep their current dialogs — bootbox-compatible calls keep working against the theme-provided shim) | unchanged |

Other port items: `private_core()` restructured to `crud('familias')` config + action dispatch; **all `error_log` debug noise removed** (lines 398, 436, 470, 487-490, 496); row actions become server-rendered forms in the row partial (inline `onclick=` handlers of `familia_item_row`, TarifarioComponents:434-477, eliminated); `TarifarioComponents` keeps `toolbar` (Excel buttons), `modal_popup_*`, `styles`, `toggle_button_group`; **trimmed from the migrated view**: `js_common` (`baseUrl`/`showLoading`/`showMessage` — replaced by hx URLs, `hx-indicator`, fs:flash), `familia_item_row` (replaced by `partials/familias/familia_row.html.twig` + `familia_rows.html.twig`), loading-overlay usage. `TarifarioComponents.html.twig` keeps its macros (single consumer is this view today, but it is plugin-shared surface).

## Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Core unit (new `tests/Core/HtmxCrudControllerTest.php`) | `buildFragment()` contract: header set (`HX-Trigger` JSON shape + CRLF stripping, `Content-Type`, `X-FS-*`), status/204 semantics, OOB `<template>`-wrapping rule, `template=false`; `flashPayload()` built only from the three accessors; `requireHtmx()` gating | `HtmxCrudController` anonymous subclass with empty constructor (proven pattern), fixture HTML via an overridden `renderPartial()` seam — no DB, no Twig |
| Core unit (existing) | `isHtmxRequest()` behavior | `tests/Base/FsControllerHtmxTest.php` — untouched, still green |
| Plugin unit (new `plugins/catalogo_core/tests/Services/TarifaFamiliaReorderTest.php`) | `plan()` pure logic: valid permutation → full chapter map (roots, nesting, descendant cascade); rejects missing/extra/duplicate codes (nothing planned); rejects unknown madre + cycles; promote/demote-shaped input | Pure static — **no DB, no model instance** |
| Plugin contract (extend, don't break) | `TarifFamiliasControllerContractTest`: **one assertion updated** — `extends fbase_controller` → `extends \FSFramework\Controller\HtmxCrudController` (the fbase drop is the port itself); all other assertions (zero `tarif_controller`, zero `plugins/tarifario`, `count_articulos`, public API surface) survive unchanged. `TarifFamiliasToggleTest` (10 tests): **unbroken** — private `ajax_toggle_*()` keep their array contract; new fragment wrappers tested alongside. `TarifFamiliasHierarchyTest` (4 tests): **unbroken** — `build_tree`/`flatten`/`get_familias_flat` survive the port. | PHPUnit 11 via `ddev exec php vendor/bin/phpunit`, root suite discovers plugin tests |
| Plugin unit (new `TarifFamiliasFragmentTest.php`) | Endpoint contracts: reorder happy path + non-permutation rejection ⇒ no saves; toggle wrapper emits row fragment via `buildFragment()` capture; delete is POST (no GET handler remains); `duplicated_petition` guard | Anonymous subclass + fake models (pattern of `TarifFamiliasToggleTest::makeFakeModel`) |
| Regression (byte-identical) | Non-opted views render unchanged; macro-import gating | HCS-09-style source/render assertions extended to the new assets; verify-phase render comparison |
| E2E / browser | Not automated (config `e2e: false`): row-swap parsing across browsers, fs-dialogs confirm flow, drag guard, footer update | sdd-verify manual matrix per D3 fallback rule |

## Spec Mapping

| Design element | Spec requirement |
|---|---|
| Flash bridge over `HX-Trigger` (payload shape, sanitized-only source, CRLF guard, header/footer untouched) | **HCS-13** |
| Fragment response contract (`buildFragment`/`renderFragment`, `template=false`, Content-Type, 204 error path, `requireHtmx` degradation) | **HCS-14** |
| `X-FS-*` headers always + OOB debug/flash tier gated (`FS_DEBUG`/`FS_DB_HISTORY`; flash OOB opt-in) | **HCS-15** |
| `htmx-crud.js` as opt-in module loaded only via `crud.boot()`; event map; CSRF strip behavior | **HCS-16** |
| Declarative base API (`HtmxCrudController` + `HtmxCrudConfig`, dual parent-chain usability) | **CRD-01** |
| Fragment endpoint conventions + CSRF rule (header is the token source; `data-fs-csrf-strip` degradation contract; mutations are POST) | **CRD-02** |
| Server-authoritative reorder (flat codes in, permutation validation, server recompute of madre/capitulo, rejection saves nothing) | **CRD-03** |
| SortableJS ordering rules (sibling-only guard, handle, flat-code serializer, cancel; group-drag follow-up does not change the contract) | **CRD-04** |
| Opt-in/byte-identical guarantees + graceful degradation (real forms, missing-macro scenario) | **CRD-05** |
| `hx-confirm` → core native `<dialog>` (fs-dialogs) interception; bootbox confined to untouched Excel flows | **CRD-06** |

## Worked Example — user toggles "activa" on row F010

**1. Markup** (row partial, rendered both for the page and as the fragment):

```html
<tr class="familia-row nivel-0" data-codfamilia="F010" data-madre="" data-nivel="0">
  …
  <td class="text-center">
    <form method="post"
          action="index.php?page=tarif_familias&codtarifa=001&action=toggle_activa"
          hx-post="index.php?page=tarif_familias&codtarifa=001&action=toggle_activa"
          hx-target="closest tr" hx-swap="outerHTML" data-fs-csrf-strip>
      <input type="hidden" name="codfamilia" value="F010">
      <input type="hidden" name="petition_id" value="p-1715-3">
      <button type="submit" class="btn btn-xs btn-success" title="Activa"><i class="fa fa-check"></i></button>
    </form>
  </td>
  …
</tr>
```

**2. Request** (htmx 4; CSRF token inherited from `hx-headers:injected` set by `htmx.boot()` — the embedded `_csrf_token` was stripped at boot):

```text
POST /index.php?page=tarif_familias&codtarifa=001&action=toggle_activa
HX-Request: true
X-CSRF-TOKEN: <session token>
Content-Type: application/x-www-form-urlencoded;charset=UTF-8

codfamilia=F010&petition_id=p-1715-3
```

**3. Server**: `pre_private_core()` → `validateCsrf()` passes on the header (no form field present) → `private_core()` → action → `ajax_toggle_activa()` flips `$fam->activa` → `renderRowFragment($fam)` → `buildFragment()` attaches headers → `template=false` → `echo`.

**4. Response**:

```text
HTTP/1.1 200 OK
Content-Type: text/html; charset=UTF-8
X-Content-Type-Options: nosniff
X-FS-Duration: 0.214 s
X-FS-Queries: 11
X-FS-Transactions: 1
HX-Trigger: {"fs:flash":{"errors":[],"messages":["Familia actualizada."],"advices":[]}}

<tr class="familia-row nivel-0" data-codfamilia="F010" data-madre="" data-nivel="0">
  … same partial, inactive styling (btn-default/fa-ban), no reload …
</tr>
```

**5. DOM**: htmx swaps the `tr` (`outerHTML`); `htmx-crud.js` renders the `fs:flash` toast ("Familia actualizada."), reads `X-FS-*` and updates the footer labels (`Consultas: 11`, `Transacciones: 1`, duration) in place — `footer.html.twig` bytes unchanged. Failure path: `204 No Content` + error-only `HX-Trigger` ⇒ no swap, row unchanged, error toast. Without htmx: the real form POSTs; CSRF via the embedded field; server PRG-redirects to the list.

## Threat Matrix

N/A — no routing tables, shell commands, subprocesses, VCS/PR automation, executable-file classification, or process-integration boundaries are introduced. Security posture is covered by the design's invariants: CSRF header source (CRD-02), sanitized-only flash payload (HCS-13), OOB `<template>` rule (HCS-15), POST-only mutations (port table).

## Migration / Rollout

No data migration; no schema changes (families tables ensured by `Init::ensureFamiliasTarifaTables`, controller:86-91). Chained delivery as per proposal: PR-1 core fragment/flash infra → PR-2 macros + JS module → PR-3 pilot list/CRUD/toggles → PR-4 reorder. Every slice is opt-in and independently revertible; Excel flows untouched throughout; byte-identical verification at each slice.

## Open Questions

- [ ] Confirm `htmx:confirm` `detail.proceed()` exists in the vendored htmx 4.0.0 build (design assumes htmx 2 parity; fallback `htmx.ajax()` re-issue documented) — resolve during apply, before PR-2.
- [ ] Confirm htmx 4.0.0's `makeFragment` still internally re-wraps top-level table children (single `<tr>` main fragments) — verify-phase browser matrix is the gate; D3 fallback flag covers regression.
- [ ] Excel import partials may invoke the bootbox-compatible API — confirm at apply that no Excel flow depends on jQuery-UI or the removed inline handlers.
