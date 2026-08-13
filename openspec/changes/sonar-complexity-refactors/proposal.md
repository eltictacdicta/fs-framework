# Proposal: Sonar Complexity Refactors (fase incremental)

## Intent

Reducir la complejidad cognitiva reportada por SonarQube en código propio del core, sin cambiar comportamiento observable. Enfoque incremental: primero casos marginales (16–17), luego media (18–24); los métodos >25 quedan para fases posteriores con cobertura dedicada.

## Scope

### Fase 1 (esta iteración) — marginales 16–17

| Archivo | Método | CC actual | Estrategia |
|---------|--------|-----------|------------|
| `controller/admin_home.php` | `check_for_updates2()` | 16 | Extraer detección plugin/core |
| `src/Core/Plugin/PluginEnableOrchestrator.php` | `enable()` | 16 | Extraer instalación y activación del plan |
| `base/fs_functions.php` | `require_all_models()` | 17 | Extraer carga por directorio |
| `base/fs_maintenance_mode.php` | `readStealthSettings()` | 16 | Extraer fuentes (constantes, caché, BD) |
| `base/fs_plugin_manager.php` | `enableModernControllers()` | 16 | Extraer registro de un controlador |
| `src/Core/Tools.php` | `folderCopy()` | 17 | Extraer copia de entrada |

### Fase 2 (pendiente) — media 18–24

`fs_file_manager`, `fs_maintenance_mode` (488), `fs_plugin_downloader`, `fs_plugin_manager` (534), `Html`, `MailService`, `PluginInstaller`, `PluginSchemaSynchronizer`, `SchemaComparator` (106), `TypeNormalizer`, etc.

### Fase 3 (pendiente) — alto riesgo >25

`PluginActionHandler` (60), `fs_functions` (390), `CssSanitizer` (32), `fs_login` (370), `updater.php` (30), `Tools` (24), `fs_mysql` (675), `Controller` (26), `SchemaComparator` (398), etc.

### Out of Scope

- `vendor/` (terceros)
- Cambios funcionales o nuevas features
- Refactors masivos sin tests de regresión

## Success Criteria

- [ ] Comportamiento idéntico verificado con PHPUnit existente + tests nuevos donde aplique
- [ ] CC de métodos fase 1 ≤ 15 según extracción verificable
- [ ] `ddev exec php vendor/bin/phpunit` — suites Base, Core, Security, Components OK

## Rollback

Revert atómico por commit/archivo. Sin migraciones de datos.
