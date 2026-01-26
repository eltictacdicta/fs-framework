# Sistema de Internacionalización (i18n) - FSFramework

## Descripción General

FSFramework incluye un sistema de traducción basado en **Symfony Translation Component** que permite:

- Traducciones multiidioma para el core y plugins
- Soporte para archivos YAML (formato recomendado) y JSON (compatibilidad FS2025)
- Fallback automático entre locales (ej: `es_AR` → `es` → `es_ES`)
- Función `trans()` disponible en plantillas Twig y código PHP
- Pluralización con ICU MessageFormat

## Uso Básico

### En Plantillas Twig

```twig
{# Como función #}
{{ trans('login-text') }}
{{ trans('hello', {'%name%': 'Juan'}) }}

{# Como filtro #}
{{ 'save'|trans }}
{{ 'greeting'|trans({'%name%': usuario.nombre}) }}

{# Obtener idioma actual #}
{{ getLocale() }}

{# Selector de idiomas #}
<select name="language">
{% for code, name in getAvailableLanguages() %}
    <option value="{{ code }}">{{ name }}</option>
{% endfor %}
</select>
```

### En Código PHP

```php
use FSFramework\Translation\FSTranslator;

// Traducción simple
echo FSTranslator::trans('login-text');

// Con parámetros
echo FSTranslator::trans('hello', ['%name%' => 'Juan']);

// Obtener/cambiar idioma
$locale = FSTranslator::getLocale();
FSTranslator::setLocale('en_EN');

// Usando funciones globales (después de incluir TranslationHelper)
echo __('save');
echo trans('delete-confirm');
```

## Estructura de Archivos

### Para el Core (FSFramework)

```
fs-framework/
└── translations/
    ├── messages.es.yaml      # Español
    ├── messages.en.yaml      # Inglés
    ├── messages.fr.yaml      # Francés
    ├── admin.es.yaml         # Dominio 'admin' (opcional)
    └── validators.es.yaml    # Dominio 'validators' (opcional)
```

### Para Plugins (Formato Nuevo - Recomendado)

```
plugins/
└── MiPlugin/
    └── translations/
        ├── messages.es.yaml
        ├── messages.en.yaml
        └── messages.fr.yaml
```

### Para Plugins FS2025 (Compatibilidad)

```
plugins/
└── Backup/
    └── Translation/
        ├── es_ES.json
        ├── en_EN.json
        └── fr_FR.json
```

## Formato de Archivos

### YAML (Recomendado)

```yaml
# translations/messages.es.yaml

# Claves simples
login: "Iniciar sesión"
logout: "Cerrar sesión"
save: "Guardar"

# Con parámetros (%variable%)
hello: "Hola, %name%!"
items-count: "Hay %count% elementos"

# Claves anidadas (se acceden con punto: user.profile)
user:
  profile: "Perfil"
  settings: "Configuración"
  logout: "Salir"

# Plurales con ICU MessageFormat
apples: "{count, plural, one {# manzana} other {# manzanas}}"
```

### JSON (Compatibilidad FS2025)

```json
{
    "backup": "Copia de seguridad",
    "restore": "Restaurar",
    "download-backup": "Descargar copia",
    "hello": "Hola, %name%!"
}
```

## Crear Traducciones para tu Plugin

### Paso 1: Crear estructura de directorios

```bash
mkdir -p plugins/MiPlugin/translations
```

### Paso 2: Crear archivos de traducción

**plugins/MiPlugin/translations/messages.es.yaml:**
```yaml
# Mi Plugin - Traducciones Español
mi-plugin-titulo: "Mi Plugin Genial"
mi-plugin-descripcion: "Este plugin hace cosas increíbles"
boton-accion: "Ejecutar Acción"
mensaje-exito: "¡Operación completada con éxito!"
mensaje-error: "Ha ocurrido un error: %error%"
```

**plugins/MiPlugin/translations/messages.en.yaml:**
```yaml
# Mi Plugin - English Translations
mi-plugin-titulo: "My Awesome Plugin"
mi-plugin-descripcion: "This plugin does amazing things"
boton-accion: "Execute Action"
mensaje-exito: "Operation completed successfully!"
mensaje-error: "An error occurred: %error%"
```

### Paso 3: Usar en las plantillas

**plugins/MiPlugin/view/mi_plugin.html.twig:**
```twig
<h1>{{ trans('mi-plugin-titulo') }}</h1>
<p>{{ trans('mi-plugin-descripcion') }}</p>

<button type="submit">{{ trans('boton-accion') }}</button>

{% if error %}
    <div class="alert alert-danger">
        {{ trans('mensaje-error', {'%error%': error}) }}
    </div>
{% endif %}
```

### Paso 4: Usar en código PHP del controlador

```php
class mi_plugin extends fs_controller
{
    protected function private_core()
    {
        // Las traducciones del plugin se cargan automáticamente
        $mensaje = FSTranslator::trans('mensaje-exito');
        $this->new_message($mensaje);
    }
}
```

## Dominios de Traducción

Los dominios permiten organizar traducciones por contexto:

```php
// Dominio por defecto: 'messages'
FSTranslator::trans('save');

// Dominio específico: 'admin'
FSTranslator::trans('admin-panel', [], 'admin');

// Dominio específico: 'validators'
FSTranslator::trans('email-invalid', [], 'validators');
```

Para crear un dominio adicional, crea archivos con el nombre del dominio:

```
translations/
├── messages.es.yaml      # Dominio 'messages'
├── admin.es.yaml         # Dominio 'admin'
└── validators.es.yaml    # Dominio 'validators'
```

## Fallback de Locales

El sistema busca traducciones en este orden:

1. Locale exacto: `es_AR`
2. Idioma base: `es`
3. Locale por defecto: `es_ES`
4. Inglés: `en`

Ejemplo: Si el usuario tiene `es_AR` y la clave no existe en `es_AR`:
- Busca en `es`
- Si no existe, busca en `es_ES`
- Si no existe, busca en `en`
- Si no existe, devuelve la clave original

## Pluralización (ICU MessageFormat)

```yaml
# translations/messages.es.yaml
items: "{count, plural, =0 {No hay elementos} one {# elemento} other {# elementos}}"
```

```twig
{{ trans('items', {'count': 0}) }}   {# "No hay elementos" #}
{{ trans('items', {'count': 1}) }}   {# "1 elemento" #}
{{ trans('items', {'count': 5}) }}   {# "5 elementos" #}
```

## Cambiar Idioma Programáticamente

```php
// Cambiar idioma para toda la sesión
FSTranslator::setLocale('en_EN');

// Traducir temporalmente en otro idioma sin cambiar el actual
$texto_ingles = FSTranslator::trans('hello', ['%name%' => 'John'], null, 'en_EN');
```

## Configurar Idioma por Defecto

En `config.php`:

```php
define('FS_LANG', 'es_ES');
```

O dinámicamente:

```php
FSTranslator::setDefaultLocale('es_ES');
```

## Compatibilidad con Plugins FS2025

Los plugins que usan el formato FS2025 (carpeta `Translation/` con archivos JSON) funcionan automáticamente. El sistema detecta y carga ambos formatos:

- `Translation/*.json` → Formato FS2025
- `translations/*.yaml` → Formato nuevo (recomendado)

## Buenas Prácticas

1. **Usa claves descriptivas en kebab-case:**
   - ✅ `user-profile-settings`
   - ❌ `userProfileSettings`

2. **Evita concatenar traducciones:**
   - ✅ `trans('hello-user', {'%name%': $name})`
   - ❌ `trans('hello') . ' ' . $name`

3. **Incluye contexto en las claves:**
   - ✅ `admin-user-delete-confirm`
   - ❌ `delete`

4. **Documenta los parámetros:**
   ```yaml
   # %name%: Nombre del usuario
   greeting: "Bienvenido, %name%!"
   ```

5. **Usa el idioma por defecto del proyecto (español) como base:**
   - Primero crea `messages.es.yaml`
   - Luego traduce a otros idiomas

## Referencia de API

### FSTranslator

```php
// Traducir texto
FSTranslator::trans(string $id, array $params = [], ?string $domain = null, ?string $locale = null): string

// Obtener/establecer locale
FSTranslator::getLocale(): string
FSTranslator::setLocale(string $locale): void

// Configuración
FSTranslator::initialize(?string $basePath = null): void
FSTranslator::setDefaultLocale(string $locale): void

// Plugins
FSTranslator::loadPluginTranslations(string $pluginName): void
FSTranslator::loadAllPluginTranslations(): void

// Utilidades
FSTranslator::getAvailableLanguages(): array
FSTranslator::reset(): void
```

### Funciones Twig

| Función | Descripción |
|---------|-------------|
| `trans(id, params, domain, locale)` | Traduce un mensaje |
| `__(id, params, domain, locale)` | Alias de trans |
| `'texto'\|trans` | Filtro de traducción |
| `getLocale()` | Obtiene el locale actual |
| `getAvailableLanguages()` | Lista de idiomas disponibles |

### Funciones PHP Globales

```php
// Después de incluir TranslationHelper.php
__('mensaje');                          // Traducción simple
trans('mensaje', ['%var%' => 'valor']); // Con parámetros
```
