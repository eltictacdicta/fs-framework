# htmx-core-support Specification

## Purpose

Opt-in htmx 4 partial-update support in core: a vendored browser asset, a per-view include macro, an HX-Request detection helper, and CSRF integration via inherited headers. htmx never loads globally; jQuery/Bootstrap 3 screens are unaffected.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| HCS-01 | The htmx 4.0.0 asset MUST be available at `view/js/htmx.min.js`, vendored through `build.sh` from an npm dependency; `package.json` MUST pin the version (`htmx.org: ^4.0.0`). | MUST |
| HCS-02 | If the npm dist path for htmx 4 cannot be resolved, the vendoring contract MUST be satisfied via the documented direct-vendor fallback (commit `htmx.min.js` with license header); `package.json` MUST then drop the htmx entry. | MUST |
| HCS-03 | Global `header.html.twig` and `footer.html.twig` MUST NOT reference htmx by any mechanism. | MUST |
| HCS-04 | htmx MUST load only through the theme macro `themes/AdminLTE/view/Macro/Htmx.html.twig`, imported once per opting-in view; there MUST be no global default. | MUST |
| HCS-05 | The macro MUST emit one `<script>` tag for `view/js/htmx.min.js` carrying `{{ csp_nonce_attr() }}`, plus a root-level inherited `hx-headers` bootstrap carrying `X-CSRF-TOKEN` sourced from `csrf_meta()`. | MUST |
| HCS-06 | `fs_controller` MUST provide `isHtmxRequest()` reading the `HX-Request` header, mirroring `isAjax()` semantics (true iff the header is present). | MUST |
| HCS-07 | htmx POSTs MUST validate CSRF through the existing `validateCsrf()` flow using the `X-CSRF-TOKEN` header; `CsrfManager` MUST NOT change and no new token mechanism MAY be introduced. | MUST |
| HCS-08 | GET fragment requests MUST NOT require a CSRF token. | MUST |
| HCS-09 | A view that does not import the macro MUST render exactly as before this change: no htmx script, no hx bootstrap, no hx-driven behavior. | MUST |

### Requirement: HCS-01 — Vendored htmx asset via build pipeline

The htmx 4.0.0 asset MUST be available at `view/js/htmx.min.js`, vendored through `build.sh` from the npm dependency `htmx.org` pinned in `package.json` (`^4.0.0`).

#### Scenario: build.sh vendors htmx from npm

- **GIVEN** `package.json` pins `htmx.org ^4.0.0`
- **WHEN** `./build.sh` runs the npm install and asset copy steps
- **THEN** `view/js/htmx.min.js` exists matching the pinned htmx 4.x release
- **AND** all pre-existing asset copy steps still succeed

### Requirement: HCS-02 — Direct-vendor fallback when npm path unresolvable

If the npm dist path for htmx 4 cannot be resolved, the vendoring contract MUST be satisfied via the documented direct-vendor fallback (commit `htmx.min.js` with license header); `package.json` MUST then drop the htmx entry.

#### Scenario: npm dist path mismatch falls back to direct vendor

- **GIVEN** the npm package does not expose `dist/htmx.min.js` at the expected path
- **WHEN** the copy step cannot produce the asset
- **THEN** `view/js/htmx.min.js` is provided by a committed direct-vendor file with license header
- **AND** the fallback method is documented and `package.json` no longer declares htmx
- **AND** HCS-01's availability contract still holds

### Requirement: HCS-03 — Global header/footer free of htmx

Global `header.html.twig` and `footer.html.twig` MUST NOT reference htmx by any mechanism.

#### Scenario: global header/footer untouched

- **GIVEN** the change is applied
- **WHEN** `themes/AdminLTE/view/header.html.twig` and `footer.html.twig` are inspected
- **THEN** no htmx reference exists
- **AND** every legacy screen loads the same assets as before

### Requirement: HCS-04 — Opt-in macro as the only load path

htmx MUST load only through the theme macro `themes/AdminLTE/view/Macro/Htmx.html.twig`, imported once per opting-in view; there MUST be no global default.

### Requirement: HCS-05 — Macro emits nonce'd script and inherited CSRF headers

The macro MUST emit one `<script>` tag for `view/js/htmx.min.js` carrying `{{ csp_nonce_attr() }}`, plus a root-level inherited `hx-headers` bootstrap carrying `X-CSRF-TOKEN` sourced from `csrf_meta()`.

#### Scenario: macro import emits nonce'd script and CSRF headers

- **GIVEN** a view imports `Macro/Htmx.html.twig` and calls the include (this also exercises HCS-04's opt-in path)
- **WHEN** the page renders
- **THEN** exactly one htmx script tag appears, with a nonce attribute from `csp_nonce_attr()`
- **AND** an inherited `hx-headers` bootstrap carries `X-CSRF-TOKEN` with the value served by `csrf_meta()`

### Requirement: HCS-06 — HX-Request detection helper on fs_controller

`fs_controller` MUST provide `isHtmxRequest()` reading the `HX-Request` header, mirroring `isAjax()` semantics (true iff the header is present).

#### Scenario: isHtmxRequest detects the header

- **GIVEN** `$_SERVER['HTTP_HX_REQUEST'] = 'true'`
- **WHEN** `isHtmxRequest()` is called
- **THEN** it returns true
- **AND** it returns false when the header is absent

### Requirement: HCS-07 — htmx POSTs validate CSRF through the existing flow

htmx POSTs MUST validate CSRF through the existing `validateCsrf()` flow using the `X-CSRF-TOKEN` header; `CsrfManager` MUST NOT change and no new token mechanism MAY be introduced.

#### Scenario: htmx POST validates CSRF via inherited header

- **GIVEN** a macro-importing view issues an htmx POST
- **WHEN** the request carries the valid `X-CSRF-TOKEN` header
- **THEN** `validateCsrf()` passes and the action executes
- **AND** an invalid or missing token follows the existing POST rejection path with no data persisted

### Requirement: HCS-08 — GET fragments need no CSRF token

GET fragment requests MUST NOT require a CSRF token.

#### Scenario: GET fragment needs no token

- **GIVEN** an htmx GET to a fragment action
- **WHEN** the controller runs
- **THEN** the request proceeds without CSRF validation, as non-POST requests do today

### Requirement: HCS-09 — Non-importing views render byte-identically

A view that does not import the macro MUST render exactly as before this change: no htmx script, no hx bootstrap, no hx-driven behavior.

#### Scenario: page without macro import unchanged

- **GIVEN** a legacy view that does not import the macro
- **WHEN** the page renders
- **THEN** output contains no htmx script, no hx-headers bootstrap, and no hx-driven behavior
- **AND** rendering is identical to pre-change output

---

## Parallel infrastructure: Alpine.js CSP boot macro

Opt-in Alpine.js 3 (CSP build) boot support, mirroring the htmx macro pattern above (Phase 5, jQuery migration plan). Infrastructure only: no view ships Alpine directives yet.

| ID | Requirement | Strength |
|----|-------------|----------|
| ABS-01 | The Alpine.js CSP build (`@alpinejs/csp`, exact version pinned in `package.json`) MUST be available at `view/js/alpine-csp.min.js`, vendored through `build.sh` from the npm dependency. | MUST |
| ABS-02 | Alpine MUST load only through the theme macro `themes/AdminLTE/view/Macro/Alpine.html.twig`, imported once per opting-in view; there MUST be no global default, and `header.html.twig`/`footer.html.twig` MUST NOT reference Alpine. | MUST |
| ABS-03 | The macro MUST emit exactly one `<script>` tag for `view/js/alpine-csp.min.js` carrying `{{ csp_nonce_attr() }}` and `defer`; the asset MUST be the CSP build (no `eval`/`new Function`). | MUST |

### Requirement: ABS-01 — Vendored Alpine CSP asset via build pipeline

The Alpine CSP build MUST be available at `view/js/alpine-csp.min.js`, vendored through `build.sh` from the npm dependency `@alpinejs/csp` pinned to an exact version in `package.json`.

#### Scenario: build.sh vendors the Alpine CSP build from npm

- **GIVEN** `package.json` pins `@alpinejs/csp` to an exact 3.x version
- **WHEN** `./build.sh` runs the npm install and asset copy steps
- **THEN** `view/js/alpine-csp.min.js` exists matching the pinned release
- **AND** the asset is the CSP build: it contains no `eval` or `new Function` calls

### Requirement: ABS-02 — Opt-in macro as the only load path

Alpine MUST load only through the theme macro `themes/AdminLTE/view/Macro/Alpine.html.twig`, imported once per opting-in view; there MUST be no global default.

#### Scenario: global header/footer stay Alpine-free

- **GIVEN** the macro exists
- **WHEN** `header.html.twig` and `footer.html.twig` are inspected
- **THEN** no Alpine reference exists
- **AND** views that do not import the macro render without any Alpine script

### Requirement: ABS-03 — Macro emits one nonce'd, deferred script tag

The macro MUST emit exactly one `<script>` tag for `view/js/alpine-csp.min.js` carrying `{{ csp_nonce_attr() }}` and `defer`.

#### Scenario: macro import emits the nonce'd deferred script

- **GIVEN** a view imports `Macro/Alpine.html.twig` and calls `alpine.boot()`
- **WHEN** the page renders
- **THEN** exactly one Alpine script tag appears, with a nonce attribute and `defer`
- **AND** the macro emits no inline bootstrap and no inline event handlers

---

## Pilot: admin_user extension tabs on htmx (jQuery migration plan)

First real htmx adoption: the `admin_user` extension tabs migrate from FSAjaxLoader (`data-ajax-url` + `shown.bs.tab`) to declarative htmx, fully client-side, zero server changes. The server keeps answering full pages; the fragment is selected client-side.

| ID | Requirement | Strength |
|----|-------------|----------|
| HCS-10 | Extension tab links in `admin_user.html.twig` MUST load their pane via htmx GET reusing the exact legacy URL (including `&ajax=1`), with `hx-trigger="click once"` (replicates the load-once guard), `hx-target` pointing at the pane, explicit `hx-swap="innerHTML"`, `data-toggle="tab"` preserved (Bootstrap still switches the panel), and MUST NOT keep `data-ajax-url` (FSAjaxLoader's `shown.bs.tab` handler would double-load). | MUST |
| HCS-11 | `hx-select` MUST be a single selector replicating FSAjaxLoader's *effective* extraction on this theme: `.content-wrapper > *`. Comma lists in `hx-select` are applied as `querySelectorAll` (all matches, document order → wrapper plus nested matches duplicated); the shell has no `.content` element, so the FSAjaxLoader primary selector `.content-wrapper .content` matches nothing and would clear the pane. | MUST |
| HCS-12 | Views whose swapped fragments may carry scripts MUST boot htmx with `boot({'allowScriptTags': false})`; the macro MUST then emit the `htmx-config` meta (inert under htmx 4.0.0, which dropped `allowScriptTags`) plus a nonce'd `htmx:before:swap` scrubber mirroring `FSAjaxLoader.sanitizeHtml` (removes script/object/embed/applet/iframe, `on*` handlers, `javascript:` URLs before insertion). The zero-argument `boot()` output MUST remain byte-identical to HCS-05. | MUST |

### Requirement: HCS-10 — Extension tabs load via htmx with URL parity

Extension tab links in `admin_user.html.twig` load their pane declaratively with htmx, reusing the legacy extension URL unchanged (zero server changes).

#### Scenario: extension tab click loads pane once via htmx

- **GIVEN** `admin_user.html.twig` is rendered with tab-type extensions
- **WHEN** the page is inspected
- **THEN** each extension tab link carries `hx-get` with the legacy URL (including `&ajax=1`), `hx-target="#ext_{name}"`, `hx-select`, `hx-swap="innerHTML"`, `hx-trigger="click once"`, and keeps `data-toggle="tab"`
- **AND** no `data-ajax-url` attribute remains on those links

### Requirement: HCS-11 — Fragment selection mirrors effective FSAjaxLoader extraction

`hx-select` uses one selector, `.content-wrapper > *`, matching what `extractMainContent` actually extracted on this theme (its primary `.content-wrapper .content` never matches; the fallback `.content-wrapper` did).

#### Scenario: swapped fragment is the wrapper's children, not duplicates

- **GIVEN** htmx receives a full-page response for an extension tab
- **WHEN** the fragment is selected with `hx-select=".content-wrapper > *"`
- **THEN** exactly the direct children of `.content-wrapper` are swapped into the pane
- **AND** no duplicated wrapper/nested matches occur (a comma list would match all of `.content-wrapper`, `.container-fluid`, `body`, … at once)

### Requirement: HCS-12 — Script sanitization parity via the macro config form

The macro's optional config reproduces FSAjaxLoader's strip-scripts posture; htmx 4.0.0 does not implement `allowScriptTags`, so enforcement is the emitted scrubber, not the meta.

#### Scenario: configured boot emits meta plus scrubber before the asset

- **GIVEN** a view calls `htmx.boot({'allowScriptTags': false})`
- **WHEN** the page renders
- **THEN** a `htmx-config` meta with `{"allowScriptTags": false}` and a nonce'd `htmx:before:swap` scrubber appear before the htmx asset
- **AND** the zero-argument `boot()` output is byte-identical to HCS-05

---

| ID | Requirement | Strength |
|----|-------------|----------|
| HCS-13 | Flash bridge: `HX-Trigger` event `fs:flash`, payload exclusively from `fs_core_log` channels, CRLF-stripped, header/footer untouched, `\|raw` trust-model parity documented. | MUST |
| HCS-14 | Fragment response contract: `renderFragment`/`buildFragment`, `template=false` + echo, `Content-Type: text/html` + `nosniff`, 204 error-only path with no swap, `requireHtmx()` degradation, `buildFragment` pure and Twig-free. | MUST |
| HCS-15 | `X-FS-Duration`/`X-FS-Queries`/`X-FS-Transactions` headers on fragment responses always; OOB tiers gated: debug OOB only under `FS_DEBUG`/`FS_DB_HISTORY`, flash OOB opt-in and always `<template>`-wrapped. | MUST |
| HCS-16 | `view/js/htmx-crud.js` as opt-in module loaded ONLY via the HtmxCrud macro boot line; event map; CSRF strip at init + `htmx:afterSettle` so the header is the only token source on htmx requests. | MUST |

### Requirement: HCS-13 — Flash bridge over `HX-Trigger` with the `fs:flash` event

Fragment responses MUST deliver controller messaging as an `HX-Trigger` header carrying the `fs:flash` event whose payload contains exactly the keys `errors`, `messages`, `advices`, built exclusively from `fs_core_log::get_errors()`, `get_messages()` and `get_advices()` — the same sanitized trust model under which `header.html.twig` renders these values with `|raw`; this parity MUST be documented with the bridge. The serialized header value MUST have CRLF sequences stripped before emission. The header MUST be set on both 200 and 204 responses and MUST be omitted when the payload is empty. Global `header.html.twig` and `footer.html.twig` MUST remain untouched, and `new_message`/`new_error_msg`/`new_advice` remain the single messaging API.

#### Scenario: flash payload attached to a fragment response

- **GIVEN** an htmx fragment action that calls `$this->new_message('Saved')`
- **WHEN** the fragment is rendered
- **THEN** the response carries `HX-Trigger` with `{"fs:flash":{"errors":[],"messages":["Saved"],"advices":[]}}`
- **AND** the serialized header value contains no CR or LF bytes

#### Scenario: payload source is exclusively the fs_core_log channels

- **GIVEN** any fragment emission
- **WHEN** the flash payload is built
- **THEN** its values come only from `get_errors()`/`get_messages()`/`get_advices()` (already sanitized for display)
- **AND** the documented `|raw` trust-model parity with `header.html.twig` is stated in the bridge documentation

#### Scenario: empty payload omits the header and header/footer stay untouched

- **GIVEN** a fragment action that produced no messages
- **WHEN** the response is emitted
- **THEN** the `HX-Trigger` header is absent
- **AND** `header.html.twig` and `footer.html.twig` contain no flash-bridge markup or logic

### Requirement: HCS-14 — Fragment response contract (`template=false` + echo)

The fragment emitters on `HtmxCrudController` (`renderFragment`, `renderRowFragment`, `renderTbodyFragment`, `noContentWithFlash`) MUST respond by setting `$this->template = false` and directly echoing the fragment HTML — with zero changes to `index.php` and no global pipeline branching. Every fragment response MUST declare `Content-Type: text/html; charset=UTF-8` with `X-Content-Type-Options: nosniff`. Error-only outcomes MUST return `204 No Content` carrying only the flash header — htmx swaps nothing and the DOM stays untouched. `requireHtmx()` MUST return false on non-htmx requests so callers degrade gracefully (full-page PRG redirect) instead of rendering a fragment. `buildFragment()` MUST be pure — returning the fragment HTML and headers as data — and unit-testable without Twig.

#### Scenario: row fragment replaces the swap target

- **GIVEN** a successful toggle action
- **WHEN** `renderRowFragment($row)` runs
- **THEN** the response status is 200 with `Content-Type: text/html; charset=UTF-8` and `X-Content-Type-Options: nosniff`
- **AND** the echoed row HTML is the entire response body and no `index.php` change is involved

#### Scenario: error-only responses swap nothing

- **GIVEN** an action that fails validation
- **WHEN** `noContentWithFlash()` runs
- **THEN** the status is 204 with the `fs:flash` error payload and no body
- **AND** the client DOM is unchanged

#### Scenario: buildFragment is pure and Twig-free

- **GIVEN** fixture HTML produced without rendering a template
- **WHEN** `buildFragment($html, $options)` is called
- **THEN** it returns the fragment HTML and its headers (Content-Type, `X-FS-*`, flash) as data
- **AND** the test requires no Twig and no database

#### Scenario: non-htmx requests degrade

- **GIVEN** a request without the `HX-Request` header
- **WHEN** `requireHtmx()` is consulted before fragment emission
- **THEN** it returns false
- **AND** the caller performs a PRG redirect to the list URL instead of echoing a fragment

### Requirement: HCS-15 — `X-FS-*` debug headers always; OOB tiers gated

Every fragment response (200 and 204) MUST carry `X-FS-Duration`, `X-FS-Queries` and `X-FS-Transactions`. An OOB debug tier (SQL history / debug panel data) MAY be emitted only when `FS_DEBUG` or `FS_DB_HISTORY` is enabled — never otherwise. The flash OOB secondary channel MUST be opt-in (config `oobFlash(true)`), MUST be emitted only when the flash payload is non-empty, and its block MUST always be `<template>`-wrapped server-side (no top-level table child adjacent to a non-table sibling outside a template). A missing OOB target MUST NOT affect the main swap.

#### Scenario: X-FS-* headers always present

- **GIVEN** any fragment response, including 204s
- **WHEN** the response headers are inspected
- **THEN** `X-FS-Duration`, `X-FS-Queries` and `X-FS-Transactions` are present

#### Scenario: debug OOB is environment-gated

- **GIVEN** `FS_DEBUG` and `FS_DB_HISTORY` are disabled
- **WHEN** a fragment is emitted
- **THEN** no debug OOB content appears
- **AND** the debug OOB tier may appear only with one of those flags enabled

#### Scenario: flash OOB is opt-in, templated and payload-gated

- **GIVEN** `oobFlash(true)` and a non-empty flash payload
- **WHEN** the fragment is emitted
- **THEN** a `<template>`-wrapped OOB block targeting `#fs-htmx-flash` follows the main fragment
- **AND** with `oobFlash` off (default) or an empty payload, no OOB block appears

#### Scenario: missing OOB target is a client-side no-op

- **GIVEN** an OOB block whose target `#fs-htmx-flash` is not in the DOM
- **WHEN** htmx processes the response
- **THEN** a `htmx:oobErrorNoTarget` console warning is raised
- **AND** the main swap completes unaffected

### Requirement: HCS-16 — `htmx-crud.js` as opt-in module loaded only via the HtmxCrud macro

`view/js/htmx-crud.js` MUST be an opt-in core module loaded ONLY through the `Macro/HtmxCrud.html.twig` boot line (ordered, deferred, nonce'd, alongside SortableJS and `fs-dialogs.js`); it MUST NOT load globally and header/footer remain untouched. The module reads its behavior scope from the `data-fs-crud-config` JSON and MUST no-op when absent. Its event map MUST implement: `fs:flash` → transient toasts (errors→`alert-danger`, messages→`alert-success`, advices→`alert-info`); `fs:modal-close` → closes the containing Bootstrap modal; `htmx:afterRequest` → updates the footer debug labels from the `X-FS-*` response headers in place (no markup ids added); init and `htmx:afterSettle` → strip inputs matching `[data-fs-csrf-strip] input[name="_csrf_token"]`; `htmx:oobErrorNoTarget` → console warning. The CSRF strip contract guarantees the `X-CSRF-TOKEN` header is the only token source on htmx requests: embedded tokens exist solely for the no-htmx fallback and never reach a htmx request body.

#### Scenario: module loads only via the boot line

- **GIVEN** a view calling the HtmxCrud boot macro
- **WHEN** the page renders
- **THEN** `htmx-crud.js`, SortableJS and `fs-dialogs.js` load as ordered, deferred, nonce'd scripts
- **AND** a view that does not call the boot line loads none of them

#### Scenario: embedded CSRF inputs are stripped at init and after settle

- **GIVEN** a page or settled fragment containing a form with `data-fs-csrf-strip` and an embedded `_csrf_token` input
- **WHEN** module init or `htmx:afterSettle` runs
- **THEN** the embedded input is removed from the DOM
- **AND** the subsequent htmx request authenticates only via the `X-CSRF-TOKEN` header

#### Scenario: module no-ops without config

- **GIVEN** a page without the `data-fs-crud-config` JSON
- **WHEN** `htmx-crud.js` initializes
- **THEN** it binds nothing and changes no page behavior

#### Scenario: flash toasts and footer update

- **GIVEN** a response with a non-empty `fs:flash` payload and `X-FS-Queries: 11`
- **WHEN** the module handles the events
- **THEN** toasts render with the severity class per channel and the footer debug label reflects 11 queries, updated in place
