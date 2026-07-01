# Tasks: Make regularizacion_iva optional for catalogo_core

**Change**: `optional-iva-regularization` (plugin SDD — `plugins/clientes_facturacion/openspec/changes/optional-iva-regularization/`)
**Strict TDD**: `true` (per `plugins/clientes_facturacion/openspec/config.yaml`)

## Goal

Remove dead `validateFacturaEjercicio()` + 2 call sites in `fbase_controller.php`; drop the defensive `require = "clientes_facturacion"` from `catalogo_core/fsframework.ini`; correct a stale comment in `ModelLoadingTest.php`. Architectural cleanup, not a bug fix.

## Pre-flight (cached)

| Dimension | Choice |
|---|---|
| Pace | A1 — Interactive |
| Artifacts | B1 — OpenSpec (this file) |
| Chained PR strategy | C1 — ask-always |
| Review budget | D1 — 400 lines |

## Review Workload Forecast

- **Estimated changed lines**: ~20 net (T2: ~+50 new test; T3: ~-30 deletions; T4–T7: 0 net).
- **400-line budget risk**: Low — well under 400.
- **Chained PRs recommended**: No — 5 file changes are atomic for the contract.
- **Decision needed before apply**: No — forecast unambiguous; C1 ask-always satisfied by default.

Decision needed before apply: **No**
Chained PRs recommended: **No**
Chain strategy: **size-exception** (single commit, 5 files, 1 PR)
400-line budget risk: **Low**

## Tasks

### T1. Establish PHPUnit baseline

- **Files**: none
- Run `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml`; confirm green; record test count.
- **TDD gate**: N/A. **Depends on**: none. **LoC**: 0.

### T2. Write anti-regression test (RED)

- **Files (create)**: `/home/javier/tarifario-07-2026/plugins/clientes_facturacion/tests/CatalogoCoreDecouplingTest.php`
- Namespace `Tests\ClientesFacturacion`; `setUp()` does `require_once FS_FOLDER . '/plugins/catalogo_core/extras/fbase_controller.php';`. 3 methods per design §5: ini-require, file-string-grep, `method_exists` (bare FQN `'fbase_controller'`).
- **TDD gate**: RED — `--filter CatalogoCoreDecouplingTest` → 3 failures.
- **Depends on**: T1. **LoC**: ~+50.

### T3. Delete dead method + 2 call sites

- **Files (modify)**: `/home/javier/tarifario-07-2026/plugins/catalogo_core/extras/fbase_controller.php`
- Remove `validateFacturaEjercicio()` at lines 676–700; remove the two `if (!$this->validateFacturaEjercicio(...)) return FALSE;` blocks at 316–318 and 536–538. Per design §4.1: 3 deletions, ~30 lines.
- **TDD gate**: GREEN — 3 anti-regression assertions pass AND `testRegularizacionIvaLoadsFromClientesFacturacion` still passes.
- **Depends on**: T2. **LoC**: ~-30.

### T4. Drop defensive `require` from `catalogo_core/fsframework.ini`

- **Files (modify)**: `/home/javier/tarifario-07-2026/plugins/catalogo_core/fsframework.ini`
- Line 5: `require = "clientes_facturacion"` → `require = ""`.
- **TDD gate**: GREEN — 3 anti-regression assertions still pass; root `--testsuite Plugins` still discovers.
- **Depends on**: T3. **LoC**: 0 net (1 line edited).

### T5. Correct stale comment in `ModelLoadingTest.php`

- **Files (modify)**: `/home/javier/tarifario-07-2026/plugins/clientes_facturacion/tests/ModelLoadingTest.php`
- Per design §4.3: update docblock 529–543 + message 547. `ventas_clientes.php` is at `plugins/clientes_core/controller/ventas_clientes.php:25`, extends `clientes_controller` (which extends `fs_controller`), no `require_once` to `fbase_controller.php`.
- **TDD gate**: GREEN — comment matches reality.
- **Depends on**: T4. **LoC**: ~0 net (lines edited, count unchanged).

### T6. Final verification (full suite + Plugins + PHPStan)

- **Files**: none
- Run (a) `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml`; (b) `ddev exec php vendor/bin/phpunit --testsuite Plugins`; (c) `ddev exec composer phpstan`. All clean.
- **TDD gate**: N/A. **Depends on**: T5. **LoC**: 0.

### T7. Commit + verify-report prep

- **Files**: none (produces commit)
- Single commit on `master` (HEAD `d62eefc3`); conventional-commits style per `git log --oneline -10`. Subject: `refactor(catalogo_core): remove dead validateFacturaEjercicio and decouple from clientes_facturacion`. Body references the SDD path and lists 5 files.
- **TDD gate**: N/A. **Depends on**: T6. **LoC**: 0.

## Acceptance criteria

- [x] `validateFacturaEjercicio()` is gone (grep on symbol passes).
- [x] Both call-site blocks (316, 536) are gone.
- [x] `catalogo_core/fsframework.ini:5` reads `require = ""`.
- [x] `ModelLoadingTest.php:547` comment reflects current state.
- [x] `CatalogoCoreDecouplingTest.php` exists with 3 assertions, all green.
- [x] Plugin PHPUnit suite passes incl. `testRegularizacionIvaLoadsFromClientesFacturacion`.
- [x] Root `--testsuite Plugins` still passes.
- [x] `composer phpstan` shows no new errors.
- [x] No entries in core `openspec/changes/optional-iva-regularization/`.
- [x] Single commit on `master`; conventional-commits style.

## Verification checklist (T6)

- `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` → all green.
- `ddev exec php vendor/bin/phpunit --testsuite Plugins` → no regression.
- `ddev exec composer phpstan` → no new errors.

## Out of scope

- No template-method, hook, or trait (zero callers).
- No migration of `regularizacion_iva` model or 30-line bridge.
- No DB schema, no XML edit, no `vendor/`, no `composer.json`/`composer.lock`.
- No CHANGELOG, no version bump, no `config.yaml` edit.
- No external `facturacion_base/` verification (R1, accepted; anti-regression test is sole safety net).
- No entry in core `openspec/changes/optional-iva-regularization/`.
