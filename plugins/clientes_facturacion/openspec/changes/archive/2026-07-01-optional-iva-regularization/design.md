# Design: Make regularizacion_iva optional for catalogo_core

**Change**: `optional-iva-regularization` (plugin SDD, lives entirely in `plugins/clientes_facturacion/openspec/`)
**Date**: 2026-07-01

---

## 1. Approach

Red-green-refactor per `strict_tdd: true`: write the 3-assertion anti-regression test, observe FAIL on the pre-deletion state, apply the 5 file changes, observe PASS, run the full suite, run `ddev exec composer phpstan`. `fbase_controller` no longer references `regularizacion_iva` and no longer declares `validateFacturaEjercicio`; the defensive `require = "clientes_facturacion"` in `catalogo_core/fsframework.ini` is removed; a stale comment in `ModelLoadingTest.php` is corrected to the current reality.

## 2. Component breakdown

| Component | File | Action |
|---|---|---|
| Anti-regression test | `plugins/clientes_facturacion/tests/CatalogoCoreDecouplingTest.php` | Create (3 assertions) |
| Dead code | `plugins/catalogo_core/extras/fbase_controller.php` | Delete method (676-700) + 2 call sites (316-318, 536-538) |
| Plugin metadata | `plugins/catalogo_core/fsframework.ini` | Line 5: `require = "clientes_facturacion"` → `require = ""` |
| Stale comment | `plugins/clientes_facturacion/tests/ModelLoadingTest.php` | Docblock 528-541 + message line 547 |

No controller, model, schema, `vendor/`, `composer.json`, or root `openspec/` changes.

## 3. Sequence (TDD-ordered)

| # | Step | Expected |
|---|---|---|
| 1 | `ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml` | Baseline green (10/10 `ModelLoadingTest` incl. `testRegularizacionIvaLoadsFromClientesFacturacion`) |
| 2 | Create `CatalogoCoreDecouplingTest.php` (3 methods, no 4th) | File exists |
| 3 | `--filter CatalogoCoreDecouplingTest` | 3 FAIL: ini still has `clientes_facturacion`; file still has `regularizacion_iva`; `method_exists` returns `true` |
| 4 | Edit `fbase_controller.php` — delete the 3 blocks per §4.1 | File 31 lines shorter |
| 5 | Edit `fsframework.ini:5` → `require = ""` | Ini updated |
| 6 | Edit `ModelLoadingTest.php` docblock (528-541) + message (line 547) per §4.3 | Comment + message reflect current state |
| 7 | Plugin suite + root `ddev exec php vendor/bin/phpunit --testsuite Plugins` | 3 anti-regression PASS; `testRegularizacionIvaLoadsFromClientesFacturacion` still passes |
| 8 | `ddev exec composer phpstan` | No new errors |

`phpunit.xml` has `processIsolation="true"` + `stopOnFailure="false"` — assertions run independently; static state resets per test.

## 4. File-level diffs

### 4.1 `plugins/catalogo_core/extras/fbase_controller.php` — 3 deletions

```php
// DELETE block A (316-318, in fbase_facturar_albaran_cliente):
        if (!$this->validateFacturaEjercicio($ejercicio, $factura)) {
            return FALSE;
        }

// DELETE block B (536-538, in fbase_facturar_albaran_proveedor):
        if (!$this->validateFacturaEjercicio($ejercicio, $factura)) {
            return FALSE;
        }

// DELETE block C (676-700, the method itself):
    /**
     * Valida que el ejercicio exista, esté abierto y no haya regularización de IVA.
     */
    private function validateFacturaEjercicio(?object $ejercicio, object $factura): bool
    {
        if (!$ejercicio) { $this->new_error_msg("Ejercicio no encontrado o está cerrado."); return false; }
        if (!$ejercicio->abierto()) { $this->new_error_msg('El ejercicio ' . $ejercicio->codejercicio . ' está cerrado.'); return false; }
        $regularizacion = new regularizacion_iva();
        if ($regularizacion->get_fecha_inside($factura->fecha)) { $this->new_error_msg('El ' . FS_IVA . ' de ese periodo ya ha sido regularizado. No se pueden añadir más facturas en esa fecha.'); return false; }
        return true;
    }
```

### 4.2 `plugins/catalogo_core/fsframework.ini` — line 5

```diff
- require = "clientes_facturacion"
+ require = ""
```

### 4.3 `plugins/clientes_facturacion/tests/ModelLoadingTest.php` — docblock + message

Test method body (lines 542-561) is **untouched**: it asserts the historical file-move contract, gated by `skipIfFacturacionBaseMissing()`. Only the docblock + message are factually wrong.

```diff
-     * File-move contract for ventas_clientes.php (fix-batch-4 / v0.17.5).
-     *
-     * Context: ventas_clientes.php has a hard `require_once 'plugins/facturacion_base/extras/fbase_controller.php'`
-     * on line 25, which means the controller extends fbase_controller (a facturacion_base
-     * extension class) and depends on facturacion_base being active. This is the same
-     * cross-plugin coupling pattern that fix batch 1 (v0.17.1) and fix batch 2 (v0.17.2)
-     * resolved by moving coupled controllers back to facturacion_base. The fix-batch-2
-     * audit missed this one because it grepped for `new \\Xxx` patterns, not
-     * `require_once` patterns.
-     *
-     * This test asserts the file is in facturacion_base and NOT in clientes_facturacion,
-     * preventing a future regression where the file gets accidentally re-moved.
+     * File-move contract for ventas_clientes.php (corrected 2026-07-01).
+     *
+     * Current repo state: ventas_clientes.php lives at
+     * plugins/clientes_core/controller/ventas_clientes.php:25 and extends
+     * clientes_controller (plugins/clientes_core/extras/clientes_controller.php:24,
+     * which extends fs_controller). It has NO require_once to fbase_controller.php.
+     * The test body is the historical file-move contract, gated by
+     * skipIfFacturacionBaseMissing(); the correction is only to this docblock
+     * + the message at line 547.
```

```diff
-            'ventas_clientes.php must NOT live in clientes_facturacion/ — it has a hard require_once to facturacion_base/extras/fbase_controller.php and extends fbase_controller.'
+            'ventas_clientes.php must NOT live in clientes_facturacion/ (file-move contract, see docblock).'
```

## 5. Test design

**Namespace**: `Tests\ClientesFacturacion` (mirrors `ModelLoadingTest.php:27`; the task brief's `Tests\Plugins\ClientesFacturacion` was a suggestion — actual convention is the shorter one).

**`fbase_controller` is global namespace** (line 25 has no `namespace` directive), so the FQN string is `'fbase_controller'` with no leading backslash. `setUp()` does `require_once FS_FOLDER . '/plugins/catalogo_core/extras/fbase_controller.php';` once per test (processIsolation guarantees a fresh process).

```php
public function testCatalogoCoreRequireDoesNotListClientesFacturacion(): void
{
    $contents = file_get_contents(FS_FOLDER . '/plugins/catalogo_core/fsframework.ini');
    preg_match('/^\s*require\s*=\s*"?\s*([^"\r\n]*)\s*"?\s*$/m', $contents, $m);
    $tokens = array_filter(array_map('trim', explode(',', $m[1])), 'strlen');
    $this->assertNotContains('clientes_facturacion', $tokens);
}

public function testFbaseControllerIsFreeOfRegularizacionIvaReference(): void
{
    $contents = file_get_contents(FS_FOLDER . '/plugins/catalogo_core/extras/fbase_controller.php');
    $this->assertStringNotContainsString('regularizacion_iva', $contents);
}

public function testCatalogoCoreHasNoValidateFacturaEjercicioMethod(): void
{
    $this->assertFalse(method_exists('fbase_controller', 'validateFacturaEjercicio'));
}
```

**No 4th test for kept-model regression**: `ModelLoadingTest::testRegularizacionIvaLoadsFromClientesFacturacion` (line 175) already covers spec requirement 4. Duplicating it creates two sources of truth.

**Why structural, not behavioral**: a behavioral test would require mocking `fs_controller`, `$db`, `$user`, `$empresa` — disproportionate for a dead-code-removal contract. The 3 textual assertions (ini line, file content, `method_exists`) are sufficient, fast, side-effect-free.

## 6. What is NOT in the design

| Out | Why |
|---|---|
| Template-method / hook / trait in `clientes_facturacion` | Zero callers; over-engineering rejected in explore |
| Migration of `regularizacion_iva` model | Kept regression guard covers it; out of scope per proposal |
| `plugins/clientes_facturacion/openspec/config.yaml` edit | `strict_tdd: true` is phase-governance, not a config tweak |
| `vendor/`, `composer.json`, `composer.lock` | No new deps |
| External `facturacion_base/` verification | R1 in proposal, accepted; anti-regression test is sole safety net |
| CHANGELOG / version bump | Internal cleanup, no user-visible behavior change |
| Entries in core `openspec/changes/optional-iva-regularization/` | Plugin-local ownership (AGENTS.md) |

## 7. Tradeoffs

| Choice | Why |
|---|---|
| Textual/structural test, not behavioral | Mocking `fs_controller` + `$db` + `$user` + `$empresa` is disproportionate for a dead-code contract |
| One commit, not 5 | The 5 file changes form one atomic contract; 2-commit red/green split is functionally equivalent but harder to review |
| Edit docblock + message, leave test body unchanged | Test body is historical contract (gated by `skipIfFacturacionBaseMissing`); only docblock + message are factually wrong about the current state |
| No template-method, hook, or trait | Dead-on-arrival: zero callers in the entire repo |

## 8. Open questions

None. Spec closed (5 ADDED, 0 MODIFIED, 0 REMOVED). 4 product questions answered in explore.

## 9. Risks

| ID | Risk | Sev | Mitigation |
|---|---|---|---|
| R1 | External `facturacion_base` may call `fbase_facturar_albaran_*`; validation no longer fires | WARN (accepted) | Per P1, SDD does not scan external repo; anti-regression test 1 is sole safety net |
| R2 | Future caller of albaran methods loses VAT regularization validation | LOW | Test 3 makes the absence intentional and discoverable |
| R3 | Botched comment fix surfaces in plugin suite immediately | LOW | Single-line + single-message edit; suite runs on every change |
| R4 *(new)* | New test namespace typo silently skips the file in root **Plugins** suite | LOW | §5 commits to `Tests\ClientesFacturacion` (matches `ModelLoadingTest`); step 7 surface-fails on discovery |
| R5 *(new)* | `fbase_controller` global-namespace pitfall: `method_exists('\\fbase_controller', ...)` silently fails | LOW | §5 commits to bare FQN `'fbase_controller'`; `require_once` in `setUp()` against absolute path under `FS_FOLDER` |

## 10. Summary

5 file changes, 1 commit, 1 red-green cycle. Anti-regression test fails on pre-state, passes on post-state. No version bump, no CHANGELOG, no vendor changes, no core openspec entries. Plugin-local ownership respected. TDD enforced by `strict_tdd: true`. **Next step**: `sdd-tasks` for the 8-step task breakdown, then `sdd-apply`.
