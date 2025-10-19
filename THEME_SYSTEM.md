# Sistema de Temas en FSFramework

## Descripción

FSFramework incluye un sistema de temas que permite personalizar la interfaz de usuario mediante plugins. El sistema está diseñado para activar automáticamente un tema por defecto en nuevas instalaciones, manteniendo la flexibilidad del sistema de plugins.

## Características

- **Auto-activación**: El tema por defecto se activa automáticamente en instalaciones nuevas
- **Configurable**: Se puede cambiar el tema por defecto mediante la constante `FS_DEFAULT_THEME`
- **Compatible**: Totalmente compatible con el sistema de plugins existente
- **Flexible**: Los usuarios pueden cambiar de tema en cualquier momento

## Tema por Defecto: AdminLTE

AdminLTE es el tema por defecto de FSFramework. Proporciona:
- Interfaz moderna y profesional
- Menú lateral responsive
- Iconos mejorados con Font Awesome
- Múltiples skins de color
- Compatibilidad con dispositivos móviles

## Configuración

### Definir el Tema por Defecto

En el archivo `config.php`, puedes definir el tema por defecto:

```php
define('FS_DEFAULT_THEME', 'AdminLTE');
```

Si quieres usar otro tema, simplemente cambia el nombre:

```php
define('FS_DEFAULT_THEME', 'MiTemaPersonalizado');
```

### Cómo Funciona

1. **Primera Instalación**: Durante la instalación (`install.php`), se define automáticamente `FS_DEFAULT_THEME`
2. **Carga de Plugins**: En `config2.php`, si no hay plugins activados, el sistema:
   - Verifica que el tema por defecto exista en `/plugins/`
   - Lo añade automáticamente a `$GLOBALS['plugins']`
   - Guarda la configuración en `enabled_plugins.list`
3. **Vistas**: El sistema de plantillas RainTPL busca automáticamente vistas en el plugin activado

## Estructura de un Tema

Un tema debe ser un plugin con la siguiente estructura:

```
plugins/
  MiTema/
    ├── functions.php           # Funciones auxiliares del tema (opcional)
    ├── fsframework.ini         # Metadatos del plugin
    ├── description             # Descripción del tema
    └── view/                   # Vistas que sobrescriben las del core
        ├── header.html
        ├── footer.html
        ├── css/
        ├── js/
        └── img/
```

### Archivos Importantes

- **view/header.html**: Cabecera de la página (navegación, CSS, JS)
- **view/footer.html**: Pie de página
- **view/feedback.html**: Sistema de mensajes/notificaciones
- **functions.php**: Funciones PHP auxiliares del tema

## Override de Vistas

El sistema de plantillas busca vistas en el siguiente orden:

1. **Plugins activos** (en orden de activación): `plugins/{plugin_name}/view/`
2. **Core**: `view/`

Esto permite que los temas sobrescriban cualquier vista del sistema.

## Ejemplo: Crear un Tema Personalizado

1. Crea una carpeta en `plugins/MiTema/`
2. Crea el archivo `fsframework.ini`:
   ```ini
   idplugin = 100
   min_version = "0.5"
   name = 'MiTema'
   version = 1
   ```
3. Crea `description`:
   ```
   Mi tema personalizado para FSFramework
   ```
4. Crea las vistas en `view/`:
   - `header.html`
   - `footer.html`
5. Activa el tema desde el panel de administración o defínelo en `config.php`

## Cambiar de Tema

### Desde el Panel de Administración

1. Ve a **Administración** → **Plugins**
2. Desactiva el tema actual
3. Activa el nuevo tema

### Mediante Configuración

Edita `config.php` y cambia:
```php
define('FS_DEFAULT_THEME', 'NuevoTema');
```

Luego elimina el archivo `tmp/{FS_TMP_NAME}/enabled_plugins.list` para que se recargue.

## Compatibilidad

El sistema de temas es totalmente compatible con:
- Instalaciones existentes
- Otros plugins (no-tema)
- Múltiples temas instalados (uno activo a la vez)

## Notas Técnicas

### config2.php

El archivo `base/config2.php` contiene la lógica de auto-activación:

```php
if (empty($GLOBALS['plugins'])) {
    $default_theme = defined('FS_DEFAULT_THEME') ? FS_DEFAULT_THEME : 'AdminLTE';
    
    if (file_exists(FS_FOLDER . '/plugins/' . $default_theme)) {
        $GLOBALS['plugins'][] = $default_theme;
        // Guarda en enabled_plugins.list
    }
}
```

### RainTPL

El motor de plantillas (`raintpl/rain.tpl.class.php`) busca automáticamente en los plugins:

```php
foreach ($GLOBALS['plugins'] as $plugin_dir) {
    if (file_exists('plugins/' . $plugin_dir . '/view/' . $tpl_name . '.html')) {
        $tpl_dir = 'plugins/' . $plugin_dir . '/view/' . $tpl_basedir;
        break;
    }
}
```

## Preguntas Frecuentes

**¿Puedo tener múltiples temas activos?**
No es recomendable, ya que pueden haber conflictos de vistas. El sistema de override solo usa el primer tema que encuentra.

**¿Qué pasa si desinstalo AdminLTE?**
El sistema volverá a usar las vistas del core (Bootstrap simple).

**¿Puedo crear un tema hijo?**
Sí, puedes crear un tema que solo sobrescriba ciertas vistas, dejando el resto al core o a otro tema.

**¿Cómo actualizo AdminLTE?**
AdminLTE se actualiza como cualquier plugin, desde el panel de administración o manualmente.

## Soporte

Para más información sobre el desarrollo de temas y plugins:
- [Documentación de FSFramework](https://github.com/eltictacdicta/fs-framework)
- [AdminLTE Documentation](https://adminlte.io/docs)


