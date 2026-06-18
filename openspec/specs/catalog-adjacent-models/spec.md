# catalog-adjacent-models Specification

## Purpose

Transferencia de 13 modelos adyacentes al catálogo y sus 13 esquemas XML desde `plugins/facturacion_base/` a `plugins/catalogo_core/`, preservando namespace, nombres de clase y nombres de tabla para mantener compatibilidad con los 76 sitios de llamada conocidos. El framework `fs_model_autoloader` maneja automáticamente la carga de modelos.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| CAM-01 | Los 13 archivos PHP **MUST** moverse de `plugins/facturacion_base/model/core/` a `plugins/catalogo_core/model/core/` | MUST |
| CAM-02 | Los 13 archivos XML **MUST** moverse de `plugins/facturacion_base/model/table/` a `plugins/catalogo_core/model/table/` | MUST |
| CAM-03 | El namespace `FSFramework\model` **MUST** permanecer inalterado para los 13 modelos adyacentes (no se reescriben a PSR-4 PascalCase en este cambio) | MUST |
| CAM-04 | Los nombres de clase (snake_case) **MUST** permanecer idénticos | MUST |
| CAM-05 | Los nombres de tabla y columnas en los XML **MUST NOT** modificarse | MUST NOT |
| CAM-06 | Los 76 sitios de llamada conocidos **MUST** seguir resolviendo las clases sin cambios | MUST |
| CAM-07 | El framework `fs_model_autoloader` **MUST** cargar automáticamente los 13 modelos adyacentes desde `plugins/catalogo_core/model/core/` | MUST |
| CAM-08 | `facturacion_base/facturascripts.ini` **MUST** seguir declarando dependencia de `catalogo_core` (no al revés) | MUST |
| CAM-09 | Los modelos adyacentes **MUST** seguir extendiendo `fs_model` con la misma firma de namespace | MUST |
| CAM-10 | Los 13 modelos adyacentes **MUST NOT** tener aliases globales creados automáticamente (no son globales legacy como articulo/familia) | MUST NOT |

### Scenario: articulo_proveedor resolves identically after git mv

- **GIVEN** `tpvmod` tiene `use FSFramework\model\articulo_proveedor;`
- **WHEN** se aplica el `git mv` a `plugins/catalogo_core/model/core/articulo_proveedor.php`
- **THEN** `fs_model_autoloader` resuelve la clase desde `catalogo_core/model/core/`
- **AND** `new articulo_proveedor()` produce el mismo objeto que antes

### Scenario: XML schema retains identical columns and constraints

- **GIVEN** `stocks.xml` con columnas `id`, `referencia`, `codalmacen`, `cantidad`, `disponible` y PK `stocks_pkey`
- **WHEN** el archivo se mueve a `plugins/catalogo_core/model/table/stocks.xml`
- **THEN** el contenido del XML es byte-idéntico
- **AND** la instalación del plugin no detecta cambios estructurales

### Scenario: fs_model_autoloader loads adjacent models from catalogo_core

- **GIVEN** `fs_model_autoloader` registrado en el bootstrap
- **WHEN** se instancia cualquier modelo adyacente (ej: `new articulo_proveedor()`)
- **THEN** el autoloader busca en `plugins/catalogo_core/model/core/`
- **AND** carga el archivo correcto según el nombre de clase
- **AND** los 13 modelos son autoloadables sin `require_once` manual

### Scenario: namespace stays FSFramework\model (no PSR-4 rewrite)

- **GIVEN** `articulo_traza.php` declara `namespace FSFramework\model;`
- **WHEN** se mueve a `catalogo_core/model/core/`
- **THEN** la declaración `namespace` no se modifica
- **AND** no se introduce un wrapper PSR-4 PascalCase (esa reescritura queda fuera de alcance)

### Scenario: 76 known call sites resolve after migration

- **GIVEN** un inventario previo lista 76 sitios de llamada a los 13 modelos adyacentes
- **WHEN** se ejecuta la suite `phpunit Plugins`
- **THEN** los 76 sitios resuelven la clase correctamente
- **AND** no se requieren cambios en el código de los consumidores

### Scenario: adjacent models do not have global aliases

- **GIVEN** los 13 modelos adyacentes en `catalogo_core/model/core/`
- **WHEN** se verifica si existen aliases globales (ej: `class_exists('articulo_proveedor', false)`)
- **THEN** no existen aliases globales para estos modelos
- **AND** solo se accede vía namespace completo `FSFramework\model\articulo_proveedor` o vía `use` statement
