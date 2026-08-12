# Design: Activación en cascada (core)

## Flujo

```
enable(target)
    │
    ▼
PluginEnableOrchestrator
    │
    ├─► PluginActivationPlanner.buildPlan(target)
    │       ├─ lee fsframework.ini de cada plugin (local)
    │       ├─ expande transitivamente
    │       ├─ detecta ciclos
    │       └─ orden topológico (deps primero)
    │
    ├─► Para cada plugin en plan (orden):
    │       ├─ ¿Existe en plugins/? ─No─► InstallProvider.install(name)
    │       │                              ├─ ok → continuar
    │       │                              └─ fail → abort, rollback log
    │       └─ ¿Activo? ─No─► enableInternal(name)  [sin re-orquestar]
    │
    └─► enableInternal(target) — lógica actual de enable
```

## Interfaces

```php
// src/Core/Plugin/PluginInstallProvider.php
interface PluginInstallProvider
{
    /** @return bool true si el plugin quedó en disco */
    public function isInstalled(string $pluginName): bool;

    /** Descarga e instala si es posible (catálogo público). */
    public function installIfAvailable(string $pluginName): bool;

    /** Human-readable reason on failure */
    public function getLastError(): string;
}
```

```php
// src/Core/Plugin/PluginDependencyResolver.php
final class PluginDependencyResolver
{
    /** @return list<string> nombres de dependencias directas */
    public function getDirectRequirements(string $pluginName): array;

    /** @return list<string> plan topológico incluyendo $pluginName al final */
    public function buildActivationOrder(string $pluginName): array;

    public function detectCycle(string $pluginName): ?string;
}
```

## Integración con fs_plugin_manager

- `enable($name)` delega en `PluginEnableOrchestrator::enable($name)`.
- `enableInternal($name)` contiene la lógica actual (check deps enabled, append, save, upgrade, controllers).
- El orchestrator **no** llama a `enable()` recursivamente sino a `enableInternal()` para evitar re-planificación.
- `PluginInstallProvider` se registra en un registry estático o vía `Container` si existe; default `NullPluginInstallProvider`.

## Resolución de ini

1. Si `plugins/{name}/fsframework.ini` existe → `parse_ini_file`, campo `require`.
2. Si no existe pero hay entrada en catálogo → provider puede resolver remoto (system_updater).
3. Si plugin no local y provider no puede resolver → error: dependencia no satisfacible.

## Mensajes de error

| Situación | Mensaje |
|-----------|---------|
| Ciclo detectado | `Dependencia circular: A → B → A` |
| Falta local + no catálogo | `Dependencia 'X' no instalada y no disponible en el catálogo público` |
| Install falló | `No se pudo descargar 'X': {provider error}` |

## Compatibilidad

- `$GLOBALS['plugins']` mantiene orden de inserción (deps antes que dependientes).
- `disable_add_plugins` flag existente se respeta en enableInternal.
