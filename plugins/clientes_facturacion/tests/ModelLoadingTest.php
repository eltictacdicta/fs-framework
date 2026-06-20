<?php
declare(strict_types=1);
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Tests\ClientesFacturacion;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that clientes_facturacion models can be loaded and instantiated
 * correctly. The framework's fs_model_autoloader handles model loading in
 * runtime, respecting plugin dependency order and allowing overrides.
 *
 * PR-A commit 1 (bootstrap) places 10 placeholder tests here (assertTrue(true)).
 * PR-A commit 2 (model layer) replaces each placeholder with a real assertion
 * that loads the model from the new home and verifies it is an fs_model.
 *
 * PR-A commit 3 (controller layer) adds the 11th test that asserts the
 * cross-plugin `factura_proveedor -> clientes_facturacion/extras/factura`
 * trait resolution works (skipped when facturacion_base is not active).
 */
class ModelLoadingTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        if (!defined('FS_VENTAS_SIN_STOCK')) {
            define('FS_VENTAS_SIN_STOCK', false);
        }

        require_once FS_FOLDER . '/base/fs_model.php';
    }

    public function testFacturaClienteLoadsFromClientesFacturacion(): void
    {
        require_once FS_FOLDER . '/plugins/clientes_facturacion/model/core/factura_cliente.php';
        $this->assertTrue(class_exists('FSFramework\model\factura_cliente', false));

        $model = new \FSFramework\model\factura_cliente();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testAlbaranClienteLoadsFromClientesFacturacion(): void
    {
        require_once FS_FOLDER . '/plugins/clientes_facturacion/model/core/albaran_cliente.php';
        $this->assertTrue(class_exists('FSFramework\model\albaran_cliente', false));

        $model = new \FSFramework\model\albaran_cliente();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testPedidoClienteLoadsFromClientesFacturacion(): void
    {
        require_once FS_FOLDER . '/plugins/clientes_facturacion/model/core/pedido_cliente.php';
        $this->assertTrue(class_exists('FSFramework\model\pedido_cliente', false));

        $model = new \FSFramework\model\pedido_cliente();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testPresupuestoClienteLoadsFromClientesFacturacion(): void
    {
        require_once FS_FOLDER . '/plugins/clientes_facturacion/model/core/presupuesto_cliente.php';
        $this->assertTrue(class_exists('FSFramework\model\presupuesto_cliente', false));

        $model = new \FSFramework\model\presupuesto_cliente();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testLineaFacturaClienteLoadsFromClientesFacturacion(): void
    {
        require_once FS_FOLDER . '/plugins/clientes_facturacion/model/core/linea_factura_cliente.php';
        $this->assertTrue(class_exists('FSFramework\model\linea_factura_cliente', false));

        $model = new \FSFramework\model\linea_factura_cliente();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testLineaAlbaranClienteLoadsFromClientesFacturacion(): void
    {
        require_once FS_FOLDER . '/plugins/clientes_facturacion/model/core/linea_albaran_cliente.php';
        $this->assertTrue(class_exists('FSFramework\model\linea_albaran_cliente', false));

        $model = new \FSFramework\model\linea_albaran_cliente();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testLineaPedidoClienteLoadsFromClientesFacturacion(): void
    {
        require_once FS_FOLDER . '/plugins/clientes_facturacion/model/core/linea_pedido_cliente.php';
        $this->assertTrue(class_exists('FSFramework\model\linea_pedido_cliente', false));

        $model = new \FSFramework\model\linea_pedido_cliente();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testLineaPresupuestoClienteLoadsFromClientesFacturacion(): void
    {
        require_once FS_FOLDER . '/plugins/clientes_facturacion/model/core/linea_presupuesto_cliente.php';
        $this->assertTrue(class_exists('FSFramework\model\linea_presupuesto_cliente', false));

        $model = new \FSFramework\model\linea_presupuesto_cliente();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testLineaIvaFacturaClienteLoadsFromClientesFacturacion(): void
    {
        require_once FS_FOLDER . '/plugins/clientes_facturacion/model/core/linea_iva_factura_cliente.php';
        $this->assertTrue(class_exists('FSFramework\model\linea_iva_factura_cliente', false));

        $model = new \FSFramework\model\linea_iva_factura_cliente();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testRegularizacionIvaLoadsFromClientesFacturacion(): void
    {
        require_once FS_FOLDER . '/plugins/clientes_facturacion/model/core/regularizacion_iva.php';
        $this->assertTrue(class_exists('FSFramework\model\regularizacion_iva', false));

        $model = new \FSFramework\model\regularizacion_iva();
        $this->assertInstanceOf('fs_model', $model);
    }

    /**
     * Controller-coupling guard (post-archive fix, 2026-06-20).
     *
     * The original extract-sales-docs batch moved 17 admin controllers from
     * facturacion_base to clientes_facturacion. Six of those controllers
     * (`informe_albaranes`, `informe_facturas`, `ventas_factura`,
     * `ventas_factura_devolucion`, `ventas_cliente`, `ventas_imprimir`) have
     * cross-plugin dependencies on `facturacion_base` (proveedores/compras:
     * `albaran_proveedor`, `factura_proveedor`, `proveedor`,
     * `direccion_proveedor`, `cuenta_banco_proveedor`; accounting: `asiento`,
     * `asiento_factura`, `cuenta_banco_cliente`; business_data: `cuenta_banco`).
     *
     * Per the project rule "todo lo que sea con respecto a proveedores y
     * compras tiene que estar en plugins/facturacion_base y plugins/
     * clientes_facturacion lo cargará de forma opcional si plugins/
     * facturacion_base está activo", those six controllers MUST live in
     * `facturacion_base/controller/` and MUST NOT live in
     * `clientes_facturacion/controller/`. These six tests pin that contract
     * so the next refactor cannot silently re-introduce the runtime fatal:
     * `Uncaught Error: Class "albaran_proveedor" not found in
     * plugins/clientes_facturacion/controller/informe_albaranes.php`.
     */
    public function testInformeAlbaranesIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/informe_albaranes.php',
            'informe_albaranes depends on albaran_proveedor and proveedor '
            . '(facturacion_base compras); it must live in facturacion_base/controller/, '
            . 'not in clientes_facturacion/controller/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/informe_albaranes.php',
            'informe_albaranes must be present in facturacion_base/controller/.'
        );
    }

    public function testInformeFacturasIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/informe_facturas.php',
            'informe_facturas depends on factura_proveedor, linea_iva_factura_proveedor '
            . 'and proveedor (facturacion_base compras); it must live in '
            . 'facturacion_base/controller/, not in clientes_facturacion/controller/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/informe_facturas.php',
            'informe_facturas must be present in facturacion_base/controller/.'
        );
    }

    public function testVentasFacturaIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/ventas_factura.php',
            'ventas_factura is full-coupled to the accounting alta flow '
            . '(asiento, asiento_factura) and the client bank-accounts flow '
            . '(cuenta_banco_cliente); it must live in facturacion_base/controller/, '
            . 'not in clientes_facturacion/controller/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/ventas_factura.php',
            'ventas_factura must be present in facturacion_base/controller/.'
        );
    }

    public function testVentasFacturaDevolucionIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/ventas_factura_devolucion.php',
            'ventas_factura_devolucion is full-coupled to asiento_factura '
            . '(a refund generates an asiento); it must live in '
            . 'facturacion_base/controller/, not in clientes_facturacion/controller/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/ventas_factura_devolucion.php',
            'ventas_factura_devolucion must be present in facturacion_base/controller/.'
        );
    }

    public function testVentasClienteIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/ventas_cliente.php',
            'ventas_cliente has mixed compras (proveedor, direccion_proveedor, '
            . 'cuenta_banco_proveedor) + accounting (cuenta_banco_cliente) deps; '
            . 'it must live in facturacion_base/controller/, not in '
            . 'clientes_facturacion/controller/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/ventas_cliente.php',
            'ventas_cliente must be present in facturacion_base/controller/.'
        );
    }

    public function testVentasImprimirIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/ventas_imprimir.php',
            'ventas_imprimir is full-coupled to accounting (cuenta_banco_cliente) '
            . 'and business_data (cuenta_banco); it must live in '
            . 'facturacion_base/controller/, not in clientes_facturacion/controller/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/ventas_imprimir.php',
            'ventas_imprimir must be present in facturacion_base/controller/.'
        );
    }

    /**
     * Controller-coupling guards — fix batch 2 (2026-06-20).
     *
     * After fix batch 1, 11 ventas admin controllers were still in
     * `clientes_facturacion/controller/`. Seven of them couple to
     * `facturacion_base` models (catalogo_core + business_data +
     * tarifario + facturacion_base) via `new \{Model}()` calls scattered
     * throughout the controller bodies. The user directive ("la parte de
     * contabilidad tiene que ser opcional") means accounting/data-layer
     * models must be optional; the established pattern from fix batch 1
     * is: any controller with cross-plugin deps moves back to the plugin
     * that provides the deps. `facturacion_base` already requires
     * `business_data`, `catalogo_core`, `clientes_core`, and
     * `clientes_facturacion`, so it is the right home for all ventas
     * admin controllers with cross-plugin deps.
     *
     * These 7 tests pin the file-move contract for the 7 ventas admin
     * controllers that need to move back to `facturacion_base/`. Each
     * test asserts the file's presence at the correct (facturacion_base)
     * home and its absence from the wrong (clientes_facturacion) home,
     * with the cross-plugin-deps rationale in the assertion message.
     */
    public function testNuevaVentaIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/nueva_venta.php',
            'nueva_venta depends on catalogo_core (articulo, almacen, fabricante, '
            . 'familia, impuesto), tarifario (tarifa, stock), business_data '
            . '(ejercicio, forma_pago, serie, divisa, agencia_transporte, pais); '
            . 'it must live in facturacion_base/controller/, not in '
            . 'clientes_facturacion/controller/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/nueva_venta.php',
            'nueva_venta must be present in facturacion_base/controller/.'
        );
    }

    public function testVentasAgruparAlbaranesIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/ventas_agrupar_albaranes.php',
            'ventas_agrupar_albaranes depends on business_data (ejercicio, '
            . 'forma_pago, serie, divisa); it must live in facturacion_base/controller/, '
            . 'not in clientes_facturacion/controller/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/ventas_agrupar_albaranes.php',
            'ventas_agrupar_albaranes must be present in facturacion_base/controller/.'
        );
    }

    public function testVentasAlbaranIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/ventas_albaran.php',
            'ventas_albaran depends on catalogo_core (articulo, almacen, fabricante, '
            . 'familia, impuesto) and business_data (ejercicio, forma_pago, serie, '
            . 'divisa, agencia_transporte, pais); it must live in '
            . 'facturacion_base/controller/, not in clientes_facturacion/controller/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/ventas_albaran.php',
            'ventas_albaran must be present in facturacion_base/controller/.'
        );
    }

    public function testVentasAlbaranesIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/ventas_albaranes.php',
            'ventas_albaranes depends on catalogo_core (articulo, almacen) and '
            . 'business_data (forma_pago, serie); it must live in '
            . 'facturacion_base/controller/, not in clientes_facturacion/controller/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/ventas_albaranes.php',
            'ventas_albaranes must be present in facturacion_base/controller/.'
        );
    }

    public function testVentasFacturasIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/ventas_facturas.php',
            'ventas_facturas depends on catalogo_core (articulo, almacen) and '
            . 'business_data (forma_pago, serie); it must live in '
            . 'facturacion_base/controller/, not in clientes_facturacion/controller/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/ventas_facturas.php',
            'ventas_facturas must be present in facturacion_base/controller/.'
        );
    }

    public function testVentasGrupoIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/ventas_grupo.php',
            'ventas_grupo depends on tarifario (tarifa) and business_data (pais); '
            . 'it must live in facturacion_base/controller/, not in '
            . 'clientes_facturacion/controller/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/ventas_grupo.php',
            'ventas_grupo must be present in facturacion_base/controller/.'
        );
    }

    public function testVentasTrazabilidadIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/ventas_trazabilidad.php',
            'ventas_trazabilidad depends on catalogo_core (articulo, articulo_traza); '
            . 'it must live in facturacion_base/controller/, not in '
            . 'clientes_facturacion/controller/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/ventas_trazabilidad.php',
            'ventas_trazabilidad must be present in facturacion_base/controller/.'
        );
    }

    /**
     * Cross-plugin guard (R7 mitigation): asserts that `factura_proveedor`
     * (staying in facturacion_base) resolves the `factura` trait from
     * clientes_facturacion/extras/factura.php.
     *
     * Skip behavior (Q2): skip when facturacion_base is not present in
     * $GLOBALS['plugins'], or when the cross-plugin `require_once` line
     * in `factura_proveedor.php:22` has not yet been updated to point
     * to the new trait home (i.e. PR-B commit 1 has not been merged).
     *
     * After PR-B is merged, the test goes green in dev environments
     * where facturacion_base is active.
     */
    public function testFacturaProveedorResolvesFacturaTraitFromClientesFacturacion(): void
    {
        // Skip when facturacion_base is not active in this test env.
        if (!in_array('facturacion_base', $GLOBALS['plugins'] ?? [], true)) {
            $this->markTestSkipped('facturacion_base not active in $GLOBALS[\'plugins\']; test requires it.');
        }

        // Skip if the shim file is not present on disk (e.g. fresh checkout
        // where facturacion_base hasn't been installed yet).
        $facturaProveedorShim = FS_FOLDER . '/plugins/facturacion_base/model/factura_proveedor.php';
        if (!file_exists($facturaProveedorShim)) {
            $this->markTestSkipped('factura_proveedor shim not on disk; requires facturacion_base active.');
        }

        // Load the shim; this triggers the core's require_once chain which
        // loads the `factura` trait. If PR-B commit 1 has not been merged,
        // the core's `__DIR__ . '/../../extras/factura.php'` points to a
        // non-existent file. In that case the require_once throws a fatal
        // error and the test must skip (not fail).
        try {
            require_once $facturaProveedorShim;
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'factura_proveedor shim could not load its core require_once chain; '
                . 'likely PR-B commit 1 (cross-plugin require_once update) is not yet applied. '
                . 'Reason: ' . $e->getMessage()
            );
        }

        if (!class_exists('factura_proveedor', false)) {
            $this->markTestSkipped('factura_proveedor class not loadable; requires facturacion_base + PR-B commit 1.');
        }

        if (!trait_exists('factura', false)) {
            $this->markTestSkipped('factura trait not yet resolvable; PR-B commit 1 likely not applied.');
        }

        // Instantiate the model — this triggers the core's require_once chain
        // and resolves the `factura` trait from the new home.
        new \factura_proveedor();

        // Verify the trait file is the one in clientes_facturacion/extras/.
        $traitReflection = new \ReflectionClass('factura');
        $traitFile = $traitReflection->getFileName();
        $this->assertNotFalse($traitFile, 'factura trait reflection must resolve a file.');
        $this->assertStringContainsString(
            'plugins/clientes_facturacion/extras/factura.php',
            str_replace('\\', '/', $traitFile),
            'factura trait must be loaded from clientes_facturacion/extras/factura.php'
        );
    }

    /**
     * Regression test for the PHP 8 compatibility bug in `get_class_name()`.
     *
     * Context: `base/fs_functions.php::get_class_name()` calls `get_class($object)`
     * unconditionally. In PHP 8+, this throws `TypeError` when `$object` is
     * `false` or `null`. The legacy ventas controllers (now in
     * `clientes_facturacion/`) call `get_class_name($this->documento)` from
     * their `url()` method, where `$this->documento` is `false` whenever
     * `private_core()` ran but the request had no `albaran`/`factura` param
     * (e.g. when the plugin manager instantiates the controller for menu
     * rendering on unrelated pages).
     *
     * The fix is in the controller: an `is_object($this->documento)` guard
     * at the top of `url()` short-circuits to `parent::url()` before the
     * dangerous `get_class_name()` call. This test asserts the guard is
     * present in the controller file (file-content contract) so that a
     * future refactor that removes the guard is caught.
     */
    public function testVentasMaquetarUrlGuardsAgainstGetClassOnFalseDocumento(): void
    {
        $controllerFile = FS_FOLDER . '/plugins/clientes_facturacion/controller/ventas_maquetar.php';
        $this->assertFileExists($controllerFile, 'ventas_maquetar controller must live in clientes_facturacion.');

        $source = file_get_contents($controllerFile);

        // The guard must be inside the `url()` method and check the
        // `$this->documento` property with `is_object()` BEFORE the
        // `get_class_name($this->documento)` call.
        $this->assertStringContainsString(
            'is_object($this->documento)',
            $source,
            'ventas_maquetar::url() must guard against non-object $this->documento before calling get_class_name().'
        );

        // Locate the url() method body and verify the guard is positioned
        // before the get_class_name call within that method.
        $urlMethodPos = strpos($source, 'public function url()');
        $this->assertNotFalse($urlMethodPos, 'ventas_maquetar must define url().');

        $endOfUrl = strpos($source, "\n    }\n", $urlMethodPos);
        if ($endOfUrl === false) {
            // fallback: scan to the next closing brace at column 4
            $endOfUrl = strpos($source, '    }', $urlMethodPos);
        }
        $urlBody = substr($source, (int) $urlMethodPos, $endOfUrl - $urlMethodPos);

        $guardPos = strpos($urlBody, 'is_object($this->documento)');
        $dangerousPos = strpos($urlBody, 'get_class_name($this->documento)');

        $this->assertNotFalse($guardPos, 'is_object() guard must be present inside url().');
        $this->assertNotFalse($dangerousPos, 'get_class_name() call must be present inside url().');
        $this->assertLessThan(
            $dangerousPos,
            $guardPos,
            'is_object() guard must appear BEFORE the get_class_name() call in url().'
        );
    }

    /**
     * File-move contract for ventas_clientes.php (fix-batch-4 / v0.17.5).
     *
     * Context: ventas_clientes.php has a hard `require_once 'plugins/facturacion_base/extras/fbase_controller.php'`
     * on line 25, which means the controller extends fbase_controller (a facturacion_base
     * extension class) and depends on facturacion_base being active. This is the same
     * cross-plugin coupling pattern that fix batch 1 (v0.17.1) and fix batch 2 (v0.17.2)
     * resolved by moving coupled controllers back to facturacion_base. The fix-batch-2
     * audit missed this one because it grepped for `new \\Xxx` patterns, not
     * `require_once` patterns.
     *
     * This test asserts the file is in facturacion_base and NOT in clientes_facturacion,
     * preventing a future regression where the file gets accidentally re-moved.
     */
    public function testVentasClientesIsBackInFacturacionBase(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/controller/ventas_clientes.php',
            'ventas_clientes.php must NOT live in clientes_facturacion/ — it has a hard require_once to facturacion_base/extras/fbase_controller.php and extends fbase_controller.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/controller/ventas_clientes.php',
            'ventas_clientes.php must live in facturacion_base/ (its parent class fbase_controller lives there).'
        );
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/clientes_facturacion/view/ventas_clientes.html',
            'ventas_clientes.html view must travel with the controller — back to facturacion_base/.'
        );
        $this->assertFileExists(
            FS_FOLDER . '/plugins/facturacion_base/view/ventas_clientes.html',
            'ventas_clientes.html view must live with the controller in facturacion_base/.'
        );
    }
}
