# Proposal: Make regularizacion_iva optional for catalogo_core

## Intent

`plugins/catalogo_core/extras/fbase_controller.php:676` defines `private function validateFacturaEjercicio(?object $ejercicio, object $factura): bool`. Its only external dependency is `new regularizacion_iva()` at line 688. The method is referenced from exactly two sites in the same file: line 316 (`fbase_facturar_albaran_cliente`) and line 536 (`fbase_facturar_albaran_proveedor`).

Both caller methods are `protected` and have **zero call sites in the entire repository** (controllers, plugins, tests). The only concrete subclass of `fbase_controller` in this repo is `plugins/tarifario/extras/tarif_controller.php`, which uses `fbase_paginas` only and never invokes either albaran method. The three controllers in `plugins/clientes_facturacion/controller/` extend `fs_controller` directly.

Consequence: the entire `validateFacturaEjercicio` chain is dead code. Its sole purpose is to require `regularizacion_iva` at load time, which is why `plugins/catalogo_core/fsframework.ini:5` declares `require = "clientes_facturacion"` — a defensive coupling with no load-bearing call. This change removes the dead code, removes the defensive require, and adds an anti-regression test that documents the decoupling. **This is an architectural cleanup, not a bug fix**: no current code path changes observable behavior.

## Scope

### In Scope

- Delete `validateFacturaEjercicio()` at `plugins/catalogo_core/extras/fbase_controller.php:676–700`.
- Delete the two call-site blocks at `plugins/catalogo_core/extras/fbase_controller.php:316–318` and `:536–538` (`if (!$this->validateFacturaEjercicio(...)) return FALSE;`).
- Remove `require = "clientes_facturacion"` from `plugins/catalogo_core/fsframework.ini:5`. Line becomes `require = ""`.
- Update the stale comment at `plugins/clientes_facturacion/tests/ModelLoadingTest.php:547`. The comment claims `ventas_clientes.php` is in `facturacion_base/controller/` and extends `fbase_controller`. In the current repo, `facturacion_base/` does not exist; `ventas_clientes.php` lives at `plugins/clientes_core/controller/ventas_clientes.php:25` and extends `clientes_controller` (`plugins/clientes_core/extras/clientes_controller.php:24`, which extends `fs_controller`). The new comment must reflect this reality.
- Add a new anti-regression test file `plugins/clientes_facturacion/tests/CatalogoCoreDecouplingTest.php` with three assertions: (a) `catalogo_core/fsframework.ini` no longer lists `clientes_facturacion` in `require`; (b) `catalogo_core/extras/fbase_controller.php` does not contain the string `regularizacion_iva`; (c) `fbase_controller` has no method named `validateFacturaEjercicio`.

### Out of Scope

- The `regularizacion_iva` model and its 30-line bridge stay where they are. Still used at `plugins/clientes_facturacion/model/core/factura_cliente.php:151` and `:590`. `ModelLoadingTest::testRegularizacionIvaLoadsFromClientesFacturacion` must keep passing.
- No template-method, hook, or trait is introduced. The exploration phase considered and rejected these as over-engineering for a dead-code removal.
- No verification against the external `facturacion_base/` repository. The anti-regression test is the safety net (per P1).
- `facturacion_base` `require` chain and any controllers that might call `fbase_facturar_albaran_cliente` / `_proveedor` from outside this repo: not validated here, not affected by this change because the call sites are deleted.

## Capabilities

- **New Capabilities**: None.
- **Modified Capabilities**: None.

Pure dead-code removal at the PHP level, with a defensive `require` line dropped. No spec-level requirement changes. The `sdd-spec` phase will produce one delta spec at `plugins/clientes_facturacion/openspec/changes/optional-iva-regularization/specs/catalogo-core-decoupling/spec.md` codifying the decoupling contract (the three assertions of the anti-regression test as normative requirements).

## Approach

The change applies four user decisions reached during the explore phase. **P1 (external repo risk)**: the SDD does not try to verify behavior in the external `facturacion_base` repository; the new anti-regression test guards the decoupling contract locally and is the sole safety net. **P2 (stale comment)**: the factually wrong `ModelLoadingTest.php:547` comment is corrected in the same change as the deletions, not deferred. **P3 (regression test)**: a dedicated test file at `plugins/clientes_facturacion/tests/CatalogoCoreDecouplingTest.php` asserts the three conditions above. **P4 (inline docs)**: no inline comment is added to `fbase_controller.php`; all documentation lives in the SDD archive.

The five file changes are independent and can land as a single commit (or one commit per file if `sdd-tasks` recommends granular commits). The plugin's `strict_tdd: true` setting in `plugins/clientes_facturacion/openspec/config.yaml` governs downstream phases: the anti-regression test must be written and observed failing **before** the deletions land, then the deletions land, then the test must pass.

## Affected Areas

| Area | Impact |
|------|--------|
| `plugins/catalogo_core/extras/fbase_controller.php:676–700` | Deleted: `validateFacturaEjercicio()` method body |
| `plugins/catalogo_core/extras/fbase_controller.php:316–318` | Deleted: 3-line call block in `fbase_facturar_albaran_cliente` |
| `plugins/catalogo_core/extras/fbase_controller.php:536–538` | Deleted: 3-line call block in `fbase_facturar_albaran_proveedor` |
| `plugins/catalogo_core/fsframework.ini:5` | `require = "clientes_facturacion"` → `require = ""` |
| `plugins/clientes_facturacion/tests/ModelLoadingTest.php:547` | Stale comment updated to reflect current inheritance |
| `plugins/clientes_facturacion/tests/CatalogoCoreDecouplingTest.php` | New file: 3-assertion anti-regression test |

## Risks

| ID | Risk | Severity | Mitigation |
|----|------|----------|------------|
| **R1** | External `facturacion_base` repository may have a controller that calls `fbase_facturar_albaran_cliente` / `_proveedor`; removing the validation changes behavior there (no IVA regularization check fires on those calls). | **WARNING (accepted)** | Per P1, the SDD does not scan the external repo. The anti-regression test documents the absence of validation. If the external repo is later inlined or vendored, a follow-up SDD will re-introduce the guard at the appropriate boundary. |
| **R2** | Behavior drift: if a future controller in this repo ever calls the albaran methods, no VAT regularization validation will run. | LOW | The anti-regression test asserts `fbase_controller` has no `validateFacturaEjercicio` method, making the absence intentional and discoverable. Future callers must add their own validation, which is the correct contract. |
| **R3** | Stale comment at `ModelLoadingTest.php:547` is in a test that is auto-discovered by both the root **Plugins** suite and the plugin's own `phpunit.xml`; if a fix is botched, the suite fails. | LOW | Comment fix is a single-line edit; no assertion logic changes. `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` runs the full plugin suite on every change. |

## Rollback Plan

`git revert <merge-commit>` restores all six file changes in one step. The `require` line and the dead code are re-introduced byte-for-byte (no DB schema involved; no migrations). Verify rollback with `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` — the existing `ModelLoadingTest::testRegularizacionIvaLoadsFromClientesFacturacion` must continue to pass before and after the revert.

## Dependencies

- No new Composer dependencies; no `vendor/` change.
- No DB schema change.
- `plugins/catalogo_core/fsframework.ini` `require` becomes empty (`""`). The plugin still `require`s `clientes_core` indirectly via its own consumers; this is not altered.
- `facturacion_base` and `tpvmod` are untouched in this repo. Their external use of the deleted method is out of scope (R1).

## Success Criteria

- [ ] `validateFacturaEjercicio()` is gone from `plugins/catalogo_core/extras/fbase_controller.php` (verified by grep on the symbol name).
- [ ] Both call-site blocks (lines 316 and 536) are gone from the same file.
- [ ] `plugins/catalogo_core/fsframework.ini` line 5 reads `require = ""` (or equivalent empty value).
- [ ] `plugins/clientes_facturacion/tests/ModelLoadingTest.php:547` comment reflects current state (no mention of `ventas_clientes` extending `fbase_controller`).
- [ ] New `plugins/clientes_facturacion/tests/CatalogoCoreDecouplingTest.php` exists with three assertions, all green.
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` passes in full, including `ModelLoadingTest::testRegularizacionIvaLoadsFromClientesFacturacion` (regression guard for the kept model).
- [ ] Root `ddev exec php vendor/bin/phpunit --testsuite Plugins` still discovers and passes the plugin tests.
- [ ] `ddev exec composer phpstan` shows no new errors attributable to the deletions.
- [ ] No entries in core `openspec/changes/optional-iva-regularization/` (plugin-local ownership respected).

## Test Plan

The new file `plugins/clientes_facturacion/tests/CatalogoCoreDecouplingTest.php` extends `PHPUnit\Framework\TestCase` and uses `FS_FOLDER` (defined in `tests/bootstrap.php`) as the project root. It runs under the plugin's own `phpunit.xml` which has `processIsolation="true"` and `stopOnFailure="false"`, so the three assertions are independent and a failure on one does not mask the others. Assertions:

1. **No defensive require**: read `plugins/catalogo_core/fsframework.ini`, parse the `require` line, assert `clientes_facturacion` is not among the comma-separated entries.
2. **No `regularizacion_iva` reference in `fbase_controller`**: read `plugins/catalogo_core/extras/fbase_controller.php`, assert it does not contain the literal string `regularizacion_iva` anywhere.
3. **No `validateFacturaEjercicio` method**: use Reflection on the `fbase_controller` class (the file is loaded via `require_once` against the absolute path under `FS_FOLDER`); assert `method_exists` returns `false` for the deleted method.

The test is intentionally string/regex based (not behavioral) because the contract under verification is **structural**: the dead code stays dead and the defensive coupling stays absent. Following `strict_tdd: true`, this test is written first, observed failing on `main` (where the method and require still exist), and only then do the deletions land.

## Open Questions

None. The four product questions (P1 external-repo risk, P2 stale comment, P3 regression test, P4 inline docs) were answered during the explore phase and are encoded above.
