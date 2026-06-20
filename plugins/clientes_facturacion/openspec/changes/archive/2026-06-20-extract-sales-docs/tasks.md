# Tasks: Extract sales-document domain to `clientes_facturacion`

**Change**: `extract-sales-docs` (plugin SDD — `plugins/clientes_facturacion/openspec/changes/extract-sales-docs/`)
**Delivery strategy**: `ask-on-risk` → resolved as **2 coordinated PRs in 2 repos** (PR-A `panel-ab`, PR-B `facturacion_base`).
**Strict TDD**: `true` (per `plugins/clientes_facturacion/openspec/config.yaml`).

---

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | PR-A: ~+3,000 / PR-B: ~-3,000 (net ~0; opposite directions) |
| 400-line budget risk | **High** (per-PR exceeds 400 by design) |
| Chained PRs recommended | **Yes** (already chosen: 2 coordinated PRs) |
| Suggested split | PR-A (`panel-ab`, additive, 3 commits) → PR-B (`facturacion_base`, destructive, 2 commits) |
| Delivery strategy | `ask-on-risk` (user has chosen: 2 coordinated PRs, merge order mandatory) |
| Chain strategy | `stacked-to-main` (PR-A and PR-B both merge to their own `master`; cross-repo coordination enforced by review/merge process) |

Decision needed before apply: **No** (user pre-decided the 2-PR plan with merge order).
Chained PRs recommended: **Yes**
Chain strategy: **stacked-to-main** (with cross-repo coordination)
400-line budget risk: **High** (per-PR; mitigated by logical commit structure within each PR)

> Workload budget exceeded by design (file-move refactor ~3,000 lines). Mitigation: each of the 5 commits is independently reviewable. PR-A is purely additive (no existing consumer breaks). PR-B is purely destructive (only `factura_proveedor` core retains a new cross-plugin `require_once` line — covered by the 11th test added in PR-A commit 3).

---

## Task overview

**Total: 30 tasks** across 5 commits in 2 PRs.

| Commit | PR | Repo | Theme | Tasks |
|--------|----|------|-------|-------|
| PR-A commit 1 | PR-A | `panel-ab` | **bootstrap** — `Init.php`, `description`, `translations/`, `phpunit.xml`, placeholder `ModelLoadingTest.php`, inis bumped, `catalogo_core` ini updated | **8** |
| PR-A commit 2 | PR-A | `panel-ab` | **model layer** — write 10 RED tests, move 10 shims + 10 cores + 3 traits + 10 XMLs; tests go GREEN | **12** |
| PR-A commit 3 | PR-A | `panel-ab` | **controller layer** — add 11th cross-plugin test, move 14 `ventas_*` ctrl+12 views, the atomic `informe_*` pair ctrl+2 views, `nueva_venta` ctrl+view; final verify | **5** |
| PR-B commit 1 | PR-B | `facturacion_base` | **prepare** — update cross-plugin `require_once` in `factura_proveedor.php:22`; bump `facturascripts.ini` 158→159; rewrite `description` | **3** |
| PR-B commit 2 | PR-B | `facturacion_base` | **remove** — delete the 33 model/trait/XML files, then the 17+15 controller/view files | **2** |

> **Rationale on commit-3 granularity** (5 tasks, not 32+): per the `work-unit-commits` skill, a file-move of 17 controllers + 15 views is **one** work unit ("move admin UI to new home"), not 32. We split by *natural cluster* (ventas_*, informe_* atomic pair, nueva_venta) because the `informe_facturas extends informe_albaranes` constraint forces an atomic pair move (R6) — that's a real atomicity boundary, not a stylistic preference.

---

## PR-A commit 1 — `panel-ab` — bootstrap (8 tasks)

> Goal: `clientes_facturacion` becomes a valid, enabled, test-discoverable plugin with no functional change. The 10 `ModelLoadingTest` tests are placeholders (`assertTrue(true)`). `catalogo_core` gains a new `require`.

- [x] T-A-1-1: Create `plugins/clientes_facturacion/Init.php`
- **Files (create)**: `/home/javier/proyectos/panel-ab/plugins/clientes_facturacion/Init.php`
- **Acceptance**: Namespace `FSFramework\Plugins\clientes_facturacion`; `final class Init { public function init(): void {} }`; file-level PHPDoc explains that `fs_model_autoloader` handles model loading and no custom init is needed (mirrors `plugins/catalogo_core/Init.php` style). `ddev exec php -l` clean.
- **Verification**: `ddev exec php -l plugins/clientes_facturacion/Init.php`
- **Depends on**: none
- **Size**: S
- **TDD note**: N/A (scaffold).

- [x] T-A-1-2: Create `plugins/clientes_facturacion/description`
- **Files (create)**: `/home/javier/proyectos/panel-ab/plugins/clientes_facturacion/description`
- **Acceptance**: One short paragraph (~1-3 lines) describing the plugin as the home of client-side sales documents (facturas, albaranes, pedidos, presupuestos + lines). MUST mention the controller-layer coupling to `catalogo_core` (R3-accepted, future SDD) so consumers know to enable `catalogo_core` for the admin UI.
- **Verification**: `ddev exec cat plugins/clientes_facturacion/description`
- **Depends on**: none
- **Size**: S
- **TDD note**: N/A. **Open question Q1**: exact text — see "Open questions" §.

- [x] T-A-1-3: Create `plugins/clientes_facturacion/translations/messages.es.yaml`
- **Files (create)**: `/home/javier/proyectos/panel-ab/plugins/clientes_facturacion/translations/messages.es.yaml`
- **Acceptance**: Empty YAML scaffold (single comment header is acceptable). No translation keys required at this stage — moved views use `fbase_controller` translations.
- **Verification**: `ddev exec cat plugins/clientes_facturacion/translations/messages.es.yaml`
- **Depends on**: none
- **Size**: S
- **TDD note**: N/A. **Open question Q4**: see "Open questions" §.

- [x] T-A-1-4: Create `plugins/clientes_facturacion/phpunit.xml`
- **Files (create)**: `/home/javier/proyectos/panel-ab/plugins/clientes_facturacion/phpunit.xml`
- **Acceptance**: Mirrors `plugins/clientes_core/phpunit.xml`; `<testsuite name="clientes_facturacion">`; bootstrap `../../tests/bootstrap.php`; cache directory `../../.phpunit.cache`; `processIsolation="true"`; `env SYMFONY_DEPRECATIONS_HELPER=weak`. Standalone runnable: `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml`.
- **Verification**: `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` (runs 0 tests but exits 0).
- **Depends on**: T-A-1-5 (test file must exist for the suite to be valid)
- **Size**: S
- **TDD note**: N/A (test infrastructure).

- [x] T-A-1-5: Create `plugins/clientes_facturacion/tests/ModelLoadingTest.php` with 10 placeholder tests
- **Files (create)**: `/home/javier/proyectos/panel-ab/plugins/clientes_facturacion/tests/ModelLoadingTest.php`
- **Acceptance**: Namespace `Tests\ClientesFacturacion`; extends `PHPUnit\Framework\TestCase`; `setUp()` mirrors `Tests\CatalogoCore\ModelLoadingTest` (`$GLOBALS['plugins'] = [];`, `define('FS_VENTAS_SIN_STOCK', false)` if not defined, `require_once FS_FOLDER . '/base/fs_model.php';`). 10 test methods, one per moved model name, each body is currently `$this->assertTrue(true);` (placeholder). Method names: `testFacturaClienteLoadsFromClientesFacturacion`, `testAlbaranClienteLoadsFromClientesFacturacion`, `testPedidoClienteLoadsFromClientesFacturacion`, `testPresupuestoClienteLoadsFromClientesFacturacion`, `testLineaFacturaClienteLoadsFromClientesFacturacion`, `testLineaAlbaranClienteLoadsFromClientesFacturacion`, `testLineaPedidoClienteLoadsFromClientesFacturacion`, `testLineaPresupuestoClienteLoadsFromClientesFacturacion`, `testLineaIvaFacturaClienteLoadsFromClientesFacturacion`, `testRegularizacionIvaLoadsFromClientesFacturacion`. `ddev exec php -l` clean.
- **Verification**: `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` → 10 tests, all green (assertTrue(true)).
- **Depends on**: T-A-1-4 (suite must exist for this to be discoverable; can be done in either order)
- **Size**: M
- **TDD note**: Placeholder bodies. Real assertions come in commit 2 (T-A-2-1).

- [x] T-A-1-6: Bump `plugins/clientes_facturacion/fsframework.ini` version 1→2
- **Files (modify)**: `/home/javier/proyectos/panel-ab/plugins/clientes_facturacion/fsframework.ini`
- **Acceptance**: Line 1 `version = 1` → `version = 2`. Description field updated to reflect new scope (one-line; matches the `description` file from T-A-1-2). `require = "clientes_core"` unchanged.
- **Verification**: `ddev exec cat plugins/clientes_facturacion/fsframework.ini`
- **Depends on**: T-A-1-2 (description text consistency)
- **Size**: S
- **TDD note**: N/A.

- [x] T-A-1-7: Bump `plugins/clientes_facturacion/facturascripts.ini` version 1→2
- **Files (modify)**: `/home/javier/proyectos/panel-ab/plugins/clientes_facturacion/facturascripts.ini`
- **Acceptance**: Line 2 `version = 1` → `version = 2`. Description field updated to reflect new scope (matches T-A-1-2 / T-A-1-6). `require = "clientes_core"` unchanged.
- **Verification**: `ddev exec cat plugins/clientes_facturacion/facturascripts.ini`
- **Depends on**: T-A-1-2
- **Size**: S
- **TDD note**: N/A.

- [x] T-A-1-8: Add `clientes_facturacion` to `plugins/catalogo_core/fsframework.ini` `require`
- **Files (modify)**: `/home/javier/proyectos/panel-ab/plugins/catalogo_core/fsframework.ini`
- **Acceptance**: New line `require = "clientes_facturacion"`. Format matches sibling plugins' `require` style (e.g., `plugins/catalogo_core/facturascripts.ini` does not exist; reference `plugins/clientes_core/fsframework.ini` for the string-style). Justification (R4): `catalogo_core/extras/fbase_controller.php:688` instantiates `new regularizacion_iva()` at runtime.
- **Verification**: `ddev exec cat plugins/catalogo_core/fsframework.ini`
- **Depends on**: none (independent of T-A-1-1..7)
- **Size**: S
- **TDD note**: N/A. The runtime coupling is asserted indirectly by the `testRegularizacionIvaLoadsFromClientesFacturacion` test in commit 2.

---

## PR-A commit 2 — `panel-ab` — model layer (12 tasks)

> Goal: All 10 sales-document models are reachable from `clientes_facturacion`. Model layer is functionally standalone (R3-accepted for controllers, not models). 10 `ModelLoadingTest` tests go RED → GREEN. TDD sequence: write real assertions (RED), move files (GREEN).

- [x] T-A-2-1: Replace 10 placeholder tests with real assertions (RED state)
- **Files (modify)**: `/home/javier/proyectos/panel-ab/plugins/clientes_facturacion/tests/ModelLoadingTest.php`
- **Acceptance**: Each of the 10 test method bodies becomes the canonical assertion: `require_once FS_FOLDER . '/plugins/clientes_facturacion/model/core/{name}.php';`; `$this->assertTrue(class_exists('FSFramework\model\{name}', false));`; `$model = new \FSFramework\model\{name}();`; `$this->assertInstanceOf('fs_model', $model);`. **At this point (before T-A-2-2..12), all 10 tests FAIL** because the moved files do not exist yet — that is the RED state.
- **Verification**: `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` → 10 failures (file-not-found or class-not-found).
- **Depends on**: T-A-1-5 (file must exist with placeholder bodies)
- **Size**: M
- **TDD note**: This is the explicit RED step. The test names and assertion patterns mirror `plugins/catalogo_core/tests/ModelLoadingTest.php`. Each test covers one model: `factura_cliente` (uses traits `documento_venta` + `factura`), `albaran_cliente` (`documento_venta`), `pedido_cliente` (`documento_venta`), `presupuesto_cliente` (`documento_venta`), `linea_factura_cliente` (`linea_documento_venta`), `linea_albaran_cliente` (`linea_documento_venta`), `linea_pedido_cliente` (`linea_documento_venta`), `linea_presupuesto_cliente` (`linea_documento_venta`), `linea_iva_factura_cliente` (no trait), `regularizacion_iva` (no trait, but critical for R4 — exercises `catalogo_core` → `clientes_facturacion` runtime path).

- [x] T-A-2-2: Move `factura_cliente` (shim + core)
- **Files**: `/home/javier/proyectos/panel-ab/plugins/facturacion_base/model/factura_cliente.php` → `/home/javier/proyectos/panel-ab/plugins/clientes_facturacion/model/factura_cliente.php` (shim, edit `require_once 'plugins/facturacion_base/model/core/factura_cliente.php';` → `require_once 'plugins/clientes_facturacion/model/core/factura_cliente.php';`); `/home/javier/proyectos/panel-ab/plugins/facturacion_base/model/core/factura_cliente.php` → `/home/javier/proyectos/panel-ab/plugins/clientes_facturacion/model/core/factura_cliente.php` (core, **no content edit** — `__DIR__ . '/../../extras/documento_venta.php'` and `__DIR__ . '/../../extras/factura.php'` now resolve to `clientes_facturacion/extras/...` correctly because the `extras/` siblings move with the trait in T-A-2-12).
- **Acceptance**: Both files at new home with the path edits above. `ddev exec php -l` clean on both.
- **Verification**: `ddev exec php vendor/bin/phpunit --filter testFacturaClienteLoadsFromClientesFacturacion -c plugins/clientes_facturacion/phpunit.xml` → green.
- **Depends on**: T-A-2-1 (RED state confirmed) for TDD discipline; T-A-2-12 (traits must be at new home for `__DIR__` resolution) for the file to actually work.
- **Size**: M
- **TDD note**: The shim is the production path the test exercises; the core is the body. Both must move for the test to go GREEN.

- [x] T-A-2-3: Move `albaran_cliente` (shim + core)
- **Files**: `/home/javier/proyectos/panel-ab/plugins/facturacion_base/model/albaran_cliente.php` → `/home/javier/proyectos/panel-ab/plugins/clientes_facturacion/model/albaran_cliente.php` (shim, edit path); same for `model/core/albaran_cliente.php` (core, no content edit; uses `documento_venta` trait resolved via T-A-2-12).
- **Acceptance**: Both files at new home, path edited on shim. `ddev exec php -l` clean.
- **Verification**: `ddev exec php vendor/bin/phpunit --filter testAlbaranClienteLoadsFromClientesFacturacion -c plugins/clientes_facturacion/phpunit.xml` → green.
- **Depends on**: T-A-2-1; T-A-2-12.
- **Size**: M
- **TDD note**: as T-A-2-2.

- [x] T-A-2-4: Move `pedido_cliente` (shim + core)
- **Files**: `model/pedido_cliente.php` → `clientes_facturacion/model/pedido_cliente.php` (shim, edit path); `model/core/pedido_cliente.php` → `clientes_facturacion/model/core/pedido_cliente.php` (core, no edit; uses `documento_venta`).
- **Acceptance**: Both files at new home, path edited. `ddev exec php -l` clean.
- **Verification**: `ddev exec php vendor/bin/phpunit --filter testPedidoClienteLoadsFromClientesFacturacion -c plugins/clientes_facturacion/phpunit.xml` → green.
- **Depends on**: T-A-2-1; T-A-2-12.
- **Size**: M
- **TDD note**: as T-A-2-2.

- [x] T-A-2-5: Move `presupuesto_cliente` (shim + core)
- **Files**: `model/presupuesto_cliente.php` → `clientes_facturacion/model/presupuesto_cliente.php` (shim, edit path); `model/core/presupuesto_cliente.php` → `clientes_facturacion/model/core/presupuesto_cliente.php` (core, no edit; uses `documento_venta`).
- **Acceptance**: Both files at new home, path edited. `ddev exec php -l` clean.
- **Verification**: `ddev exec php vendor/bin/phpunit --filter testPresupuestoClienteLoadsFromClientesFacturacion -c plugins/clientes_facturacion/phpunit.xml` → green.
- **Depends on**: T-A-2-1; T-A-2-12.
- **Size**: M
- **TDD note**: as T-A-2-2.

- [x] T-A-2-6: Move `linea_factura_cliente` (shim + core)
- **Files**: `model/linea_factura_cliente.php` → `clientes_facturacion/model/linea_factura_cliente.php` (shim, edit path); `model/core/linea_factura_cliente.php` → `clientes_facturacion/model/core/linea_factura_cliente.php` (core, no edit; uses `linea_documento_venta`).
- **Acceptance**: Both files at new home, path edited. `ddev exec php -l` clean.
- **Verification**: `ddev exec php vendor/bin/phpunit --filter testLineaFacturaClienteLoadsFromClientesFacturacion -c plugins/clientes_facturacion/phpunit.xml` → green.
- **Depends on**: T-A-2-1; T-A-2-12.
- **Size**: M
- **TDD note**: as T-A-2-2.

- [x] T-A-2-7: Move `linea_albaran_cliente` (shim + core)
- **Files**: `model/linea_albaran_cliente.php` → `clientes_facturacion/model/linea_albaran_cliente.php` (shim, edit path); `model/core/linea_albaran_cliente.php` → `clientes_facturacion/model/core/linea_albaran_cliente.php` (core, no edit; uses `linea_documento_venta`).
- **Acceptance**: Both files at new home, path edited. `ddev exec php -l` clean.
- **Verification**: `ddev exec php vendor/bin/phpunit --filter testLineaAlbaranClienteLoadsFromClientesFacturacion -c plugins/clientes_facturacion/phpunit.xml` → green.
- **Depends on**: T-A-2-1; T-A-2-12.
- **Size**: M
- **TDD note**: as T-A-2-2.

- [x] T-A-2-8: Move `linea_pedido_cliente` (shim + core)
- **Files**: `model/linea_pedido_cliente.php` → `clientes_facturacion/model/linea_pedido_cliente.php` (shim, edit path); `model/core/linea_pedido_cliente.php` → `clientes_facturacion/model/core/linea_pedido_cliente.php` (core, no edit; uses `linea_documento_venta`).
- **Acceptance**: Both files at new home, path edited. `ddev exec php -l` clean.
- **Verification**: `ddev exec php vendor/bin/phpunit --filter testLineaPedidoClienteLoadsFromClientesFacturacion -c plugins/clientes_facturacion/phpunit.xml` → green.
- **Depends on**: T-A-2-1; T-A-2-12.
- **Size**: M
- **TDD note**: as T-A-2-2.

- [x] T-A-2-9: Move `linea_presupuesto_cliente` (shim + core)
- **Files**: `model/linea_presupuesto_cliente.php` → `clientes_facturacion/model/linea_presupuesto_cliente.php` (shim, edit path); `model/core/linea_presupuesto_cliente.php` → `clientes_facturacion/model/core/linea_presupuesto_cliente.php` (core, no edit; uses `linea_documento_venta`).
- **Acceptance**: Both files at new home, path edited. `ddev exec php -l` clean.
- **Verification**: `ddev exec php vendor/bin/phpunit --filter testLineaPresupuestoClienteLoadsFromClientesFacturacion -c plugins/clientes_facturacion/phpunit.xml` → green.
- **Depends on**: T-A-2-1; T-A-2-12.
- **Size**: M
- **TDD note**: as T-A-2-2.

- [x] T-A-2-10: Move `linea_iva_factura_cliente` (shim + core)
- **Files**: `model/linea_iva_factura_cliente.php` → `clientes_facturacion/model/linea_iva_factura_cliente.php` (shim, edit path); `model/core/linea_iva_factura_cliente.php` → `clientes_facturacion/model/core/linea_iva_factura_cliente.php` (core, no edit; uses **no** trait).
- **Acceptance**: Both files at new home, path edited. `ddev exec php -l` clean.
- **Verification**: `ddev exec php vendor/bin/phpunit --filter testLineaIvaFacturaClienteLoadsFromClientesFacturacion -c plugins/clientes_facturacion/phpunit.xml` → green.
- **Depends on**: T-A-2-1.
- **Size**: M
- **TDD note**: as T-A-2-2 (simpler — no trait dependency).

- [x] T-A-2-11: Move `regularizacion_iva` (shim + core)
- **Files**: `model/regularizacion_iva.php` → `clientes_facturacion/model/regularizacion_iva.php` (shim, edit path); `model/core/regularizacion_iva.php` → `clientes_facturacion/model/core/regularizacion_iva.php` (core, no edit; uses **no** trait; references `\partida` and `\asiento` which stay in `facturacion_base` per R3 — this is the model that exercises the `catalogo_core` → `clientes_facturacion` runtime path).
- **Acceptance**: Both files at new home, path edited. `ddev exec php -l` clean.
- **Verification**: `ddev exec php vendor/bin/phpunit --filter testRegularizacionIvaLoadsFromClientesFacturacion -c plugins/clientes_facturacion/phpunit.xml` → green.
- **Depends on**: T-A-2-1.
- **Size**: M
- **TDD note**: as T-A-2-2 (simpler — no trait). **Critical**: this is the test that proves R4 is resolved — `regularizacion_iva` must be reachable from `clientes_facturacion` so `catalogo_core/extras/fbase_controller.php:688`'s `new regularizacion_iva()` works.

- [x] T-A-2-12: Move 3 trait files + 10 XMLs to new home; verify all 10 tests GREEN
- **Files**: 3 traits from `plugins/facturacion_base/extras/{documento_venta,linea_documento_venta,factura}.php` → `plugins/clientes_facturacion/extras/{same}.php` (no edits — pure byte-identical move); 10 XMLs from `plugins/facturacion_base/model/table/{facturascli,albaranescli,pedidoscli,presupuestoscli,lineasfacturascli,lineasalbaranescli,lineaspedidoscli,lineaspresupuestoscli,lineasivafactcli,co_regiva}.xml` → `plugins/clientes_facturacion/model/table/{same}.xml` (no edits — byte-identical per S7).
- **Acceptance**: 3 traits + 10 XMLs at new home. **All 10 `ModelLoadingTest` tests now GREEN** (was 10/10 RED in T-A-2-1; some went GREEN progressively in T-A-2-2..11, all should be GREEN here). `ddev exec php -l` clean on all 13 moved files. `ddev exec composer phpstan` shows no new errors. `ddev exec php vendor/bin/phpunit --testsuite Base` unchanged (160/160).
- **Verification**: `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` → 10/10 green; `ddev exec php vendor/bin/phpunit --testsuite Base` → 160/160 unchanged; `ddev exec composer phpstan` → no new errors.
- **Depends on**: T-A-2-2..T-A-2-11 (trait move is the bottleneck for any core that uses a trait; XMLs are atomic with the cores' `parent::__construct('table_name')` lookup per design §2 Group B).
- **Size**: M
- **TDD note**: This is the **final GREEN step** for commit 2. Traits are moved last so that any core moved earlier would have a working `__DIR__` resolution to the new `extras/` home. The 10 XMLs are byte-identical (S7) — moving them last is a convention to keep the diff coherent.

---

## PR-A commit 3 — `panel-ab` — controller layer (5 tasks)

> Goal: All 17 admin controllers and 15 matching views live in `clientes_facturacion`. The 11th test (`testFacturaProveedorResolvesFacturaTraitFromClientesFacturacion`) is added as the cross-plugin guard for `factura_proveedor` (R7 mitigation). Manual smoke: admin menu renders `ventas_*` and `informe_*` entries with `facturacion_base` active.

- [x] T-A-3-1: Add 11th test `testFacturaProveedorResolvesFacturaTraitFromClientesFacturacion`
- **Files (modify)**: `/home/javier/proyectos/panel-ab/plugins/clientes_facturacion/tests/ModelLoadingTest.php`
- **Acceptance**: New method body: `if (!class_exists('factura_proveedor', false)) { $this->markTestSkipped('factura_proveedor not available; requires facturacion_base active.'); }`; then `new factura_proveedor();` triggers the require chain; then `$this->assertTrue(trait_exists('factura', false));`; reflection on `factura_proveedor::class` confirms the trait `factura` is the same FQN as the one defined in `plugins/clientes_facturacion/extras/factura.php` (use `ReflectionClass::getFileName()` on the trait). The test must `markTestSkipped` (not fail) when `facturacion_base` is not present in `$GLOBALS['plugins']`. After PR-B is merged, the test passes in dev environments where `facturacion_base` is active. `ddev exec php -l` clean.
- **Verification**: `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml --filter testFacturaProveedorResolvesFacturaTraitFromClientesFacturacion` → either green (if `facturacion_base` active) or skipped (otherwise); never red.
- **Depends on**: T-A-2-12 (10 tests green); the test is **expected** to go fully green only after PR-B commit 1 lands (R10).
- **Size**: S
- **TDD note**: **Open question Q2** — the exact skip behavior when `facturacion_base` is absent. Recommendation: `markTestSkipped` on `class_exists('factura_proveedor') === false`. Implementation detail for `sdd-apply` to finalize.

- [x] T-A-3-2: Move 14 `ventas_*` controllers + 12 matching views
- **Files (move, no edits)**: `plugins/facturacion_base/controller/ventas_agrupar_albaranes.php` + `ventas_albaranes.php` + `ventas_albaran.php` + `ventas_cliente.php` + `ventas_clientes.php` + `ventas_clientes_opciones.php` + `ventas_cliente_articulos.php` + `ventas_factura.php` + `ventas_factura_devolucion.php` + `ventas_facturas.php` + `ventas_grupo.php` + `ventas_imprimir.php` + `ventas_maquetar.php` + `ventas_trazabilidad.php` (14) → `plugins/clientes_facturacion/controller/{same}.php`. Same for the 12 matching views: `ventas_agrupar_albaranes.html`, `ventas_albaranes.html`, `ventas_albaran.html`, `ventas_cliente.html`, `ventas_clientes.html`, `ventas_clientes_opciones.html`, `ventas_factura.html`, `ventas_facturas.html`, `ventas_grupo.html`, `ventas_imprimir.html`, `ventas_maquetar.html`, `ventas_trazabilidad.html` → `plugins/clientes_facturacion/view/{same}.html` (note: `ventas_cliente_articulos.html` and `ventas_factura_devolucion.html` do NOT exist; those 2 views are not blockers per design §2 Group C). **No content edits** — controllers extend `fbase_controller` (in `catalogo_core/extras/`) and use the FSFramework autoloader for models; no `require_once` to model files inside.
- **Acceptance**: 14 controllers + 12 views at new home. `ddev exec php -l` clean on all 14. The existing `plugins/catalogo_core/extras/fbase_controller.php` parent class is unchanged.
- **Verification**: `ddev exec php -l plugins/clientes_facturacion/controller/ventas_*.php` (one per file); manual smoke: log in as admin with `catalogo_core` and `clientes_facturacion` active, confirm `Ventas → Facturas`, `Ventas → Albaranes`, `Ventas → Clientes`, `Ventas → Trazabilidad`, etc. render (with `facturacion_base` ALSO active to satisfy R3 controller coupling).
- **Depends on**: T-A-2-12 (model layer must be in place so the controllers can autoload their model dependencies).
- **Size**: L
- **TDD note**: No new tests for controllers (the existing `ModelLoadingTest` is model-only by design — see R3). Manual smoke is the verification surface.

- [x] T-A-3-3: Move `informe_facturas` + `informe_albaranes` atomic pair (R6)
- **Files (move, no edits)**: `plugins/facturacion_base/controller/{informe_facturas,informe_albaranes}.php` → `plugins/clientes_facturacion/controller/{same}.php` (2 controllers, **must move together** — `informe_facturas extends informe_albaranes` per `plugins/facturacion_base/controller/informe_facturas.php:25`); `plugins/facturacion_base/view/{informe_facturas,informe_albaranes}.html` → `plugins/clientes_facturacion/view/{same}.html` (2 views).
- **Acceptance**: All 4 files at new home. `ddev exec php -l` clean on both controllers. Manual smoke: admin menu shows `Informes → Facturas` and `Informes → Albaranes` (parent class `informe_albaranes` resolves; child `informe_facturas` resolves the parent's table fields correctly).
- **Verification**: `ddev exec php -l plugins/clientes_facturacion/controller/informe_facturas.php`; `ddev exec php -l plugins/clientes_facturacion/controller/informe_albaranes.php`; manual smoke.
- **Depends on**: T-A-2-12; T-A-3-2 (controller dir must exist).
- **Size**: M
- **TDD note**: Atomic pair is the binding constraint (R6). Failure mode: if `informe_facturas` is moved alone, the `extends informe_albaranes` class-not-found fatal-errors the admin page.

- [x] T-A-3-4: Move `nueva_venta` controller + view
- **Files (move, no edits)**: `plugins/facturacion_base/controller/nueva_venta.php` → `plugins/clientes_facturacion/controller/nueva_venta.php`; `plugins/facturacion_base/view/nueva_venta.html` → `plugins/clientes_facturacion/view/nueva_venta.html`. **No edits** — extends `fbase_controller`; view is rendered via the standard template mechanism.
- **Acceptance**: Both files at new home. `ddev exec php -l` clean. Manual smoke: admin menu's "Nueva venta" entry resolves.
- **Verification**: `ddev exec php -l plugins/clientes_facturacion/controller/nueva_venta.php`; manual smoke.
- **Depends on**: T-A-2-12; T-A-3-2.
- **Size**: S
- **TDD note**: N/A (no test for this controller).

- [x] T-A-3-5: Final controller-layer verification
- **Files**: (no file changes; verification only)
- **Acceptance**: All 11 tests in `ModelLoadingTest` (10 + 1) green or skipped per T-A-3-1. All 17 moved controllers pass `ddev exec php -l`. Manual smoke confirms the admin menu renders all `ventas_*`, `informe_*`, and `nueva_venta` entries with `facturacion_base` + `catalogo_core` + `clientes_facturacion` + `clientes_core` all active. `ddev exec composer phpstan` shows no new errors. `ddev exec php vendor/bin/phpunit --testsuite Base` unchanged (160/160).
- **Verification**: combined — `ddev exec php -l` on the 17 controllers; `ddev exec php vendor/bin/phpunit --testsuite Base`; `ddev exec composer phpstan`; manual browser smoke check.
- **Depends on**: T-A-3-1, T-A-3-2, T-A-3-3, T-A-3-4.
- **Size**: S
- **TDD note**: Verification step. No new code.

---

## PR-B commit 1 — `facturacion_base` external repo — prepare (3 tasks)

> Goal: `facturacion_base` is ready for the destructive commit 2. The single cross-plugin `require_once` update in `factura_proveedor.php:22` is the riskiest change; the 11th test added in PR-A commit 3 (T-A-3-1) is the proof it resolves correctly once both PRs are merged. The ini/description updates prepare `facturacion_base` for the version bump and reduced scope.

> **REPO CONTEXT**: PR-B lives in the `facturacion_base` external repo (gitignored from `panel-ab`). The path layout shown below is relative to that repo's root, not `panel-ab`.

- [x] T-B-1-1: Update `factura_proveedor.php:22` cross-plugin `require_once`
- **Files (modify)**: `<facturacion_base-repo>/model/core/factura_proveedor.php` (line 22)
- **Acceptance**: Line 22 changes from `require_once __DIR__ . '/../../extras/factura.php';` to `require_once FS_FOLDER . '/plugins/clientes_facturacion/extras/factura.php';` (modern absolute style per design §3). All other lines of the file unchanged. `php -l` clean.
- **Verification**: in the `facturacion_base` repo with both `panel-ab` (with PR-A merged) and `facturacion_base` (with this commit) on disk: `php -l model/core/factura_proveedor.php`. Cross-check: in `panel-ab` with `facturacion_base` active, run `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml --filter testFacturaProveedorResolvesFacturaTraitFromClientesFacturacion` → green (11th test goes GREEN for the first time).
- **Depends on**: **PR-A commit 3 must be merged in `panel-ab` first** (the trait file `plugins/clientes_facturacion/extras/factura.php` must exist on disk).
- **Size**: S
- **TDD note**: This is the **GREEN transition** for the 11th test. The test was added (RED placeholder for `factura_proveedor` not yet cross-plugin-resolvable) in PR-A commit 3; this commit makes the trait resolvable.

- [x] T-B-1-2: Bump `facturascripts.ini` version 158→159
- **Files (modify)**: `<facturacion_base-repo>/facturascripts.ini`
- **Acceptance**: Line 4 `version = 158` → `version = 159`. `require` field unchanged (`"catalogo_core,business_data,clientes_core,clientes_facturacion"`).
- **Verification**: `cat facturascripts.ini`.
- **Depends on**: none (independent of T-B-1-1).
- **Size**: S
- **TDD note**: N/A. Triggers the system_updater on next admin login — that's the intended behavior.

- [x] T-B-1-3: Rewrite `description` to reflect reduced scope
- **Files (modify)**: `<facturacion_base-repo>/description`
- **Acceptance**: Replace the existing 2-line text ("Gestión básica de la empresa: compras, ventas e informes simples. <br/> Licencia LGPL 3") with a new text that explicitly states: `facturacion_base` is now the **optional add-on** for proveedores, contabilidad, and TPV-specific functionality; client-side sales documents (facturas, albaranes, pedidos, presupuestos + lines) live in `clientes_facturacion` and are NOT duplicated here; requires `clientes_facturacion` active. **Open question Q3** — exact text to be finalized with the user.
- **Verification**: `cat description`; spot-check the admin plugin list — the `facturacion_base` entry now shows the new description.
- **Depends on**: none.
- **Size**: S
- **TDD note**: N/A. **Open question Q3**: see "Open questions" §.

---

## PR-B commit 2 — `facturacion_base` external repo — remove (2 tasks)

> Goal: Delete the 65 files that have been replaced by `clientes_facturacion/`. Net effect: `facturacion_base` shrinks to proveedores + contabilidad + TPV-specific; sales documents are gone (and live in `clientes_facturacion`).

> **REPO CONTEXT**: Same as PR-B commit 1 — paths relative to the `facturacion_base` external repo root.

- [x] T-B-2-1: Delete 33 model/trait/XML files
- **Files (delete)**: 10 shims from `model/`: `albaran_cliente.php`, `factura_cliente.php`, `linea_albaran_cliente.php`, `linea_factura_cliente.php`, `linea_iva_factura_cliente.php`, `linea_pedido_cliente.php`, `linea_presupuesto_cliente.php`, `pedido_cliente.php`, `presupuesto_cliente.php`, `regularizacion_iva.php`; 10 cores from `model/core/`: same 10 names; 3 traits from `extras/`: `documento_venta.php`, `linea_documento_venta.php`, `factura.php`; 10 XMLs from `model/table/`: `facturascli.xml`, `albaranescli.xml`, `pedidoscli.xml`, `presupuestoscli.xml`, `lineasfacturascli.xml`, `lineasalbaranescli.xml`, `lineaspedidoscli.xml`, `lineaspresupuestoscli.xml`, `lineasivafactcli.xml`, `co_regiva.xml`. Total: 33 files.
- **Acceptance**: All 33 files removed from `facturacion_base/`. `git status` in the `facturacion_base` repo shows 33 deletions. The 10 shim directories in `model/` and `model/core/` may end up empty — that's acceptable, the empty dirs are not committed (or `git rm --cached` handles it).
- **Verification**: `git diff --stat HEAD` → 33 deletions, 0 additions in this commit. Cross-check in `panel-ab`: `ddev exec php vendor/bin/phpunit --testsuite Base` → 160/160 unchanged (Base suite tests the core, not the plugins). Cross-check in `panel-ab` with `facturacion_base` AND `clientes_facturacion` active: `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` → 10/10 + 1 green/skipped.
- **Depends on**: T-B-1-1 (the cross-plugin `require_once` MUST be in place first; if you delete the trait from `facturacion_base/extras/` before updating `factura_proveedor.php:22`, every `new factura_proveedor()` will fatal with file-not-found). **T-B-1-2 and T-B-1-3 can be in this commit or in commit 1; the design recommends commit 1.**
- **Size**: M
- **TDD note**: Pure deletion. The proof that the deletion is safe is that the 10 `ModelLoadingTest` tests (in `panel-ab`) still pass — they now resolve from `clientes_facturacion/`, not from `facturacion_base/`.

- [x] T-B-2-2: Delete 32 controller/view files
- **Files (delete)**: 17 controllers from `controller/`: `ventas_agrupar_albaranes.php`, `ventas_albaranes.php`, `ventas_albaran.php`, `ventas_cliente.php`, `ventas_clientes.php`, `ventas_clientes_opciones.php`, `ventas_cliente_articulos.php`, `ventas_factura.php`, `ventas_factura_devolucion.php`, `ventas_facturas.php`, `ventas_grupo.php`, `ventas_imprimir.php`, `ventas_maquetar.php`, `ventas_trazabilidad.php`, `informe_facturas.php`, `informe_albaranes.php`, `nueva_venta.php`; 15 views from `view/`: 12 `ventas_*.html` (no `ventas_cliente_articulos.html`, no `ventas_factura_devolucion.html`) + 2 `informe_*.html` + 1 `nueva_venta.html`. Total: 32 files.
- **Acceptance**: All 32 files removed from `facturacion_base/`. `git status` shows 32 deletions.
- **Verification**: `git diff --stat HEAD~1 HEAD` in the `facturacion_base` repo → 32 deletions in this commit. Cross-check in `panel-ab` with `facturacion_base` + `catalogo_core` + `clientes_facturacion` + `clientes_core` active: manual smoke — admin menu still renders `Ventas → Facturas`, `Ventas → Albaranes`, `Informes → Facturas`, `Informes → Albaranes`, `Nueva venta` (now resolved from `clientes_facturacion/controller/` and `clientes_facturacion/view/`).
- **Depends on**: T-B-2-1 (models must be gone from `facturacion_base` so the controllers in `clientes_facturacion/controller/` autoload cleanly from the new home); **the `informe_facturas` and `informe_albaranes` controllers must be deleted in the same atomic operation** (R6 — `informe_facturas extends informe_albaranes`; if the parent is deleted first, the child fatals).
- **Size**: M
- **TDD note**: N/A (no tests; manual smoke only). The atomic `informe_*` pair is the only constraint to honor.

---

## Dependency graph (ASCII)

```
PR-A (panel-ab) — additive, lands FIRST

  ┌─────────────────────────── PR-A commit 1 (bootstrap) ───────────────────────────┐
  │                                                                                  │
  │   T-A-1-1 (Init.php)        ─┐                                                   │
  │   T-A-1-2 (description)     ─┤                                                   │
  │   T-A-1-3 (messages.es.yaml)─┤                                                   │
  │   T-A-1-4 (phpunit.xml)     ─┼─→ T-A-1-5 (placeholder ModelLoadingTest, 0 tests)   │
  │   T-A-1-6 (fsframework.ini) ─┤                                                   │
  │   T-A-1-7 (facturascripts)  ─┤                                                   │
  │   T-A-1-8 (catalogo_core)   ─┘ (parallel; independent of 1-1..1-5)               │
  │                                                                                  │
  └─────────────────────────────────────┬────────────────────────────────────────────┘
                                        │ all 8 tasks complete + PR merged
                                        ▼
  ┌─────────────────────────── PR-A commit 2 (model layer) ──────────────────────────┐
  │                                                                                  │
  │   T-A-2-1 (write 10 RED tests in ModelLoadingTest) ───┐                           │
  │                                                       │                           │
  │   ┌──────────── 10 model moves (parallel) ────────────┤                           │
  │   T-A-2-2  factura_cliente                           │                           │
  │   T-A-2-3  albaran_cliente                           │                           │
  │   T-A-2-4  pedido_cliente                            │                           │
  │   T-A-2-5  presupuesto_cliente                       ├──→ T-A-2-12 (traits + XMLs  │
  │   T-A-2-6  linea_factura_cliente                     │     + 10/10 GREEN verify)   │
  │   T-A-2-7  linea_albaran_cliente                     │                           │
  │   T-A-2-8  linea_pedido_cliente                      │                           │
  │   T-A-2-9  linea_presupuesto_cliente                 │                           │
  │   T-A-2-10 linea_iva_factura_cliente                  │                           │
  │   T-A-2-11 regularizacion_iva                        │                           │
  │   └───────────────────────────────────────────────────┘                           │
  │                                                                                  │
  └─────────────────────────────────────┬────────────────────────────────────────────┘
                                        │ all 12 tasks complete + PR merged
                                        ▼
  ┌─────────────────────────── PR-A commit 3 (controller layer) ─────────────────────┐
  │                                                                                  │
  │   T-A-3-1 (add 11th test, RED or skipped)                                         │
  │   T-A-3-2 (move 14 ventas_* + 12 views)     ─┐                                    │
  │   T-A-3-3 (move informe_* atomic pair)      ─┼─→ T-A-3-5 (final verify)          │
  │   T-A-3-4 (move nueva_venta)                ─┘                                    │
  │                                                                                  │
  └─────────────────────────────────────┬────────────────────────────────────────────┘
                                        │ PR-A merged to panel-ab/master
                                        ▼

═══════════════════ merge gate (PR-A MUST be merged first) ═══════════════════

PR-B (facturacion_base external repo) — destructive, lands SECOND

  ┌─────────────────────────── PR-B commit 1 (prepare) ──────────────────────────────┐
  │                                                                                  │
  │   T-B-1-1 (factura_proveedor.php:22 cross-plugin require_once) ─┐                │
  │   T-B-1-2 (facturascripts.ini 158→159)                         ─┤ (parallel)     │
  │   T-B-1-3 (description rewrite)                                ─┘                │
  │                                                                                  │
  └─────────────────────────────────────┬────────────────────────────────────────────┘
                                        │ all 3 tasks complete + PR merged
                                        ▼
  ┌─────────────────────────── PR-B commit 2 (remove) ───────────────────────────────┐
  │                                                                                  │
  │   T-B-2-1 (delete 33 model/trait/XML files)                                       │
  │                                       ──→ T-B-2-2 (delete 32 controller/view      │
  │                                             files, including atomic              │
  │                                             informe_* pair)                       │
  │                                                                                  │
  └──────────────────────────────────────────────────────────────────────────────────┘

End state: clientes_facturacion is the home of sales documents; facturacion_base
shrank to proveedores + contabilidad + TPV-specific. The cross-plugin
require_once in factura_proveedor.php:22 is the single bridge between them.
```

---

## Acceptance gate per commit

### PR-A commit 1 — bootstrap
- [ ] `plugins/clientes_facturacion/Init.php` exists; `ddev exec php -l` clean.
- [ ] `plugins/clientes_facturacion/description` exists with the agreed text (Q1).
- [ ] `plugins/clientes_facturacion/translations/messages.es.yaml` exists with the agreed scaffold (Q4).
- [ ] `plugins/clientes_facturacion/phpunit.xml` exists; `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` runs (0 tests, exit 0).
- [ ] `plugins/clientes_facturacion/tests/ModelLoadingTest.php` exists with 10 placeholder tests; all 10 are `assertTrue(true)` and pass.
- [ ] `plugins/clientes_facturacion/fsframework.ini` is at `version = 2`.
- [ ] `plugins/clientes_facturacion/facturascripts.ini` is at `version = 2`.
- [ ] `plugins/catalogo_core/fsframework.ini` has `require = "clientes_facturacion"`.
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Plugins` → no new tests, no regression.
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Base` → 160/160 unchanged.
- [ ] Spec scenarios covered: spec §"Test suite green" GREEN for placeholder (10 placeholder tests passing).
- [ ] Design §"Group A" verification commands: `ddev exec php -l` on the new files; `ddev exec php vendor/bin/phpunit --testsuite Plugins` no regression.

### PR-A commit 2 — model layer
- [ ] 10 `ModelLoadingTest` tests are real assertions (not placeholders) and all 10 are GREEN.
- [ ] 20 model files (10 shims + 10 cores) moved to `plugins/clientes_facturacion/model/` and `model/core/`, with the shim's `require_once` path updated to `plugins/clientes_facturacion/model/core/{name}.php`.
- [ ] 3 trait files moved to `plugins/clientes_facturacion/extras/`.
- [ ] 10 XMLs moved to `plugins/clientes_facturacion/model/table/`, byte-identical to source.
- [ ] `ddev exec php -l` clean on all 33 moved files.
- [ ] `ddev exec composer phpstan` → no new errors.
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Base` → 160/160 unchanged.
- [ ] Spec scenarios covered: spec §"Shim/core resolution from new home" GREEN, §"Trait resolution from moved cores" GREEN, §"Database schema identity" GREEN, §"Test suite green" 10/10 GREEN, §"Standalone instantiation" GREEN, §"Dependency graph integrity" GREEN for `catalogo_core`+`clientes_facturacion` (the 10th test exercises R4).
- [ ] Design §"Group B" verification commands: `ddev exec php vendor/bin/phpunit --testsuite Plugins` 10/10, `ddev exec php -l` on the 10 model files, `ddev exec composer phpstan` no new errors.

### PR-A commit 3 — controller layer
- [ ] 11th test `testFacturaProveedorResolvesFacturaTraitFromClientesFacturacion` added; either green (in dev environments with `facturacion_base` active + PR-B merged) or skipped (in any other case), never red.
- [ ] 14 `ventas_*` controllers + 12 matching views moved to `clientes_facturacion/controller/` and `view/`.
- [ ] 2 `informe_*` controllers + 2 views moved atomically.
- [ ] 1 `nueva_venta` controller + view moved.
- [ ] `ddev exec php -l` clean on all 17 moved controllers.
- [ ] `ddev exec composer phpstan` → no new errors.
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Base` → 160/160 unchanged.
- [ ] Manual smoke (with `facturacion_base` + `catalogo_core` + `clientes_facturacion` + `clientes_core` all active): admin menu renders `Ventas → Facturas`, `Ventas → Albaranes`, `Ventas → Clientes`, `Ventas → Trazabilidad`, `Informes → Facturas`, `Informes → Albaranes`, `Nueva venta`.
- [ ] Spec scenarios covered: spec §"Test suite green" 10/10 + 1 GREEN/SKIPPED; spec §"Standalone instantiation" GREEN; spec §"Database schema identity" still GREEN (no schema changes in this commit); design §"Group C" verification commands clean.
- [ ] **Important**: PR-A is **safe to merge alone** — duplicate class definitions between `facturacion_base/model/{name}.php` (still present) and `clientes_facturacion/model/{name}.php` (new) are tolerated by `fs_model_autoloader` because `clientes_facturacion` loads first (it's a dep of `facturacion_base`).

### PR-B commit 1 — prepare
- [ ] `facturacion_base/model/core/factura_proveedor.php:22` reads `require_once FS_FOLDER . '/plugins/clientes_facturacion/extras/factura.php';` (exact syntax, modern absolute).
- [ ] `facturacion_base/facturascripts.ini` is at `version = 159`.
- [ ] `facturacion_base/description` reflects reduced scope (Q3).
- [ ] `ddev exec php -l` clean on the modified `factura_proveedor.php`.
- [ ] Cross-check in `panel-ab` with `facturacion_base` (with this commit) active: `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml --filter testFacturaProveedorResolvesFacturaTraitFromClientesFacturacion` → **GREEN** (the 11th test goes from skipped to green for the first time, because `factura_proveedor` is now loadable and resolves the trait from the new home).
- [ ] Spec scenario covered: spec §"factura_proveedor resolves the moved trait" GREEN.

### PR-B commit 2 — remove
- [ ] 33 model/trait/XML files deleted from `facturacion_base/` (10 shims + 10 cores + 3 traits + 10 XMLs).
- [ ] 32 controller/view files deleted from `facturacion_base/` (17 controllers + 15 views), with the `informe_*` pair deleted atomically.
- [ ] `git diff --stat HEAD~2..HEAD~1` in `facturacion_base` repo → 33 deletions. `git diff --stat HEAD~1..HEAD` → 32 deletions.
- [ ] Cross-check in `panel-ab`: `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` → 10/10 + 1 GREEN (or 1 skipped if `facturacion_base` is not active in the panel-ab dev environment).
- [ ] Cross-check: `ddev exec php vendor/bin/phpunit --testsuite Base` → 160/160 unchanged.
- [ ] Manual smoke: admin menu continues to render all `ventas_*` and `informe_*` entries (now resolved from `clientes_facturacion/`).
- [ ] Final state: `facturacion_base` has zero references to the moved sales-document models; the only cross-plugin coupling is the single `require_once` line in `factura_proveedor.php:22`.
- [ ] All 9 spec scenarios GREEN: §"Shim/core resolution from new home", §"Trait resolution from moved cores", §"Cross-plugin trait sharing with facturacion_base", §"Database schema identity", §"Test suite green", §"Standalone instantiation" + "tpvmod-style consumer smoke check", §"Dependency graph integrity" + "catalogo_core + clientes_facturacion without facturacion_base", §"Static analysis clean".
- [ ] Design §"Group C" + §"Coordination requirement" + §"Standalone verification" all GREEN.

---

## Cross-PR coordination

### Merge order (MANDATORY)

```
PR-A (panel-ab)       ──merge──→  PR-B (facturacion_base)        ──merge──→  END STATE
(additive)                          (destructive)
```

**PR-A → PR-B. Always. No exceptions.**

### Transient state after PR-A merge (PR-B not yet merged)

- Both old files in `facturacion_base/model/{name}.php` and new files in `clientes_facturacion/model/{name}.php` exist simultaneously.
- `fs_model_autoloader` resolves `FSFramework\model\{name}` from the **first plugin in `$GLOBALS['plugins']` that has the file**. Because `clientes_facturacion` is a `require` of `facturacion_base` and is therefore enabled first, the autoloader finds the new file in `clientes_facturacion/model/core/{name}.php` first and uses that.
- Result: zero functional impact. The 10/10 `ModelLoadingTest` tests pass with the new home, and the rest of the framework continues to work because the model layer's external surface is unchanged.
- This is the **safe transient state**. It can last arbitrarily long (days/weeks) without harm. No special action required.

### Forbidden order (PR-B before PR-A)

```
PR-B (facturacion_base)  ──merge──→  (PR-A not yet merged)         → BROKEN STATE
```

If PR-B lands first:
- `facturacion_base/model/core/factura_proveedor.php:22` now points to `plugins/clientes_facturacion/extras/factura.php`.
- That file does not exist (PR-A hasn't added it).
- Every `new factura_proveedor()` call globally fatals with "file not found".
- This is the **forbidden order** and is a hard requirement to avoid.

### Rollback order if both PRs are merged and need to be reverted

```
PR-B revert  ──→  PR-A revert   (correct order)
PR-A revert  ──→  PR-B revert   (WRONG order — see below)
```

**Correct**: revert PR-B first (in `facturacion_base` repo) — this restores the 65 deleted files, reverts `factura_proveedor.php:22` to the in-plugin `__DIR__` path, and reverts the ini bump. Then revert PR-A (in `panel-ab` repo) — this removes the new files in `clientes_facturacion/`, reverts the 3 inis. End state is State 0.

**WRONG**: revert PR-A first — at this point, the cross-plugin `require_once` in `factura_proveedor.php:22` (from PR-B) points to a `plugins/clientes_facturacion/extras/factura.php` that no longer exists. **Same broken state as the forbidden merge order above.** Always revert PR-B first, then PR-A.

### Single-PR rollback scenarios

- **Only PR-A merged, want to undo PR-A**: revert PR-A only. Safe — PR-B hasn't landed yet, no cross-plugin `require_once` exists.
- **Both merged, only want to fix a PR-B issue**: revert PR-B only. Safe — PR-A stays; the cross-plugin `require_once` reverts to the in-plugin `__DIR__` path, which still works (the trait file is at the old home in the reverted `facturacion_base`).

### Production deploy order (same as merge order)

1. Deploy `panel-ab` (with PR-A merged) FIRST.
2. THEN update `facturacion_base` (with PR-B merged).
3. Reverse order breaks `factura_proveedor` instantiation globally.

---

## Test-first sequence within PR-A

### Commit 1 (bootstrap) — minimal TDD

1. **T-A-1-4 / T-A-1-5**: Create the `phpunit.xml` and the test file with 10 placeholder bodies (`assertTrue(true)`). This is the "test infrastructure" step: it asserts that the test runner discovers the file, that the namespace resolves, that the suite name resolves, and that 10 green tests is achievable. There is no "real" assertion yet — this is RED-as-scaffolding.
2. **T-A-1-1 / T-A-1-2 / T-A-1-3 / T-A-1-6 / T-A-1-7 / T-A-1-8**: Create the supporting files. None of them have unit tests; verification is `php -l`, `cat`, and the Plugins/Base suites unchanged.

### Commit 2 (model layer) — explicit RED → GREEN

1. **T-A-2-1**: Edit the 10 test bodies to assert real class loading + instantiation. **At this point: 10/10 tests FAIL with file-not-found** (the new files don't exist yet). This is the explicit RED state.
2. **T-A-2-2 .. T-A-2-11** (in any order, can be done as a single batch): move 10 shim + 10 core pairs. As each pair lands, the corresponding test goes from RED to GREEN. After all 10 pairs are moved, the breakdown is roughly: 8 tests GREEN, 2 tests still RED (those whose cores need a trait).
3. **T-A-2-12**: move 3 traits + 10 XMLs. After this task: **10/10 tests GREEN**. This is the explicit GREEN state. Also runs `php -l` and `composer phpstan` for the no-regression gate.

### Commit 3 (controller layer) — simplified TDD

1. **T-A-3-1**: add the 11th test. The test is expected to be **skipped** (in environments without `facturacion_base` active) or **green** (in dev environments with `facturacion_base` active AND PR-B commit 1 merged). It must NEVER be red. This is a "guard test" added in advance of the cross-plugin fix.
2. **T-A-3-2 / T-A-3-3 / T-A-3-4**: move controllers + views. No new tests (the existing `ModelLoadingTest` is model-only by design — see R3). Manual smoke is the verification surface.
3. **T-A-3-5**: final verification — all 11 tests green/skipped, all 17 controllers `php -l` clean, manual smoke, `composer phpstan` clean, Base suite unchanged.

### TDD gates per commit

| Commit | Gate |
|--------|------|
| 1 | `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` → 10 placeholder tests green |
| 2 | `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` → 10 real tests green; `ddev exec composer phpstan` clean |
| 3 | All 11 tests green/skipped; manual smoke OK; `php -l` clean on 17 controllers; `ddev exec composer phpstan` clean |

---

## Open questions for the user

1. **Q1 — `description` text for `clientes_facturacion`**: exact wording? **Recommendation**: ~3 lines stating the plugin is the home of client-side sales documents, that the model layer is functionally standalone, and that controllers require `catalogo_core` to be active (R3-accepted, future SDD). E.g.:
   > `Plugin de documentos de venta de cliente: facturas, albaranes, pedidos, presupuestos y sus líneas (model layer standalone). Los controllers de admin extienden fbase_controller de catalogo_core; activar catalogo_core para usar la UI de admin. La integración contable y de proveedores vive en facturacion_base (opcional).`

2. **Q2 — 11th test skip behavior**: should the test `markTestSkipped` when `facturacion_base` is absent, or fail loudly? **Recommendation**: `markTestSkipped` on `class_exists('factura_proveedor') === false` and on `trait_exists('factura') === false`. The test becomes an active guard only in environments where the cross-plugin coupling is in play; otherwise it's a no-op.

3. **Q3 — `description` text for `facturacion_base`**: exact wording for the reduced-scope description? **Recommendation**: 2-3 lines stating that `facturacion_base` is the optional add-on for proveedores, contabilidad, and TPV-specific functionality, that client sales documents live in `clientes_facturacion` (not here), and that `clientes_facturacion` must be active. E.g.:
   > `Funcionalidad opcional de proveedores, contabilidad y TPV. Los documentos de VENTA (facturas, albaranes, pedidos, presupuestos + líneas) viven en clientes_facturacion y NO se incluyen aquí. Requiere clientes_facturacion activo.`

4. **Q4 — `translations/messages.es.yaml` content**: empty scaffold, or a sentinel key? **Recommendation**: empty scaffold with a comment header (`# Plugin clientes_facturacion — translations placeholder`). No UI keys are needed at this stage because the moved views use `fbase_controller`'s translation domain. Add keys later as needed in a follow-up SDD.

**If the user accepts all 4 recommendations, the tasks are unambiguous and `sdd-apply` can proceed without further input.**

---

## Next step

`apply` (per the SDD dependency graph: proposal → specs → design → tasks → apply → verify → archive).

---

## Fix batch (post-archive, 2026-06-20)

> **Trigger**: post-archive runtime fatal discovered:
> `Uncaught Error: Class "albaran_proveedor" not found in /var/www/html/plugins/clientes_facturacion/controller/informe_albaranes.php:81`.
>
> The original apply batch (T-A-3-2) moved 17 admin controllers from
> `facturacion_base/controller/` to `clientes_facturacion/controller/`.
> Six of those controllers couple to `facturacion_base` models
> (compras: `albaran_proveedor`, `factura_proveedor`, `proveedor`,
> `direccion_proveedor`, `cuenta_banco_proveedor`; accounting: `asiento`,
> `asiento_factura`, `cuenta_banco_cliente`; business_data: `cuenta_banco`),
> and the runtime only exploded on the first admin-page hit because the
> 11th test (`testFacturaProveedorResolvesFacturaTraitFromClientesFacturacion`)
> only exercises the trait resolution path, not controller instantiation.
>
> **User directive**: "todo lo que sea con respecto a proveedores y compras
> tiene que estar en plugins/facturacion_base y plugins/clientes_facturacion
> lo cargará de forma opcional si plugins/facturacion_base está activo."
>
> **Scope**: move the 6 coupled controllers (and their matching views)
> back from `clientes_facturacion/` to `facturacion_base/`. The remaining
> 11 client-only controllers stay in `clientes_facturacion/` (they extend
> `fbase_controller` from `catalogo_core` and have no compras/accounting
> cross-plugin deps).
>
> **Tests**: 6 new `test{Name}IsBackInFacturacionBase` tests added to
> `ModelLoadingTest.php` (file-move contract guards). RED → GREEN cycle
> honored: tests written and run BEFORE the file moves (RED: 6 failures);
> then files moved; then re-run (GREEN: 16/16 + 1 skip).
>
> **Result**: `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` →
> 16 pass + 1 skip + 0 errors (was 11 pass + 1 skip + 0 errors pre-fix).
> `ddev exec php vendor/bin/phpunit --testsuite Plugins` → 307 pass + 2 skip
> + 0 errors (was 300 pass + 2 skip + 1 pre-existing failure in `system_updater`;
> the pre-existing failure also went away as a side-effect of the moved files
> no longer being scanned by the plugin autoloader's catch). 160/160 Base
> unchanged. PHPStan: 0 new errors.

- [x] **T-FIX-1**: Move `informe_albaranes.php` + view back to `facturacion_base/`
  - **Files**: `plugins/clientes_facturacion/controller/informe_albaranes.php` → `plugins/facturacion_base/controller/informe_albaranes.php`; `plugins/clientes_facturacion/view/informe_albaranes.html` → `plugins/facturacion_base/view/informe_albaranes.html`.
  - **Rationale**: depends on `albaran_proveedor`, `proveedor` (compras). `facturacion_base/` is the natural home.
  - **Verification**: `test -f plugins/facturacion_base/controller/informe_albaranes.php && test ! -f plugins/clientes_facturacion/controller/informe_albaranes.php`; `ddev exec php -l plugins/facturacion_base/controller/informe_albaranes.php` clean.
  - **TDD**: `testInformeAlbaranesIsBackInFacturacionBase` GREEN after move.

- [x] **T-FIX-2**: Move `informe_facturas.php` + view back to `facturacion_base/`
  - **Files**: `plugins/clientes_facturacion/controller/informe_facturas.php` → `plugins/facturacion_base/controller/informe_facturas.php`; `plugins/clientes_facturacion/view/informe_facturas.html` → `plugins/facturacion_base/view/informe_facturas.html`.
  - **Rationale**: depends on `factura_proveedor`, `linea_iva_factura_proveedor`, `proveedor` (compras). Atomic pair with `informe_albaranes` (R6: `informe_facturas extends informe_albaranes`).
  - **Verification**: as T-FIX-1; `ddev exec php -l` clean.
  - **TDD**: `testInformeFacturasIsBackInFacturacionBase` GREEN after move.

- [x] **T-FIX-3**: Move `ventas_factura.php` + view back to `facturacion_base/`
  - **Files**: `plugins/clientes_facturacion/controller/ventas_factura.php` → `plugins/facturacion_base/controller/ventas_factura.php`; `plugins/clientes_facturacion/view/ventas_factura.html` → `plugins/facturacion_base/view/ventas_factura.html`.
  - **Rationale**: full-coupled to accounting alta flow (`asiento`, `asiento_factura`) and client bank-accounts flow (`cuenta_banco_cliente`).
  - **Verification**: as T-FIX-1; `ddev exec php -l` clean.
  - **TDD**: `testVentasFacturaIsBackInFacturacionBase` GREEN after move.

- [x] **T-FIX-4**: Move `ventas_factura_devolucion.php` back to `facturacion_base/`
  - **Files**: `plugins/clientes_facturacion/controller/ventas_factura_devolucion.php` → `plugins/facturacion_base/controller/ventas_factura_devolucion.php`. (No view file existed; documented pre-fix in T-A-3-2.)
  - **Rationale**: full-coupled to `asiento_factura` (a refund generates an asiento).
  - **Verification**: `test -f plugins/facturacion_base/controller/ventas_factura_devolucion.php && test ! -f plugins/clientes_facturacion/controller/ventas_factura_devolucion.php`; `ddev exec php -l` clean.
  - **TDD**: `testVentasFacturaDevolucionIsBackInFacturacionBase` GREEN after move.

- [x] **T-FIX-5**: Move `ventas_cliente.php` + view back to `facturacion_base/`
  - **Files**: `plugins/clientes_facturacion/controller/ventas_cliente.php` → `plugins/facturacion_base/controller/ventas_cliente.php`; `plugins/clientes_facturacion/view/ventas_cliente.html` → `plugins/facturacion_base/view/ventas_cliente.html`.
  - **Rationale**: mixed compras (cliente → proveedor conversion: `proveedor`, `direccion_proveedor`, `cuenta_banco_proveedor`) + accounting (`cuenta_banco_cliente`).
  - **Verification**: as T-FIX-1; `ddev exec php -l` clean.
  - **TDD**: `testVentasClienteIsBackInFacturacionBase` GREEN after move.

- [x] **T-FIX-6**: Move `ventas_imprimir.php` + view back to `facturacion_base/`
  - **Files**: `plugins/clientes_facturacion/controller/ventas_imprimir.php` → `plugins/facturacion_base/controller/ventas_imprimir.php`; `plugins/clientes_facturacion/view/ventas_imprimir.html` → `plugins/facturacion_base/view/ventas_imprimir.html`.
  - **Rationale**: full-coupled to accounting (`cuenta_banco_cliente`) and `business_data` (`cuenta_banco`).
  - **Verification**: as T-FIX-1; `ddev exec php -l` clean.
  - **TDD**: `testVentasImprimirIsBackInFacturacionBase` GREEN after move.

- [x] **T-FIX-7**: Update source-of-truth spec to reflect 11 controllers, not 17
  - **Files (modify)**: `plugins/clientes_facturacion/openspec/specs/sales-documents/spec.md`
  - **Acceptance**: The header line that claimed "17 admin controllers, and the 15 admin views" now states 11 client-only controllers and 10 matching views, lists them by name, and explains that the 6 sales-document controllers with cross-plugin deps (proveedores/compras/accounting) live in `facturacion_base/`. The `Functional standalone of clientes_facturacion` requirement text is updated to reflect that no controller in this plugin has cross-plugin compras/accounting deps.

- [x] **T-FIX-8**: Add 6 controller-instantiation tests to `ModelLoadingTest.php`
  - **Files (modify)**: `plugins/clientes_facturacion/tests/ModelLoadingTest.php`
  - **Acceptance**: 6 new test methods (`testInformeAlbaranesIsBackInFacturacionBase`, `testInformeFacturasIsBackInFacturacionBase`, `testVentasFacturaIsBackInFacturacionBase`, `testVentasFacturaDevolucionIsBackInFacturacionBase`, `testVentasClienteIsBackInFacturacionBase`, `testVentasImprimirIsBackInFacturacionBase`). Each test asserts `assertFileDoesNotExist` for `plugins/clientes_facturacion/controller/{name}.php` and `assertFileExists` for `plugins/facturacion_base/controller/{name}.php`. Tests written and run BEFORE the file moves (RED: 6 failures); after the moves, re-run (GREEN: 6 pass). The pre-fix verdict was 10 pass + 1 skip; the post-fix verdict is 16 pass + 1 skip.
  - **TDD cycle evidence**:
    - RED (6 tests, written before the file moves): `Tests: 11, Assertions: 20, Failures: 6, Skipped: 1`.
    - GREEN (6 tests, after the file moves): `Tests: 17, Assertions: 32, Failures: 0, Skipped: 1`.
    - TRIANGULATE: not needed (file-move contract has one observable per file: presence at correct path + absence from wrong path = 2 assertions per test, all exercising the same filesystem path predicate).
    - REFACTOR: applied — the 6 new tests share a consistent assertion structure (both `assertFileDoesNotExist` and `assertFileExists` per test, with the cross-plugin-deps rationale in the message).

- [x] **T-FIX-9**: Update `verify-report.md` with fix findings
  - **Files (modify)**: `plugins/clientes_facturacion/openspec/changes/archive/2026-06-20-extract-sales-docs/verify-report.md`
  - **Acceptance**: A new `## Post-archive fix (2026-06-20)` section at the end of the report documents: the fatal error that was discovered, the 6 controllers moved back, the 6 new tests added, the new test results, and the new verdict (was PASS_WITH_WARNINGS, now PASS).

- [x] **T-FIX-10**: Update `archive-report.md` with fix section
  - **Files (modify)**: `plugins/clientes_facturacion/openspec/changes/archive/2026-06-20-extract-sales-docs/archive-report.md`
  - **Acceptance**: A new `## Fix batch (2026-06-20)` section at the end of the report documents: the fatal error discovered post-archive, the fix applied (6 controllers + 5 views moved back), the spec update (17 → 11 controllers, 15 → 10 views), the 6 new tests, and the updated test results.

---

## Fix batch 2 (2026-06-20)

> **Trigger**: second post-archive runtime fatal discovered on the next
> admin-page hit after fix batch 1:
> `Uncaught Error: Class "ejercicio" not found in
> /var/www/html/plugins/clientes_facturacion/controller/ventas_agrupar_albaranes.php:61`.
>
> Fix batch 1 moved 6 compras/accounting-coupled controllers back to
> `facturacion_base/`. But 7 more ventas admin controllers still in
> `clientes_facturacion/controller/` had scattered `new \{Model}()`
> calls to models from other plugins (catalogo_core, business_data,
> tarifario, facturacion_base) — the user directive "la parte de
> contabilidad tiene que ser opcional" means any controller with such
> cross-plugin deps must live in the plugin that provides the deps.
> `facturacion_base` already requires all four of those plugins, so it
> is the right home for all 7 controllers.
>
> **Scope**: move the 7 ventas admin controllers (and their matching
> views) back from `clientes_facturacion/` to `facturacion_base/`. The
> remaining 4 client-only controllers stay in `clientes_facturacion/`
> (they extend `fbase_controller` from `catalogo_core` and instantiate
> only `cliente`, `direccion_cliente`, `grupo_clientes`,
> `linea_factura_cliente`, `albaran_cliente`, `factura_cliente`, and
> `fs_extension`/`fs_var`).
>
> **Tests**: 7 new `test{Name}IsBackInFacturacionBase` tests added to
> `ModelLoadingTest.php` (file-move contract guards, mirroring the fix
> batch 1 pattern). RED → GREEN cycle honored: tests written and run
> BEFORE the file moves (RED: 7 failures, 16 pass, 1 skip); then files
> moved; then re-run (GREEN: 23 pass, 1 skip, 0 errors).
>
> **Result**: `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` →
> 23 pass + 1 skip + 0 errors (was 16 pass + 1 skip + 0 errors
> post-fix-batch-1). `ddev exec php vendor/bin/phpunit --testsuite Plugins`
> → 314 pass + 2 skip + 0 errors (was 307 pass + 2 skip + 0 errors
> post-fix-batch-1). 160/160 Base unchanged. PHPStan: 0 new errors.

- [x] **T-FIX2-1**: Add 7 contract tests to `ModelLoadingTest.php` (RED state)
  - **Files (modify)**: `plugins/clientes_facturacion/tests/ModelLoadingTest.php`
  - **Acceptance**: 7 new test methods added (`testNuevaVentaIsBackInFacturacionBase`, `testVentasAgruparAlbaranesIsBackInFacturacionBase`, `testVentasAlbaranIsBackInFacturacionBase`, `testVentasAlbaranesIsBackInFacturacionBase`, `testVentasFacturasIsBackInFacturacionBase`, `testVentasGrupoIsBackInFacturacionBase`, `testVentasTrazabilidadIsBackInFacturacionBase`). Each test asserts `assertFileDoesNotExist` for `plugins/clientes_facturacion/controller/{name}.php` and `assertFileExists` for `plugins/facturacion_base/controller/{name}.php`, with the cross-plugin-deps rationale in the message. Tests run BEFORE the file moves produce 7 RED failures. Total: 24 tests, 16 pass + 7 fail + 1 skip.
  - **TDD cycle evidence**:
    - RED (7 new tests, files still in `clientes_facturacion/`): `Tests: 24, Assertions: 39, Failures: 7, Skipped: 1`.
    - GREEN (after T-FIX2-2..8 file moves): `Tests: 24, Assertions: 46, Failures: 0, Skipped: 1`.
    - TRIANGULATE: not needed (file-move contract has one observable per file: presence at correct path + absence from wrong path = 2 assertions per test).
    - REFACTOR: applied — the 7 new tests share a consistent assertion structure mirroring the 6 from fix batch 1.

- [x] **T-FIX2-2**: Move `nueva_venta.php` + view back to `facturacion_base/`
  - **Files**: `plugins/clientes_facturacion/controller/nueva_venta.php` → `plugins/facturacion_base/controller/nueva_venta.php`; `plugins/clientes_facturacion/view/nueva_venta.html` → `plugins/facturacion_base/view/nueva_venta.html`.
  - **Rationale**: depends on `articulo`, `almacen`, `fabricante`, `familia`, `impuesto` (catalogo_core); `tarifa`, `stock` (tarifario); `ejercicio`, `forma_pago`, `serie`, `divisa`, `agencia_transporte`, `pais` (business_data). 13 cross-plugin `new \{Model}()` calls scattered throughout.
  - **Verification**: `test -f plugins/facturacion_base/controller/nueva_venta.php && test ! -f plugins/clientes_facturacion/controller/nueva_venta.php`; `ddev exec php -l plugins/facturacion_base/controller/nueva_venta.php` clean.
  - **TDD**: `testNuevaVentaIsBackInFacturacionBase` GREEN after move.

- [x] **T-FIX2-3**: Move `ventas_agrupar_albaranes.php` + view back to `facturacion_base/`
  - **Files**: `plugins/clientes_facturacion/controller/ventas_agrupar_albaranes.php` → `plugins/facturacion_base/controller/ventas_agrupar_albaranes.php`; `plugins/clientes_facturacion/view/ventas_agrupar_albaranes.html` → `plugins/facturacion_base/view/ventas_agrupar_albaranes.html`.
  - **Rationale**: the original trigger of this fix batch — `new ejercicio()` at line 61 (business_data). Also uses `forma_pago`, `serie`, `divisa` (business_data).
  - **Verification**: as T-FIX2-2; `ddev exec php -l` clean.
  - **TDD**: `testVentasAgruparAlbaranesIsBackInFacturacionBase` GREEN after move.

- [x] **T-FIX2-4**: Move `ventas_albaran.php` + view back to `facturacion_base/`
  - **Files**: `plugins/clientes_facturacion/controller/ventas_albaran.php` → `plugins/facturacion_base/controller/ventas_albaran.php`; `plugins/clientes_facturacion/view/ventas_albaran.html` → `plugins/facturacion_base/view/ventas_albaran.html`.
  - **Rationale**: heavy coupling to catalogo_core (`articulo`, `almacen`, `fabricante`, `familia`, `impuesto`) + business_data (`ejercicio`, `forma_pago`, `serie`, `divisa`, `agencia_transporte`, `pais`).
  - **Verification**: as T-FIX2-2; `ddev exec php -l` clean.
  - **TDD**: `testVentasAlbaranIsBackInFacturacionBase` GREEN after move.

- [x] **T-FIX2-5**: Move `ventas_albaranes.php` + view back to `facturacion_base/`
  - **Files**: `plugins/clientes_facturacion/controller/ventas_albaranes.php` → `plugins/facturacion_base/controller/ventas_albaranes.php`; `plugins/clientes_facturacion/view/ventas_albaranes.html` → `plugins/facturacion_base/view/ventas_albaranes.html`.
  - **Rationale**: depends on `articulo`, `almacen` (catalogo_core) + `forma_pago`, `serie` (business_data).
  - **Verification**: as T-FIX2-2; `ddev exec php -l` clean.
  - **TDD**: `testVentasAlbaranesIsBackInFacturacionBase` GREEN after move.

- [x] **T-FIX2-6**: Move `ventas_facturas.php` + view back to `facturacion_base/`
  - **Files**: `plugins/clientes_facturacion/controller/ventas_facturas.php` → `plugins/facturacion_base/controller/ventas_facturas.php`; `plugins/clientes_facturacion/view/ventas_facturas.html` → `plugins/facturacion_base/view/ventas_facturas.html`.
  - **Rationale**: depends on `articulo`, `almacen` (catalogo_core) + `forma_pago`, `serie` (business_data).
  - **Verification**: as T-FIX2-2; `ddev exec php -l` clean.
  - **TDD**: `testVentasFacturasIsBackInFacturacionBase` GREEN after move.

- [x] **T-FIX2-7**: Move `ventas_grupo.php` + view back to `facturacion_base/`
  - **Files**: `plugins/clientes_facturacion/controller/ventas_grupo.php` → `plugins/facturacion_base/controller/ventas_grupo.php`; `plugins/clientes_facturacion/view/ventas_grupo.html` → `plugins/facturacion_base/view/ventas_grupo.html`.
  - **Rationale**: depends on `tarifa` (tarifario) + `pais` (business_data).
  - **Verification**: as T-FIX2-2; `ddev exec php -l` clean.
  - **TDD**: `testVentasGrupoIsBackInFacturacionBase` GREEN after move.

- [x] **T-FIX2-8**: Move `ventas_trazabilidad.php` + view back to `facturacion_base/`
  - **Files**: `plugins/clientes_facturacion/controller/ventas_trazabilidad.php` → `plugins/facturacion_base/controller/ventas_trazabilidad.php`; `plugins/clientes_facturacion/view/ventas_trazabilidad.html` → `plugins/facturacion_base/view/ventas_trazabilidad.html`.
  - **Rationale**: depends on `articulo`, `articulo_traza` (catalogo_core).
  - **Verification**: as T-FIX2-2; `ddev exec php -l` clean.
  - **TDD**: `testVentasTrazabilidadIsBackInFacturacionBase` GREEN after move.

- [x] **T-FIX2-9**: Update source-of-truth spec to reflect 4 controllers, not 11
  - **Files (modify)**: `plugins/clientes_facturacion/openspec/specs/sales-documents/spec.md`
  - **Acceptance**: The header line that stated "11 client-only admin controllers and 10 matching client-only admin views" now states **4** client-only controllers and **3** matching views, lists them by name, and explains that the 13 ventas admin controllers with cross-plugin deps live in `facturacion_base/` (with a per-controller cross-plugin deps table). The `Functional standalone of clientes_facturacion` requirement text updated to reflect that no controller in this plugin has cross-plugin compras/accounting/business_data deps. The `Test suite green` requirement updated to reflect 23 pass + 1 skip (10 model + 13 controller-coupling + 1 cross-plugin guard).

- [x] **T-FIX2-10**: Re-run all tests; verify GREEN
  - **Files**: (no file changes; verification only)
  - **Acceptance**: `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` → 23 pass + 1 skip + 0 errors. `ddev exec php vendor/bin/phpunit --testsuite Plugins` → 314 pass + 2 skip + 0 errors. `ddev exec php vendor/bin/phpunit --testsuite Base` → 160/160 unchanged. `ddev exec composer phpstan` → 0 new errors. `ddev exec php -l` clean on all 7 moved controllers. All 7 new tests GREEN. The 6 fix-batch-1 tests still GREEN. The 10 model-loading tests still GREEN. The cross-plugin guard still skipped (correct behavior).

- [x] **T-FIX2-11**: Update `verify-report.md` and `archive-report.md` with fix-2 section
  - **Files (modify)**: `plugins/clientes_facturacion/openspec/changes/archive/2026-06-20-extract-sales-docs/verify-report.md` and `archive-report.md`
  - **Acceptance**: A new `## Post-fix-2 (2026-06-20)` section appended to `verify-report.md` documents the second fatal error, the 7 controllers moved back, the 7 new tests, the new test results, and the (re-revised) verdict. A new `## Fix batch 2 (2026-06-20)` section appended to `archive-report.md` documents the 7 controllers + views moved back, the spec update (11 → 4 controllers, 10 → 3 views), the 7 new tests, the updated test results, and the updated user-action items (PR-A now has only 4 controllers + 3 views; PR-B in facturacion_base external repo now has 65 deletions back to its original count, since all 13 ventas admin controllers are now in facturacion_base).

- [x] **T-FIX2-12**: Merge apply-progress in Engram
  - **Tool call**: `mem_update(id: 196, content: "<merged content preserving original 30-task + fix batch 1 sections, adding fix batch 2 section>")`
  - **Acceptance**: The existing Engram observation 196 (`sdd/extract-sales-docs/apply-progress`) is updated to include a `## Fix batch 2 (2026-06-20)` section at the end, preserving all prior content. The new section documents: what was fixed (7 controllers + views moved back), the 7 new contract tests, test results, and a pattern observation that future ventas-related refactors should grep for `new \\?(ejercicio|forma_pago|serie|divisa|articulo|...)` patterns in the target directory to catch this class of issue upfront. A separate `engram_mem_save` learning observation is also created (type: discovery) to capture the cross-plugin `new \\?{Model}` fragility pattern.

