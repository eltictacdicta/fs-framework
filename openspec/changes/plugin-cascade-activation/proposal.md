# Proposal: Activación en cascada de plugins con resolución de dependencias

## Intent

Hoy `fs_plugin_manager::enable()` falla si falta una dependencia activa, sin intentar activar transitivamente ni descargar plugins ausentes. Con un ecosistema de 12+ plugins en repos separados, activar `tpvmod` o `factura_pdf1` requiere descargar y activar manualmente 4–5 dependencias en el orden correcto.

Este change introduce un **orquestador de activación en el core** que:

1. Resuelve el grafo de dependencias desde `fsframework.ini` (transitivo).
2. Activa dependencias en orden topológico antes del plugin solicitado.
3. Funciona **sin** `system_updater` activo cuando los plugins ya están en disco.
4. Delega la **descarga** de plugins ausentes a un proveedor opcional registrado por `system_updater`.

## Decisiones acordadas (2026-08-11)

| Tema | Decisión |
|------|----------|
| Entry points | Todos los flujos que llaman a `enable()` |
| Deps privadas | Permitidas si ya están instaladas localmente |
| Fuente de deps | `fsframework.ini` (transitivo), no solo JSON del catálogo |
| Arquitectura | Lógica de cascada en **core**; descarga remota extendida por **system_updater** |

## Scope

### In Scope (core)

- Contrato `PluginDependencyResolver` + `PluginInstallProvider` (interfaces en `src/Core/Plugin/`)
- `PluginActivationPlanner`: grafo, orden topológico, detección de ciclos
- `PluginEnableOrchestrator`: plan → instalar faltantes (si provider) → enable en cascada
- Integración en `fs_plugin_manager::enable()` vía orquestador (sin romper API pública)
- Resolución de `require` desde ini local (`plugins/{name}/fsframework.ini`)
- Tests unitarios del planner/orchestrator (sin red, sin BD)
- Tests de integración con provider mock

### Out of Scope (core)

- Descarga desde GitHub / catálogo JSON (→ change `remote-catalog-install` en system_updater)
- UI nueva en admin (mensajes de log existentes + errores descriptivos)
- Desactivación en cascada inversa (disable)
- Resolución de versiones semver / conflictos de versión

## Capabilities

### New

- `plugin-cascade-activation`: activación ordenada con resolución transitiva de dependencias

### Modified

- Comportamiento de `fs_plugin_manager::enable()`: deja de fallar en silencio cuando puede resolver deps locales

## Affected Areas

| Area | Impact |
|------|--------|
| `src/Core/Plugin/PluginDependencyResolver.php` | New |
| `src/Core/Plugin/PluginInstallProvider.php` | New (interface) |
| `src/Core/Plugin/PluginActivationPlanner.php` | New |
| `src/Core/Plugin/PluginEnableOrchestrator.php` | New |
| `src/Core/Plugin/NullPluginInstallProvider.php` | New (no-op default) |
| `base/fs_plugin_manager.php` | Modified — delega en orchestrator |
| `tests/Core/PluginActivationPlannerTest.php` | New |
| `tests/Core/PluginEnableOrchestratorTest.php` | New |

## Risks

| Risk | Mitigation |
|------|------------|
| Recursión infinita en enable | Orchestrator usa plan precomputado; enable interno con flag `$activating` |
| Ciclos A→B→A | Planner detecta y aborta con mensaje claro |
| Regresión en enable simple | Tests de compatibilidad; plugin sin deps sin cambios |
| Core acoplado a system_updater | Solo interface; NullProvider por defecto |

## Success Criteria

- [ ] Activar `tpvmod` con todas las deps en disco activa 5 plugins en orden correcto
- [ ] Activar plugin con dep local no activada activa la dep primero
- [ ] Ciclo de dependencias → error explícito, ningún plugin activado parcialmente inconsistente
- [ ] Sin system_updater: mismo comportamiento si plugins ya instalados
- [ ] Con provider mock: faltante descargable se instala antes de activar
- [ ] Dep faltante no local y no descargable → error sin activar el target

## Dependencies

- Change complementario: `plugins/system_updater/openspec/changes/remote-catalog-install/`
