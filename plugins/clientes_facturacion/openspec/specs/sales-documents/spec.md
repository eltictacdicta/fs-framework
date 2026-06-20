# sales-documents

Domain contract for the client-side sales document model layer that lives in
`plugins/clientes_facturacion/`. The plugin owns the `FSFramework\model`
shim/core pattern (10 shims in `model/`, 10 cores in `model/core/`,
`FSFramework\model` namespace), the 3 sales-document traits
(`documento_venta`, `linea_documento_venta`, `factura`), the 10 XML schemas
under `model/table/`, the **4** client-only admin controllers
(`ventas_clientes`, `ventas_clientes_opciones`, `ventas_cliente_articulos`,
`ventas_maquetar`) and the **3** matching client-only admin views
(`ventas_clientes.html`, `ventas_clientes_opciones.html`,
`ventas_maquetar.html`; `ventas_cliente_articulos` has no view). All 4
controllers in this plugin extend `fbase_controller` from `catalogo_core`
and have **no** cross-plugin deps (they instantiate only models from
`clientes_core` + `fs_extension`).

The remaining 13 ventas admin controllers live in `facturacion_base/`
because they couple to models from other plugins:

| Controller (in `facturacion_base/`) | Cross-plugin deps | Plugin of deps |
|---|---|---|
| `nueva_venta` | `articulo`, `almacen`, `fabricante`, `familia`, `impuesto`, `tarifa`, `stock`, `ejercicio`, `forma_pago`, `serie`, `divisa`, `agencia_transporte`, `pais` | catalogo_core + business_data + tarifario + facturacion_base |
| `ventas_agrupar_albaranes` | `ejercicio`, `forma_pago`, `serie`, `divisa` | business_data |
| `ventas_albaran` | `articulo`, `almacen`, `fabricante`, `familia`, `impuesto`, `ejercicio`, `forma_pago`, `serie`, `divisa`, `agencia_transporte`, `pais` | catalogo_core + business_data + facturacion_base |
| `ventas_albaranes` | `articulo`, `almacen`, `forma_pago`, `serie` | catalogo_core + business_data |
| `ventas_facturas` | `articulo`, `almacen`, `forma_pago`, `serie` | catalogo_core + business_data |
| `ventas_grupo` | `tarifa`, `pais` | tarifario + business_data |
| `ventas_trazabilidad` | `articulo`, `articulo_traza` | catalogo_core |
| `ventas_factura` | `asiento`, `asiento_factura`, `cuenta_banco_cliente` | facturacion_base accounting + business_data |
| `ventas_factura_devolucion` | `asiento_factura` | facturacion_base accounting |
| `ventas_cliente` | `proveedor`, `direccion_proveedor`, `cuenta_banco_proveedor`, `cuenta_banco_cliente` | facturacion_base compras + accounting + business_data |
| `ventas_imprimir` | `cuenta_banco_cliente`, `cuenta_banco` | facturacion_base accounting + business_data |
| `informe_albaranes` | `albaran_proveedor`, `proveedor` | facturacion_base compras |
| `informe_facturas` | `factura_proveedor`, `linea_iva_factura_proveedor`, `proveedor` | facturacion_base compras |

`facturacion_base/` is the right home for these 13 controllers because it
already requires `business_data`, `catalogo_core`, `clientes_core`, and
`clientes_facturacion` (per its `fsframework.ini`), so all the
cross-plugin models are guaranteed active. The user directive
("la parte de contabilidad tiene que ser opcional" — the accounting/data
layer must be optional) means accounting and compras models must NOT be
required by `clientes_facturacion`; they live in `facturacion_base` and
are loaded conditionally.

The plugin is functionally standalone at the **model layer**: with only
`clientes_facturacion` and `clientes_core` active, every model in this
domain instantiates without requiring `facturacion_base`. The model layer's
external surface (table names, column sets, public properties, save/load
contract) is byte-identical to the historical home in `facturacion_base/`
— same DB tables, same XML schemas, same `fs_model` parent class.

## Requirements

### Requirement: Plugin ownership of sales-document model layer

The 10 sales-document models and their `FSFramework\model` cores MUST live under `plugins/clientes_facturacion/model/`. Each shim's `require_once` MUST point to the new core path; the autoloader resolves `FSFramework\model\{name}` from the new home.

#### Scenario: Shim/core resolution from new home
- GIVEN the moved shim at `plugins/clientes_facturacion/model/factura_cliente.php`
- WHEN PHP loads the shim
- THEN it `require_once`s the new core path and `FSFramework\model\factura_cliente` is instantiable

### Requirement: Plugin ownership of sales-document traits

The 3 traits `documento_venta`, `linea_documento_venta`, `factura` MUST live under `plugins/clientes_facturacion/extras/`. Moved cores `require_once` and `use` them from the new path. Trait bodies are byte-identical to the source.

#### Scenario: Trait resolution from moved cores
- GIVEN a moved core declares `use \documento_venta; use \factura;`
- WHEN the file loads
- THEN both `require_once` calls resolve under the new `extras/`

### Requirement: Cross-plugin trait sharing with facturacion_base

`factura_proveedor` (in `facturacion_base`) MUST keep working with the `factura` trait. The `__DIR__`-relative `require_once` call in `facturacion_base/model/core/factura_proveedor.php:22` MUST point to `plugins/clientes_facturacion/extras/factura.php`. Safe because `facturacion_base`'s `require` already lists `clientes_facturacion`.

#### Scenario: factura_proveedor resolves the moved trait
- GIVEN `facturacion_base` active alongside `clientes_facturacion`
- WHEN `new factura_proveedor()` triggers the require chain
- THEN `\factura` resolves from `plugins/clientes_facturacion/extras/factura.php`

### Requirement: Database schema identity

The 10 XML schemas in `plugins/clientes_facturacion/model/table/` MUST be byte-identical to the source XMLs. `table_name` and column set of every moved model stay the same. No DDL change; no DB migration.

#### Scenario: XMLs map to existing tables
- GIVEN the moved `facturascli.xml` at the new `model/table/`
- WHEN the framework loads the schema
- THEN it maps to the `facturascli` table with the same columns as pre-change

### Requirement: Test suite green

`plugins/clientes_facturacion/tests/ModelLoadingTest.php` MUST contain tests that pin the file-move contract for the model layer AND the controller-layer coupling. The model-layer tests (10) verify each moved model is reachable from `clientes_facturacion`. The controller-coupling tests (13) assert that the 13 ventas admin controllers with cross-plugin deps live in `facturacion_base/controller/` (not in `clientes_facturacion/controller/`); the 4 client-only controllers in this plugin have no contract test (they are explicitly not coupled to other plugins). The cross-plugin guard (1) verifies that `factura_proveedor` resolves the `factura` trait from the new home. `ddev exec php vendor/bin/phpunit --testsuite Plugins` reports 23 passed + 1 skipped (the cross-plugin guard skips when `facturacion_base` is not in `$GLOBALS['plugins']`) with no regression.

#### Scenario: Plugin suite passes
- GIVEN the change applied (move + Init + tests)
- WHEN `ddev exec php vendor/bin/phpunit --testsuite Plugins` runs
- THEN 23 new tests pass (10 model + 13 controller-coupling) and 1 cross-plugin guard is skipped, with no previously-green test failing

### Requirement: Functional standalone of clientes_facturacion

With `clientes_facturacion` active and `facturacion_base` inactive, every moved model class MUST still be instantiable. Consumers that `require` only `clientes_facturacion` instantiate each moved model without a `facturacion_base` model-layer dependency. (The 13 ventas admin controllers that couple to `facturacion_base` accounting/compras/business_data models are NOT in this plugin — they live in `facturacion_base/controller/`. The 4 client-only controllers in this plugin extend `fbase_controller` from `catalogo_core` and have no cross-plugin deps: they instantiate only `cliente`, `direccion_cliente`, `grupo_clientes`, `linea_factura_cliente`, `albaran_cliente`, `factura_cliente`, and `fs_extension`/`fs_var`.)

#### Scenario: Standalone instantiation
- GIVEN `facturacion_base` inactive
- WHEN `new factura_cliente()` is called from a `clientes_facturacion` consumer
- THEN the shim resolves and the core instantiates without fatal error

#### Scenario: tpvmod-style consumer smoke check
- GIVEN a script that does `require_once 'plugins/clientes_facturacion/model/factura_cliente.php'; new factura_cliente();`
- WHEN executed with only `clientes_facturacion` and `clientes_core` active
- THEN the class is reachable and instantiable

### Requirement: Dependency graph integrity

The plugin graph MUST hold without cycles: `clientes_facturacion` keeps `require = "clientes_core"`; `facturacion_base` keeps its full `require`; `catalogo_core/fsframework.ini` MUST add `require = "clientes_facturacion"` because `fbase_controller.php:688` instantiates `regularizacion_iva` at runtime.

#### Scenario: catalogo_core + clientes_facturacion without facturacion_base
- GIVEN `catalogo_core` and `clientes_facturacion` active, `facturacion_base` inactive
- WHEN `fbase_controller::validateFacturaEjercicio()` fires `new regularizacion_iva()`
- THEN the class resolves from the new home via the new `require`

### Requirement: Static analysis clean

`ddev exec composer phpstan` MUST report no new errors attributable to the move. Pre-existing errors in `facturacion_base/` (out of scope) MUST stay unchanged.

#### Scenario: PHPStan after move
- GIVEN the change applied
- WHEN `ddev exec composer phpstan` runs
- THEN no error originates from the moved plugin or the updated `require_once` line in `facturacion_base`
