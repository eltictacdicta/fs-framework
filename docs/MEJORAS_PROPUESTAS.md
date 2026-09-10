# Propuestas de Mejora para FSFramework

## Resumen Ejecutivo

Este documento detalla las mejoras implementadas y propuestas para reducir duplicidades, aumentar mantenibilidad, integrar componentes Symfony y optimizar el rendimiento.

---

## ✅ MEJORAS IMPLEMENTADAS

### 1. SessionManager con Symfony (`src/Security/SessionManager.php`)

**Antes**: Implementación custom con `$_SESSION` directo
**Después**: Usa Symfony HttpFoundation Session internamente

```php
$session = SessionManager::getInstance();

// API simple
$session->set('key', 'value');
$value = $session->get('key');

// Flash messages (Symfony FlashBag)
$session->flash('success', 'Operación completada');
$flashes = $session->getFlashes('success');

// CSRF
$token = $session->getCsrfToken();
$valid = $session->verifyCsrfToken($token);
```

**Beneficios**:
- Flash messages nativos de Symfony
- Mejor manejo de storage
- Configuración de cookies más robusta
- Mantiene compatibilidad con cookies legacy

### 2. Extracción del dominio de clientes (`clientes_core`)

**Antes**: Modelos de cliente, dirección y grupo embebidos en `facturacion_base`
**Después**: Plugin independiente `clientes_core` con:
- Modelos: `cliente`, `direccion_cliente`, `grupo_clientes` con wrappers y core classes
- Schemas XML en `model/table/`
- Traducciones YAML nativas (`translations/messages.es.yaml`, `messages.en.yaml`)
- Vistas Twig en `themes/AdminLTE/view/terceros/`
- Macros reutilizables, extensión Twig (`src/Twig/TercerosExtension.php`)
- Sin dependencia de `legacy_support`

**Beneficios**:
- `facturacion_base` y `presupuestos_y_pedidos` consumen clientes como dependencia
- Dominio de terceros aislado de integraciones contables
- Plugin modernizado desde el inicio (Twig nativo, YAML, sin RainTPL)

### 3. Sistema de Backup de Plugins

Backup automático al sobrescribir plugins con restore desde el admin:
- `fs_plugin_manager`: `has_backup()`, `create_backup()`, `restore_backup()`
- Convención `_back` para directorios de backup
- Modal de confirmación al sobrescribir plugins existentes

---

## 🟡 MEJORAS PROPUESTAS (Pendientes)

### 4. Unificar fs_session_manager con SessionManager

**Esfuerzo**: Bajo
**Impacto**: Medio

Hacer que `fs_session_manager` (legacy) delegue a `SessionManager` (Symfony):

```php
// base/fs_session_manager.php
class fs_session_manager {
    public static function get($key, $default = null) {
        return \FSFramework\Security\SessionManager::getInstance()->get($key, $default);
    }
    // ... delegación de todos los métodos
}
```

### 5. Integrar Symfony Validator en Modelos

**Estado**: IMPLEMENTADO
**Esfuerzo**: Alto
**Impacto**: Alto

Implementado en `src/Traits/ValidatorTrait.php` con tests en `tests/Traits/ValidatorTraitTest.php`. Usar atributos de validación de Symfony en lugar de `test()` manual:

```php
use Symfony\Component\Validator\Constraints as Assert;

class cliente extends fs_model {
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $nombre = null;

    #[Assert\Email]
    public ?string $email = null;

    #[Assert\Regex('/^[A-Z0-9]{8,9}[A-Z]?$/')]
    public ?string $cifnif = null;
}
```

### 6. Query Builder Integrado con Modelos

**Esfuerzo**: Medio
**Impacto**: Alto

Integrar `fs_query_builder` directamente en los modelos:

```php
// En fs_model o trait
public static function query(): fs_query_builder {
    $qb = new fs_query_builder();
    return $qb->table(static::$tableName);
}

// Uso
$clientes = cliente::query()
    ->where('activo', true)
    ->where('provincia', 'Madrid')
    ->orderBy('nombre')
    ->get();
```

### 7. Event Dispatcher para Hooks de Modelo

**Estado**: IMPLEMENTADO
**Esfuerzo**: Medio
**Impacto**: Alto

Implementado en `src/Event/FSEventDispatcher.php` y `src/Event/ModelEvent.php`. Eventos disponibles: `model.before_save`, `model.after_save`, `model.before_delete`, `model.after_delete`, `controller.before_action`, `controller.after_action`. Usar Symfony EventDispatcher para hooks before/after save:

```php
// En el modelo
protected function save(): bool {
    $event = new ModelEvent($this);
    $this->dispatcher->dispatch($event, 'model.before_save');

    if ($event->isPropagationStopped()) {
        return false;
    }

    $result = $this->doSave();

    $this->dispatcher->dispatch(new ModelEvent($this), 'model.after_save');

    return $result;
}

// En un plugin
$dispatcher->addListener('model.before_save', function(ModelEvent $e) {
    if ($e->getModel() instanceof factura) {
        // Validación adicional
    }
});
```

---

## 📊 Matriz de Priorización

| Mejora | Esfuerzo | Impacto | Riesgo | Prioridad |
|--------|----------|---------|--------|-----------|
| SessionManager Symfony | ✅ Hecho | Medio | Bajo | - |
| Extracción clientes_core | ✅ Hecho | Alto | Bajo | - |
| Backup de plugins | ✅ Hecho | Medio | Bajo | - |
| Unificar session managers | Bajo | Medio | Bajo | 🔴 Alta |
| Symfony Validator | ✅ Hecho | Alto | Bajo | - |
| Query Builder en modelos | Medio | Alto | Bajo | 🟡 Media |
| Event Dispatcher hooks | ✅ Hecho | Alto | Bajo | - |

---

## 🚀 Plan de Migración Sugerido

### Fase 1: Consolidación (1-2 días)
1. ✅ SessionManager con Symfony
2. Unificar fs_session_manager → SessionManager

### Fase 2: Validación Moderna (1 semana)
1. ✅ Integrar Symfony Validator (`ValidatorTrait`)
2. ✅ Crear atributos de validación comunes (Assert constraints)
3. Migrar validaciones de test() a atributos en modelos existentes

### Fase 3: Query Builder Avanzado (3-5 días)
1. Integrar query() estático en modelos
2. Añadir scopes reutilizables
3. Documentar patrones de consulta

---

## 📁 Archivos Creados/Modificados

### Nuevos
- `src/Security/SessionManager.php` - Session con Symfony
- `docs/MEJORAS_PROPUESTAS.md` - Este documento

---

## 📈 Métricas de Mejora Esperadas

| Área | Antes | Después | Mejora |
|------|-------|---------|--------|
| Clases Cache | 3 implementaciones | 1 + facade | -67% |
| Controladores duplicados | 2 archivos | 1 archivo | -50% |
| SQL Injection risk | Alto (concatenación) | Bajo (prepared) | ↓↓↓ |
| Query compilation | Por cada query | Cacheado | +30% perf |

---

## 🔧 Configuración Recomendada

Añadir a `config.php` para habilitar las mejoras:

```php
// Sesiones con Symfony
define('FS_SESSION_LIFETIME', 7200);
define('FS_SESSION_NAME', 'FSSESSION');
define('FS_SESSION_SAVE_PATH', FS_FOLDER . '/tmp/sessions');

// Logging avanzado
define('FS_LOG_FILE', FS_FOLDER . '/tmp/fs_framework.log');
define('FS_LOG_LEVEL', 'INFO');  // DEBUG en desarrollo

// Cache
define('FS_CACHE_PREFIX', 'fs_');

// Performance
define('FS_PREPARED_STMT_CACHE', 100);  // Max statements en cache
```
