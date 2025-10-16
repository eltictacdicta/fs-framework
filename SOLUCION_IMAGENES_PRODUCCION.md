# Solución: Imágenes no se cargan en Producción

## 🐛 Problema

Las imágenes en `https://otp.grupoinfrico.com/imgs/api_auth/business_cards/*.jpg` devuelven "Infrico" (página de error) en lugar de la imagen real.

## 🔍 Diagnóstico

El problema ocurre porque el `.htaccess` raíz del framework está redirigiendo todas las peticiones al `index.php`, incluyendo las imágenes.

## ✅ Solución

### 1. Verificar que el directorio existe en producción

```bash
# Conectarse al servidor de producción
ssh usuario@otp.grupoinfrico.com

# Verificar directorio
cd /ruta/al/fsf
ls -la imgs/api_auth/business_cards/

# Si no existe, crearlo
mkdir -p imgs/api_auth/business_cards
chmod 755 imgs/api_auth/business_cards
```

### 2. Verificar que el `.htaccess` está en la carpeta `imgs/`

El archivo `imgs/.htaccess` debe existir con el siguiente contenido:

```apache
# Desactivar rewrite para servir imágenes directamente
<IfModule mod_rewrite.c>
    RewriteEngine Off
</IfModule>

# Permitir acceso a archivos de imagen
<FilesMatch "\.(jpg|jpeg|png|gif|webp)$">
    # Apache 2.4+
    <IfModule mod_authz_core.c>
        Require all granted
    </IfModule>
    # Apache 2.2
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Allow from all
    </IfModule>
    
    # Asegurar que se sirvan como imágenes, no como texto
    ForceType image/jpeg
</FilesMatch>

# Prevenir listado de directorios
Options -Indexes

# Configurar tipos MIME correctamente
<IfModule mod_mime.c>
    AddType image/jpeg .jpg .jpeg
    AddType image/png .png
    AddType image/gif .gif
    AddType image/webp .webp
</IfModule>

# Configurar cache para imágenes (30 días)
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpeg "access plus 30 days"
    ExpiresByType image/png "access plus 30 days"
    ExpiresByType image/gif "access plus 30 days"
    ExpiresByType image/webp "access plus 30 days"
</IfModule>

# Headers de seguridad y CORS
<IfModule mod_headers.c>
    Header set Access-Control-Allow-Origin "*"
    Header set X-Content-Type-Options "nosniff"
</IfModule>
```

### 3. Copiar el archivo al servidor

```bash
# Desde tu máquina local
scp imgs/.htaccess usuario@otp.grupoinfrico.com:/ruta/al/fsf/imgs/
```

### 4. Verificar permisos en producción

```bash
# En el servidor de producción
chmod 644 imgs/.htaccess
chmod 755 imgs/api_auth/business_cards
chmod 644 imgs/api_auth/business_cards/*.jpg
```

### 5. Probar que funciona

```bash
# Desde tu máquina local
curl -I https://otp.grupoinfrico.com/fsf/imgs/api_auth/business_cards/0be8581b-98ad-42d4-81f8-97b162e61ac9_1760560849.jpg

# Debería devolver:
# HTTP/2 200
# content-type: image/jpeg
```

## 🔧 Solución Alternativa: Modificar .htaccess raíz

Si el problema persiste, puede ser necesario modificar el `.htaccess` raíz del framework para excluir la carpeta `imgs/`:

```apache
# En el .htaccess raíz (fsf/.htaccess o fsf/htaccess-sample)
# Añadir ANTES de las reglas de RewriteRule existentes:

<IfModule mod_rewrite.c>
    RewriteEngine On
    
    # Excluir carpeta de imágenes del rewrite
    RewriteRule ^imgs/ - [L]
    
    # ... resto de reglas existentes ...
</IfModule>
```

## 📝 Verificación Final

1. **Probar URL directa:**
   ```
   https://otp.grupoinfrico.com/fsf/imgs/api_auth/business_cards/{uuid}_{timestamp}.jpg
   ```

2. **Verificar en el panel web:**
   - Ir a: `https://otp.grupoinfrico.com/fsf/index.php?page=admin_infrico_contacts`
   - Hacer clic en una imagen
   - Debería abrirse el modal con la imagen correctamente

3. **Verificar headers HTTP:**
   ```bash
   curl -I https://otp.grupoinfrico.com/fsf/imgs/api_auth/business_cards/test.jpg
   ```
   
   Debe devolver:
   - `HTTP/2 200` (o `HTTP/1.1 200`)
   - `content-type: image/jpeg`
   - NO debe devolver `content-type: text/html`

## 🚨 Problemas Comunes

### Problema 1: Permisos denegados (403 Forbidden)

**Solución:**
```bash
chmod -R 755 imgs/
chmod 644 imgs/.htaccess
```

### Problema 2: Archivo no encontrado (404 Not Found)

**Solución:**
- Verificar que el archivo existe físicamente en el servidor
- Verificar que la ruta en la base de datos es correcta

### Problema 3: Sigue devolviendo "Infrico" o HTML

**Solución:**
- Verificar que el `.htaccess` en `imgs/` tiene `RewriteEngine Off`
- Añadir exclusión en el `.htaccess` raíz (ver "Solución Alternativa" arriba)
- Reiniciar Apache: `sudo service apache2 restart`

## 📊 Logs para Debugging

```bash
# Ver logs de Apache en producción
tail -f /var/log/apache2/error.log
tail -f /var/log/apache2/access.log

# Buscar errores relacionados con imgs/
grep "imgs/" /var/log/apache2/error.log
```

---

**Fecha:** 15 de Octubre de 2025  
**Última actualización:** Configuración de .htaccess mejorada con ForceType y CORS

