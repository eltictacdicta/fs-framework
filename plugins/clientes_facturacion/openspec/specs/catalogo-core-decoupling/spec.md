# Spec: catalogo-core-decoupling

> Synced from changes/optional-iva-regularization on 2026-07-01.

## Purpose

Codifies the structural decoupling between `catalogo_core` and
`clientes_facturacion` after the dead-code removal of
`fbase_controller::validateFacturaEjercicio()`. The five requirements
are the normative form of the three anti-regression assertions plus
two guards (kept model, corrected comment). All scenarios run under
`ddev exec php vendor/bin/phpunit -c plugins/clientes_facturacion/phpunit.xml`.

## Requirements

### Requirement: catalogo-core no longer requires clientes-facturacion

`plugins/catalogo_core/fsframework.ini` MUST NOT list
`clientes_facturacion` in its `require` field. Line 5 MUST read
`require = ""`. The decoupling is structural: `catalogo_core` MUST
load with no client-invoicing plugin active.

#### Scenario: fsframework.ini require is empty
- **GIVEN** `plugins/catalogo_core/fsframework.ini` exists
- **WHEN** the test reads line 5 and parses the `require` directive
- **THEN** the parsed list of comma-separated tokens does NOT contain
  `clientes_facturacion` (empty list is acceptable)

### Requirement: fbase_controller no longer references regularizacion_iva

`plugins/catalogo_core/extras/fbase_controller.php` MUST NOT contain
the string `regularizacion_iva` anywhere — no symbol, no comment, no
string literal. The dead-code removal MUST leave the class free of
any token that would force a load-time dep on the client-side plugin.

#### Scenario: fbase_controller is regularizacion_iva-free
- **GIVEN** `plugins/catalogo_core/extras/fbase_controller.php` exists
- **WHEN** the test greps the file for the literal `regularizacion_iva`
- **THEN** zero matches are returned

### Requirement: fbase_controller no longer declares validateFacturaEjercicio

`fbase_controller` MUST NOT declare a method named
`validateFacturaEjercicio` (any visibility, any signature). The class
MUST remain loadable; only the deleted method is asserted absent.

#### Scenario: validateFacturaEjercicio method does not exist
- **GIVEN** `plugins/catalogo_core/extras/fbase_controller.php` has
  been `require_once`-loaded under `FS_FOLDER`
- **WHEN** the test calls
  `method_exists('fbase_controller', 'validateFacturaEjercicio')`
- **THEN** the result is `false`

### Requirement: regularizacion_iva model and bridge remain reachable

`regularizacion_iva` MUST stay loadable from
`plugins/clientes_facturacion/model/regularizacion_iva.php` (canonical)
and `plugins/clientes_facturacion/model/core/regularizacion_iva.php`
(bridge), with consumers at
`plugins/clientes_facturacion/model/core/factura_cliente.php:151` and
`:590` still resolving the class. The deletion MUST NOT touch the
model, the bridge, or the two consumer lines.

#### Scenario: regularizacion_iva loads from clientes_facturacion
- **GIVEN** `plugins/clientes_facturacion` is active and the
  canonical file `plugins/clientes_facturacion/model/regularizacion_iva.php`
  exists
- **WHEN** the existing test
  `ModelLoadingTest::testRegularizacionIvaLoadsFromClientesFacturacion`
  runs
- **THEN** it passes (the bridge + canonical pair remain functional
  and the class is instantiable under
  `FSFramework\model\regularizacion_iva`)

### Requirement: stale comment in ModelLoadingTest.php is corrected

The docblock at
`plugins/clientes_facturacion/tests/ModelLoadingTest.php:529–543` and
the assertion message at line 547 MUST reflect the current
inheritance of `ventas_clientes.php`: the file lives at
`plugins/clientes_core/controller/ventas_clientes.php`, extends
`clientes_controller` (which extends `fs_controller`), and has NO
`require_once` to `fbase_controller.php`. The comment MUST NOT claim
that `ventas_clientes.php` extends `fbase_controller` or lives in
`facturacion_base/controller/`.

#### Scenario: comment names the real parent and path
- **GIVEN** `plugins/clientes_facturacion/tests/ModelLoadingTest.php`
  has been read into memory
- **WHEN** the test greps the docblock (lines 529–543) and the
  assertion message at line 547
- **THEN** the substring `fbase_controller` does NOT appear in the
  comment or message in the context of `ventas_clientes`, AND the
  substrings `clientes_controller` and
  `clientes_core/controller/ventas_clientes` each appear at least
  once (the comment names the real parent class and the real file
  path)
