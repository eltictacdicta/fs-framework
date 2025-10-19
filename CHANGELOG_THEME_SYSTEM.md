# Changelog - Sistema de Temas

## Fecha: 19 de Octubre de 2025

### Cambios Implementados

Se ha implementado un **sistema de temas** en FSFramework que resuelve el problema de tener que activar manualmente AdminLTE en cada instalación nueva.

---

## 🎯 Problema Resuelto

**Antes**: El plugin AdminLTE tenía que ser activado manualmente desde el panel de administración en cada instalación nueva, lo cual era tedioso y poco intuitivo.

**Ahora**: AdminLTE se activa **automáticamente** en todas las instalaciones nuevas, proporcionando una interfaz moderna desde el primer momento.

---

## 📝 Archivos Modificados

### 1. `base/config2.php`
**Cambios**: Añadido sistema de auto-activación de tema por defecto

```php
/**
 * SISTEMA DE TEMAS: Auto-activación del tema por defecto
 * 
 * Si no hay plugins activados y existe el tema por defecto (AdminLTE),
 * lo activamos automáticamente. Esto garantiza que las nuevas instalaciones
 * tengan una interfaz moderna sin necesidad de configuración manual.
 */
if (empty($GLOBALS['plugins'])) {
    $default_theme = defined('FS_DEFAULT_THEME') ? FS_DEFAULT_THEME : 'AdminLTE';
    
    if (file_exists(FS_FOLDER . '/plugins/' . $default_theme)) {
        $GLOBALS['plugins'][] = $default_theme;
        
        /// Guardamos el tema por defecto en la lista de plugins activos
        if (FS_TMP_NAME != '' && !file_exists(FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'enabled_plugins.list')) {
            @file_put_contents(
                FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'enabled_plugins.list',
                $default_theme
            );
        }
    }
}
```

**Beneficios**:
- Auto-activación en instalaciones nuevas
- Respeta instalaciones existentes
- Configurable mediante constante

---

### 2. `install.php`
**Cambios**: Añadida definición de `FS_DEFAULT_THEME` durante la instalación

```php
/// Sistema de temas: Definimos AdminLTE como tema por defecto
fwrite($archivo, "define('FS_DEFAULT_THEME', 'AdminLTE');\n");
```

**Beneficios**:
- Todas las nuevas instalaciones tienen AdminLTE configurado
- El tema queda persistido en config.php
- Fácil de cambiar si se desea otro tema

---

### 3. `config.php`
**Cambios**: Añadida constante `FS_DEFAULT_THEME`

```php
define('FS_DEFAULT_THEME', 'AdminLTE');
```

**Beneficios**:
- Configuración centralizada del tema
- Fácil cambio de tema editando una línea
- Compatible con instalaciones existentes

---

## 📚 Archivos de Documentación Creados

### 1. `THEME_SYSTEM.md`
Documentación completa del sistema de temas que incluye:
- Descripción del sistema
- Cómo funciona internamente
- Cómo crear temas personalizados
- Cómo cambiar de tema
- Preguntas frecuentes
- Notas técnicas

### 2. `plugins/AdminLTE/README.md`
Documentación actualizada de AdminLTE como tema oficial:
- Características del tema
- Información sobre auto-activación
- Configuración
- Licencias y créditos

### 3. `README.md` (principal)
Actualizado con información sobre el sistema de temas:
- Mención en la sección de mejoras
- Nueva sección dedicada al sistema de temas
- Características de AdminLTE
- Instrucciones de configuración

### 4. `test_theme_system.php`
Script de prueba para verificar el funcionamiento del sistema:
- Verifica existencia de AdminLTE
- Prueba la lógica de auto-activación
- Comprueba configuración
- Ejecutable con: `php test_theme_system.php`

---

## 🔧 Arquitectura Técnica

### Flujo de Activación

```
1. Usuario instala FSFramework
   ↓
2. install.php crea config.php con FS_DEFAULT_THEME='AdminLTE'
   ↓
3. Primera carga: index.php → config.php → config2.php
   ↓
4. config2.php detecta que $GLOBALS['plugins'] está vacío
   ↓
5. Verifica si existe plugins/AdminLTE/
   ↓
6. Añade AdminLTE a $GLOBALS['plugins']
   ↓
7. Guarda en tmp/{FS_TMP_NAME}/enabled_plugins.list
   ↓
8. Carga functions.php del tema
   ↓
9. RainTPL busca vistas en plugins/AdminLTE/view/
   ↓
10. ¡Interfaz moderna lista!
```

### Override de Vistas

```
Orden de búsqueda de vistas:
1. plugins/AdminLTE/view/header.html
2. view/header.html (fallback)

Orden de búsqueda determinado por:
- Orden en $GLOBALS['plugins']
- Primer archivo encontrado gana
```

---

## ✅ Ventajas de Esta Implementación

1. **No Rompe Compatibilidad**: Instalaciones existentes siguen funcionando sin cambios
2. **Experiencia Mejorada**: Nuevas instalaciones tienen interfaz moderna desde el inicio
3. **Flexible**: Se puede cambiar el tema fácilmente
4. **Mantiene Filosofía de Plugins**: AdminLTE sigue siendo un plugin, no está en el core
5. **Configurable**: Se puede usar otro tema cambiando `FS_DEFAULT_THEME`
6. **Bien Documentado**: Documentación completa para usuarios y desarrolladores
7. **Testeable**: Incluye script de prueba

---

## 🎨 Cómo Usar

### Para Usuarios Finales
1. Instala FSFramework normalmente
2. AdminLTE se activa automáticamente
3. ¡Disfruta de la interfaz moderna!

### Para Desarrolladores
1. Para cambiar el tema por defecto, edita `config.php`:
   ```php
   define('FS_DEFAULT_THEME', 'MiTema');
   ```

2. Para crear un nuevo tema:
   - Crea `plugins/MiTema/`
   - Añade `view/header.html`, `footer.html`, etc.
   - Consulta `THEME_SYSTEM.md` para más detalles

3. Para desactivar el sistema:
   - Desactiva AdminLTE desde el panel de administración
   - O borra `tmp/{FS_TMP_NAME}/enabled_plugins.list`

---

## 🚀 Próximos Pasos (Opcionales)

Posibles mejoras futuras:
- [ ] Panel de selección de temas en la interfaz de administración
- [ ] Previsualización de temas antes de activarlos
- [ ] Marketplace de temas
- [ ] Sistema de temas hijo (child themes)
- [ ] Importación/exportación de configuración de temas

---

## 📞 Soporte

Para más información:
- Consulta `THEME_SYSTEM.md` para documentación técnica
- Visita `plugins/AdminLTE/README.md` para info del tema
- Contacto: https://misterdigital.es/contacto/

---

## 🙏 Agradecimientos

- Equipo original de FacturaScripts por AdminLTE
- AdminLTE.io por el template base
- Comunidad FSFramework

---

**Implementado por**: Sistema automatizado  
**Fecha**: 19 de Octubre de 2025  
**Versión**: 1.0.0


