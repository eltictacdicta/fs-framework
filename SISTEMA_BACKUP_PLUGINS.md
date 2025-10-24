# Sistema de Backup Automático de Plugins

## Descripción

Este sistema permite crear backups automáticos de plugins cuando se sobrescriben, y restaurar versiones anteriores desde el listado de plugins.

## Características Implementadas

### 1. Backup Automático al Sobrescribir

Cuando se sube un plugin (manual o desde descargas) que ya existe:
- Se muestra un modal con información del plugin actual y el nuevo
- Muestra las versiones de ambos plugins
- Si el usuario confirma:
  - Se elimina cualquier backup anterior (`nombre_plugin_back`)
  - Se copia el plugin actual a `nombre_plugin_back`
  - Se instala el nuevo plugin

### 2. Filtrado de Plugins Backup

Los plugins que terminan en `_back`:
- **NO** aparecen en el listado normal de plugins
- **NO** se pueden activar directamente
- Solo existen para ser restaurados

### 3. Restauración de Backups

Si un plugin tiene un backup disponible:
- Aparece un botón "Restaurar versión anterior" en el listado
- Al hacer click, se muestra confirmación
- Si se confirma:
  - Se desactiva el plugin actual (si está activo)
  - Se elimina el plugin actual
  - Se renombra `nombre_plugin_back` a `nombre_plugin`
  - La carpeta `_back` desaparece

## Archivos Modificados

### 1. `/base/fs_plugin_manager.php`

**Nuevos métodos públicos:**
- `has_backup($plugin_name)` - Verifica si existe backup
- `create_backup($plugin_name)` - Crea backup del plugin
- `restore_backup($plugin_name)` - Restaura desde backup
- `check_plugin_exists($plugin_name)` - Obtiene info del plugin
- `detect_plugin_from_zip($zip_path)` - Detecta nombre y versión desde ZIP

**Métodos modificados:**
- `installed()` - Filtra plugins `_back` y agrega flag `has_backup`
- `install($path, $name, $create_backup)` - Nuevo parámetro para crear backup
- `download($plugin_id, $create_backup)` - Nuevo parámetro para crear backup

### 2. `/controller/admin_home.php`

**Nuevos métodos:**
- `restore_plugin_backup($plugin_name)` - Maneja restauración

**Métodos modificados:**
- `install_plugin()` - Detecta plugin existente y muestra confirmación
- `download($plugin_id)` - Detecta plugin existente y muestra confirmación
- `exec_actions()` - Maneja nuevas acciones:
  - `restore_backup` - Restaurar backup
  - `cancel_pending_install` - Cancelar instalación pendiente
  - `cancel_pending_download` - Cancelar descarga pendiente

### 3. `/view/tab/admin_home_plugins.html`

**Cambios:**
- Agregado botón "Restaurar versión anterior" (solo si `has_backup` es true)
- El botón llama a función JavaScript `restaurar_backup(nombre)`

### 4. `/view/admin_home.html`

**JavaScript añadido:**
- Función `restaurar_backup(name)` - Confirmación antes de restaurar

**Modales añadidos:**
- `modal_confirm_overwrite_install` - Confirmación para sobrescribir al instalar
- `modal_confirm_overwrite_download` - Confirmación para sobrescribir al descargar

**Scripts de inicialización:**
- Detecta si hay `$_SESSION['pending_plugin']` y muestra modal
- Detecta si hay `$_SESSION['pending_download']` y muestra modal
- Limpia sesión al cancelar modales

## Flujo de Trabajo

### Instalación Manual con Sobrescritura

1. Usuario sube archivo ZIP del plugin
2. Sistema detecta nombre y versión del ZIP
3. Si el plugin existe:
   - Archivo se guarda temporalmente
   - Info se guarda en `$_SESSION['pending_plugin']`
   - Se muestra modal de confirmación
4. Si usuario confirma:
   - Se crea backup del plugin actual
   - Se instala el nuevo plugin
   - Se limpia sesión
5. Si usuario cancela:
   - Se elimina archivo temporal
   - Se limpia sesión

### Descarga con Sobrescritura

1. Usuario hace click en "Descargar" de un plugin
2. Sistema verifica si el plugin existe
3. Si existe:
   - Info se guarda en `$_SESSION['pending_download']`
   - Se muestra modal de confirmación
4. Si usuario confirma:
   - Se descarga el plugin
   - Se crea backup del actual
   - Se instala el nuevo
   - Se limpia sesión
5. Si usuario cancela:
   - Se limpia sesión

### Restauración de Backup

1. Usuario ve botón "Restaurar versión anterior" en plugin con backup
2. Usuario hace click en el botón
3. Se muestra confirmación JavaScript (bootbox)
4. Si confirma:
   - Se desactiva el plugin si está activo
   - Se elimina carpeta del plugin actual
   - Se renombra `nombre_plugin_back` a `nombre_plugin`
   - Se limpia caché
   - Se muestra mensaje de éxito

## Variables de Sesión Utilizadas

- `$_SESSION['pending_plugin']` - Plugin pendiente de instalación manual
  - `name` - Nombre del plugin
  - `new_version` - Versión del nuevo plugin
  - `current_version` - Versión del plugin actual
  - `temp_file` - Ruta al archivo ZIP temporal

- `$_SESSION['pending_download']` - Plugin pendiente de descarga
  - `plugin_id` - ID del plugin en la lista de descargas
  - `name` - Nombre del plugin
  - `current_version` - Versión del plugin actual

## Consideraciones de Seguridad

- Los archivos temporales se eliminan después de la instalación o cancelación
- Las sesiones se limpian al cancelar modales
- Se verifican permisos de escritura antes de crear backups
- Solo administradores pueden gestionar plugins

## Pruebas Recomendadas

1. **Instalación de plugin nuevo** - Verificar que se instala sin crear backup
2. **Sobrescritura de plugin existente** - Verificar modal de confirmación
3. **Cancelación de sobrescritura** - Verificar que se limpia archivo temporal
4. **Restauración de backup** - Verificar que el plugin se restaura correctamente
5. **Plugin con backup desactivado** - Verificar que botón de restaurar aparece
6. **Plugin sin backup** - Verificar que botón de restaurar NO aparece
7. **Múltiples sobrescrituras** - Verificar que solo se mantiene un backup

## Mejoras Futuras Posibles

- Permitir múltiples backups con timestamps
- Comparar archivos para detectar cambios reales
- Exportar backup como ZIP antes de sobrescribir
- Log de operaciones de backup/restauración
- Interfaz para ver detalles del backup (fecha, versión, etc.)

