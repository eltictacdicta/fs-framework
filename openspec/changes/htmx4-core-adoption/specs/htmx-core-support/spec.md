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

### Scenario: build.sh vendors htmx from npm

- GIVEN `package.json` pins `htmx.org ^4.0.0`
- WHEN `./build.sh` runs the npm install and asset copy steps
- THEN `view/js/htmx.min.js` exists matching the pinned htmx 4.x release
- AND all pre-existing asset copy steps still succeed

### Scenario: npm dist path mismatch falls back to direct vendor

- GIVEN the npm package does not expose `dist/htmx.min.js` at the expected path
- WHEN the copy step cannot produce the asset
- THEN `view/js/htmx.min.js` is provided by a committed direct-vendor file with license header
- AND the fallback method is documented and `package.json` no longer declares htmx
- AND HCS-01's availability contract still holds

### Scenario: global header/footer untouched

- GIVEN the change is applied
- WHEN `themes/AdminLTE/view/header.html.twig` and `footer.html.twig` are inspected
- THEN no htmx reference exists
- AND every legacy screen loads the same assets as before

### Scenario: macro import emits nonce'd script and CSRF headers

- GIVEN a view imports `Macro/Htmx.html.twig` and calls the include
- WHEN the page renders
- THEN exactly one htmx script tag appears, with a nonce attribute from `csp_nonce_attr()`
- AND an inherited `hx-headers` bootstrap carries `X-CSRF-TOKEN` with the value served by `csrf_meta()`

### Scenario: page without macro import unchanged

- GIVEN a legacy view that does not import the macro
- WHEN the page renders
- THEN output contains no htmx script, no hx-headers bootstrap, and no hx-driven behavior
- AND rendering is identical to pre-change output

### Scenario: isHtmxRequest detects the header

- GIVEN `$_SERVER['HTTP_HX_REQUEST'] = 'true'`
- WHEN `isHtmxRequest()` is called
- THEN it returns true
- AND it returns false when the header is absent

### Scenario: htmx POST validates CSRF via inherited header

- GIVEN a macro-importing view issues an htmx POST
- WHEN the request carries the valid `X-CSRF-TOKEN` header
- THEN `validateCsrf()` passes and the action executes
- AND an invalid or missing token follows the existing POST rejection path with no data persisted

### Scenario: GET fragment needs no token

- GIVEN an htmx GET to a fragment action
- WHEN the controller runs
- THEN the request proceeds without CSRF validation, as non-POST requests do today
