# Exploration: htmx-crud-methodology

> SDD explore phase artifact. Read-only investigation; no source code was modified.
> Scope: reusable HTMX-first CRUD methodology in core + `tarif_familias` (catalogo_core) pilot.
> Constraints honored: opt-in culture (HCS-01..12, ABS-01..03), Bootstrap 3 frozen, ddev-only PHP, single messaging API.

## 1. Executive Summary

The building blocks already exist and are healthy: htmx 4, Alpine CSP and SortableJS are vendored (`view/js/htmx.min.js`, `alpine-csp.min.js`, `Sortable.min.js`), the opt-in boot macros are spec'd and implemented, `fs_controller::isHtmxRequest()` ships (HCS-06) **but has zero consumers** — no server-side fragment path exists yet. The pilot screen is a 767-line Twig + 1472-line controller doing 8 inline `$.ajax` JSON calls, jQuery UI sortable, `location.reload()` after every mutation, and hand-rolled toasts. The gap is therefore narrow and well-shaped: (a) a fragment-render + flash-message bridge in core (PSR-4, usable from legacy plugin controllers), (b) an opt-in declarative CRUD base + theme macros, (c) a server-authoritative reorder contract, and (d) the pilot port. The estimated vertical slice is ~2.0–2.7k changed lines, which exceeds the 400-line review budget ~5–6×: **chained PRs are mandatory**, sliced core-first (infra → macros/module → pilot list/CRUD → reorder).

## 2. Current-State Map (file:line evidence)

### 2.1 Render pipeline (legacy echo flow)

| Evidence | Meaning |
|---|---|
| `index.php:347-361` | If `$fsc->template` truthy → `echo Html::render(...)`; **AJAX branch already exists**: `isAjax()` → `Html::renderAjax()` (content-only, regex extraction). `template=false` suppresses render — how JSON endpoints work today. |
| `src/Core/Html.php:39-59` | `render()` — Twig render with theme+plugin loader paths. |
| `src/Core/Html.php:69-107` | `renderAjax()` — renders template, strips full-page shell via regex patterns (`content-wrapper`, `content`, `main`, fallbacks). Works but is extraction-based, not targeted-fragment-based. |
| `src/Core/Html.php:161-217` | Twig FilesystemLoader: core `view/`, theme path (prepend, highest priority), each plugin's `view/` + `View/` prepended. Theme macro imports from plugin views resolve (pilot already imports `Macro/TarifarioComponents.html.twig` from its own dir; theme path wins for `Macro/Htmx.html.twig`). |
| `base/fs_controller.php:485-488` | `isHtmxRequest()` — header presence check. **Only consumer: `tests/Base/FsControllerHtmxTest.php`** (HCS-06 verified, unused in prod flow). |
| `base/fs_controller.php:200-202` | `is_ajax` = XHR header OR `ajax` param (GET/POST). |
| `index.php:204-273, 284` | Modern FS2025 controller discovery scans `plugins/{p}/Controller/` (PascalCase, `run()`/`handle()`), separate from legacy `find_controller` flow. |
| `composer.json:41-44` | PSR-4: `FSFramework\` → `src/`, `FSFramework\Plugins\` → `plugins/`; plus `index.php:62-73` spl_autoload for `FSFramework\Plugins\`. **Legacy plugin controllers can `use`/`extends` any PSR-4 core class** — proven by core legacy controllers (`controller/admin_stealth.php:82` uses `FSFramework\Core\StealthMode`) and by pilot imports (`tarif_familias.php:24-26`). |
| `index.php:166` | `base/fs_controller.php` is required **before** any plugin controller is found → a PSR-4 base class `extends \fs_controller` is safely loadable from `src/`. |

### 2.2 Messaging, CSRF, logging

| Evidence | Meaning |
|---|---|
| `base/fs_controller.php:566-595` | `new_error_msg` / `new_message` / `new_advice` → `fs_core_log` channels (`errors`/`messages`/`advices`); API untouched by this change. |
| `base/fs_core_log.php:500-520` | `log()` — single funnel; feeds `DebugBar::addLog` under `FS_DEBUG` (510-519). |
| `base/fs_core_log.php:527-537, 539-578` | `read()` sanitizes on display via `sanitizeForDisplay` (DOM allowlist: a/b/strong/i/em/br/code/span). Downstream renderers use `|raw` on this **already-sanitized** content. |
| `themes/AdminLTE/view/header.html.twig:263-277` | Full-page messages: three alert blocks (`get_errors`/`get_messages`/`get_advices`) rendered with `|raw`. **Header/footer are frozen by HCS-03/HCS-09 — must not change (byte-identical culture).** |
| `base/fs_controller.php:382-425` | `validateCsrf()`: token resolution order = form field `_csrf_token` → form field `_token` → **`X-CSRF-TOKEN` header (396)**. `pre_private_core()` (946-965) validates on every POST before `private_core()`. |
| `themes/AdminLTE/view/Macro/Htmx.html.twig:45-87` | Boot macro: inherited `hx-headers` with `X-CSRF-TOKEN` + nonce'd deferred htmx 4 asset; optional scrubber form (`allowScriptTags:false`). |
| `themes/AdminLTE/view/Macro/Alpine.html.twig:31-33` | Alpine CSP boot: one nonce'd deferred script. CSP build ⇒ inline expressions limited; rich logic belongs in `Alpine.data()` registered from nonce'd scripts (alpine:init pattern). |
| `base/fs_app.php:77-97` | `duplicated_petition($pid)` cache guard; `duration()`. `fs_core_log::get_sql_history()` (380-383) feeds footer. |
| `themes/AdminLTE/view/footer.html.twig:14-26, 58-63, 66-68` | SQL history panel (when `FS_DB_HISTORY` and not FS_DEBUG), queries/transactions/duration labels, `debugBarRender.render()` under FS_DEBUG. |

### 2.3 Pilot screen (tarif_familias)

| Evidence | Current behavior |
|---|---|
| `plugins/catalogo_core/View/tarif_familias.html.twig` (767 lines) | Inline `<style>` (11-73); jQuery UI sortable with descendant-moving drag (377-411) + client-side level recalculation (414-440); **8 inline `$.ajax` calls** (185, 233, 264, 289, 312, 335, 363, 448); `location.reload()` after reorder/promote/demote (197, 242, 273); bootbox confirms (215-255); hand-rolled toast `showMessage` (macro js_common, TarifarioComponents:383-388); `csrf_meta()` (740); **resumable.js from CDN** (743); Excel modals via ES modules (758) + plugin partials (761-764). |
| `plugins/catalogo_core/controller/tarif_familias.php` (1472 lines) | `process_action` (117-191): JSON switch for reorder/get_next_capitulo/promote/demote/toggle_*, `template=false` + `header('Content-Type: application/json')`; `ajax_reorder` (386-452) accepts client-computed `{codfamilia, madre, posicion}` groups + legacy fallback, **`renumber_siblings_group` (459-501) already renumbers madre/capitulo server-side**; promote/demote (196-273) reparent one level with `suggest_capitulo` + recursive child renumber; toggles (278-348); `save_familia`/`add_familia`/`delete` are plain POST without PRG (594-721); Excel export/import/template/chunk (852-1217). Leftover `error_log` debug noise at 398, 436, 470, 487-490, 496. |
| `View/Macro/TarifarioComponents.html.twig` (480 lines) | Plugin-shared macros: styles (9-182), toolbar (205-292), loading overlay (297-304), modal popup (309-331), toggle group (336-362), `js_common` with baseUrl/showLoading/showMessage (367-390), `familia_item_row` (395-480) with inline `onclick=` handlers (434-477). |
| `extras/fbase_controller.php:25` | `fbase_controller extends fs_controller` — the pilot's legacy base (allow_delete etc.). |
| `model/tarif_tarifa_familia.php:371,392,414,498-504` | Queries `ORDER BY tf.capitulo` (+`orden` for activas) — chapter string is the de-facto sort key. |
| `View/js/familias/*` (4 files) | Excel flow: ES modules, `fetch`/Resumable with CSRF token from `csrf_meta()` — **stays untouched** per decision #1. |

### 2.4 Existing htmx / sortable precedents

| Evidence | Meaning |
|---|---|
| `themes/AdminLTE/view/admin_user.html.twig:218` | HCS-10..12 pilot: `hx-get` + `hx-select=".content-wrapper > *"` on extension tabs — **purely client-side; server keeps serving full pages**. |
| `themes/AdminLTE/view/admin_orden_menu.html.twig:1-62` | SortableJS + plain POST form (`csrf_field()` + `orden[folder][]` hidden inputs) — the core's server-authoritative reorder precedent. |
| `view/js/ajax-loader.js` | FSAjaxLoader: modal/tab HTML loading with sanitize + `securePost` JSON with CSRF field (legacy screens). |

## 3. Gap Analysis

1. **No fragment-render mechanism.** `isHtmxRequest()` exists but nothing branches on it. `Html::renderAjax()` is extraction-based (regex over full page) — unsuitable for targeted `<tr>`/tbody fragments and for response headers.
2. **No message bridge over fragments.** `fs_core_log` messages render only in `header.html.twig` (full page). Fragments must deliver them; header/footer are frozen (HCS-03/09), so the bridge cannot add markup to the global shell.
3. **No debug/duration channel over fragments.** Footer SQL history/duration/DebugBar are rendered once per full page; fragment responses bypass them.
4. **No declarative CRUD surface.** Legacy `fs_list_controller` (tab-based, RainTPL-era) is explicitly *not* the foundation (decision #3); the FS2025 article's declarative shape (addView/addSearchFields/addOrderBy/addButton) has no core implementation.
5. **Reorder contract is client-trusting.** Client sends computed `madre`+`posicion` (view:151-180); spec decision #2 requires flat codes in, server recomputes. Server renumbering logic already exists (`renumber_siblings_group`) and is reusable.
6. **UX debt on the pilot**: JSON+reload cycle, bootbox, jQuery UI, inline onclick, debug error_logs, CDN resumable.js.

## 4. Proposed Architecture

### 4.1 Fragment rendering (Q1)

**Recommendation: controller-owned fragment responses; zero changes to `index.php`.**

- New PSR-4 class `FSFramework\Controller\HtmxFragmentResponse` (or a method pair on the new base): renders a named partial via `Html::render($partial, $params)`, sends `Content-Type: text/html; charset=UTF-8`, and — if `fs_core_log` has pending errors/messages/advices — attaches the flash payload (see 4.2). The controller sets `$this->template = false` and echoes the result (exactly how JSON endpoints already bypass the pipeline at `tarif_familias.php:159-160`).
- Alternatives:
  - *index.php-level `isHtmxRequest()` → `renderAjax()` branch*: rejected — regex extraction of "main content" cannot express "return exactly this tbody", and global branching violates the opt-in culture (behavior would change for any htmx-issuing view).
  - *Symfony Response objects end-to-end*: rejected for the pilot — legacy flow is echo-based; introducing Response plumbing would drag core redesign into the vertical slice. (Modern `src/Core/Base/Controller::run()` already supports `Html::render` and can adopt the same fragment helper later.)
- **Legacy-plugin usability verified**: vendor autoload is live before controller discovery (`index.php:59`), `FSFramework\` maps to `src/` (`composer.json:43`), and `base/fs_controller.php` is required before plugin controllers (`index.php:166`), so a no-namespace `controller/tarif_familias.php` can declare `class tarif_familias extends \FSFramework\Controller\HtmxCrudController` and both parent chains resolve. Modern `Controller/` PSR-4 controllers can extend the same base later (it must therefore not assume legacy-only properties beyond what `fs_controller` provides).

### 4.2 Declarative controller base (Q2)

**Recommendation: `FSFramework\Controller\HtmxCrudController extends \fs_controller`, thin and opt-in**, in `src/Controller/HtmxCrudController.php` (namespace already hosts `BaseController`/`PageController`).

API surface (mirrors the article's declarative shape; conceptual only, legacy `fs_list_controller` is not code foundation):

```php
// configuration (called from private_core before dispatch)
$this->crudView('familias')
    ->rowPartial('partials/familias/familia_row.html.twig')  // plugin-owned row template
    ->addOrderBy(['capitulo' => 'asc'])
    ->addSearchFields(['descripcion'])                        // optional; filters flat list
    ->addFilterCheckbox('activa')
    ->addButton('add', ['modal' => 'modal_add_familia', ...])
    ->addRowAction('edit', [...])->addRowAction('delete', [...]);

// fragment endpoints (protected helpers)
$this->renderFragment('partials/familias/familia_rows.html.twig', ['rows' => $rows]); // + auto flash
$this->renderRowFragment($row);           // single-row swap for toggles
$this->isHtmxRequest();                   // inherited, now consumed
```

- Keeps `fs_controller` semantics: page resolution, `pre_private_core()` CSRF, `template=false` for fragments, `new_message`/`new_error_msg` unchanged (decision #5).
- Dual usability: legacy plugin controllers extend it directly (4.1); PSR-4 FS2025 controllers may extend later — no legacy-only hard dependencies in the base.

### 4.3 Message bridge (Q3)

**Recommendation: `HX-Trigger` response header carrying sanitized messages; consumed by a small core JS module; full-page path untouched.**

- The helper serializes `{errors: [...], messages: [...], advices: [...]}` from `get_errors()/get_messages()/get_advices()` — the same `sanitizeForDisplay`-processed strings the header block renders with `|raw` (same trust model, single source: `fs_core_log`). Header name: `HX-Trigger` with event `fs:flash` and the payload as event detail — no signature change anywhere (decision #5 holds: the bridge only *reads* channels).
- Consumer: `view/js/htmx-crud.js` (nonce'd, loaded **only by opting-in views** via a line in the new `HtmxCrud` macro; not from header/footer — HCS-03 safe). It listens for `fs:flash` (htmx dispatches `htmx:afterRequest`-adjacent custom events from HX-Trigger) and renders Bootstrap-3 alert markup into a runtime-created `#fs-htmx-flash` container (or per-view target). Rendered HTML = the sanitized strings, injected as HTML (parity with `|raw` in header).
- Why not OOB-only: OOB swap of a flash partial requires a stable target id **in the full-page DOM**; adding `id=` to the header.html.twig alert block would change byte output on every screen, colliding with the byte-identical culture (HCS-03/09 spirit). OOB remains available as an *optional* secondary channel targeting the JS-created container for rich fragments (e.g., messages containing links) — the helper can emit `<div id="fs-htmx-flash" hx-swap-oob="innerHTML">…</div>` when configured; primary channel stays header-based to avoid DOM dependencies.
- Legacy FSAjaxLoader JSON screens: they already surface messages on the next full load via `header.html.twig`. They can adopt the same flash payload convention incrementally (their `securePost` success handlers may call the same renderer if `htmx-crud.js` is loaded), but **no forced migration** — FSAjaxLoader remains untouched; "ideal adoption" is documented, not implemented, in this change.

### 4.4 SQL history + duration over fragments (Q4)

**Recommendation: two-tier.**

- **Tier 1 (always, tiny): `X-FS-Duration`, `X-FS-Queries`, `X-FS-Transactions` response headers**, read by the same `htmx-crud.js` listener updating the existing footer spans/labels in place (`footer.html.twig:58-63` markup unchanged; JS only updates text nodes). Cheap, always-on, no markup drift.
- **Tier 2 (FS_DEBUG / FS_DB_HISTORY only): OOB debug fragment.** When `DebugBar::shouldRender()` (or `FS_DB_HISTORY`), fragment responses append an OOB `<template>`/`<div hx-swap-oob>` block re-rendering the DebugBar (`debugBarRender.render()` string is already produced server-side; wrap it) and/or the SQL panel. Dev-only weight, zero production cost.
- Tradeoffs: headers-only can't carry full SQL history (header size limits, hundreds of queries); OOB-only adds markup to every fragment; the two-tier split keeps production responses clean and dev parity with full pages. DebugBar under FS_DEBUG gets refreshed content without JS parsing — it is server-rendered HTML by construction.

### 4.5 Server-authoritative reorder (Q5)

**Contract (decision #2):** `POST {url}&action=reorder` with a single flat, top-to-bottom array of `codfamilia` strings (form-encoded JSON as today). **No madre/posicion from the client.** Server:

1. Validates the payload is a permutation of the tarifa's current family set (missing/extra codes ⇒ reject, nothing saved).
2. Derives the resulting sibling order: each madre's children, in their first-appearance order within the flat list (hierarchy itself is **unchanged by drag**; reparenting happens only via promote/demote/edit — see below).
3. Renumbers `capitulo` per sibling group (prefix = madre's chapter; `renumber_siblings_group` logic from `tarif_familias.php:459-501` is the seed, moved into a reusable, testable service — the stack-algorithm from the view's `recalculateLevels` view:414-440 dies with the jQuery code).
4. Responds with a **tbody fragment** (rows re-rendered with new chapters) + flash payload; **no `location.reload()`**.
- `get_next_capitulo` becomes an htmx GET swapping a preview span (HTML-first; no JSON envelope — decision "no new JSON-envelope hand-rolling").
- `promote`/`demote` stay (they are the server-authoritative reparenting tools) but respond with row/tbody fragments + flash instead of JSON+reload. Toggles (`toggle_catalogo/en_tarifa/activa`) respond with the **single-row fragment** (`hx-target="closest tr"`, `hx-swap="outerHTML"`) — kills the client-side class juggling at view:288-358.
- **Tree-drag UX recommendation: lightweight sibling-scoped drag (acceptable and recommended for the pilot).** SortableJS on the tbody with an `onMove` guard rejecting drops across `data-madre` boundaries; drag handle per row; descendants never move implicitly. Full group drag (parent + descendants) is a *follow-up*: the endpoint contract does not change (flat codes still suffice because a block move keeps children inside the parent's region and the server renumbers) — only the client `onMove` guard and descendant re-insertion (today's jQuery `start/stop` logic at view:388-407, reimplemented for SortableJS) would be added. Recommend NOT building group drag now: it is the single largest client complexity, and promote/demote/edit-modal already cover every reparenting path.

> **Open product decision (genuine fork — see §8):** the confirmed wording "client sends only flat order; server recomputes madre/nivel/capitulo" is only unambiguous if drag **cannot change parentage** (flat codes alone cannot express re-nesting). Recommended reading: reorder permutes sibling order; madre changes exclusively via promote/demote/edit. If the product expects drag-reparenting parity with today's screen (dragging a parent under another family re-parents it — current view:414-440 does exactly that), the flat-codes contract is insufficient and needs either an explicit `new-madre` field for the dragged node or nesting-by-indentation convention. Needs confirmation at proposal phase.

### 4.6 Template organization (Q6)

- **Core/theme (reusable):**
  - `themes/AdminLTE/view/Macro/HtmxCrud.html.twig` — list macro (table shell, column config, row action buttons, hx attributes with explicit `action`/`method` fallbacks), toolbar button macros, flash container markup, boot line for `htmx-crud.js`, Sortable init block. Imports the existing `Macro/Htmx.html.twig` and `Macro/Alpine.html.twig` boot macros (HCS-04/ABS-02 intact: the plugin view imports once).
  - `view/js/htmx-crud.js` — flash listener, `X-FS-*` footer updater, reorder serializer (flat codes from Sortable DOM), `onMove` same-madre guard. Nonce'd; no inline handlers anywhere.
  - Optional shared partial for flash markup used by the OOB secondary channel (theme `partials/`), **not** included by header.html.twig.
- **Plugin (screen-specific):** `plugins/catalogo_core/View/partials/familias/familia_row.html.twig` + `familia_rows.html.twig` (tbody fragment; row template reused by both page and fragments — single definition, no duplication), page shell `tarif_familias.html.twig` (imports Htmx/Alpine/HtmxCrud macros, declares config), Excel modals untouched.
- **Pilot declaration sketch:**

```twig
{# tarif_familias.html.twig (page shell, pilot) #}
{% import 'Macro/HtmxCrud.html.twig' as crud %}
{% import 'Macro/Htmx.html.twig' as htmx %}
{% import 'Macro/Alpine.html.twig' as alpine %}
{% include 'header.html.twig' %}
{{ htmx.boot({'allowScriptTags': false}) }}{{ alpine.boot() }}
{{ crud.flash_container() }}
{{ crud.toolbar(fsc, {...}) }}
{{ crud.table('familias', {
    rows: fsc.get_familias_flat(),
    rowPartial: 'partials/familias/familia_row.html.twig',
    sortable: { id: 'familias-tbody', url: fsc.url() ~ '&action=reorder', guard: 'same-madre' },
    columns: [...],
}) }}
{% include 'partials/familias/modal_editar_familia.html.twig' %}
...
{% include 'footer.html.twig' %}
```

```php
// controller/tarif_familias.php (fragment endpoints)
case 'toggle_activa':
    $this->toggleActiva();                                   // existing logic, unchanged
    $this->renderRowFragment($this->tarifa_familia->get($this->codtarifa, $cod)); // replaces echo json_encode
    return;
case 'reorder':
    $this->reorderServerAuthoritative($flatCodesFromPost);   // validates permutation, renumbers
    $this->renderFragment('partials/familias/familia_rows.html.twig', [...]);
    return;
```

### 4.7 Spec IDs (Q7)

- **Extend `openspec/specs/htmx-core-support/spec.md`** (same domain, same contracts it already owns): `HCS-13` (flash bridge: HX-Trigger payload of `fs_core_log` channels; header/footer untouched), `HCS-14` (fragment response contract: partial render, template=false, flash auto-attach), `HCS-15` (`X-FS-*` footer headers + OOB debug tier under FS_DEBUG/FS_DB_HISTORY), `HCS-16` (`htmx-crud.js` as opt-in module, loaded only via the HtmxCrud macro).
- **New spec section/file for the methodology**: `openspec/specs/htmx-crud/spec.md` with `CRD-01..` (declarative base API, opt-in/byte-identical guarantees, fragment endpoint conventions, SortableJS ordering, server-authoritative reorder contract incl. permutation validation and renumbering). Rationale: HCS file governs htmx *core support*; the CRUD methodology is a distinct reusable capability that other plugins will consume — cleaner cross-references, no HCS renumbering churn.
- Resumable.js CDN removal (view:743) → **follow-up note only** (core already owns `base/fs_chunked_upload.php` + vendoring policy); do not bundle into this slice.

### 4.8 Risks (Q8)

| Risk | Severity | Mitigation |
|---|---|---|
| CSP nonce + Alpine CSP build: `Alpine.data()` registration must happen from a nonce'd script *before* Alpine init (defer ⇒ use `alpine:init` listener or post-load hook); no `eval` anywhere | Medium | Register from `htmx-crud.js` (nonce'd) via `alpine:init`; verify against SecurityHeaders nonce plumbing; add smoke scenario |
| `<tr>` fragment parsing under htmx 4 (swapping `tr`/`tbody` fragments) | Medium | Use tbody-level swaps where possible; explicit verify scenario for `hx-swap="outerHTML"` on `<tr>` in the pilot's browsers |
| CSRF token shadowing: `validateCsrf()` prefers form field over header (fs_controller.php:391-397) — a stale embedded `csrf_field()` in an hx-post form would shadow the fresh `X-CSRF-TOKEN` header | High | Methodology rule: hx-post forms MUST NOT embed `csrf_field()`; inherited header is the token source (HCS-05). Add to CRD spec + verify scenario |
| `\|raw` on flash content: payloads are `sanitizeForDisplay` output; any future renderer bypassing it would open XSS | Medium | Bridge reads only via `get_*()` accessors (sanitized by construction); document invariant in HCS-13 |
| htmx not booted (view forgot macro import): `hx-post` buttons become inert (no fallback submit) | Medium | Macro methodology emits real `action`/`method` on forms so graceful full-POST degradation exists; CRD scenario "macro missing ⇒ standard form semantics" |
| Byte-identical regression on non-opted views | Low | No header/footer/global edits; all changes gated behind macro imports + `isHtmxRequest()` branches; existing HCS-09-style verification |
| `duplicated_petition` interplay: rapid hx clicks can double-fire mutations | Low | Adopt `duplicated_petition` guard in the reorder/toggle endpoints (pattern exists in fs_edit_controller.php:93) |
| Pagination/search interplay: pilot has no pagination, but the declarative base will be reused by paginated lists | Medium | Keep filter/pagination state in hx-vals/URL params (`codtarifa`, `b_solo_activas` preserved via `hx-include`); defer full pagination support to a later CRD requirement — pilot ships without it |
| `ORDER BY capitulo` as sort key (model:371,392,498-504): chapter strings like `2.10` vs `2.9` sort lexically | Low | Already the status quo; reorder renumbering preserves single-segment-per-level `prefix.N` so lexical order matches; note in design |
| DebugBar/feed duplication: `fs_core_log::log()` feeds DebugBar per entry (fs_core_log.php:510-519) — OOB re-render must not double-log | Low | OOB tier re-renders the static `DebugBar` state; no new `addLog` calls |

## 5. Estimated Scope (Q9)

| Slice (chained PR order) | Contents | ~Changed lines (add+del) |
|---|---|---|
| PR-1 Core fragment + flash infra | `src/Controller/HtmxCrudController.php` (~200), fragment/flash helper (~100), `view/js/htmx-crud.js` (~150), PHPUnit (~200) | **~650–900** |
| PR-2 Core macros | `Macro/HtmxCrud.html.twig` (~250), Sortable init, toolbar/flash containers | **~250–350** |
| PR-3 Pilot list + CRUD + toggles | controller rewrite of actions/toggles/save (net −JSON/+fragments), view shell, row partials, TarifarioComponents trim | **~700–900** |
| PR-4 Server-authoritative reorder | reorder service + tests, Sortable wiring, promote/demote fragment responses | **~350–500** |
| **Total** | | **~2.0–2.7k** |

**Review-budget guard (per §E of the phase protocol):**

```text
Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High
```

Rationale: even the smallest slice exceeds 400 lines; delivery strategy is `ask-on-risk`, so the orchestrator must confirm the chained-PR plan (4 slices above, each with clear start/finish/verification/rollback) or an explicit size exception before `sdd-apply`.

## 6. Answers Index (questions 1–9)

1. Fragment mechanism — §4.1 (controller-owned, PSR-4 helper, template=false + echo; no index.php change; legacy-plugin PSR-4 usage verified).
2. Declarative base — §4.2 (`FSFramework\Controller\HtmxCrudController extends \fs_controller`; addView-equivalent fluent config; rowPartial/fragment helpers; usable from legacy and PSR-4 controllers).
3. Message bridge — §4.3 (HX-Trigger `fs:flash` with sanitized channel arrays; `htmx-crud.js` consumer; header.html.twig stays source of truth for full page; OOB secondary channel optional; FSAjaxLoader screens adopt optionally, no breakage).
4. SQL/duration/DebugBar — §4.4 (X-FS-* headers always + OOB debug tier under FS_DEBUG/FS_DB_HISTORY).
5. Reorder — §4.5 (flat codes in; permutation validation; server renumber via reworked `renumber_siblings_group`; toggles/promote/demote return fragments; sibling-scoped drag recommended, group drag follow-up; get_next_capitulo becomes hx-get preview swap).
6. Templates — §4.6 (theme `Macro/HtmxCrud.html.twig` + `view/js/htmx-crud.js` core; plugin owns row partials + declaration; sketch included).
7. Spec IDs — §4.7 (HCS-13..16 extend htmx-core-support; new `htmx-crud` spec with CRD-01..).
8. Risks — §4.8 (CSRF field-shadowing is the top one).
9. Estimate — §5 (2.0–2.7k lines; 4 chained slices; budget risk High).

## 7. Open Product Decisions

1. **Drag-reparenting semantics (blocks proposal detail, not direction).** Confirm that reorder = structure-preserving (sibling order only) with promote/demote/edit as the reparenting paths — the only reading under which "flat codes only" is unambiguous. If drag-reparent parity is required, the reorder payload needs an explicit addition (e.g. `dropped-under` for the dragged node) — still server-authoritative, but a contract change.
2. **Confirm dialogs**: replace bootbox with native `hx-confirm` on htmx actions (recommended; bootbox stays for untouched Excel modals), or keep bootbox everywhere for visual consistency during the transition.

## 8. Follow-ups (explicitly out of scope)

- `fsframework-htmx-crud` skill + cross-reference in `fsframework-plugin-scaffold` (decision #7 — after pilot succeeds).
- Resumable.js CDN removal → vendored chunked upload per core `base/fs_chunked_upload.php`.
- FSAjaxLoader flash adoption on legacy JSON screens.
- Full group-drag (parent + descendants) behind the unchanged reorder contract.
