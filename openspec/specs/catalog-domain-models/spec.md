# catalog-domain-models Specification

## Purpose

Propiedad única sobre las 7 entidades de catálogo (`articulo`, `familia`, `fabricante`, `impuesto`, `almacen`, `divisa`, `pais`) bajo `plugins/catalogo_core/`. El framework `fs_model_autoloader` maneja automáticamente la carga de modelos y la creación de aliases globales, respetando el orden de dependencias entre plugins y permitiendo overrides.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| CDM-01 | Las 7 entidades **MUST** residir como clases bajo `FSFramework\model` en `plugins/catalogo_core/model/core/` | MUST |
| CDM-02 | Cada entidad **MUST** tener también un wrapper PSR-4 PascalCase en `plugins/catalogo_core/Model/` que apunte al mismo archivo de implementación | MUST |
| CDM-03 | Los stubs de 30 líneas en `plugins/facturacion_base/model/{articulo,familia,fabricante,impuesto}.php` **MUST** ser eliminados como parte de esta migración | MUST |
| CDM-04 | El framework `fs_model_autoloader` **MUST** crear automáticamente aliases globales para `articulo`, `familia`, `fabricante` e `impuesto` cuando se cargan desde `FSFramework\model` | MUST |
| CDM-05 | `almacen`, `divisa` y `pais` **MUST NOT** requerir alias porque ya eran clases globales antes de la migración | MUST NOT |
| CDM-06 | Los modelos **MUST** permitir que plugins dependientes sobrescriban su lógica mediante el mecanismo de override del framework | MUST |
| CDM-07 | `plugins/catalogo_core/Init.php` **MUST** existir pero **MAY** estar vacío, ya que el framework maneja la carga de modelos | MUST |
| CDM-08 | La identidad de clase **MUST** preservarse: `new articulo()`, `\FSFramework\model\articulo` y `\FSFramework\Plugins\catalogo_core\Model\Articulo` **MUST** devolver la misma instancia subyacente | MUST |
| CDM-09 | `articulo::url()`, `familia::url()`, `fabricante::url()` e `impuesto::url()` **MUST** seguir produciendo exactamente las mismas URLs de página que producían antes de la migración | MUST |
| CDM-10 | El comportamiento de cada entidad (CRUD, validaciones, eventos) **MUST NOT** cambiar respecto a la implementación previa en `facturacion_base` | MUST NOT |
| CDM-11 | Los modelos existentes en `plugins/catalogo_core/model/core/` para `almacen`, `divisa` y `pais` **MUST** ser cargados correctamente por el `fs_model_autoloader` | MUST |

### Scenario: articulo resolves identically across the three namespaces

- **GIVEN** `plugins/catalogo_core` activo y `fs_model_autoloader` registrado
- **WHEN** el código instancia `new articulo()` o usa `\FSFramework\model\articulo` o `\FSFramework\Plugins\catalogo_core\Model\Articulo`
- **THEN** las tres formas devuelven instancias del mismo FQCN
- **AND** `instanceof` y `get_class()` son consistentes entre las tres formas

### Scenario: facturacion_base stubs removed without breaking consumers

- **GIVEN** los stubs en `plugins/facturacion_base/model/{articulo,familia,fabricante,impuesto}.php` eliminados
- **WHEN** un plugin consumidor (p.ej. `tpvmod`, `tarifario`) usa `new articulo()`
- **THEN** la clase se resuelve vía `fs_model_autoloader` desde `catalogo_core/model/core/articulo.php`
- **AND** el comportamiento de los métodos CRUD es idéntico al previo

### Scenario: almacen, divisa and pais need no alias

- **GIVEN** `almacen`, `divisa`, `pais` ya estaban registrados como globales antes de la migración
- **WHEN** cualquier consumidor instancia `new almacen()`
- **THEN** la clase se resuelve correctamente desde `catalogo_core/model/core/almacen.php`
- **AND** no depende de los stubs de `facturacion_base`

### Scenario: dependent plugin can override catalogo_core model

- **GIVEN** `tarifario` depende de `catalogo_core` y tiene su propio `model/familia.php`
- **WHEN** `tarifario` define `class familia extends FSFramework\model\tarif_familia`
- **THEN** el override funciona correctamente sin error "Cannot declare class"
- **AND** `fs_model_autoloader` respeta el orden de plugins en `$GLOBALS['plugins']`

### Scenario: articulo::url() returns the legacy ventas_articulo URL

- **GIVEN** un `articulo` con `referencia = 'A001'`
- **WHEN** se invoca `articulo::url()`
- **THEN** retorna `index.php?page=ventas_articulo&ref=A001` (sin cambios respecto al comportamiento previo)

### Scenario: fs_model_autoloader loads models from catalogo_core

- **GIVEN** `fs_model_autoloader` registrado en el bootstrap
- **WHEN** se instancia cualquier modelo de `catalogo_core`
- **THEN** el autoloader busca en `plugins/catalogo_core/model/core/`
- **AND** carga el archivo correcto según el nombre de clase

### Scenario: zero behavior change on CRUD for all 7 entities

- **GIVEN** las 7 entidades migradas a `catalogo_core/model/core/` con sus XML en `catalogo_core/model/table/`
- **WHEN** se ejecuta la suite `phpunit Plugins`
- **THEN** los tests existentes que tocan `save()`, `delete()`, `exists()`, `get()` y `all()` para cada entidad pasan sin modificación
