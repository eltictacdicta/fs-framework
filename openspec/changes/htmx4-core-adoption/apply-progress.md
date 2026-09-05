# Apply Progress: htmx4-core-adoption

Work Unit 1 of 2 — core enablement ONLY (delivery_strategy: auto-chain,
chain_strategy: stacked-to-main; PR 2 pilot branches from main afterwards).
Strict TDD mode active (openspec/config.yaml `strict_tdd: true`, `tdd: true`).
Runner: `ddev exec php vendor/bin/phpunit`.

## Status

- **Unit 1 (this run)**: COMPLETE — Phases 1, 2, 3 + task 6.1.
- **Unit 2 (next run)**: PENDING — Phases 4, 5, task 6.2 (pilot: tarifario templates, JS trim, controller cleanup, full-suite verification).

## Completed Tasks

### Phase 1: Asset Provenance (D1, HCS-01/02)

- [x] 1.1 `npm install htmx.org@4.0.0` — verified `node_modules/htmx.org/dist/htmx.min.js` exists (36,716 bytes), license `BSD-0-Clause`, version `4.0.0` marker inside the asset. **npm dist path resolved → D1 primary path used; HCS-02 direct-vendor fallback NOT needed.**
- [x] 1.2 `package.json` gains `"htmx.org": "^4.0.0"` (npm wrote it exactly as specified); `build.sh` gains `cp node_modules/htmx.org/dist/htmx.min.js view/js/` (between font-awesome and jquery, before cleanup).

### Phase 2: isHtmxRequest() (D3, HCS-06)

- [x] 2.1 RED: `tests/Base/FsControllerHtmxTest.php` — 3 tests (present→true, absent→false, any value→true). RED run: 3 errors (`Call to undefined method fs_controller@anonymous::isHtmxRequest()`).
- [x] 2.2 GREEN: `isHtmxRequest(): bool` added to `base/fs_controller.php` beside `isAjax()` — `$this->request->headers->get('HX-Request') !== null`. GREEN run: OK (3 tests, 3 assertions).

### Phase 3: Boot Macro (D2/D7, HCS-03/04/05)

- [x] 3.1 RED: `tests/Core/HtmxMacroContractTest.php` — 8 tests (single script tag, nonce+defer on asset tag, nonce on both scripts, inherited-headers bootstrap on documentElement, X-CSRF-TOKEN + token value, bootstrap-before-asset ordering, header/footer htmx-free). RED run: 6 errors (template not found) + 2 passing regression guards on existing reality.
- [x] 3.2 GREEN: `themes/AdminLTE/view/Macro/Htmx.html.twig` — `boot()` emits inline `documentElement.setAttribute('hx-headers:inherited', JSON.stringify({'X-CSRF-TOKEN': csrf_token()}))` then the nonce'd deferred `<script src="view/js/htmx.min.js">`. GREEN run: OK (8 tests, 13 assertions).

### Phase 6 (this unit's slice)

- [x] 6.1 `ddev exec ./build.sh` green (composer install + npm install + all prior cp steps); `view/js/htmx.min.js` present with `4.0.0` marker; asset committed. 6.2 (full suite) belongs to Unit 2.

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1/1.2 | N/A (structural: npm dep + cp line) | Build | N/A (new dep) | ➖ Skipped: purely structural, single output | Via 6.1 build run ✅ | ➖ Skipped: same reason | ➖ None needed |
| 2.1/2.2 | `tests/Base/FsControllerHtmxTest.php` | Unit | ✅ 7/7 (FsControllerCsrfBlockingTest + SelectDefaultPageRedirectTest) | ✅ Written (3 errors) | ✅ OK (3 tests, 3 assertions) | ✅ 3 cases (present/absent/value-insensitive) | ➖ None needed (1-line impl) |
| 3.1/3.2 | `tests/Core/HtmxMacroContractTest.php` | Contract (Twig render) | ✅ header/footer guards pass pre-change | ✅ Written (6 errors) | ✅ OK (8 tests, 13 assertions) | ✅ 8 cases across 7 contracts | ➖ One assertion fix (see Decisions) |

### Test Summary

- **Total tests written**: 11 (3 unit + 8 contract)
- **Total tests passing**: 11
- **Layers used**: Unit (3), Twig contract (8), build verification (1 non-PHPUnit)
- **Approval tests**: None — no refactoring tasks (additive only)
- **Pure functions created**: 0 (method on existing controller, 1-line predicate)

## Work Unit Evidence

| Evidence | Required value |
|---|---|
| Focused test command + result | `ddev exec php vendor/bin/phpunit --filter Htmx` → OK, 11 tests, 16 assertions (exit 0; 6 pre-existing PHPUnit deprecations in unrelated files: SessionManagerTest, StealthModeTest, SessionAuthTest — doc-comment metadata, none mine) |
| Runtime harness + result | `ddev exec ./build.sh` → exit 0; `view/js/htmx.min.js` 36,716 bytes, `4.0.0` marker present; `git diff` confirms NO changes to `header.html.twig`, `footer.html.twig`, `plugins/**` |
| Rollback boundary | Revert commits `cb5bb632` (package.json, build.sh, view/js/htmx.min.js) + `df3931de` (macro, fs_controller, tests). Nothing outside these files depends on the additions (no callers of `isHtmxRequest()` yet; macro imported nowhere yet). |

## Decisions Taken

1. **D1 primary path, no fallback**: npm dist path `htmx.org/dist/htmx.min.js` verified live — HCS-02 direct-vendor fallback not invoked. `package.json` entry npm-authored as `^4.0.0` exactly.
2. **build.sh executed via ddev** (`ddev exec ./build.sh`): host lacks composer; container has composer+npm (AGENTS.md mandates ddev for composer commands). Side effect: container composer rewrote `vendor/composer/installed.php` git-reference hash — reverted (`git checkout --`) to keep the diff clean.
3. **Macro test assertion formatting fix (GREEN-stage)**: `bootstrapSetsInheritedHeadersOnDocumentElement` initially asserted a single-line `setAttribute(...)` substring; the readable multi-line macro tripped it. Fixed the assertion to a whitespace-tolerant regex — the behavioral contract (documentElement + `hx-headers:inherited`) is unchanged. Lesson: RED only proved "template missing"; whitespace assumptions only surface at GREEN.
4. **JSON.stringify bootstrap shape**: `JSON.stringify({'X-CSRF-TOKEN': '{{ csrf_token() }}'})` — runtime attribute value is exactly `{"X-CSRF-TOKEN":"<token>"}` per design contract; token (Symfony-generated hex) is JS-string-safe; keeps template readable and test assertions exact.
5. **Suite verification syntax**: PHPUnit 11 rejects `--testsuite` twice (warning); Base and Core suites verified with separate invocations (183 + 127 tests).

## Verification Evidence (unit 1 exit battery)

| Check | Command | Result |
|-------|---------|--------|
| New tests | `ddev exec php vendor/bin/phpunit --filter Htmx` | ✅ OK (11 tests, 16 assertions) |
| Base suite | `ddev exec php vendor/bin/phpunit --testsuite Base` | ✅ OK (183 tests, 551 assertions) |
| Core suite | `ddev exec php vendor/bin/phpunit --testsuite Core` | ✅ OK (127 tests, 292 assertions) |
| Build | `ddev exec ./build.sh` | ✅ exit 0; asset + 4.0.0 marker |
| Scope guard | `git status --short` / `git diff --stat` | ✅ no changes to header/footer/plugins/** |

## Commits

| Commit | Unit | Files |
|--------|------|-------|
| `cb5bb632` | chore(assets): vendor htmx 4.0.0 via npm + build.sh | package.json, build.sh, view/js/htmx.min.js |
| `df3931de` | feat(core): htmx boot macro + HX request detection | Macro/Htmx.html.twig, fs_controller.php, 2 test files |
| (this one) | docs(openspec): htmx4-core-adoption planning artifacts | openspec/changes/htmx4-core-adoption/** |

## Remaining (Unit 2 — next run)

- [ ] 4.1/4.2/4.3/4.4 Pilot templates + JS (tarifario hx attributes, htmx.ajax fan-out, afterSwap shim)
- [ ] 5.1/5.2 Controller cleanup (remove dead `is_htmx_request()`; delegate to `fs_controller::isHtmxRequest()`)
- [ ] 6.2 Full-suite green + TCP-09/10 confirmation
