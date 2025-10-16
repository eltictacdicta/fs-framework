# Cambios en la Ubicación de Imágenes de Tarjetas de Visita

## 📋 Resumen

Se ha cambiado la ubicación de almacenamiento de las imágenes de tarjetas de visita desde `/tmp/infrico_uploads/business_cards/` a `/imgs/api_auth/business_cards/`.

## 🔧 Cambios Realizados

### 1. Modificación del código PHP

**Archivo modificado:** `plugins/api_auth/model/infrico_helpers.php`

- ✅ Cambiada la ruta de guardado de imágenes
- ✅ Actualizada la URL pública retornada

**Antes:**
```php
$uploadDir = FS_FOLDER . '/tmp/infrico_uploads/business_cards/';
return $base_url . '/tmp/infrico_uploads/business_cards/' . $fileName;
```

**Después:**
```php
$uploadDir = FS_FOLDER . '/imgs/api_auth/business_cards/';
return $base_url . '/imgs/api_auth/business_cards/' . $fileName;
```

### 2. Creación de directorios

```bash
mkdir -p imgs/api_auth/business_cards
chmod -R 755 imgs/api_auth
```

### 3. Configuración de Apache (.htaccess)

Se ha creado el archivo `imgs/.htaccess` con:
- ✅ Acceso público a imágenes (jpg, jpeg, png, gif, webp)
- ✅ Prevención de listado de directorios
- ✅ Configuración de tipos MIME
- ✅ Cache de 30 días para imágenes

## 📂 Nueva Estructura de Directorios

```
fs-framework/
├── imgs/
│   ├── .htaccess
│   └── api_auth/
│       └── business_cards/
│           ├── {uuid}_{timestamp}.jpg          # Imagen original
│           └── {uuid}_{timestamp}_thumb.jpg    # Thumbnail
```

## 🌐 URLs en Base de Datos

**Antes (URL absoluta):**
```
https://otp.grupoinfrico.com/fsf/tmp/infrico_uploads/business_cards/{uuid}_{timestamp}.jpg
```

**Después (ruta relativa):**
```
imgs/api_auth/business_cards/{uuid}_{timestamp}.jpg
```

**Ventaja de rutas relativas:**
- ✅ Funciona en cualquier dominio (desarrollo, staging, producción)
- ✅ No hay problemas de CORS
- ✅ No se afecta por redirecciones del .htaccess
- ✅ Más corto y eficiente

## ✅ Ventajas del Cambio

1. **Mejor organización:** Las imágenes están en una carpeta dedicada fuera de `/tmp/`
2. **Persistencia:** `/tmp/` puede ser limpiado automáticamente por el sistema
3. **Seguridad:** Carpeta específica para el plugin api_auth
4. **Mantenimiento:** Más fácil de gestionar y hacer backups

## 🔍 Verificación

Para verificar que todo funciona correctamente:

1. **Subir una nueva tarjeta desde la app móvil**
2. **Verificar que la imagen se guarda en la nueva ubicación:**
   ```bash
   ls -la imgs/api_auth/business_cards/
   ```
3. **Verificar que la URL es accesible:**
   ```bash
   curl -I "https://otp.grupoinfrico.com/fsf/imgs/api_auth/business_cards/{filename}.jpg"
   ```

## 📝 Notas Importantes

- Las imágenes antiguas en `/tmp/infrico_uploads/business_cards/` seguirán funcionando
- Las nuevas imágenes se guardarán en la nueva ubicación
- Se recomienda migrar las imágenes antiguas cuando sea conveniente

## 🔄 Migración de Imágenes Antiguas (Opcional)

Si deseas migrar las imágenes antiguas a la nueva ubicación:

```bash
# Copiar imágenes antiguas a la nueva ubicación
ddev exec cp -r tmp/infrico_uploads/business_cards/* imgs/api_auth/business_cards/

# Actualizar URLs en la base de datos
ddev exec mysql -e "
USE fs_framework;
UPDATE contacts 
SET imageUrl = REPLACE(imageUrl, '/tmp/infrico_uploads/business_cards/', '/imgs/api_auth/business_cards/')
WHERE imageUrl LIKE '%/tmp/infrico_uploads/business_cards/%';
"
```

## 🐛 Solución de Problemas

### Problema: Las imágenes no se muestran

**Solución:**
1. Verificar permisos del directorio:
   ```bash
   ddev exec chmod -R 755 imgs/api_auth
   ```

2. Verificar que el archivo `.htaccess` existe en `imgs/`

3. Limpiar caché del navegador

### Problema: Error 403 Forbidden

**Solución:**
Verificar configuración de Apache y que el `.htaccess` permite acceso a imágenes.

---

**Fecha de cambio:** 15 de Octubre de 2025  
**Autor:** Sistema de sincronización Infrico Catcher

