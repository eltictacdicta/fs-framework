# Tasks: htmx4-core-adoption

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~630 authored (~380 tests, ~250 prod); `htmx.min.js` excluded (generated) |
| Suggested split | PR 1 core enablement (~250) → PR 2 tarifario pilot (~380) |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|---------------------|----------------|-------------------|
| 1 | Core enablement | PR 1 | `phpunit --filter Htmx` | `./build.sh`; HCS-09 smoke | Revert core files + `package.json`/`build.sh` |
| 2 | Tarifario pilot | PR 2 | `phpunit --filter TarifCatalogoHtmx` | Catalog smoke (expand/load-more/filter) | Revert pilot files (JS isolated) |

Strict TDD: RED→GREEN per unit. Runner: `ddev exec php vendor/bin/phpunit`.

## Phase 1: Asset Provenance (D1, HCS-01/02)

- [x] 1.1 Run `npm install htmx.org@4.0.0`; verify `node_modules/htmx.org/dist/htmx.min.js` (read-only) exists (0BSD). If unresolvable: HCS-02 direct-vendor fallback (commit file + license header; drop `package.json` htmx entry; document).
- [x] 1.2 Add `"htmx.org": "^4.0.0"` to `package.json`; add `cp node_modules/htmx.org/dist/htmx.min.js view/js/` to `build.sh` before cleanup. Est: +2.

## Phase 2: isHtmxRequest() (D3, HCS-06)

- [x] 2.1 RED: create `tests/Base/FsControllerHtmxTest.php` — `Kernel::boot()` + anonymous `fs_controller` subclass (no DB, `Kernel::request()`); assert present→true, absent→false, any value→true (presence-based). Est: +90.
- [x] 2.2 GREEN: add `isHtmxRequest(): bool` to `base/fs_controller.php` beside `isAjax()` (:473): `$this->request->headers->get('HX-Request') !== null`. Est: +14.

## Phase 3: Boot Macro (D2/D7, HCS-03/04/05)

- [x] 3.1 RED: create `tests/Core/HtmxMacroContractTest.php` — `FilesystemLoader` on `themes/AdminLTE/view` (read-only), stub `csrf_token`/`csp_nonce_attr`; render import+`boot()`; assert one `<script src="view/js/htmx.min.js"` (nonce, `defer`); bootstrap sets `hx-headers:inherited` on `documentElement` = `{"X-CSRF-TOKEN": <token>}`; `header.html.twig`/`footer.html.twig` (read-only) htmx-free. Est: +115.
- [x] 3.2 GREEN: create `themes/AdminLTE/view/Macro/Htmx.html.twig` — `boot()`: nonce'd script tag + inline bootstrap setting `hx-headers:inherited` on `document.documentElement` pre-init. Est: +30.

## Phase 4: Pilot Templates + JS (D4a-D4c/D5, TCP-01..07)

- [ ] 4.1 RED: create `plugins/tarifario/tests/Controller/TarifCatalogoHtmxContractTest.php` (DB-free; pattern per `TarifTabPreciosTest.php` (read-only)): view renders macro import+boot, `id="catalogo-main-region"`; headers carry `hx-get`, `hx-trigger="click[!this.classList.contains('expanded')]"`, `hx-target="#tbody-{codfamilia}"`, `hx-swap="innerHTML"`; load-more rows `hx-swap="beforeend"` in both fragment templates; TCP-05 parity (`htmx_articulos`/`htmx_articulos_agrupados`). Est: +90.
- [ ] 4.2 GREEN: edit `plugins/tarifario/View/tarif_catalogo_view.html.twig` (import+boot, region id, header hx attrs), `plugins/tarifario/View/partials/catalogo/toolbar.html.twig` (filters: `hx-boost="true"` + `hx-push-url="true"` + name attrs, TCP-04), `plugins/tarifario/View/tarif_catalogo_articulos.html.twig` and `tarif_catalogo_articulos_agrupados.html.twig` (load-more → hx attrs, drop onclick). Est: +42/-14.
- [ ] 4.3 RED: extend contract test — `plugins/tarifario/View/js/catalogo/catalogo-main.js` has no `loadArticulos`/`loadMoreArticulos` fetches; has `htmx.ajax` view-mode/sort fan-out + v4 `htmx:after:swap`/`htmx:after:request` listeners (`evt.detail.ctx`); jQuery POST/inline-edit/sortable/export handlers intact (TCP-06). Est: +70.
- [ ] 4.4 GREEN: edit `plugins/tarifario/View/js/catalogo/catalogo-main.js` — delete `loadArticulos` (:175-218), `loadMoreArticulos` (:401-417), filter navigations (:54-98); add D4b/D5 bridge+shim: `htmx.ajax` fan-out, re-init `initInlineEdit`/`initSortable` on `evt.detail.ctx.target`, load-more cleanup, view-mode sync, double-bind guards (`.off().on()`, `data-ui-sortable`). Est: +50/-95.

## Phase 5: Controller Cleanup (TCP-08)

- [ ] 5.1 RED: extend contract test — `plugins/tarifario/controller/tarif_catalogo_view.php`: no `is_htmx_request` definition/call; HX detection delegates to `fs_controller::isHtmxRequest()`. Est: +15.
- [ ] 5.2 GREEN: remove dead `is_htmx_request()` (`plugins/tarifario/controller/tarif_catalogo_view.php:589-592`). Est: -5.

## Phase 6: Verification (HCS-01/03, TCP-09/10)

- [x] 6.1 `./build.sh`: `view/js/htmx.min.js` present with `4.0.0` marker, prior steps succeed; stage vendored asset (repo versions `view/js/`).
- [ ] 6.2 Full suite `ddev exec php vendor/bin/phpunit` green — TCP-09; error rows untouched per D6 (read-only `tarif_catalogo_view.php:744-747,849-853`).
