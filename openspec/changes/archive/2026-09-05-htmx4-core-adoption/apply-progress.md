# Apply Progress: htmx4-core-adoption

delivery_strategy: auto-chain; chain_strategy: stacked-to-main (PR 1 core
enablement merged to main as #10; PR 2 pilot = branch feat/htmx4-catalog-pilot).
Strict TDD mode active (openspec/config.yaml `strict_tdd: true`, `tdd: true`).
Runner: `ddev exec php vendor/bin/phpunit`.

## Status

- **Unit 1**: COMPLETE — Phases 1, 2, 3 + task 6.1 (merged to main, PR #10).
- **Unit 2 (this run)**: COMPLETE — Phases 4, 5, 6.1 re-verified, 6.2. All tasks [x].

## Completed Tasks — Unit 1

### Phase 1: Asset Provenance (D1, HCS-01/02)

- [x] 1.1 `npm install htmx.org@4.0.0` — verified `node_modules/htmx.org/dist/htmx.min.js` exists (36,716 bytes), license `BSD-0-Clause`, version `4.0.0` marker inside the asset. **npm dist path resolved → D1 primary path used; HCS-02 direct-vendor fallback NOT needed.**
- [x] 1.2 `package.json` gains `"htmx.org": "^4.0.0"` (npm wrote it exactly as specified); `build.sh` gains `cp node_modules/htmx.org/dist/htmx.min.js view/js/` (between font-awesome and jquery, before cleanup).

### Phase 2: isHtmxRequest() (D3, HCS-06)

- [x] 2.1 RED: `tests/Base/FsControllerHtmxTest.php` — 3 tests (present→true, absent→false, any value→true). RED run: 3 errors (`Call to undefined method fs_controller@anonymous::isHtmxRequest()`).
- [x] 2.2 GREEN: `isHtmxRequest(): bool` added to `base/fs_controller.php` beside `isAjax()` — `$this->request->headers->get('HX-Request') !== null`. GREEN run: OK (3 tests, 3 assertions).

### Phase 3: Boot Macro (D2/D7, HCS-03/04/05)

- [x] 3.1 RED: `tests/Core/HtmxMacroContractTest.php` — 8 tests. RED run: 6 errors (template not found) + 2 passing regression guards.
- [x] 3.2 GREEN: `themes/AdminLTE/view/Macro/Htmx.html.twig` — `boot()` emits inline inherited-headers bootstrap + nonce'd deferred `<script src="view/js/htmx.min.js">`. GREEN run: OK (8 tests, 13 assertions).

### Phase 6 (unit 1 slice)

- [x] 6.1 `ddev exec ./build.sh` green; `view/js/htmx.min.js` present with `4.0.0` marker; asset committed.

## Completed Tasks — Unit 2 (this run)

### Phase 4: Pilot Templates + JS (D4a-D4c/D5, TCP-01..07)

- [x] 4.1 RED: `plugins/tarifario/tests/Controller/TarifCatalogoHtmxContractTest.php` (DB-free; TarifTabPreciosTest precedent) — 6 tests: view imports `Macro/Htmx.html.twig` + `htmx.boot()` + `id="catalogo-main-region"`; family headers carry `hx-get` (action=htmx_articulos, same params as the old $.ajax), `hx-trigger="click[!this.classList.contains('expanded')]"`, `hx-target="#tbody-{{ fam.codfamilia }}"`, `hx-swap="innerHTML"`, no `onclick="toggleFamilia(`; load-more row carries `hx-swap="beforeend"` + paginated `hx-get` (TCP-03), no `onclick="loadMoreArticulos(`; controller still pins both fragment templates (TCP-05); toolbar RENDERED with real Twig (ArrayLoader + stub fsc) asserting `hx-boost="true"`, `hx-push-url="true"`, `hx-select/target="body"`, `name="codtarifa|b_codfamilia|query|sort"` and NO JS navigation callbacks (TCP-04). RED run: 3 failures + 2 errors (macro import unresolvable pre-migration) / 6 tests; 1 TCP-05 pin passing as regression guard.
- [x] 4.2 GREEN: `tarif_catalogo_view.html.twig` (macro import + `{{ htmx.boot() }}` after styles, `id="catalogo-main-region"` on the main container, header div: inline onclick removed → conditional hx-trigger + `hx-include="#select_ordenacion"` so the CURRENT sort rides every fragment request); `partials/catalogo/toolbar.html.twig` (tarifa selector inlined with hx attrs — macro untouched, its `cambiarTarifa` dependency removed; familia select + query input + search button: `hx-get` + `hx-trigger` + `hx-target="body"` + `hx-select="body"` + `hx-swap="outerHTML"` + `hx-push-url="true"` + `hx-boost="true"` + name attrs; `name="sort"` added to `select_ordenacion`); `tarif_catalogo_articulos.html.twig` (load-more → `hx-get` with `offset={{ fsc.htmx_offset + fsc.articulos|length }}` + `hx-target="#tbody-..."` + `hx-swap="beforeend"` + `hx-include="#select_ordenacion"`). GREEN run: OK (6 tests, 28 assertions).
- [x] 4.3 RED: 3 JS contract tests — no `loadArticulos`/`loadMoreArticulos`/`toggleFamilia`/`cambiarTarifa`/`filtrarPorFamilia`/`filtrarPorTexto`; POST/inline-edit/sortable/export endpoints intact (`reorder_articulos`, `toggle_en_tarifa`, `toggle_en_catalogo`, `inline_edit_articulo`, `export_catalogo_json`, `export_excel_template`, `type: 'POST'`); `htmx.ajax(` fan-out carrying `action=htmx_articulos(_agrupados)` + codtarifa/codfamilia/sort/query (TCP-02/05); v4-only event names `'htmx:after:swap'`/`'htmx:after:request'` + `evt.detail.ctx` + `ui-sortable` guard + `.off('click` idempotent binding; v2 names banned. RED run: 3 failures / 10 tests.
- [x] 4.4 GREEN: `catalogo-main.js` — deleted `loadArticulos`, `loadMoreArticulos`, `cambiarTarifa`, `filtrarPorFamilia`, `filtrarPorTexto`, `toggleFamilia`, `switchViewMode`, `loadedFamilias` cache; added module-scoped `managerInstance`, `bindHeaderToggle()` (document-delegated visual toggle, `.off('click.htmxPilot').on(...)`, grouped-mode fan-out on expand), `refetchFamilia()` (D4b: mode-dependent `htmx.ajax('GET', url, {target, swap})`, same endpoint/action/params as the old $.ajax), `syncViewContainers()`, `initSortable()` `data-ui-sortable` guard + refresh path; expandAll/collapseAll dispatch real clicks (single code path through htmx + bridge); shim section at the bottom listening `'htmx:after:swap'`/`'htmx:after:request'` via `evt.detail.ctx` (re-init inline edit/sortable for swapped tbodies/grouped divs, sync view containers, remove consumed load-more row via `ctx.elt.closest('tr[id^="loadmore-row-"]')`, hide `#loading-*` on after:request incl. errors). JS syntax verified with `node --check` (ESM). GREEN run: OK (10 tests, 54 assertions).

### Phase 5: Controller Cleanup (TCP-08)

- [x] 5.1 RED: 2 tests — controller source contains no `is_htmx_request`; anonymous subclass (skip ctor, inject Symfony Request) exercises the inherited `fs_controller::isHtmxRequest()` (header present→true, absent→false) and reflection asserts the method is DECLARED by `fs_controller`, not redeclared by the plugin. RED run: 1 failure + 1 passing guard / 12 tests.
- [x] 5.2 GREEN: removed dead `is_htmx_request()` (+ its docblock) from `plugins/tarifario/controller/tarif_catalogo_view.php`. GREEN run: OK (12 tests, 58 assertions).

### Phase 6 (unit 2 slice)

- [x] 6.1 (re-verified) `ddev exec ./build.sh` → exit 0; `view/js/htmx.min.js` 36,716 bytes with `4.0.0` marker; `vendor/composer/installed.php` container-rewrite reverted (unit-1 gotcha).
- [x] 6.2 Full suite `ddev exec php vendor/bin/phpunit`: 1284 tests, 3224 assertions, **18 errors — all verified PRE-EXISTING**: identical 18 errors on plugin `master` via `phpunit -c plugins/tarifario/phpunit.xml` (144 tests/18 errors before this unit vs 156/18 after = exactly the +12 new green tests). All 18 are `Tests\Tarifario\...PermissionListener/HookRegistration/QuickCreateGate` failing on `Class FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent not found` — a catalogo_core dependency unrelated to this change. Not fixed per safety-net rule (pre-existing failures are reported, not fixed, by apply). Error-row fragments untouched per D6 (controller :744-747/:849-853 intact; pre-existing colspan=8-vs-11 out of scope).

## TDD Cycle Evidence (unit 2)

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 4.1/4.2 | `plugins/tarifario/tests/Controller/TarifCatalogoHtmxContractTest.php` | Contract (source + real Twig render) | ✅ 11/11 unit-1 `--filter Htmx`; TarifTabPrecios 13/13 | ✅ Written (3 F + 2 E) | ✅ OK (6 tests, 28 assertions) | ✅ 6 cases across 5 contracts | ➖ None needed |
| 4.3/4.4 | same file | Contract (JS source) | ✅ 6/6 after 4.2 | ✅ Written (3 F) | ✅ OK (10 tests, 54 assertions) | ✅ 3 cases (removal/POST-preservation/bridge+events) | ✅ 1 comment reword (docblock mentioned removed fn names; behavior unchanged) |
| 5.1/5.2 | same file | Unit (delegation) + source contract | ✅ 10/10 after 4.4 | ✅ Written (1 F + 1 guard) | ✅ OK (12 tests, 58 assertions) | ✅ 2 cases (header present/absent) | ➖ None needed |

### Test Summary (unit 2)

- **Total tests written**: 12 — **Total passing**: 12
- **Layers used**: Twig contract render (1 harness / 2 tests), source contracts (8), unit/delegation (2)
- **Approval tests**: guards only (TCP-05 template pins + delegation reflection assert current reality before change)
- **Pure functions created**: 0 (bridge/shim methods on the existing manager class; `node --check` used as JS syntax gate)
- **Focused command**: `ddev exec php vendor/bin/phpunit --filter TarifCatalogoHtmx` → OK, 12 tests, 58 assertions (exit 0; 6 pre-existing deprecations in unrelated files)

## Work Unit Evidence (unit 2)

| Evidence | Required value |
|---|---|
| Focused test command + result | `ddev exec php vendor/bin/phpunit --filter TarifCatalogoHtmx` → OK, 12 tests, 58 assertions, exit 0 |
| Runtime harness + result | N/A (justified): no E2E tooling cached (`openspec/config.yaml` `e2e: false`); browser behaviors (expand/load-more/filter swaps, hx-boost navigation, trigger-filter ordering) degraded to the highest available layer = Twig-render + JS/delegation contract tests. A manual browser smoke of expand/load-more/filter is recommended at sdd-verify (design Open Questions flagged the same). |
| Rollback boundary | Revert plugin commit `e8bb5e7` (6 files: 4 view/JS files, controller, test file) + core docs commit below. The JS module is self-contained; controller removal is independent; no schema/data changes; unit-1 core artifacts (macro, isHtmxRequest, asset) are not depended on by anything outside the pilot. |

## Decisions Taken (unit 2)

1. **Load-more interpretation**: task 4.1 said "load-more rows in both fragment templates", but `htmx_articulos_agrupados` loads all rows (limit 9999) and never sets `has_more` (controller :832 is the only setter) — the agrupados fragment has no load-more row. Adding one would be dead markup. Asserted instead: load-more hx contract in `tarif_catalogo_articulos.html.twig` + TCP-05 endpoint/template parity for BOTH templates via controller pins. Agrupados fragment left byte-identical.
2. **D4c letter vs htmx semantics**: `hx-boost` is inert on bare `<select>/<input>/<button>` (htmx boosts only a/form descendants), so the functional mechanism is explicit `hx-get` + `hx-target="body"` + `hx-select="body"` + `hx-swap="outerHTML"` + `hx-push-url="true"` — exactly boost's full-document-into-body behavior; `hx-boost="true"` is still carried on every filter control per D4c's letter (and the TCP-04 contract). Known cosmetic gap vs real boost: `document.title` is not refreshed on filter swaps.
3. **Header visual toggle moved out of inline onclick**: inline `onclick="toggleFamilia(...)"` would run BEFORE htmx's element listener (registration order), making the conditional trigger filter `click[!this.classList.contains('expanded')]` always fail (class already added) and killing lazy-load. Replaced with a document-level delegated jQuery handler (bubbles AFTER the element-level htmx listener → filter sees the pre-click state: expand requests, collapse doesn't). Child `stopPropagation()` (notes badge, edit actions) keeps suppressing both, as today.
4. **Accepted double-fetch on grouped-mode expand** (D4b's "refetch-on-expand"): header hx-get loads normal rows into tbody AND the bridge fans out the grouped request (default viewMode is 'agrupado'). Accepted by design for a simpler state model; noted for the future companion phases.
5. **Sort parity without controller changes** (scope: controller touched ONLY for is_htmx_request removal): fragment hx URLs omit a static sort; `select_ordenacion` gained `name="sort"` and fragment/header requests use `hx-include="#select_ordenacion"`, so the CURRENT sortMode always rides — exact parity with the old JS `sortMode`, no shim URL rewriting needed.
6. **expandAll/collapseAll via dispatched real clicks**: single code path (htmx fetch + visual bridge) instead of duplicating fetch logic; zero-article headers skipped (parity with the old early return).
7. **Nested repo**: all pilot files live in `plugins/tarifario` — a separate git repo (gitignored by core per AGENTS.md). The pilot commit therefore lives in the PLUGIN repo on `feat/htmx4-catalog-pilot`; the core branch of the same name carries only the openspec docs commit (unit-1 precedent).

## Verification Evidence (unit 2 exit battery)

| Check | Command | Result |
|-------|---------|--------|
| New tests | `ddev exec php vendor/bin/phpunit --filter TarifCatalogoHtmx` | ✅ OK (12 tests, 58 assertions) |
| Plugin suite | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | ✅ 156 tests / 528 assertions / same 18 pre-existing errors (144/18 on master) |
| Full suite | `ddev exec php vendor/bin/phpunit` | ✅ 1284 tests, 3224 assertions; 18 errors ALL pre-existing (see above), 0 regressions, 11 skipped, 20 deprecations |
| Build | `ddev exec ./build.sh` | ✅ exit 0; `view/js/htmx.min.js` 36,716 bytes + `4.0.0` marker |
| JS syntax | `node --check` on ESM copy | ✅ |
| Scope guard | `git status` both repos | ✅ only in-scope files; themes/AdminLTE/**, base/**, src/** untouched; core master untouched |

## Commits (unit 2)

| Commit | Repo | Files |
|--------|------|-------|
| `e8bb5e7` | plugins/tarifario (branch feat/htmx4-catalog-pilot) | 4 view/JS files + controller + TarifCatalogoHtmxContractTest (591+/197−) |
| (this one) | core (branch feat/htmx4-catalog-pilot) | openspec apply-progress + tasks.md checkboxes |

## Workload / PR Boundary (unit 2)

- Mode: stacked PR slice (PR 2 → master), one deliverable work unit, tests+code in the same commit.
- Authored ledger: **591 insertions + 197 deletions = 788 changed lines** vs 450 budget → **size:exception recommended**. Composition: contract test file 369 lines (vs ~175 estimated — Twig render harness + delegation harness + contract docblocks; trimming comments/helpers/tests to fit is forbidden), catalogo-main.js 314 changed lines (net-negative LOC: ~200 deleted fetch paths vs ~95 added bridge/shim, diff churn from rewrite), templates +97, controller −8. The slice is one cohesive behavior: templates without the JS bridge are half-broken (headers fetch but nothing expands), so no smaller green commit split exists.

## Remaining

- None for this change — all tasks [x]. Next: sdd-verify (recommend including the manual browser smoke: expand → rows load, load-more appends, filters push URL; and triaging the pre-existing Tests\Tarifario permission-listener errors — likely a catalogo_core `ArticlePermissionFilterEvent` autoload/ordering issue owned by that plugin's SDD).
