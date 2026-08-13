# Tasks: Sonar Complexity Refactors

## Fase 1 — Marginales (16–17) ✅

- [x] Crear SDD (`proposal.md`, `design.md`, `tasks.md`)
- [x] Refactor `admin_home::check_for_updates2()` + test
- [x] Refactor `PluginEnableOrchestrator::enable()`
- [x] Refactor `require_all_models()` + test helper
- [x] Refactor `fs_maintenance_mode::readStealthSettings()` + test
- [x] Refactor `fs_plugin_manager::enableModernControllers()`
- [x] Refactor `Tools::folderCopy()`
- [x] Ejecutar PHPUnit suites core
- [x] Actualizar `verify-report.md`

## Fase 2 — Media (18–24) ✅

- [x] `fs_file_manager::recurse_copy()`
- [x] `fs_maintenance_mode::isStealthRootRequest()` + `readSessionSnapshot()`
- [x] `fs_plugin_downloader::download()`
- [x] `fs_plugin_manager::install()`
- [x] `Html::registerUtilityFunctions()` (split en 4 registradores)
- [x] `MailService::saveConfig()` + `testConnection()`
- [x] `PluginInstaller::installSystemUpdaterIn()` + `recursiveCopy()`
- [x] `PluginSchemaSynchronizer::refreshPluginModels()`
- [x] `SchemaComparator::compareColumns()`
- [x] `TypeNormalizer::convertPostgresType()` + test
- [x] PHPUnit suites core

## Fase 3 — Alta (CC >25) ✅

- [x] `Router::getRoutesSourceFingerprint()` — helpers de fingerprint por directorio
- [x] `FSTranslator::loadAllPluginTranslations()` — `resolvePluginNamesForTranslation()`
- [x] `SchemaComparator::validateFkConstraints()` + `fs_mysql::validate_fk_constraints()` — helpers FK compartidos
- [x] `fs_core_log::sanitizeNode()` — atributos y render separados
- [x] `CssSanitizer::sanitizeCssList()` — dispatch por tipo de item
- [x] `Tools::log()` — clase `ToolsLogger` extraída
- [x] `PluginActionHandler::handle()` — dispatch por acción + test
- [x] `fs_curl_update_ca_bundle()` — helpers de descarga/instalación + tests
- [x] `updater_finalize_self_update()` — manifest, staged path y deploy extraídos
- [x] `Controller::__construct()` — `initializeConnectedSession()` y helpers
- [x] `fs_login::log_in_user()` — flujo de login en métodos privados
- [x] PHPUnit suites core
- [x] Actualizar `verify-report.md`
