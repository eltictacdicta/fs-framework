# Proposal: htmx4-core-adoption

**Change root**: `openspec/changes/htmx4-core-adoption/` (core).

## Intent

Screens are server-rendered Twig with jQuery fetch-and-swap glue; the pilot already runs the fragment pattern server-side (`$this->template` swaps), a ~610-line JS tax in `catalogo-main.js`. The framework needs an opt-in partial-update convention leaving jQuery/Bootstrap 3 untouched.

## Why now / why htmx (not Symfony UX)

htmx 4.0.0 (2026-08-28; Context7 `/bigskysoftware/htmx/v4.0.0`) formalizes the pilot's fragment flow: ~14KB gzipped, no build step, XHR→fetch, explicit inheritance (`hx-headers:inherited` for CSRF headers), 4xx/5xx swapped by default with per-status `hx-status`, events renamed (`htmx:config:request`). Symfony UX needs a build pipeline, JSON fragments, and view rewiring; htmx costs one vendored file and one macro. Staged, backwards-compatible: htmx never enters global header/footer; legacy screens stay untouched.

## Scope

### In Scope

1. **Vendoring**: `"htmx.org": "^4.0.0"` + `build.sh` copy to `view/js/htmx.min.js` (npm path unverified — direct-vendor fallback).
2. **Opt-in macro** `themes/AdminLTE/view/Macro/Htmx.html.twig`: nonce'd script tag + inherited `hx-headers` (`X-CSRF-TOKEN` from `csrf_meta()`); one import per view.
3. **Helper** `fs_controller::isHtmxRequest()` mirroring `isAjax()`. No `CsrfManager` change — `X-CSRF-TOKEN` header already accepted; GET fragments need no token.
4. **Pilot** (`plugins/tarifario/`, GET fragments only): lazy-load rows, view-mode/sort refetch, load-more (`hx-swap="beforeend"`), filters; delete dead `is_htmx_request()`; afterSwap shim keeps `initInlineEdit`/`initSortable`; trim `catalogo-main.js` fetch logic.
5. **Tests** (strict TDD; `ddev exec php vendor/bin/phpunit`): helper unit, macro contract, plugin regression (`tests/Base/`, `tests/Core/`, `plugins/tarifario/tests/`).

### Out of Scope

Alpine 3, SortableJS, Bootstrap 5, htmx POST/JSON flows; global header/footer changes; global default.

## Capabilities

### New Capabilities
- `htmx-core-support`: vendoring, opt-in include convention, HX detection, CSRF via inherited headers, backwards-compat guarantee.
- `tarifario-catalog-htmx-pilot`: hx-attribute GET fragment flows, afterSwap shim, regression coverage.

### Modified Capabilities
None — `login-csrf`, `catalog-page-views` requirement-unchanged.

## Approach

Staged, backwards-compatible: core first, pilot second.

## Affected Areas

| Area | Impact |
|---|---|
| `package.json`, `build.sh`, `view/js/htmx.min.js` | New/Modified |
| `themes/AdminLTE/view/Macro/Htmx.html.twig`, `base/fs_controller.php`, `plugins/tarifario/`, tests | New/Modified |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| npm path unverified | Medium | Verify first; vendor fallback |
| jQuery rebind lost on swaps | Medium | afterSwap shim; GET-only pilot |
| CSP violations | Low | `csp_nonce_attr()` everywhere |
| 4xx/5xx swap inside `<tr>` | Low | Keep error-row fragments |

Budget: `sdd-tasks` MUST forecast vs 400-line budget; chain PRs if High.

## Rollback Plan

All additive/isolated: revert pilot + JS trim, remove macro imports, delete `htmx.min.js` + build lines, drop `isHtmxRequest()`; no legacy screen depends on it.

## Dependencies

`htmx.org` 4.x npm (fallback: direct vendor); DDEV.

## Success Criteria

- [ ] `htmx.min.js` vendored; global header/footer untouched
- [ ] Helper unit, macro contract, plugin regression green
- [ ] Pilot GET flows via hx attributes; jQuery keeps POST toggles, inline edit, sortable, exports
- [ ] `ddev exec php vendor/bin/phpunit` green; diff within budget
