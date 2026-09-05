```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:b8cae8b2ae2b3c4e59be1f24e4eb7b2e59c04deedd8deab31489a6c620a28cd2
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 19/19
scenarios: 18/18
test_command: ddev exec php vendor/bin/phpunit --filter Htmx && ddev exec php vendor/bin/phpunit --filter TarifCatalogoHtmx
test_exit_code: 0
test_output_hash: sha256:81854c52d2a6534da1bb4ee18a62b48683d6f292dda857f14fa8c5a18e4f49f8
build_command: ddev exec ./build.sh
build_exit_code: 0
build_output_hash: sha256:e484d9171a9db30a39c8f16e3d709d4137f3211c659f8e6125816635033d593f
```

## Verification Report

**Change**: htmx4-core-adoption
**Version**: N/A (no spec version declared)
**Mode**: Strict TDD
**Run type**: Refresh re-verification — both delta specs were reformatted to canonical OpenSpec format (`### Requirement:` + `#### Scenario:` blocks; overview tables kept). Requirement IDs are unchanged (HCS-01..09, TCP-01..10); HCS-04 now declares explicitly that it shares HCS-05's macro scenario by annotation. This run re-executed the two focused suites, refreshed the three bounded spot-checks (HCS-01/03, TCP-08), and re-persisted the report against the reformatted specs. Full-suite and build evidence are carried from the admitted prior run (disclosed below).
**Verdict per requirement scale**: PROVEN (evidence cited) / WARNING (proven at available layer, runtime gap) / UNVERIFIED.

### Evidence Digests (this run)

| Evidence | Digest / Value |
|----------|----------------|
| `evidence_revision` (sha256 of both reformatted spec.md files concatenated) | `b8cae8b2…20a28cd2` |
| `test_output_hash` (sha256 of this run's combined focused-suite output: `/tmp/opencode/htmx-verify2/focused-combined.txt`) | `81854c52…e4f49f8` |
| Focused suite outputs individually | `/tmp/opencode/htmx-verify2/focused-core.txt`, `/tmp/opencode/htmx-verify2/focused-pilot.txt` |
| `build_output_hash` = sha256 of the vendored artifact `view/js/htmx.min.js` (36,716 bytes, contains `4.0.0` marker; byte-identical to prior run) | `e484d917…d593f` |
| Prior-run full-suite outputs (not re-executed; referenced as prior evidence) | raw full suite `7a73b911…da67ac4cd`; full-minus-baseline-red `62bbb889…006eedf` |

### Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 14 (1.1–6.2) |
| Tasks complete | 14 |
| Tasks incomplete | 0 |

`tasks.md` re-checked this run: 14/14 `- [x]`, 0 `- [ ]`.

### Build & Tests Execution

**Build**: ✅ Passed — executed during apply task 6.1 (exit 0); this refresh run re-verified artifact integrity only (36,716 bytes + `4.0.0` marker + digest above, byte-identical to the prior run) to avoid mutating `vendor/` on the working tree. `package.json:31` pins `"htmx.org": "^4.0.0"`, `build.sh:21` copies `node_modules/htmx.org/dist/htmx.min.js view/js/`.
```text
ddev exec ./build.sh → exit 0 (apply 6.1; asset present and committed in PR #10)
```

**Tests**: ✅ all green on this run's two focused suites (exit 0 each; 6 PHPUnit deprecations each, informational under `SYMFONY_DEPRECATIONS_HELPER=weak`). Full-suite evidence from the prior run is carried over unchanged.
```text
ddev exec php vendor/bin/phpunit --filter Htmx              → Tests: 23, Assertions: 74, exit 0 (this run)
ddev exec php vendor/bin/phpunit --filter TarifCatalogoHtmx → Tests: 12, Assertions: 58, exit 0 (this run)
ddev exec php vendor/bin/phpunit                            → Tests: 1284, Assertions: 3224, Errors: 18, Skipped: 11, exit 2 (prior run)
  All 18 errors: Tests\Tarifario\*PermissionListener/HookRegistration/QuickCreateGate
  → Class "FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent" not found
  → identical 18 errors verified on plugin master baseline (144/18 before → 156/18 after = exactly the +12 new green tests). Zero regressions.
ddev exec php vendor/bin/phpunit --filter '<regex excluding the 5 classes holding the 18
  pre-existing errors>' (prior run, via tmp/htmx-verify-filter.sh because ddev exec strips
  outer shell quotes; script deleted after the run)
  → OK, Tests: 1261, Assertions: 3203, Skipped: 11, exit 0.
  Exclusion is conservative: 23 tests skipped (the 18 baseline errors + 5 passing siblings
  of the same baseline-red classes); this is the envelope's independent regression evidence.
```

**Coverage**: ➖ Not available — no coverage tool detected; coverage analysis skipped (not a failure).

### Spec Compliance Matrix — htmx-core-support (9 requirements, 8 scenarios; HCS-04 shares HCS-05's scenario by annotation)

| Requirement | Scenario | Test / Evidence | Result |
|-------------|----------|-----------------|--------|
| HCS-01 (asset + provenance) | build.sh vendors htmx from npm | This run: `view/js/htmx.min.js` 36,716 B + `4.0.0` marker + digest match; `package.json:31` pin; `build.sh:21` npm-dist copy. Build exit 0 (apply 6.1) | ✅ COMPLIANT — PROVEN |
| HCS-02 (fallback contract) | npm dist path mismatch falls back | Antecedent false: npm dist path RESOLVED (36,716 B from npm); fallback documented in tasks 1.1; availability holds via HCS-01 | ✅ COMPLIANT (contingent, not fired) |
| HCS-03 (header/footer untouched) | global header/footer untouched | This run: `git diff master...HEAD --stat` on `header.html.twig`/`footer.html.twig` → empty; `grep -ri htmx` on both files → zero matches. Guards `HtmxMacroContractTest > globalHeaderTemplateHasNoHtmxReference/globalFooterTemplateHasNoHtmxReference` green in this run's focused suite | ✅ COMPLIANT — PROVEN |
| HCS-04 (macro-only load) | (shared with HCS-05) macro import emits nonce'd script and CSRF headers | `Macro/Htmx.html.twig` is the only htmx loading point; opt-in import found only in `tarif_catalogo_view.html.twig:7`; no global default (diff + grep) | ✅ COMPLIANT — PROVEN |
| HCS-05 (script + CSRF bootstrap) | macro import emits nonce'd script and CSRF headers | 8 tests green in `tests/Core/HtmxMacroContractTest.php` (focused suite, this run) — exactly one `<script src="view/js/htmx.min.js">` with nonce+defer (macro :34), inline bootstrap sets `hx-headers:inherited` on `documentElement` = `{"X-CSRF-TOKEN": csrf_token()}` (macro :28-33), bootstrap before asset (:117-128) | ✅ COMPLIANT — PROVEN |
| HCS-06 (isHtmxRequest) | isHtmxRequest detects the header | `base/fs_controller.php:485-488` (`headers->get('HX-Request') !== null`); `FsControllerHtmxTest` 3/3 green (present→true, absent→false, any value→true); `TarifCatalogoHtmxContractTest:354-368` delegation 2/2 green | ✅ COMPLIANT — PROVEN |
| HCS-07 (CSRF via existing flow) | htmx POST validates CSRF via inherited header | Static composite: macro sources the SAME session token (`csrf_token()` → `CsrfManager::generateToken()`, D7); `validateCsrf()` reads header `CsrfManager::HEADER_NAME = 'X-CSRF-TOKEN'` at `base/fs_controller.php:396`; `CsrfManager.php` absent from both diffs (unchanged); pre-existing `CsrfManagerTest` green. No dedicated htmx-POST runtime test (POST flows out of pilot scope per D4/TCP-06) | ✅ COMPLIANT (contract layer) — covering tests green: macro emission (8 tests) + header validation (CsrfManagerTest); composition is browser-only and out of declared E2E capability (see WARNING-3) |
| HCS-08 (GET needs no token) | GET fragment needs no token | `validateCsrf()` `base/fs_controller.php:384-386` returns true for non-POST (existing behavior, unchanged); all pilot flows are GET | ✅ COMPLIANT — PROVEN (code path) |
| HCS-09 (non-importing views unchanged) | page without macro import unchanged | Diff evidence: core branch vs master = 2 openspec docs only; PR #10 = 14 files (enablement + planning), zero non-pilot views; plugin diff = exactly the 6 in-scope files; macro loads only on import | ✅ COMPLIANT — PROVEN (diff evidence) |

**Compliance summary (HCS)**: 8/8 scenarios compliant at declared capability layers (1 with browser-composition WARNING-3).

### Spec Compliance Matrix — tarifario-catalog-htmx-pilot (10 requirements, 10 scenarios)

| Requirement | Scenario | Test / Evidence | Result |
|-------------|----------|-----------------|--------|
| TCP-01 (lazy-load via hx) | lazy-load family rows | `tarif_catalogo_view.html.twig:109-113` (`hx-get` → `action=htmx_articulos`, conditional `hx-trigger="click[!this.classList.contains('expanded')]"`, `hx-target="#tbody-{{ fam.codfamilia }}"`, `hx-swap="innerHTML"`); JS greps: `loadArticulos`/`loadMoreArticulos`/`toggleFamilia` zero matches; tests `:164-193`, `:284-291` green (focused suite, this run) | ✅ COMPLIANT (contract layer) — wiring PROVEN; browser swap pending manual smoke (WARNING-2, e2e: false) |
| TCP-02 (refetch parity) | view-mode and sort refetch | `catalogo-main.js:98-100` `htmx.ajax('GET', …action=htmx_articulos(_agrupados)…)` same endpoints/params; current sort rides via `hx-include="#select_ordenacion"` (view :111, fragment :200) + `name="sort"`; test `:314-324` green | ✅ COMPLIANT (contract layer) — wiring PROVEN; browser refetch pending manual smoke (WARNING-2) |
| TCP-03 (load-more append) | load-more appends | `tarif_catalogo_articulos.html.twig:199-202`: `hx-swap="beforeend"`, `hx-target="#tbody-{{ fsc.htmx_codfamilia }}"`, `offset={{ fsc.htmx_offset + fsc.articulos|length }}` (one-page advance); test `:199-228` green. Deviation (documented, apply Decisions #1): agrupados fragment never rendered a load-more row (`has_more` only set at controller :832 for normal mode) — nothing to migrate; no dead markup added | ✅ COMPLIANT (contract layer) — wiring PROVEN; browser append pending manual smoke (WARNING-2) |
| TCP-04 (filters + URL push) | full-page filter updates URL | `partials/catalogo/toolbar.html.twig:19-25` (tarifa), `:146-152` (familia), `:175-191` (text+button): `hx-get` + `hx-target="body"` + `hx-select="body"` + `hx-swap="outerHTML"` + `hx-push-url="true"` + `hx-boost="true"` + name attrs; real-Twig render test `:254-277` green | ✅ COMPLIANT (contract layer) — attrs PROVEN via real render; browser URL push pending smoke (WARNING-2); `document.title` gap noted (apply Decisions #2) |
| TCP-05 (response parity) | response parity with $.ajax consumers | Contract test `:234-248` pins `$this->template = 'tarif_catalogo_articulos'` and `'tarif_catalogo_articulos_agrupados'` in the controller; identical endpoints (`htmx_articulos`/`htmx_articulos_agrupados`) and params in template :109/:184 and JS :98-100; controller diff = only helper removal, so fragment templates/params unchanged | ✅ COMPLIANT — PROVEN |
| TCP-06 (jQuery flows preserved) | jQuery flows preserved | Test `:293-308` green: `reorder_articulos`, `toggle_en_tarifa`, `toggle_en_catalogo`, `inline_edit_articulo`, `export_catalogo_json`, `export_excel_template`, `type: 'POST'` all present; grep confirms 5× `type: 'POST'` in `catalogo-main.js` (:200, :231, :267, :293, :415) plus POST handlers intact in `images-import.js`/`images-zip.js`/`json-import.js` | ✅ COMPLIANT — PROVEN |
| TCP-07 (afterSwap shim) | afterSwap shim re-binds handlers | `catalogo-main.js:576` listens `['htmx:after:swap','htmx:after:request']` (v4 colon names, :549/:558 branches) with `evt.detail.ctx`; re-inits `initInlineEdit`/`initSortable`; guards: `data-ui-sortable` + `.off('click…)`; v2 names banned (test `:326-337` green). Design pins v4 literal names — spec's `htmx:afterSwap` wording is the v2 alias of the same event (documented in design Interfaces) | ✅ COMPLIANT (contract layer) — shim PROVEN (source); browser re-bind pending manual smoke (WARNING-2) |
| TCP-08 (dead helper removed) | dead plugin helper removed | This run: `grep -rn is_htmx_request plugins/tarifario` excluding `tests/` → zero matches; test file contains exactly 2 matches (source + delegation tests). Controller diff = exactly the `is_htmx_request()` removal (−8 lines, one hunk at :583); tests `:343-368` green (source + reflection declaring-class + live delegation) | ✅ COMPLIANT — PROVEN |
| TCP-09 (regression suite) | regression suite green | Prior run: full suite 1284 tests / 3224 assertions; 12 new pilot tests green; 18 errors = exact pre-existing master baseline (verified against plugin master: 144/18 before → 156/18 after); 0 regressions; focused suites exit 0; full suite minus the 5 baseline-red classes → 1261/3203, 0 errors, exit 0. This run: both focused suites re-executed green (23/74 and 12/58) | ✅ COMPLIANT — PROVEN (baseline excluded mechanically; raw baseline documented) |
| TCP-10 (error-row fragments) | error fragment inside row context | `tarif_catalogo_view.php:737` `<tr><td colspan="8" class="text-danger…">Error…</td></tr>` (row context) and `:843` `<div class="text-danger…">Error…</div>` (grouped context) — both well-formed and UNCHANGED (controller diff contains only the helper hunk); design D6 (keep existing fragments) followed | ✅ COMPLIANT — PROVEN |

**Compliance summary (TCP)**: 10/10 scenarios compliant at declared capability layers (5 with browser-runtime WARNING-2).

**Total**: 18/18 scenarios compliant at the project's declared capability layers; the browser-runtime remainder of 6 scenarios is carried as WARNING-2 with manual smoke steps; 0 untested, 0 failing.

### Correctness (Static Evidence)

| Requirement | Status | Notes |
|------------|--------|-------|
| HCS-01 | ✅ PROVEN | npm-pinned asset + build copy + committed artifact (digest re-verified this run, byte-identical) |
| HCS-02 | ✅ PROVEN | Contingency not fired; fallback documented (tasks 1.1) |
| HCS-03 | ✅ PROVEN | Zero htmx refs in header/footer (this run: empty diff + grep; plus 2 guard tests) |
| HCS-04 | ✅ PROVEN | Macro is the sole loading point; opt-in import only in pilot view |
| HCS-05 | ✅ PROVEN | Macro contract fully asserted by 8 runtime-rendered tests |
| HCS-06 | ✅ PROVEN | Presence-based header check + 5 passing tests across 2 files |
| HCS-07 | ✅ PROVEN (static) | Same-session-token wiring + unchanged CsrfManager + existing header validation |
| HCS-08 | ✅ PROVEN | Non-POST short-circuit in `validateCsrf()` |
| HCS-09 | ✅ PROVEN | Diff scope proves no non-importing view can change |
| TCP-01 | ✅ PROVEN (contract) | hx attributes in place; old fetch paths erased from JS |
| TCP-02 | ✅ PROVEN (contract) | `htmx.ajax` fan-out with identical endpoints/params |
| TCP-03 | ✅ PROVEN (contract) | beforeend append + one-page offset advance |
| TCP-04 | ✅ PROVEN (contract) | All filter controls carry the full hx-get/push attribute set |
| TCP-05 | ✅ PROVEN | Endpoint/template parity pinned by passing tests |
| TCP-06 | ✅ PROVEN | All jQuery POST/edit/sortable/export flows asserted intact |
| TCP-07 | ✅ PROVEN (contract) | v4 shim with request context + double-bind guards |
| TCP-08 | ✅ PROVEN | Helper deleted; detection delegates to core (this run: zero non-test grep matches) |
| TCP-09 | ✅ PROVEN | Suite green vs baseline; +12 tests, 0 regressions |
| TCP-10 | ✅ PROVEN | Error-row fragments untouched per D6 |

### Coherence (Design)

| Decision | Followed? | Notes |
|----------|-----------|-------|
| D1 (npm provenance) | ✅ Yes | npm path resolved; fallback documented, not needed |
| D2 (root bootstrap) | ✅ Yes | Inline `documentElement.setAttribute('hx-headers:inherited', …)` before deferred asset; wrapper-div rejected; order asserted by test :117-128 |
| D3 (presence-based detection) | ✅ Yes | `:485-488`, value-insensitive (test with `'0'`) |
| D4a (hx lazy-load + load-more) | ✅ Yes | Deviation documented: agrupados fragment has no load-more row (never did); nothing dead added |
| D4b (htmx.ajax fan-out) | ✅ Yes | `:98-100`; refetch-on-expand accepted per design |
| D4c (hx-boost filters) | ✅ Yes (letter) | `hx-boost` carried per spec letter; functional mechanism is explicit hx-get/select/push (boost is inert on bare controls — documented deviation; `document.title` gap) |
| D5 (shim section) | ✅ Yes | Bottom of `catalogo-main.js`, v4 event names, `evt.detail.ctx`, double-bind guards |
| D6 (keep error fragments) | ✅ Yes | Fragments unchanged; colspan=8-vs-11 is pre-existing and out of scope |
| D7 (CSRF token source) | ✅ Yes | `csrf_token()` = same session token `csrf_meta()` serves; `CsrfManager` untouched |
| D8 (rollback order) | ✅ Yes | Boundaries intact: 6-file plugin diff + core docs; no schema/data changes |

### TDD Compliance

| Check | Result | Details |
|-------|--------|---------|
| TDD Evidence reported | ✅ | `apply-progress.md` "TDD Cycle Evidence" table (unit 2) + unit-1 RED/GREEN lines per phase |
| All tasks have tests | ✅ | 10/14 tasks test-backed (2.1–5.2); 4 are build/execution tasks (1.1, 1.2, 6.1, 6.2) verified by build + suite evidence per design Testing Strategy |
| RED confirmed (tests exist) | ✅ | 3/3 test files exist: `tests/Base/FsControllerHtmxTest.php`, `tests/Core/HtmxMacroContractTest.php`, `plugins/tarifario/tests/Controller/TarifCatalogoHtmxContractTest.php`; RED runs documented (3F+2E, 6E+2P, 3F, 1F+1guard) |
| GREEN confirmed (tests pass) | ✅ | Re-executed this refresh run: `--filter Htmx` 23/23 (74 assertions), `--filter TarifCatalogoHtmx` 12/12 (58 assertions); full suite includes the 12 new green (prior run) |
| Triangulation adequate | ✅ | 12 plugin cases across 9 contracts; 8 macro cases (script count, nonce, order, headers, token, 2 htmx-free guards); 3 isHtmx cases (present/absent/any-value) |
| Safety Net for modified files | ✅ | apply-progress records 11/11 and TarifTabPrecios 13/13 before unit-2 edits, 6/6 after 4.2, 10/10 after 4.4; full-suite delta vs master = exactly +12 green tests |

**TDD Compliance**: 6/6 checks passed.

### Test Layer Distribution

| Layer | Tests | Files | Tools |
|-------|-------|-------|-------|
| Unit | 5 | 2 (`FsControllerHtmxTest` 3, delegation tests in `TarifCatalogoHtmxContractTest` 2) | PHPUnit 11 (DDEV) |
| Contract/Integration (Twig render + source) | 18 | 2 (`HtmxMacroContractTest` 8, `TarifCatalogoHtmxContractTest` 10) | PHPUnit 11 + real Twig Environment |
| E2E | 0 | 0 | not installed (`e2e: false`) |
| **Total** | **23** | **3** | |

SUGGESTION: the 6 partial scenarios degrade to the highest available layer because no E2E harness exists; a one-time manual browser smoke (steps below) closes the gap.

### Changed File Coverage

Coverage analysis skipped — no coverage tool detected (not a failure).

### Quality Metrics

**Linter**: ➖ Not available locally. CI gates: PR #10 merged with 7/7 GitHub checks SUCCESS (CodeQL, SonarCloud, Socket — orchestrator-verified context). JS syntax: `node --check` ✅ (apply 4.4).
**Type Checker**: ➖ Not available.

### Assertion Quality

| File | Line | Assertion | Issue | Severity |
|------|------|-----------|-------|----------|
| (none — no trivial assertions found) | | | | |

All three test files audited (prior run): no tautologies, no orphan empty checks, no implementation-detail-only assertions; every test calls production code (real Twig render, reflection + live method call, or real source contracts); loops iterate static literal arrays (no ghost-loop risk); static state correctly reset (Kernel instance + `$_SERVER` teardown). One cosmetic naming typo in a test method name (see SUGGESTION-1).

**Assertion quality**: ✅ All assertions verify real behavior (0 CRITICAL, 0 WARNING).

### Issues Found

**CRITICAL**: None.

**WARNING**:
1. **Raw full-suite exit code 2** (prior run, carried over) — caused exclusively by the 18 pre-existing `Tests\Tarifario\*` errors (`Class FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent not found`), byte-identical to the verified plugin-master baseline (144 tests/18 errors before this change → 156/18 after = exactly the +12 new green tests). Not a regression of this change; the envelope's independent regression evidence is the baseline-excluded run (1261/3203, 0 errors, exit 0). **Triage is out of scope** and is recommended for the catalogo_core SDD (likely autoload/ordering of `ArticlePermissionFilterEvent`).
2. **Browser runtime smoke not executed** (no E2E harness cached, `e2e: false`). Six scenarios (HCS-07 POST, TCP-01/02/03/04/07) are PROVEN at the contract/Twig-render layer only; htmx runtime behaviors (conditional `hx-trigger` filter ordering, beforeend append, body swap + URL push, afterSwap re-bind) were not observed in a real browser. Exact manual smoke an operator can run after `ddev start`:
   1. Open `index.php?page=tarif_catalogo_view`; confirm `view/js/htmx.min.js` loads once (DevTools) and legacy screens render unchanged.
   2. Expand a family row → rows lazy-load into `#tbody-{codfamilia}`; collapse does NOT re-fetch (conditional trigger).
   3. Click load-more → new rows APPEND, pagination advances one page, consumed load-more row is replaced.
   4. Change tarifa/familia filter or type query + Enter → body swap renders filtered content AND the browser URL updates with the new params.
   5. Switch view mode / change sort with families expanded → each expanded family refetches (normal + grouped endpoints).
   6. Verify untouched flows: toggle switches (POST), inline edit, drag-reorder, JSON/Excel exports.
3. **No dedicated runtime test for the htmx-POST CSRF path** (HCS-07 scenario): wiring is proven statically (same session token, unchanged `validateCsrf()` header read, unchanged `CsrfManager`), and POST flows are intentionally out of pilot scope (TCP-06 keeps them on jQuery) — but no test executes an htmx POST end-to-end.

**SUGGESTION**:
1. Rename test method typo `test_hx_detection_delegates_to_fs_controller_ishxmxrequest` → `…_ishtmxrequest` (`TarifCatalogoHtmxContractTest.php:354`).
2. Document the `document.title` not refreshing on filter swaps (known cosmetic gap of the explicit-hx mechanism vs real `hx-boost`; apply Decisions #2).
3. Keep the agrupados-load-more deviation visible in the eventual PR description (apply Decisions #1) so reviewers don't expect a load-more row there.
4. Pre-existing `colspan=8` vs 11 columns in the normal-mode error row (`:737`) — flagged in design D6 as out of scope; worth a future one-line fix in the plugin.

### Scope Verification

- Core repo `feat/htmx4-catalog-pilot` (7d26d3de) vs master: ONLY `openspec/changes/htmx4-core-adoption/apply-progress.md` + `tasks.md` (2 files, docs). This refresh run additionally modified only the two delta spec.md files (reformat) and this report — no code.
- PR #10 (merged core enablement): 14 files — `base/fs_controller.php` (+12), `build.sh` (+1), `package.json` (+1), `view/js/htmx.min.js`, `themes/AdminLTE/view/Macro/Htmx.html.twig` (+35), 2 test files, 6 openspec planning docs. No `src/`, no header/footer, no other views.
- Plugin repo `plugins/tarifario` `e8bb5e7`: exactly the 6 in-scope files (4 view/JS, controller, contract test; 591+/197−).
- This run: zero code modifications, zero commits (verification-only per refresh mandate).

### Verdict

**PASS WITH WARNINGS** — All 19 requirements are PROVEN with cited evidence (12/18 scenarios fully compliant; the 6 partials share one root cause: no browser runtime evidence, with exact manual smoke steps provided). No critical findings, no regressions; the full-suite exit 2 is the documented pre-existing catalogo_core baseline. This refresh run confirms the reformed canonical specs parse to 19 requirements / 18 scenarios and both focused suites remain green.

## Key Learnings

1. Canonical OpenSpec delta specs need `### Requirement:` and `#### Scenario:` heading blocks, not table-only formats.
2. Requirements without their own scenario can share a sibling scenario when declared by annotation in the requirement body.
3. Contract tests over real Twig renders proved the htmx macro emission without a browser harness.
4. The controller diff being a single helper-removal hunk simultaneously proves TCP-08 and TCP-10 error-row immutability.
