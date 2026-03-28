# Propuestas de Mejora para FSFramework

## Resumen Ejecutivo

Este documento detalla las mejoras implementadas y propuestas para reducir duplicidades, aumentar mantenibilidad, integrar componentes Symfony y optimizar el rendimiento.

---

## ✅ MEJORAS IMPLEMENTADAS

### 1. Consolidación de Cache (`src/Core/Cache.php`)

**Antes**: Wrapper simple sobre `fs_cache` legacy (87 líneas redundantes)
**Después**: Facade estática sobre `CacheManager` (Symfony Cache)

```php
// Uso simple
Cache::set('key', $value, 300);
$value = Cache::get('key', 'default');

// Con callback (nuevo)
$value = Cache::remember('key', fn() => expensiveOperation(), 600);
```

**Beneficios**:
- Elimina duplicación con `CacheManager`
- API estática simple para casos comunes
- `CacheManager` para operaciones avanzadas

### 2. Trait CRUD para Modelos (`base/fs_model_crud_trait.php`)

**Problema**: Código repetitivo en todos los modelos (exists, save, delete, get, all)
**Solución**: Trait reutilizable que genera SQL automáticamente

```php
class mi_modelo extends fs_model {
    use fs_model_crud_trait;
    
    protected static string $primaryKey = 'codmodelo';
    protected static array $fields = ['codmodelo', 'nombre', 'activo'];
    protected static array $defaults = ['activo' => true];
    
    // Solo implementar test() con validaciones específicas
    public function test() {
        $this->sanitizeFields();
        return strlen($this->nombre) > 0;
    }
}
```

**Beneficios**:
- Reduce ~50-100 líneas por modelo
- SQL generado automáticamente desde metadatos
- Métodos adicionales: `findBy()`, `findOneBy()`, `count()`, `toArray()`
- Compatible con modelos existentes (opt-in)

### 3. SessionManager con Symfony (`src/Security/SessionManager.php`)

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

### 4. Eliminación de Controlador Duplicado

**Antes**: `Controller.php` duplicado en:
- `src/FacturaScripts/Core/Base/Controller.php` (511 líneas)
- `plugins/facturascripts_support/Core/Base/Controller.php` (511 líneas)

**Después**: Solo existe en `src/FacturaScripts/Core/Base/Controller.php`

### 5. Extracción del dominio de clientes (`clientes_core`)

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

### 6. Sistema de Backup de Plugins

Backup automático al sobrescribir plugins con restore desde el admin:
- `fs_plugin_manager`: `has_backup()`, `create_backup()`, `restore_backup()`
- Convención `_back` para directorios de backup
- Modal de confirmación al sobrescribir plugins existentes

---

## 🟡 MEJORAS PROPUESTAS (Pendientes)

### 7. Migrar Modelos a usar el Trait CRUD

**Esfuerzo**: Medio
**Impacto**: Alto

Migrar gradualmente los modelos existentes para usar `fs_model_crud_trait`:

```php
// Antes (empresa.php - ~200 líneas de CRUD)
class empresa extends fs_model {
    public function exists() { /* 10 líneas */ }
    public function save() { /* 50 líneas */ }
    public function delete() { /* 10 líneas */ }
    public function get($cod) { /* 15 líneas */ }
    // ...
}

// Después (~50 líneas)
class empresa extends fs_model {
    use fs_model_crud_trait;
    
    protected static string $primaryKey = 'id';
    protected static array $fields = ['id', 'nombre', 'cifnif', ...];
    
    public function test() {
        $this->sanitizeFields();
        // Validaciones específicas
        return true;
    }
}
```

### 8. Unificar fs_session_manager con SessionManager

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

### 9. Integrar Symfony Validator en Modelos

**Estado**: IMPLEMENTADO
**Esfuerzo**: Alto
**Impacto**: Alto

Implementado en `src/Traits/ValidatorTrait.php` con tests en `tests/Traits/ValidatorTraitTest.php`. Usar atributos de validación de Symfony en lugar de `test()` manual:

```php
use Symfony\Component\Validator\Constraints as Assert;

class cliente extends fs_model {
    use fs_model_crud_trait;
    
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $nombre = null;
    
    #[Assert\Email]
    public ?string $email = null;
    
    #[Assert\Regex('/^[A-Z0-9]{8,9}[A-Z]?$/')]
    public ?string $cifnif = null;
}
```

### 10. Query Builder Integrado con Modelos

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

### 11. Event Dispatcher para Hooks de Modelo

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
| Cache consolidado | ✅ Hecho | Alto | Bajo | - |
| Trait CRUD | ✅ Hecho | Alto | Bajo | - |
| SessionManager Symfony | ✅ Hecho | Medio | Bajo | - |
| Eliminar duplicados | ✅ Hecho | Bajo | Bajo | - |
| Extracción clientes_core | ✅ Hecho | Alto | Bajo | - |
| Backup de plugins | ✅ Hecho | Medio | Bajo | - |
| Migrar modelos a trait | Medio | Alto | Bajo | 🔴 Alta |
| Unificar session managers | Bajo | Medio | Bajo | 🔴 Alta |
| Symfony Validator | ✅ Hecho | Alto | Bajo | - |
| Query Builder en modelos | Medio | Alto | Bajo | 🟡 Media |
| Event Dispatcher hooks | ✅ Hecho | Alto | Bajo | - |

---

## 🚀 Plan de Migración Sugerido

### Fase 1: Consolidación (1-2 días)
1. ✅ Consolidar Cache
2. ✅ Crear trait CRUD
3. ✅ SessionManager con Symfony
4. ✅ Eliminar duplicados
5. Unificar fs_session_manager → SessionManager

### Fase 2: Migración de Modelos (1 semana)
1. Migrar modelos core (fs_user, fs_page, fs_access)
2. Migrar modelos de plugins principales
3. Documentar patrón de migración

### Fase 3: Validación Moderna (1 semana)
1. ✅ Integrar Symfony Validator (`ValidatorTrait`)
2. ✅ Crear atributos de validación comunes (Assert constraints)
3. Migrar validaciones de test() a atributos en modelos existentes

### Fase 4: Query Builder Avanzado (3-5 días)
1. Integrar query() estático en modelos
2. Añadir scopes reutilizables
3. Documentar patrones de consulta

---

## 📁 Archivos Creados/Modificados

### Nuevos
- `base/fs_model_crud_trait.php` - Trait CRUD genérico
- `src/Security/SessionManager.php` - Session con Symfony
- `docs/MEJORAS_PROPUESTAS.md` - Este documento

### Modificados
- `src/Core/Cache.php` - Ahora facade sobre CacheManager

### Eliminados
- `plugins/facturascripts_support/Core/Base/Controller.php` - Duplicado

---

## 🚀 MEJORAS DE PERFORMANCE IMPLEMENTADAS

### 12. Prepared Statements con Cache (`base/fs_prepared_db.php`)

Nueva clase que proporciona prepared statements con cache para mejor seguridad y performance:

```php
$db = new fs_prepared_db();

// Query con parámetros (prepared statement)
$users = $db->query(
    "SELECT * FROM fs_users WHERE admin = ? AND enabled = ?",
    [true, true]
);

// Insert seguro
$db->execute(
    "INSERT INTO clientes (nombre, email) VALUES (?, ?)",
    [$nombre, $email]
);

// Transacciones
$db->beginTransaction();
try {
    $db->execute("UPDATE stock SET cantidad = ? WHERE ref = ?", [10, 'REF001']);
    $db->execute("INSERT INTO movimientos (ref, cantidad) VALUES (?, ?)", ['REF001', 10]);
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
}

// Estadísticas
$stats = fs_prepared_db::getStats();
// ['query_count' => 150, 'total_time' => 0.234, 'cached_statements' => 25]
```

**Beneficios**:
- Prevención de SQL injection automática
- Cache de statements compilados (hasta 100)
- Fallback automático a fs_db2 para PostgreSQL
- Estadísticas de rendimiento

### 13. Container con nuevos servicios

Añadidos al Service Container:

```php
use FSFramework\DependencyInjection\Container;

// Session Manager (Symfony)
$session = Container::session();

// Prepared DB
$db = Container::preparedDb();

// O via get()
$session = Container::get('session');
$db = Container::get('prepared_db');
```

---

## 📈 Métricas de Mejora Esperadas

| Área | Antes | Después | Mejora |
|------|-------|---------|--------|
| Líneas de código en modelos | ~150/modelo | ~50/modelo | -67% |
| Duplicación de CRUD | 100% manual | 0% (trait) | -100% |
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
