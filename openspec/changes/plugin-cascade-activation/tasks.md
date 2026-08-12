# Tasks: plugin-cascade-activation (core)

## Wave 1 — Planner (TDD)

- [x] **T1** `tests/Core/PluginDependencyResolverTest.php` — direct require parsing, transitive order, cycle detection (RED)
- [x] **T2** `src/Core/Plugin/PluginDependencyResolver.php` (GREEN)
- [x] **T3** `tests/Core/LocalPluginRequirementsReaderTest.php` — lectura de `fsframework.ini` (sustituye PluginActivationPlannerTest)

## Wave 2 — Orchestrator (TDD)

- [x] **T4** `src/Core/Plugin/PluginInstallProvider.php` + `NullPluginInstallProvider.php`
- [x] **T5** `tests/Core/PluginEnableOrchestratorTest.php` with mock provider (RED)
- [x] **T6** `src/Core/Plugin/PluginEnableOrchestrator.php` (GREEN)
- [x] **T7** Test: partial failure does not leave inconsistent state

## Wave 3 — Integration

- [x] **T8** Refactor `fs_plugin_manager::enable()` → orchestrator + `enableWithoutDependencyResolution()`
- [x] **T9** Registry hook: `PluginInstallProviderRegistry::set()` callable from system_updater Init
- [x] **T10** Regression: plugin sin deps, plugin ya activo, save failure

## Wave 4 — Verify

- [x] **T11** `ddev exec php vendor/bin/phpunit tests/Core/Plugin*Test.php` — 8 tests OK; Core suite 63 tests OK
- [ ] **T12** Manual: activar `tpvmod` o `factura_pdf1` desde tienda/admin sin deps en disco
- [ ] **T13** verify-report.md

## Dependencies

- Blocks on: nothing
- Blocks: system_updater `remote-catalog-install` (provider implementation) — **implementado y testeado**
