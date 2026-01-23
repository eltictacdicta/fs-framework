# FSFramework

Este es un fork de FSFramework versión 2017 para uso general.

Software libre bajo licencia GNU/LGPL.

## Advertencia

Este framework, aunque mantiene la misma lógica de FSFramework y preserva su compatibilidad básica, tiene eliminados algunos componentes del core que no se necesitan en todos los proyectos. **No es 100% compatible con la funcionalidad base de facturación de FSFramework.**

## Plugin business_data (Compatibilidad FacturaScripts)

Los modelos de negocio que fueron eliminados del core se han movido al plugin **`business_data`**. Este plugin incluye:

- `empresa` - Datos de empresa
- `ejercicio` - Ejercicios contables
- `serie` - Series de facturación
- `divisa` - Divisas/monedas
- `forma_pago` - Formas de pago
- `almacen` - Almacenes
- `pais` - Países
- `cuenta_banco` / `cuenta_banco_cliente` - Cuentas bancarias

**Si vas a usar plugins antiguos de FacturaScripts o plugins que dependan de estos modelos (como `facturacion_base`), debes activar el plugin `business_data` primero.**

```
Orden de activación recomendado:
1. AdminLTE (tema) este ya esta activo por defecto.
2. business_data (modelos de negocio)
3. facturacion_base (si se necesita facturación)
4. Otros plugins
```

## Mejoras Recientes

### RainTPL3 4.0 
Se ha actualizado el motor de plantillas de RainTPL 2.8 a **RainTPL 4.0**, manteniendo compatibilidad total con los templates existentes:

- **Mejor manejo de estructuras de datos**: Soporte mejorado para arrays, objetos y JSON en templates
- **Parser más estricto**: Detecta errores de sintaxis en templates (tags sin cerrar, etc.)
- **Compatibilidad legacy**: Adaptador que mantiene la misma API (`RainTPL::configure()`)
- **Sistema de overrides**: Los plugins pueden sobrescribir vistas del core (AdminLTE, facturacion_base, etc.)
- **Caché compatible**: Los templates compilados siguen en `tmp/<FS_TMP_NAME>/`

### Integración Symfony
Se han integrado componentes de Symfony para modernizar el framework sin romper compatibilidad:

- **Symfony Routing**: Sistema de rutas moderno con soporte para:
  - Rutas declarativas mediante atributos PHP 8 (`#[FSRoute('/path')]`)
  - Parámetros dinámicos con validación regex
  - Generación de URLs
  - Caché de rutas en producción
- **Symfony HttpFoundation**: Objetos Request/Response para manejo de peticiones
- **ResponseTrait**: Trait para controladores con helpers de respuesta JSON/redirect

```php
// Ejemplo de ruta con atributos
#[FSRoute('/api/users/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
class api_user extends fs_controller { ... }
```

### AdminLTE Actualizado 
- Actualización del tema AdminLTE con mejoras de compatibilidad
- Corrección de estilos y scripts
- Mejor integración con el sistema de temas

### Otras Mejoras
- Compatibilidad con PHP 8.3
- Sistema de temas con auto-activación
- AdminLTE como tema por defecto

## Sistema de Temas

FSFramework incluye un sistema de temas que permite personalizar la interfaz de usuario. El tema **AdminLTE** se activa automáticamente en nuevas instalaciones, proporcionando una interfaz moderna y profesional.

Para más información, consulta la [Documentación del Sistema de Temas](THEME_SYSTEM.md).

### Características del Tema AdminLTE
- Interfaz moderna basada en AdminLTE
- Diseño responsive
- Múltiples skins de color
- Menú lateral colapsable

### Configuración
El tema por defecto se puede cambiar en `config.php`:
```php
define('FS_DEFAULT_THEME', 'AdminLTE');
```

## Estructura del Proyecto

```
/base/          # Clases core del framework (fs_model, fs_controller, etc.)
/config/        # Configuración de rutas Symfony
/controller/    # Controladores principales
/model/         # Modelos y esquemas XML
/plugins/       # Plugins (AdminLTE, facturacion_base, tarifario, etc.)
/raintpl/       # Motor de plantillas RainTPL3 4.0
/src/           # Código moderno (Symfony, Atributos, Traits)
  /Attribute/   # Atributos PHP 8 (FSRoute)
  /Controller/  # Controladores Symfony
  /Core/        # Kernel y Router
  /Traits/      # Traits reutilizables
/view/          # Plantillas HTML y assets
/vendor/        # Dependencias Composer
```

## Instalación

1. Clonar el repositorio
2. Instalar dependencias:
   ```bash
   composer install
   ./build.sh
   ```
3. Configurar base de datos en `config.php`
4. Acceder a `index.php` en el navegador

## Contribuciones

Se anima a quien quiera contribuir al proyecto a realizar pull requests.

## Contacto

Para cualquier consulta, visita: https://misterdigital.es/contacto/
