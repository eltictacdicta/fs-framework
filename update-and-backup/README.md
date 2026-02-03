# FSFramework Update and Backup System

Este directorio contiene el sistema de actualización y copias de seguridad independiente de FSFramework.

## Estructura

```
update-and-backup/
├── index.php              # Punto de entrada del módulo
├── UpdaterController.php  # Controlador principal (standalone)
├── updater_manager.php    # Gestor de versiones del actualizador
├── fs_backup_manager.php  # Librería de copias de seguridad (retrocompatible)
├── fsframework.ini        # Versión y configuración del actualizador
├── data/                  # Directorio donde se almacenan los backups
│   ├── .htaccess         # Protección de acceso
│   ├── index.php         # Previene listado de directorio
│   └── *.sql.gz / *.zip  # Archivos de backup
└── README.md             # Este archivo
```

## Características

- **Independiente del framework**: Funciona sin necesidad del Kernel o autoloader de Symfony
- **Retrocompatible**: Compatible con PHP 7.x y versiones anteriores del framework
- **Auto-actualizable**: El módulo puede actualizarse a sí mismo
- **Copias automáticas**: Se crea backup antes de cada actualización
- **Rotación automática**: Mantiene solo los últimos 5 backups
- **Verificación de sesión**: Requiere sesión de administrador

## Acceso

El módulo se accede a través de `updater.php` en la raíz del proyecto, que verifica
la sesión de administrador antes de cargar este módulo.

## Uso del Backup Manager

```php
require_once 'update-and-backup/fs_backup_manager.php';

$backup = new fs_backup_manager();

// Crear backup completo
$result = $backup->create_backup('mi_backup');

// Listar backups
$backups = $backup->list_backups();

// Eliminar backup
$backup->delete_backup('nombre_archivo.zip');
```

## Uso del Updater Manager

```php
require_once 'update-and-backup/updater_manager.php';

$updater = new updater_manager();

// Obtener información del actualizador
$info = $updater->get_info();

// Comprobar actualizaciones
$update = $updater->check_for_updates();
if ($update) {
    // Hay actualización disponible
    $updater->update_updater();
}
```

## Archivo fsframework.ini

El archivo `fsframework.ini` contiene:
- **version**: Versión actual del actualizador
- **remote_version_url**: URL para comprobar actualizaciones
- **update_url**: URL base para descargar actualizaciones

## Actualización independiente

Este directorio puede actualizarse de forma independiente desde el panel de
administración (pestaña "Actualizador") o manualmente copiando los archivos
desde una versión más reciente del framework.
