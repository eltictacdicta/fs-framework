# Verify Report: Sonar Complexity Refactors

**Fecha:** 2026-08-13  
**Alcance:** Fases 1, 2 y 3 (CC 16–25+)

## Resultado

| Criterio | Estado |
|----------|--------|
| Refactors fase 1 (CC 16–17) | ✅ |
| Refactors fase 2 (CC 18–24) | ✅ |
| Refactors fase 3 (CC >25) | ✅ |
| PHPUnit Base, Core, Security, Components | ✅ 432 tests, 1029 assertions |
| Regresiones detectadas | ❌ Ninguna |

## Fase 1 — archivos

- `controller/admin_home.php`
- `src/Core/Plugin/PluginEnableOrchestrator.php`
- `base/fs_functions.php`
- `base/fs_maintenance_mode.php` (stealth settings)
- `base/fs_plugin_manager.php` (modern controllers)
- `src/Core/Tools.php`

## Fase 2 — archivos

- `base/fs_file_manager.php` — `recurse_copy`
- `base/fs_maintenance_mode.php` — `isStealthRootRequest`, `readSessionSnapshot`
- `base/fs_plugin_downloader.php` — `download`
- `base/fs_plugin_manager.php` — `install`
- `src/Core/Html.php` — utilidades Twig
- `src/Core/MailService.php` — `saveConfig`, `testConnection`
- `src/Core/PluginInstaller.php` — `installSystemUpdaterIn`, `recursiveCopy`
- `src/Core/Plugin/PluginSchemaSynchronizer.php` — `refreshPluginModels`
- `src/Database/SchemaComparator.php` — `compareColumns`
- `src/Database/TypeNormalizer.php` — `convertPostgresType`

## Fase 3 — archivos

- `src/Core/Router.php` — fingerprint por directorio
- `src/Translation/FSTranslator.php` — resolución de plugins a cargar
- `src/Database/SchemaComparator.php` — validación FK
- `base/fs_mysql.php` — validación FK
- `base/fs_core_log.php` — sanitización DOM
- `src/Core/CssSanitizer.php` — dispatch por tipo CSS
- `src/Core/Tools.php` — clase `ToolsLogger`
- `src/Core/PluginActionHandler.php` — dispatch por acción
- `base/fs_functions.php` — helpers CA bundle
- `updater.php` — manifest, staged path y deploy
- `src/Core/Base/Controller.php` — sesión conectada
- `base/fs_login.php` — flujo de login

## Tests añadidos / ampliados

- `tests/Core/AdminHomeUpdatesTest.php`
- `tests/Base/FsFunctionsTest.php` (helper modelos + CA bundle)
- `tests/Core/TypeNormalizerTest.php` (`convertPostgresType`)
- `tests/Core/PluginActionHandlerTest.php`
