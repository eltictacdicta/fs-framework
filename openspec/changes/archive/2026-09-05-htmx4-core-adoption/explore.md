# Exploration: htmx4-core-adoption

Change root verified: `openspec/changes/htmx4-core-adoption/` (see §6).
Artifact filename `explore.md` per orchestrator handoff (openspec-convention.md names it `exploration.md`; dispatcher locator wins).

## 1. Current State

### Asset pipeline
- `build.sh:3-21` — `composer install` + `npm install`, then copies from `node_modules` into `view/js/` + `view/css/` + `view/fonts/`, then deletes `node_modules` and `package-lock.json` (line 22). This is exactly how `bootbox.min.js` (line 5), `jquery.min.js` (line 21) and `bootstrap.min.js` (line 8) land in core.
- `package.json:26-32` — runtime deps: `bootbox ^5.5.3`, `bootstrap ^3.4.1`, `bootswatch 3.*`, `font-awesome 4.*`, `jquery ^3.7.1`. `view/js/` currently contains: ajax-loader, base, bootbox, bootstrap, bootstrap-datepicker, jquery, jquery-ui, jquery.autocomplete, jquery.ui.shake (verified `ls view/js/`). No htmx anywhere in core, theme, or plugin views.
- Global header loads for ALL screens: `themes/AdminLTE/view/header.html.twig:39-59` — jQuery, Bootstrap 3, datepicker, bootbox, jQuery UI, autocomplete, slimscroll, adminlte, base.js, ajax-loader.js — every tag carries `{{ csp_nonce_attr() }}` (function registered in `src/Core/Html.php:281`).

### Loading conventions for per-screen JS
- Plugin views inject their own scripts inline in the view body: `plugins/tarifario/View/tarif_catalogo_view.html.twig:64` (`<script type="module" src="plugins/tarifario/View/js/catalogo/index.js">`) and `tarif_familias.html.twig:758` (same pattern for familias). ES modules, no bundler.
- Core injection mechanisms already in place: `fsc.extensions` of type `head` rendered in `header.html.twig:121-123`; FS2025 Extension/View pattern `{ParentTemplate}_{position}_{order}.html.twig` scanned from plugin `Extension/View/` dirs (`src/Core/Html.php:563-622`).
- Twig loader merges core `view/`, theme `view/`, and plugin `View/` paths; theme paths are re-prepended to highest priority (`src/Core/Html.php:161-188`).

### Partial-render precedent (fragment actions)
- The pilot controller already implements the manual-htmx pattern server-side: actions set `$this->template = '<fragment-template>'` and the renderer outputs just that fragment; JSON actions set `$this->template = false` + `echo json_encode(...)` (`plugins/tarifario/controller/tarif_catalogo_view.php:601-604, 614-731`).

## 2. Affected Areas

- `build.sh` + `package.json` — add htmx 4 dep + copy line (or direct-vendor fallback).
- `view/js/htmx.min.js` — new vendored asset (committed; root `vendor/` convention applies only to Composer, `view/js/` is fully committed today).
- `themes/AdminLTE/view/Macro/` — new opt-in Twig macro (per-screen include point). Theme is part of core repo; zero changes to `header.html.twig` / `footer.html.twig`.
- `base/fs_controller.php` — small `isHtmxRequest()` helper mirroring `isAjax()` (`base/fs_controller.php:473-476`).
- `plugins/tarifario/View/tarif_catalogo_view.html.twig` + `View/tarif_catalogo_articulos.html.twig` + `View/tarif_catalogo_articulos_agrupados.html.twig` — pilot: htmx attributes replacing the AJAX-load path of `View/js/catalogo/catalogo-main.js`.
- `tests/Base/` (helper test), `tests/Security/` (CSRF header acceptance — already covered), `plugins/tarifario/tests/` (plugin-side regression).

## 3. Pilot deep-dive

### Server actions (all already fragment-capable)
- `htmx_articulos` (`tarif_catalogo_view.php:736-836`) — paginated rows (offset/limit=50, sort, query) → template `tarif_catalogo_articulos`.
- `htmx_articulos_agrupados` (`:844-1051`) — tag-grouped view → template `tarif_catalogo_articulos_agrupados`.
- `htmx_search` (`:1056-1103`) — HTML search results → template `tarif_catalogo_search`.
- JSON actions (`toggle_en_tarifa`, `toggle_en_catalogo`, `reorder_articulos`, `inline_edit_articulo`, notas, imports) set `template=false` + echo JSON (`:613-731`).
- `is_htmx_request()` (`:589-592`) reads `$_SERVER['HTTP_HX_REQUEST'] === 'true'` — **defined but never called** (grep across plugin controllers/extras: 0 call sites). HX detection is a NEW convention, not a refactor.
- Fragment templates use inline `onclick=` handlers (e.g. `tarif_catalogo_articulos.html.twig:151,166,196`) — these survive htmx swaps unmodified; the jQuery rebind (`initInlineEdit`, `initSortable`) does not and needs `htmx:afterSwap` re-init or stays as-is.

### Behavior inventory of `View/js/catalogo/catalogo-main.js` (610 lines)
Representable with htmx 4 **now** (pilot scope):
1. Lazy-load family rows: `toggleFamilia` → `loadArticulos` (`:103-122, 175-218`) — `$.ajax GET → .html()` swap = `hx-get` + `hx-target="#tbody-{codfamilia}"` + `hx-swap="innerHTML"`.
2. View-mode switch refetch (`:127-158`) — same GET with different action param = re-pointed `hx-get` URL.
3. Sort refetch (`:163-170`) — reload with `sort=` param = hx-get with `hx-vals`.
4. Load-more append (`:401-417`) — GET → `append(html)` = `hx-swap="beforeend"` on the load-more row already rendered by the fragment template (`tarif_catalogo_articulos.html.twig:191-200`).
5. Full-page filters (`cambiarTarifa/filtrarPorFamilia/filtrarPorTexto`, `:54-98`) — plain links/forms with `hx-get` + `hx-push-url="true"` (or left as-is).

NOT representable now (explicit follow-ups, out of scope):
- Drag-drop reordering via jQuery UI sortable (`:223-240`) → SortableJS companion (recommended companion phase).
- Inline Excel-style cell editing (`:422-557`) → Alpine/contenteditable companion.
- JSON POST toggles + order save (`:255-375`) → hx-post + response handling or Ext event API companion.
- File exports (`:562-609`) — already native fetch/redirect; keep.

Estimated pilot surface: ~100 lines of JS removed/replaced by hx attributes in 2-3 templates + a small `htmx:afterSwap` shim to keep `initInlineEdit`/`initSortable` working for the not-yet-migrated parts. `catalogo-main.js` remains (it still owns toggles/inline-edit/sort/save) but loses `loadArticulos`/`loadMoreArticulos`/`toggleFamilia` fetch logic.

## 4. CSRF + HX-Request detection

- **No core shim needed for CSRF headers.** `CsrfManager::HEADER_NAME = 'X-CSRF-TOKEN'` (`src/Security/CsrfManager.php:70`); `getTokenFromRequest()` already checks POST `_csrf_token`, POST `_token`, then header `X-CSRF-TOKEN` (`:236-251`). `fs_controller::validateCsrf()` enforces this for every POST via `pre_private_core()` → `validateCsrf()` (`base/fs_controller.php:934,943`), header fallback at `:396`. An htmx request configured with root-level `hx-headers` sending `X-CSRF-TOKEN` (value from the existing `{{ csrf_meta() }}` → `CsrfManager::metaTag()`, `CsrfManager.php:220-229`) validates automatically today. GET fragment loads need no token (validateCsrf returns true for non-POST, `:384-386`).
- **HX-Request detection: small core helper.** Add `fs_controller::isHtmxRequest()` (reads `HX-Request` header / `HTTP_HX_REQUEST`, mirroring `isAjax()` at `base/fs_controller.php:473-476`) so controllers branch full-page vs fragment consistently instead of each plugin re-implementing `is_htmx_request()` (pilot copy at `tarif_catalogo_view.php:589`). Pilot can then delete its private copy.
- Strict-CSRF interplay: strict by default; `FS_CSRF_SOFT` only downgrades to warnings (`fs_controller.php:388-389`) — htmx requests behave like any other POST.

## 5. Strict TDD implications (config: openspec/config.yaml `strict_tdd: true`)

PHP-testable without browser:
- `isHtmxRequest()` unit test → `tests/Base/` (needs `$_SERVER` manipulation; no DB).
- CSRF header acceptance already exercised by `tests/Security/` suite (31 files per openspec/config.yaml); add a case documenting `X-CSRF-TOKEN` header path if not covered.
- Twig macro contract (renders script tag with nonce + headers config) → `tests/Core/`.
- htmx asset presence + build.sh copy → verification task (file-existence assertion or CI step), not a PHPUnit unit.
- Plugin regression → `plugins/tarifario/tests/` (subdirs Controller/Integration/Model/Services exist; suite wired via root Plugins testsuite and `plugins/tarifario/phpunit.xml`).
- Commands: `ddev exec php vendor/bin/phpunit` (mandatory ddev per AGENTS.md).

## 6. OpenSpec routing (verified)

- `plugins/tarifario/openspec/config.yaml:19-28` — explicit rule: if the change touches core (`base/`, `src/`, conventions crossing plugins), the SDD opens in **core** `openspec/` and references the plugin. AGENTS.md "OpenSpec per Plugin" hybrid rule: SDD lives where the main beneficiary is; here the core is the big piece (asset pipeline + helper + convention), the plugin is only the pilot.
- **Correct change root: `openspec/changes/htmx4-core-adoption/` (core). No conflict with repo conventions.** Plugin changes (hx attributes in tarifario views) are executed under this change as pilot work referencing `plugins/tarifario/...` paths; a future purely-plugin follow-up (inline edit/SortableJS) should open its own SDD in `plugins/tarifario/openspec/changes/`.

## Approaches (asset + include point)

1. **npm dep + build.sh copy (recommended)** — add `"htmx.org": "^4.0.0"` to package.json, `cp node_modules/htmx.org/dist/htmx.min.js view/js/` in build.sh.
   - Pros: identical provenance/upgrade path to bootbox/jQuery; version pinned in package.json.
   - Cons: must verify exact npm package name/dist path for the 4.x release (post-training release — verify at implementation; fallback: direct vendor).
   - Effort: Low.
2. **Direct vendor commit** — commit `htmx.min.js` straight into `view/js/` with license header comment.
   - Pros: zero build.sh/package.json churn; works even when npm unavailable.
   - Cons: breaks the established provenance pattern; manual upgrades.
   - Effort: Low.

Include-point options:
1. **Theme Twig macro (recommended)** — new `themes/AdminLTE/view/Macro/Htmx.html.twig` exporting e.g. `htmx_scripts()` that emits the nonce'd script tag + inherited-headers bootstrap (hx-headers with X-CSRF-TOKEN from `csrf_meta`). Views opt in with one `{% import %}` line. Zero header/footer edits → full retrocompat. Effort: Low.
2. **fsc.extensions head injection** — automatic global-ish, harder to scope per-screen. Effort: Medium.
3. **Raw script tags in each view** — works for pilot but no reusable convention; duplicates CSP/CSRF wiring. Effort: Low but non-scalable.

## Recommendation

- Vendoring: Approach 1 (npm + build.sh copy), with direct-vendor as documented fallback if the npm package layout differs.
- Include point: theme macro `Macro/Htmx.html.twig`, imported only by opted-in views; htmx never added to the global header.
- Core code: only `isHtmxRequest()` helper in `base/fs_controller.php`; **no CsrfManager change**.
- Pilot: convert `htmx_articulos` / `htmx_articulos_agrupados` / load-more consumers to hx attributes (GET fragments only); keep JSON POSTs, inline edit, sortable, exports on jQuery for this phase.
- Follow-ups (do not implement here, mention in proposal): Alpine 3 (inline edit, toggles), SortableJS (drag-drop), Bootstrap 5 migration.

## Risks

- **[Medium] htmx 4 npm package name/dist path unverified offline** — resolve at implementation before writing build.sh line.
- **[Medium] jQuery rebind breakage after swaps** — `initSortable`/`initInlineEdit` binding is lost on innerHTML swaps; pilot must re-init on `htmx:afterSwap` (renamed from configRequest-era events) or scope the pilot to viewers/read-only rows first.
- **[Low] CSP**: htmx script tag and any inline config must carry `{{ csp_nonce_attr() }}` (pattern proven in header.html.twig:39-59).
- **[Low] Double-processing**: `data-submit-once` / global submit listeners (header.html.twig:98-117) don't fire on hx requests — no conflict, but document that hx buttons must not rely on those handlers.
- **[Low] htmx 4 swaps 4xx/5xx by default** — fragment actions returning error HTML inside `<tr>` context could inject malformed rows; keep the existing error-row fragments (`tarif_catalogo_view.php:744-747`).
- **[Low] Review budget**: pilot touches core (helper + build + macro) + plugin (3 templates, 1 JS trim) — likely under 400 lines but forecast for sdd-tasks.

## Ready for Proposal

Yes. Proposal should state: staged/backwards-compat adoption (no global header change, jQuery/BS3 untouched), core ownership per §6, pilot limited to GET fragment flows, companion stack explicitly deferred.
