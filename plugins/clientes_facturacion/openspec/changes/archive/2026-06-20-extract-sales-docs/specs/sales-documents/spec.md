# Delta: sales-documents

Change: `extract-sales-docs`
Move target: `facturacion_base/{model,model/core,model/table,extras,controller,view}/` → `clientes_facturacion/{model,model/core,model/table,extras,controller,view}/`
See proposal §Affected Areas and §Risks for the file inventory and R1–R6.

> Ownership transfer, not behavior change. The shim/core pattern (10 shims in `model/`, 10 cores in `model/core/`, `FSFramework\model` namespace) is preserved at the new home. Moved controllers keep their UI/UX.

## MODIFIED Requirements

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

`factura_proveedor` (staying in `facturacion_base`) MUST keep working with the moved `factura` trait. The 2 `__DIR__`-relative `require_once` calls in `facturacion_base/model/core/factura_proveedor.php:22` and `factura_cliente.php:23` MUST point to `plugins/clientes_facturacion/extras/factura.php`. Safe because `facturacion_base`'s `require` already lists `clientes_facturacion`.

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

`plugins/clientes_facturacion/tests/ModelLoadingTest.php` MUST contain 10 tests (one per moved model class). `ddev exec php vendor/bin/phpunit --testsuite Plugins` reports 10/10 green with no regression.

#### Scenario: Plugin suite passes
- GIVEN the change applied (move + Init + tests)
- WHEN `ddev exec php vendor/bin/phpunit --testsuite Plugins` runs
- THEN 10 new tests pass and no previously-green test fails

### Requirement: Functional standalone of clientes_facturacion

With `clientes_facturacion` active and `facturacion_base` inactive, every moved model class MUST still be instantiable. Consumers that `require` only `clientes_facturacion` instantiate each moved model without a `facturacion_base` model-layer dependency. (Runtime coupling between moved **controllers** and `facturacion_base`'s accounting models is a future-SDD concern — see R3.)

#### Scenario: Standalone instantiation
- GIVEN `facturacion_base` inactive
- WHEN `new factura_cliente()` is called from a `clientes_facturacion` consumer
- THEN the shim resolves and the core instantiates without fatal error

#### Scenario: tpvmod-style consumer smoke check
- GIVEN a script that does `require_once 'plugins/clientes_facturacion/model/factura_cliente.php'; new factura_cliente();`
- WHEN executed with only `clientes_facturacion` and `clientes_core` active
- THEN the class is reachable and instantiable

### Requirement: Dependency graph integrity

The plugin graph MUST hold without cycles: `clientes_facturacion` keeps `require = "clientes_core"` (ini 1→2); `facturacion_base` keeps its full `require` (158→159); `catalogo_core/fsframework.ini` MUST add `require = "clientes_facturacion"` because `fbase_controller.php:688` instantiates `regularizacion_iva` at runtime (R4).

#### Scenario: catalogo_core + clientes_facturacion without facturacion_base
- GIVEN `catalogo_core` and `clientes_facturacion` active, `facturacion_base` inactive
- WHEN `fbase_controller::validateFacturaEjercicio()` fires `new regularizacion_iva()`
- THEN the class resolves from the new home via the new `require`

### Requirement: Static analysis clean

`ddev exec composer phpstan` MUST report no new errors attributable to the move. Pre-existing errors in `facturacion_base/` (out of scope) MUST stay unchanged.

#### Scenario: PHPStan after move
- GIVEN the change applied
- WHEN `ddev exec composer phpstan` runs
- THEN no error originates from the moved plugin or the 2 updated `require_once` lines in `facturacion_base`
