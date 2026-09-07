# Delta for htmx-core-support

Adds the server-side fragment half of htmx adoption (HCS-13..16): flash bridge over `HX-Trigger`, fragment response contract, debug headers with gated OOB tiers, and the opt-in `htmx-crud.js` module. No existing requirement (HCS-01..12, ABS-01..03) is modified or removed.

## ADDED Requirements

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
