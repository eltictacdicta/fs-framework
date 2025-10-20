# Integración del Sistema de Temas en el Instalador

## Resumen de Cambios

El archivo `install.php` ha sido adaptado para ser consciente del sistema de temas de FSFramework, específicamente del tema por defecto **AdminLTE**.

## ¿Por qué esta adaptación?

El `install.php` es un archivo PHP standalone que no puede utilizar el sistema RainTPL ni el sistema de plugins de FSFramework (porque aún no están configurados). Sin embargo, es importante que:

1. **Detecte** si el tema AdminLTE está disponible
2. **Cargue** recursos CSS/JS del tema si están disponibles
3. **Informe** al usuario sobre el tema que se instalará
4. **Configure** correctamente `FS_DEFAULT_THEME` en `config.php`

## Cambios Realizados

### 1. Detección del Tema

```php
// Verificar que el tema por defecto existe
$default_theme = 'AdminLTE';
$theme_available = file_exists(__DIR__ . '/plugins/' . $default_theme);
```

### 2. Carga Condicional de Recursos

**CSS:**
```php
<?php
// Cargar estilos de AdminLTE si está disponible
if (file_exists('plugins/AdminLTE/view/css/AdminLTE.min.css')) {
    echo '<link rel="stylesheet" href="plugins/AdminLTE/view/css/AdminLTE.min.css" />';
}
if (file_exists('plugins/AdminLTE/view/css/skins/_all-skins.min.css')) {
    echo '<link rel="stylesheet" href="plugins/AdminLTE/view/css/skins/_all-skins.min.css" />';
}
?>
```

**JavaScript:**
```php
<?php
// Scripts de AdminLTE si están disponibles
if (file_exists('plugins/AdminLTE/view/js/jquery.slimscroll.min.js')) {
    echo '<script src="plugins/AdminLTE/view/js/jquery.slimscroll.min.js"></script>';
}
if (file_exists('plugins/AdminLTE/view/js/app.min.js')) {
    echo '<script src="plugins/AdminLTE/view/js/app.min.js"></script>';
}
?>
```

### 3. Información Visual al Usuario

**Alerta informativa:**
```html
<div class="alert alert-info">
    <i class="fa fa-paint-brush"></i>
    <strong>Tema AdminLTE detectado:</strong> 
    Se instalará automáticamente el tema AdminLTE para proporcionar 
    una interfaz moderna y profesional.
</div>
```

**Modal con detalles:**
- Botón "Info del Tema" que abre un modal
- Descripción de características de AdminLTE
- Explicación del sistema de temas

### 4. Configuración en `config.php`

La función `guarda_config()` ahora:

```php
// Escribe comentarios explicativos
fwrite($archivo, "\n// Sistema de temas\n");
fwrite($archivo, "// El tema por defecto se activa automáticamente en config2.php\n");

// Escribe FS_DEFAULT_THEME si el tema existe
global $default_theme, $theme_available;
if ($theme_available) {
    fwrite($archivo, "define('FS_DEFAULT_THEME', '" . $default_theme . "');\n");
} else {
    fwrite($archivo, "// define('FS_DEFAULT_THEME', 'AdminLTE'); // Tema no encontrado\n");
}
```

## Compatibilidad

### Con AdminLTE Disponible
- ✅ Carga recursos CSS de AdminLTE
- ✅ Carga scripts JS de AdminLTE
- ✅ Muestra alerta informativa azul
- ✅ Muestra botón "Info del Tema"
- ✅ Escribe `FS_DEFAULT_THEME` en config.php

### Sin AdminLTE (carpeta no existe)
- ✅ Usa solo recursos del core (Bootstrap básico)
- ✅ Muestra alerta de advertencia amarilla
- ✅ No escribe `FS_DEFAULT_THEME` (usa vista del core)
- ✅ Informa que se puede instalar después

## Diferencias con el Sistema RainTPL

| Aspecto | install.php | Sistema Normal (RainTPL) |
|---------|-------------|--------------------------|
| **Motor de plantillas** | HTML/PHP directo | RainTPL |
| **Carga de vistas** | Condicional manual | Automática por plugins |
| **Recursos CSS/JS** | Carga manual con `file_exists()` | Tags RainTPL `{if="..."}` |
| **Configuración** | Escribe `config.php` | Lee `config.php` |

## Flujo de Instalación

```
1. Usuario accede a install.php
   ↓
2. Se detecta si plugins/AdminLTE/ existe
   ↓
3. Se cargan recursos condicionalmente:
   - Si existe: CSS/JS de AdminLTE + recursos core
   - Si NO existe: Solo recursos core
   ↓
4. Se muestra información al usuario
   ↓
5. Usuario completa formulario
   ↓
6. Se crea config.php con FS_DEFAULT_THEME
   ↓
7. Redirección a index.php
   ↓
8. config2.php activa el tema automáticamente
   ↓
9. Usuario ve la interfaz con AdminLTE activo
```

## Pruebas

Ejecutar el script de test:

```bash
php test_theme_system.php
```

### Resultado Esperado:
```
✓ AdminLTE encontrado en plugins/
✓ El instalador detecta la disponibilidad del tema
✓ El instalador carga recursos CSS de AdminLTE condicionalmente
✓ El instalador muestra información sobre el tema
✓ El instalador configura FS_DEFAULT_THEME
✓ El instalador maneja el caso cuando el tema no existe
```

## Archivos Modificados

- `install.php` - Instalador adaptado al sistema de temas
- `test_theme_system.php` - Test extendido con validación del instalador
- `INSTALLER_THEME_INTEGRATION.md` - Este documento

## Ventajas de esta Implementación

1. **Progresiva**: Funciona con o sin AdminLTE
2. **Informativa**: El usuario sabe qué tema se instalará
3. **Consistente**: Sigue la lógica del sistema de temas
4. **Mantenible**: Código claro y bien documentado
5. **Compatible**: No rompe instalaciones existentes

## Próximos Pasos

- ✅ Verificar que `config2.php` activa el tema automáticamente
- ✅ Documentar el sistema en `THEME_SYSTEM.md`
- ✅ Crear test de validación
- ⏳ Probar instalación real en servidor
- ⏳ Verificar que los recursos se cargan correctamente en navegador

## Notas Técnicas

### ¿Por qué no usar RainTPL en install.php?

El instalador se ejecuta **antes** de que exista `config.php`, por lo tanto:
- No hay configuración de base de datos
- No se pueden cargar plugins
- No se puede usar el sistema de plantillas
- Debe ser completamente autocontenido

### ¿Por qué carga recursos manualmente?

- **Flexibilidad**: Permite preview del tema durante instalación
- **Experiencia**: El usuario ve el estilo que tendrá la aplicación
- **Validación**: Confirma que los archivos del tema existen

### ¿Qué pasa si borro AdminLTE después?

Si se elimina AdminLTE después de la instalación:
1. `config2.php` detecta que el plugin no existe
2. No lo añade a `$GLOBALS['plugins']`
3. RainTPL usa vistas del core automáticamente
4. Todo sigue funcionando con las vistas básicas

## Referencias

- [THEME_SYSTEM.md](THEME_SYSTEM.md) - Documentación completa del sistema de temas
- [test_theme_system.php](test_theme_system.php) - Script de validación
- [plugins/AdminLTE/](plugins/AdminLTE/) - Archivos del tema AdminLTE

