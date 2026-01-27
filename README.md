# FSFramework

Fork modernizado de FacturaScripts 2017 con soporte inicial para plugins de FacturaScripts 2025.

Software libre bajo licencia GNU/LGPL.

## Advertencia

Este framework mantiene la lógica original de FacturaScripts 2017 pero ha sido modernizado con componentes de Symfony 7.4 y migrado a Twig como motor de plantillas principal. **No es 100% compatible con la funcionalidad base de facturación original**, en el futuro ofrecerá compatibilidad parcial con plugins de FacturaScripts 2025.

## Novedades Principales

### Migración Completa a Twig

El motor de plantillas ha sido migrado de RainTPL a **Twig 3.x**, con:

- **Plantillas principales migradas**: Login, Header, Footer, Menús y templates base
- **Traductor automático RainTPL → Twig**: Las plantillas legacy `.html` se traducen automáticamente a sintaxis Twig
- **Soporte dual**: Detecta y renderiza tanto `.html.twig` (nativo) como `.html` (traducido)
- **Macros reutilizables**: Sistema de macros Twig para menús y utilidades comunes

```
view/
├── Master/
│   ├── Base.html.twig           # Template base con bloques extensibles
│   ├── MenuTemplate.html.twig   # Layout con menú lateral AdminLTE
│   └── MenuBghTemplate.html.twig # Layout alternativo (compatible BS5)
├── Login/
│   └── Login.html.twig          # Página de login modernizada
├── Macro/
│   ├── Menu.html.twig           # Macros para renderizar menús
│   └── Utils.html.twig          # Utilidades generales
├── header.html.twig             # Header compatible con legacy
└── footer.html.twig             # Footer compatible con legacy
```

### Traductor RainTPL a Twig

Para mantener compatibilidad con plugins de FacturaScripts 2017, se incluye un traductor automático que convierte sintaxis RainTPL a Twig en tiempo real:

| RainTPL | Twig |
|---------|------|
| `{$variable}` | `{{ variable }}` |
| `{$obj->method()}` | `{{ obj.method() }}` |
| `{loop="$items"}` | `{% for value in items %}` |
| `{if="$cond"}` | `{% if cond %}` |
| `{include="file"}` | `{{ include('file.html') }}` |
| `{function="name()"}` | `{{ name() }}` |
| `{#CONSTANT#}` | `{{ constant('CONSTANT') }}` |

### Compatibilidad con FacturaScripts 2025

FSFramework ofrece **soporte inicial** para plugins de FacturaScripts 2025:

- **Capa de compatibilidad**: Namespace `FacturaScripts\Core` con bridges para `Controller`, `Tools`, `Html`, `Cache`, etc.
- **Estructura dual**: Soporte para `Controller/` (FS2025) y `controller/` (legacy)
- **Traducciones FS2025**: Carga automática de `Translation/{locale}.json`
- **Vistas FS2025**: Soporte para carpeta `View/` (PascalCase) además de `view/`
- **Plugins probados**: Backup y otros plugins básicos de FS2025

> **Nota**: La compatibilidad con FS2025 es parcial. Se recomienda probar los plugins en un entorno de desarrollo antes de usarlos en producción.

## Integración Symfony 7.4

FSFramework utiliza componentes modernos de Symfony 7.4 manteniendo retrocompatibilidad:

### Componentes Integrados

| Componente | Versión | Uso |
|------------|---------|-----|
| `symfony/http-foundation` | ^7.4 | Request/Response HTTP |
| `symfony/routing` | ^7.4 | Rutas con atributos PHP 8 |
| `symfony/security-csrf` | ^7.4 | Protección CSRF en formularios |
| `symfony/event-dispatcher` | ^7.4 | Sistema de eventos |
| `symfony/validator` | ^7.4 | Validación de modelos |
| `symfony/dependency-injection` | ^7.4 | Contenedor de servicios |
| `symfony/form` | ^7.4 | Formularios con CSRF |
| `symfony/translation` | ^7.4 | Internacionalización (i18n) |
| `symfony/cache` | ^7.4 | Gestión de caché unificada |
| `twig/twig` | ^3.0 | Motor de plantillas |

### Routing con Atributos PHP 8

```php
use FSFramework\Attribute\FSRoute;

#[FSRoute('/api/users/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
class api_user extends fs_controller
{
    protected function private_core()
    {
        $id = $this->request->get('id');
        // ...
    }
}
```

### Protección CSRF

```twig
<form method="post" action="{{ fsc.url() }}">
    {{ csrf_field() }}
    <input type="text" name="campo" />
    <button type="submit">Guardar</button>
</form>
```

### Sistema de Eventos

```php
use FSFramework\Event\FSEventDispatcher;
use FSFramework\Event\ModelEvent;

$dispatcher = FSEventDispatcher::getInstance();
$dispatcher->addListener(ModelEvent::BEFORE_SAVE, function(ModelEvent $event) {
    $model = $event->getModel();
    if (!$model->isValid()) {
        $event->cancel('Datos inválidos');
    }
});
```

### Validación de Modelos

```php
use FSFramework\Traits\ValidatorTrait;
use Symfony\Component\Validator\Constraints as Assert;

class Cliente extends fs_model
{
    use ValidatorTrait;
    
    #[Assert\NotBlank(message: 'El nombre es obligatorio')]
    #[Assert\Length(max: 100)]
    public $nombre;
    
    #[Assert\Email(message: 'Email inválido')]
    public $email;
    
    public function test()
    {
        return $this->validate();
    }
}
```

### Contenedor de Dependencias

```php
use FSFramework\DependencyInjection\Container;

$db = Container::db();
$request = Container::request();
$cache = Container::cache();
$hasher = Container::passwordHasher();
```

### Sistema de Traducciones

```php
use FSFramework\Translation\FSTranslator;

// En PHP
echo FSTranslator::trans('login-text');
echo FSTranslator::trans('hello', ['%name%' => 'Juan']);
```

```twig
{# En Twig #}
{{ trans('login-text') }}
{{ trans('hello', {'%name%': 'Juan'}) }}
{{ 'save'|trans }}
```

Formatos soportados:
- `translations/messages.{locale}.yaml` - Formato nuevo (recomendado)
- `Translation/{locale}.json` - Formato FS2025 (compatibilidad)

### Gestión de Caché

```php
use FSFramework\Cache\CacheManager;

$cache = CacheManager::getInstance();

// Get con callback (auto-genera si no existe)
$users = $cache->get('all_users', fn() => $this->db->select("SELECT * FROM fs_users"));

// Limpiar todas las cachés (Symfony + Twig + RainTPL legacy)
$cache->clearAll();
```

## Plugin business_data

Los modelos de negocio que fueron eliminados del core se encuentran en el plugin **`business_data`**:

- `empresa` - Datos de empresa
- `ejercicio` - Ejercicios contables
- `serie` - Series de facturación
- `divisa` - Divisas/monedas
- `forma_pago` - Formas de pago
- `almacen` - Almacenes
- `pais` - Países
- `cuenta_banco` / `cuenta_banco_cliente` - Cuentas bancarias

**Si vas a usar plugins que dependan de estos modelos (como `facturacion_base`), activa `business_data` primero.**

```
Orden de activación recomendado:
1. AdminLTE (tema, activo por defecto)
2. business_data (modelos de negocio)
3. facturacion_base (si se necesita facturación)
4. Otros plugins
```

## Sistema de Temas

El tema **AdminLTE** se activa automáticamente, proporcionando una interfaz moderna y responsive.

### Características
- Interfaz basada en AdminLTE
- Diseño responsive
- Múltiples skins de color
- Menú lateral colapsable
- Compatible con Bootstrap 3 y capa de compatibilidad Bootstrap 5 (para plugins FS2025)

### Configuración

```php
// config.php
define('FS_DEFAULT_THEME', 'AdminLTE');
```

## Estructura del Proyecto

```
/
├── base/                    # Clases core (fs_model, fs_controller, etc.)
├── config/                  # Configuración de rutas Symfony
├── controller/              # Controladores principales
├── model/                   # Modelos y esquemas XML
│   └── table/              # Definiciones XML de tablas
├── plugins/                 # Plugins
│   ├── AdminLTE/           # Tema AdminLTE
│   └── business_data/      # Modelos de negocio
├── src/                     # Código moderno (Symfony, Traits, etc.)
│   ├── Attribute/          # Atributos PHP 8 (FSRoute)
│   ├── Cache/              # CacheManager
│   ├── Controller/         # Controladores Symfony
│   ├── Core/               # Kernel y Router
│   ├── DependencyInjection/ # Container de servicios
│   ├── Event/              # Sistema de eventos
│   ├── FacturaScripts/     # Capa de compatibilidad FS2025
│   │   ├── Core/           # Bridges (Controller, Tools, Html, Cache)
│   │   └── Dinamic/        # Modelos dinámicos
│   ├── Form/               # FormHelper
│   ├── Security/           # CSRF, Password Hasher, UserAdapter
│   ├── Traits/             # ResponseTrait, ValidatorTrait
│   ├── Translation/        # FSTranslator, FS2025JsonLoader
│   └── Twig/               # Extensiones Twig
├── translations/            # Traducciones del core (YAML)
├── view/                    # Plantillas Twig y assets
│   ├── Master/             # Templates base
│   ├── Login/              # Login
│   ├── Macro/              # Macros reutilizables
│   ├── css/                # Estilos
│   ├── js/                 # JavaScript
│   └── img/                # Imágenes
├── vendor/                  # Dependencias Composer
├── docs/                    # Documentación
└── extras/                  # Librerías terceros (PHPMailer, XLSXWriter)
```

## Requisitos

- PHP 8.2 o superior
- MySQL o PostgreSQL
- Servidor web (Apache/Nginx/PHP built-in server)
- Composer

## Instalación

1. Clonar el repositorio
2. Instalar dependencias:
   ```bash
   composer install
   ./build.sh
   ```
3. Configurar base de datos en `config.php`
4. Acceder a `index.php` en el navegador

## Documentación Adicional

- [Sistema de Traducciones](docs/TRANSLATION.md)
- [Sistema de Temas](THEME_SYSTEM.md)
- [Guía para Agentes de IA](AGENTS.md)

## Contribuciones

Se anima a quien quiera contribuir al proyecto a realizar pull requests.

## Contacto

Para cualquier consulta, visita: https://misterdigital.es/contacto/
