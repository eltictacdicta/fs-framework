# catalog-page-views Specification

## Purpose

Migración de las 10 páginas de catálogo a controladores PSR-4 + plantillas Twig bajo `plugins/catalogo_core/`, con wrappers legacy que preservan el routing y los nombres de página consumidos por `tpvmod`, `tarifario`, `business_data` y `clientes_catalogo`.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| CPV-01 | Las 10 páginas de catálogo **MUST** renderizarse vía Twig en `plugins/catalogo_core/View/` | MUST |
| CPV-02 | Los controladores modernos **MUST** vivir como clases PSR-4 en `plugins/catalogo_core/Controller/` | MUST |
| CPV-03 | Cada página **MUST** tener un wrapper legacy en `plugins/catalogo_core/controller/<lowercase>.php` que preserve el routing | MUST |
| CPV-04 | Los wrappers **MUST** aceptar los mismos parámetros de query (`ref`, `cod`, ...) que la versión previa | MUST |
| CPV-05 | Los nombres de página **MUST** preservarse: `ventas_articulo`, `ventas_familia`, `ventas_fabricante`, `admin_paises`, `admin_almacenes`, `admin_divisas`, `ventas_articulos`, `ventas_familias`, `ventas_fabricantes` | MUST |
| CPV-06 | Toda salida Twig **MUST** usar `{{ }}`; los datos provistos por el usuario **MUST NOT** renderizarse con `\|raw` | MUST NOT |
| CPV-07 | Todo formulario POST **MUST** incluir `{{ csrf_field() }}` y el controlador **MUST** pasar la validación CSRF automática | MUST |
| CPV-08 | Toda consulta SQL **MUST** usar `$this->var2str()` o prepared statements; **MUST NOT** concatenar entrada del usuario | MUST NOT |
| CPV-09 | Los call sites en `facturacion_base` y `tpvmod` **MUST NOT** modificarse | MUST NOT |
| CPV-10 | Los wrappers legacy **MUST** seguir el patrón ya establecido en `admin_almacenes.php`/`AdminAlmacenes.php` | MUST |
| CPV-11 | Las 10 páginas **MUST** devolver HTTP 200 a usuarios autenticados con permisos, idéntico a la versión RainTPL previa | MUST |

### Scenario: ventas_articulo renders via Twig wrapper

- **GIVEN** usuario autenticado y un `articulo` con `referencia = 'A001'`
- **WHEN** navega a `index.php?page=ventas_articulo&ref=A001`
- **THEN** el router instancia `controller/ventas_articulo.php` (wrapper)
- **AND** el wrapper delega en `Controller/VentasArticulo.php`
- **AND** `View/ventas_articulo.html.twig` se renderiza con los datos del artículo

### Scenario: CSRF blocks malformed POST to admin_almacenes

- **GIVEN** POST a `admin_almacenes` sin token CSRF o con token inválido
- **WHEN** el framework ejecuta `pre_private_core()`
- **THEN** `isCsrfValid()` retorna `false`
- **AND** `new_error_msg()` se invoca con el mensaje de token inválido
- **AND** ningún cambio se persiste

### Scenario: ventas_familia preserves cod parameter

- **GIVEN** una familia con `codfamilia = 'FAM01'`
- **WHEN** se accede a `index.php?page=ventas_familia&cod=FAM01`
- **THEN** el wrapper recibe `cod` y lo pasa al controlador PSR-4
- **AND** la plantilla Twig muestra los artículos de `FAM01`

### Scenario: ventas_fabricante URL preserved for tpvmod

- **GIVEN** `tpvmod` enlaza a `index.php?page=ventas_fabricante&cod=ACME`
- **WHEN** un usuario sigue ese enlace
- **THEN** la página `ventas_fabricante` sigue disponible bajo `catalogo_core`
- **AND** `tpvmod` no requiere cambios en su código

### Scenario: Twig auto-escape protects against XSS

- **GIVEN** un país con `nombre = '<script>alert(1)</script>'`
- **WHEN** la plantilla Twig renderiza el campo
- **THEN** la salida usa `{{ fsc.pais.nombre }}` (auto-escape)
- **AND** el navegador muestra el texto literal sin ejecutar el script

### Scenario: SQL injection blocked by var2str on search

- **GIVEN** usuario en `ventas_articulos` envía `search='%' OR 1=1 --`
- **WHEN** el controlador construye la query LIKE
- **THEN** el término se pasa por `$this->var2str()` antes de interpolarse
- **AND** la query falla de forma segura sin exfiltrar filas

### Scenario: wrapper pattern matches admin_almacenes reference

- **GIVEN** `controller/admin_almacenes.php` y `Controller/AdminAlmacenes.php` ya implementados
- **WHEN** se crean los 9 wrappers restantes
- **THEN** cada wrapper replica la estructura del reference
- **AND** cada controlador PSR-4 replica la estructura del reference

### Scenario: legacy admin_paises?cod=XX still resolves

- **GIVEN** usuario navega a `index.php?page=admin_paises&cod=ESP`
- **WHEN** el router resuelve `page=admin_paises`
- **THEN** instancia `controller/admin_paises.php` (wrapper legacy)
- **AND** `cod=ESP` se lee y se usa para cargar el país España
- **AND** la plantilla Twig renderiza el detalle
