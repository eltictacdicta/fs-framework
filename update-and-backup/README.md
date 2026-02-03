# FSFramework Update & Backup Module

## Versión 2.0.0

Módulo autónomo para gestionar actualizaciones y copias de seguridad del sistema FSFramework.

## Características

### Copias de Seguridad

- **Backup completo unificado**: Crea un paquete ZIP que incluye:
  - Copia de la base de datos (SQL comprimido)
  - Copia de todos los archivos (incluyendo plugins)
  - Metadatos con información de versiones

- **Tracking de versiones**: Cada backup incluye:
  - Versión del framework
  - Versión de PHP
  - Lista de plugins con sus versiones
  - Timestamp de creación

- **Restauración flexible**:
  - `restore_complete()` - Restaura todo (archivos + BD)
  - `restore_files()` - Restaura solo archivos
  - `restore_database()` - Restaura solo base de datos

### Actualizaciones

- Verificación de dependencias antes de actualizar
- Backup automático antes de cada actualización
- Auto-actualización del propio módulo actualizador

## Estructura de Directorios

```
update-and-backup/
├── data/                    # Directorio de backups (protegido)
│   ├── .htaccess           # Protección Apache
│   ├── index.php           # Bloqueo de listado
│   ├── metadata.json       # Metadatos de backups
│   └── *.zip / *.sql.gz    # Archivos de backup
├── fs_backup_manager.php   # Gestor de backups
├── updater_manager.php     # Gestor de auto-actualización
├── UpdaterController.php   # Controlador principal
├── fsframework.ini         # Configuración y versión
├── index.php               # Punto de entrada
├── .htaccess               # Protección de seguridad
└── README.md               # Este archivo
```

## Uso desde código

### Crear una copia de seguridad completa

```php
require_once 'update-and-backup/fs_backup_manager.php';

$backupManager = new fs_backup_manager();
$result = $backupManager->create_backup('mi_backup');

if ($result['complete']['success']) {
    echo "Backup creado: " . $result['complete']['unified_file'];
}
```

### Restaurar backup completo

```php
$backupManager = new fs_backup_manager();
$result = $backupManager->restore_complete('mi_backup_complete.zip');

if ($result['success']) {
    echo "Restauración completa realizada correctamente";
}
```

### Restaurar solo archivos

```php
$backupManager = new fs_backup_manager();
$result = $backupManager->restore_files('mi_backup_files.zip');
```

### Restaurar solo base de datos

```php
$backupManager = new fs_backup_manager();
$result = $backupManager->restore_database('mi_backup_db.sql.gz');
```

### Listar backups disponibles

```php
$backupManager = new fs_backup_manager();
$backups = $backupManager->list_backups();

foreach ($backups as $backup) {
    echo $backup['name'] . ' - ' . $backup['type'] . ' - ' . $backup['size_formatted'];
    echo ' (Restaurar: ';
    if ($backup['can_restore_complete']) echo 'completo, ';
    if ($backup['can_restore_files']) echo 'archivos, ';
    if ($backup['can_restore_database']) echo 'BD';
    echo ')' . PHP_EOL;
}
```

## Tipos de Backup

| Tipo | Sufijo | Descripción |
|------|--------|-------------|
| `complete` | `_complete.zip` | Paquete unificado con archivos + BD + metadatos |
| `files` | `_files.zip` | Solo archivos del sistema |
| `database` | `_db.sql.gz` | Solo base de datos comprimida |

## Seguridad

- El directorio `data/` está protegido contra acceso web
- Acceso directo al módulo bloqueado (solo vía `updater.php`)
- Archivos PHP internos protegidos por `.htaccess`

## Requisitos

- PHP 7.4 o superior
- Extensión PHP `zip`
- Extensión PHP `gd`
- Extensión PHP `curl`
- `mysqldump` o `pg_dump` para backups de BD
- Permisos de escritura en el directorio

## Notas

- Los archivos `config.php` y `config2.php` no se sobrescriben al restaurar
- Se mantienen los últimos 5 backups de cada tipo automáticamente
- Las copias se crean en formato comprimido para ahorrar espacio

## Autor

Javier Trujillo

## Licencia

LGPL-3.0-or-later
