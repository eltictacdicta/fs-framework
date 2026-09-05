# Design: htmx4-core-adoption

## Technical Approach

Staged, backwards-compatible enablement then pilot, exactly as proposed. Verified at design time: `htmx.org@4.0.0` exists on npm, tarball ships `package/dist/htmx.min.js` (`main: dist/htmx.js`, license 0BSD), so the npm provenance path is confirmed and the direct-vendor fallback is contingency only. htmx 4 semantics pinned from v4.0.0 docs: inheritance is explicit (`hx-headers:inherited` applies to the carrier's subtree; implicit inheritance is off), events use colon names (`htmx:after:swap`, `htmx:config:request`), request context lives in `evt.detail.ctx`.

## Architecture Decisions

| # | Decision | Option | Tradeoff | Choice |
|---|----------|--------|----------|--------|
| D1 | Asset provenance (HCS-01/02) | npm+build.sh copy vs direct vendor commit | npm = same provenance/upgrade path as bootbox/jQuery; direct vendor = zero build churn but manual upgrades | **npm + build.sh copy** (path verified). Direct-vendor fallback stays documented in tasks |
| D2 | Macro contract (HCS-04/05) | wrapper `<div hx-headers:inherited>` vs root bootstrap | `:inherited` covers only the carrier's **subtree**; a macro cannot wrap page content, so a sibling div never ancestors the triggers | **Macro emits a nonce'd inline bootstrap script** that sets `hx-headers:inherited` on `document.documentElement` before htmx initializes (mirrors htmx's canonical `<html hx-headers:inherited>` CSRF pattern; survives body swaps), **plus** the `htmx.min.js` script tag. Wrapper div rejected |
| D3 | `isHtmxRequest()` (HCS-06) | raw `$_SERVER` read vs Symfony Request | Request style matches `isAjax()`/fs_controller conventions; superglobals work in tests via `Kernel::boot()` | **`$this->request->headers->get('HX-Request') !== null`** — presence check, value-insensitive, bool return |
| D4a | Lazy-load + load-more (TCP-01/03) | hx attributes on header/load-more button | static, declarative, per-element | **hx attributes**: header `hx-get` + `hx-trigger="click[!this.classList.contains('expanded')]"` + `hx-target="#tbody-{fam}"` + `hx-swap="innerHTML"`; load-more `hx-target="#tbody-{fam}"` + `hx-swap="beforeend"` (appended fragment carries the next load-more row; shim deletes the consumed row) |
| D4b | View-mode / sort refetch (TCP-02) | static attrs vs imperative | one toolbar control must refetch **N dynamically expanded families** with mode-dependent action/targets — impossible with static attributes | **Residual JS calls `htmx.ajax('GET', url, {target, swap})`** per expanded family, same endpoint/action/params as today (TCP-05 parity). Accepts refetch-on-expand (drop of `loadedFamilias` cache) for a simpler state model |
| D4c | Full-page filters (TCP-04) | new region fragment endpoint vs `hx-boost` | region swap needs a new server fragment (scope creep, TCP-05 pins endpoints); boost swaps full-document response into `<body>` and pushes URL with no new server code | **`hx-boost="true"` + `hx-push-url="true"` on filter selects/inputs** (tarifa, familia, text); name attributes added so the changed control's value rides the request. ES-module re-exec is prevented by the module map; inline re-render is idempotent |
| D5 | afterSwap shim (TCP-07) | dedicated file vs section | the module already loads per view; one fewer asset | **Section at the bottom of `catalogo-main.js`** listening on v4 `htmx:after:swap` / `htmx:after:request` via `evt.detail.ctx.target`. Re-runs `initInlineEdit`/`initSortable` for swapped tbodies, removes consumed load-more rows, syncs view-mode containers. Double-bind guards: `.off().on()` (already idempotent) + `data-ui-sortable` presence check |
| D6 | Error rows (TCP-10) | new HX error mechanism vs existing fragments | htmx 4 swaps 4xx/5xx by default, but fragment actions already answer with well-formed fragments | **Keep existing error-row fragments** (`<tr><td colspan>Error…</td></tr>` at `tarif_catalogo_view.php:744-747`; div equivalent at `:849-853`). No new mechanism. Note (pre-existing, out of scope): normal-view error row uses `colspan=8` vs 11 columns |
| D7 | CSRF wiring (HCS-07/08) | macro token source | spec wants the token `csrf_meta()` serves | Macro calls `csrf_token()` (→ `CsrfManager::generateToken()`, default tokenId) — **the same session token** `csrf_meta()` renders. No `CsrfManager` change; GET fragments need no token |
| D8 | Rollback | — | additive/isolated per proposal | Pilot + JS trim first, then macro imports, then build/package lines, helper last (no other callers) |

## Data Flow

```
Macro boot() ──▶ documentElement[hx-headers:inherited=X-CSRF-TOKEN] ──▶ <script htmx.min.js>
     │
hx-get (rows/load-more: attributes ─▶ fragment action ─▶ fragment template ─▶ swap)
htmx.ajax (view-mode/sort fan-out from residual JS ─▶ same fragment actions)
hx-boost (filters ─▶ full page URL ─▶ body swap + URL push)
htmx:after:swap ─▶ shim: initInlineEdit / initSortable / row cleanup
POST (out of pilot scope) would carry X-CSRF-TOKEN ─▶ validateCsrf() (unchanged)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `package.json` | Modify | Add `"htmx.org": "^4.0.0"` dependency |
| `build.sh` | Modify | One `cp node_modules/htmx.org/dist/htmx.min.js view/js/` line before cleanup |
| `view/js/htmx.min.js` | Create | Vendored asset (build output, committed) |
| `themes/AdminLTE/view/Macro/Htmx.html.twig` | Create | `boot()` macro: nonce'd script tag + root inherited-headers bootstrap |
| `base/fs_controller.php` | Modify | `isHtmxRequest()` next to `isAjax()` (~line 477) |
| `plugins/tarifario/View/tarif_catalogo_view.html.twig` | Modify | Macro import + boot; hx attributes on family headers, toolbar filters, `id="catalogo-main-region"` |
| `plugins/tarifario/View/tarif_catalogo_articulos.html.twig` | Modify | Load-more row → hx attributes |
| `plugins/tarifario/View/tarif_catalogo_articulos_agrupados.html.twig` | Modify | Load-more row → hx attributes |
| `plugins/tarifario/View/js/catalogo/catalogo-main.js` | Modify | Delete `loadArticulos` fetch bodies, `loadMoreArticulos`, filter navigations; add htmx bridge section; keep toggles/inline-edit/sortable/save-order/exports |
| `plugins/tarifario/controller/tarif_catalogo_view.php` | Modify | Remove dead `is_htmx_request()` (:589-592) |
| `tests/Base/FsControllerHtmxTest.php`, `tests/Core/HtmxMacroContractTest.php`, `plugins/tarifario/tests/Controller/TarifCatalogoHtmxContractTest.php` | Create | See Testing Strategy |

`header.html.twig` / `footer.html.twig`: untouched (HCS-03). No schema/data changes.

## Interfaces / Contracts

```php
public function isHtmxRequest(): bool  // true iff HX-Request header present
```

Macro emission contract (asserted by tests): exactly one `<script src="view/js/htmx.min.js" {{ csp_nonce_attr() }} defer>`; bootstrap sets attribute `hx-headers:inherited` on `document.documentElement` with JSON `{"X-CSRF-TOKEN": "<csrf_token()>"}`. Opt-in: `{% import 'Macro/Htmx.html.twig' as htmx %}` + `{{ htmx.boot() }}`. Event names in JS: v4 `htmx:after:swap` / `htmx:after:request` with `evt.detail.ctx` (the spec's `htmx:afterSwap` is the v2 name for the same event — tasks use the v4 literal).

## Testing Strategy

Strict TDD (`strict_tdd: true`); runner `ddev exec php vendor/bin/phpunit`; RED before GREEN per unit.

| Layer | What | How |
|-------|------|-----|
| Unit | `isHtmxRequest()` presence/absence | `tests/Base/FsControllerHtmxTest.php`: set `$_SERVER['HTTP_HX_REQUEST']`, `Kernel::boot()`, anonymous subclass (empty constructor, no DB) injecting `Kernel::request()` |
| Unit | Macro contract | `tests/Core/HtmxMacroContractTest.php`: Twig `FilesystemLoader` on `themes/AdminLTE/view`, stub `csrf_token`/`csp_nonce_attr` functions, render import+boot, assert script tag, nonce, `hx-headers:inherited`, header name, token |
| Regression | Pilot contracts (DB-free) | `plugins/tarifario/tests/Controller/TarifCatalogoHtmxContractTest.php`: `is_htmx_request` absent; HX branch delegates to `fs_controller::isHtmxRequest()`; view imports macro; hx attribute endpoints/params match TCP-05 (`htmx_articulos`/`htmx_articulos_agrupados`); JS keeps jQuery POST handlers and listens v4 event names |
| Verification | Asset presence | Build step check: `./build.sh` → `view/js/htmx.min.js` exists, version marker `4.0.0` (not PHPUnit) |
| Full suite | No regressions | `ddev exec php vendor/bin/phpunit` green |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. `build.sh` gains one static `cp` line mirroring 16 existing lines (fixed path, no user input).

## Migration / Rollout

No migration. Rollback order per proposal: revert pilot templates + JS trim, remove macro imports, delete asset + build/package lines, drop `isHtmxRequest()`; no legacy screen depends on any of it (HCS-09).

## Open Questions

- hx-boost full-document swap behavior and conditional trigger filter syntax (`click[!this.classList.contains('expanded')]`) under htmx 4: smoke-checked in the first pilot task; fallback documented (`hx-get` + `hx-select`, or a header-local `expanded` guard in the shim).
- None blocking.
