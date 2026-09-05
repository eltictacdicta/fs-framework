# tarifario-catalog-htmx-pilot Specification

## Purpose

First htmx adoption pilot in `plugins/tarifario/`: GET fragment flows of `tarif_catalogo_view` driven by hx attributes, with behavioral parity against the current `$.ajax` consumers, jQuery flows preserved, dead helper removed, and plugin regression tests.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| TCP-01 | Lazy-load of family rows MUST use hx attributes (`hx-get` to the existing fragment action, `hx-target` to the row body, `hx-swap="innerHTML"`); the replaced `$.ajax` fetch path (`loadArticulos`/`toggleFamilia` loading) MUST be removed from `catalogo-main.js`. | MUST |
| TCP-02 | View-mode refetch and sort refetch MUST be driven by `hx-get` requests carrying the same action and parameters as today's refetch. | MUST |
| TCP-03 | Load-more MUST append rows via `hx-swap="beforeend"` onto the load-more target rendered by the fragment template. | MUST |
| TCP-04 | Full-page filters (tarifa, familia, text) MUST use `hx-get` with URL push so URL and content update together. | MUST |
| TCP-05 | Responses for hx consumers MUST be identical to those for current `$.ajax` fragment consumers: same endpoints (`htmx_articulos` / `htmx_articulos_agrupados`), same query params, same fragment templates. | MUST |
| TCP-06 | JSON POST toggles, inline edit, jQuery UI sortable, and file exports MUST keep working through the existing jQuery code. | MUST |
| TCP-07 | An `htmx:afterSwap` re-init shim MUST restore `initInlineEdit`/`initSortable` bindings for swapped regions. | MUST |
| TCP-08 | The plugin's dead private `is_htmx_request()` MUST be removed; HX detection MUST delegate to `fs_controller::isHtmxRequest()`. | MUST |
| TCP-09 | Plugin regression tests in `plugins/tarifario/tests/` MUST cover the pilot flows and pass under `ddev exec php vendor/bin/phpunit`. | MUST |
| TCP-10 | Error responses swapped inside `<tr>` context MUST be well-formed error-row fragments, not unbalanced HTML. | MUST |

### Scenario: lazy-load family rows

- GIVEN the catalog view with an unexpanded family row
- WHEN the user activates the row trigger carrying `hx-get`, `hx-target="#tbody-{codfamilia}"`, `hx-swap="innerHTML"`
- THEN the request hits the same fragment action the old `$.ajax` used
- AND the fragment renders inside the row body with output identical to pre-change for the same parameters

### Scenario: view-mode and sort refetch

- GIVEN the user switches view mode or changes sort
- WHEN the `hx-get` refetch executes
- THEN the target region is replaced via the same fragment action and template as before
- AND the rendered fragment reflects the requested view mode and sort

### Scenario: load-more appends

- GIVEN a paginated fragment with its load-more row rendered
- WHEN the load-more trigger issues its `hx-get` with `hx-swap="beforeend"`
- THEN new rows are appended without removing existing rows
- AND each request advances pagination by exactly one page

### Scenario: full-page filter updates URL

- GIVEN the user applies a full-page filter
- WHEN the `hx-get` with URL push completes
- THEN the browser URL reflects the new filter parameters
- AND the page content reflects the filtered response

### Scenario: response parity with $.ajax consumers

- GIVEN identical parameters for the same fragment action
- WHEN an hx consumer and a legacy `$.ajax` consumer each request it
- THEN both receive the same fragment template rendered with the same data

### Scenario: jQuery flows preserved

- GIVEN the pilot is active
- WHEN the user uses JSON POST toggles, inline edit, jQuery UI sortable reorder, or file exports
- THEN each flow works exactly as before via existing jQuery handlers

### Scenario: afterSwap shim re-binds handlers

- GIVEN an innerHTML swap completed in a migrated region
- WHEN htmx fires `htmx:afterSwap`
- THEN the shim re-runs `initInlineEdit`/`initSortable` for the swapped region
- AND both work on the new rows

### Scenario: dead plugin helper removed

- GIVEN the pilot cleanup
- WHEN the plugin code is searched for `is_htmx_request`
- THEN no definition or call site remains
- AND controllers branch HX requests via `fs_controller::isHtmxRequest()`

### Scenario: regression suite green

- GIVEN pilot regression tests exist under `plugins/tarifario/tests/`
- WHEN `ddev exec php vendor/bin/phpunit` runs
- THEN the pilot tests pass
- AND no pre-existing test regresses

### Scenario: error fragment inside row context

- GIVEN a fragment action answers with an error row (4xx/5xx body)
- WHEN htmx swaps it into the `<tr>` target
- THEN the fragment is a well-formed error row
- AND the table structure stays valid with a readable error state
