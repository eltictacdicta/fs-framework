# Verify Report: plugin-enable-ajax-wizard

**Date**: 2026-09-19
**Status**: PARTIAL — core scope complete, plugin consumer (Wave 3) pendiente

## Summary

Core changes (Waves 1, 2, 4) implementados y verificados. El guard AJAX en `fs_plugin_manager::enableWithoutDependencyResolution()` impide la emisión de `header('Location: ...')` cuando la petición es AJAX, y expone el wizard vía `getLastEnableWizard()`. El orquestador propaga ese valor al caller.

## Requirements Verification

### ✅ REQ: AJAX enable requests never receive a redirect

| Scenario | Status | Evidence |
|----------|--------|----------|
| Wizard plugin via AJAX | ✅ PASS | `wizardPluginViaAjaxExposesWizardWithoutRedirect` — no redirect emitido, wizard expuesto como `admin_factura_pdf1` |
| Non-wizard plugin via AJAX | ✅ PASS | `pluginWithoutWizardViaAjaxHasNoWizardAndNoRedirect` — sin wizard, sin redirect |

### ✅ REQ: Non-AJAX enable requests keep redirect-to-wizard behavior

| Scenario | Status | Evidence |
|----------|--------|----------|
| Wizard plugin via normal request | ✅ PASS | `wizardPluginViaNormalRequestPreservesRedirect` — redirect emitido, wizard expuesto |

### ✅ REQ: Activation result is observable in the JSON response

| Scenario | Status | Evidence |
|----------|--------|----------|
| Orchestrator exposes wizard from last step | ✅ PASS | `orchestratorExposesWizardFromLastStep` — `getLastEnableWizard()` returns `admin_factura_pdf1` after target activation |
| Dependency plugin (runWizard=false) | ✅ PASS | `dependencyPluginRunWizardFalseNeverRedirects` — wizard null, no redirect |

### ✅ REQ: Role-permission gateway provides explicit allow_delete (plugin-local)

| Scenario | Status | Evidence |
|----------|--------|----------|
| roleHasPageAccess includes allow_delete | ✅ PASS | `LegacyRolePermissionsGateway.php:49` now includes `'allow_delete' => false` |
| fs_rol_access defensive read | ✅ PASS | `model/fs_rol_access.php:40` uses `$data['allow_delete'] ?? false` |

## Test Results

```
PHPUnit 11.5.56
tests/Core/PluginEnableAjaxSafetyTest.php — 5 tests, 13 assertions, OK
tests/Core/PluginEnableOrchestratorTest.php — 10 tests, 29 assertions, OK (no regression)
Full suite — 2369 tests, 7890 assertions, 0 failures, 24 skipped
```

## Files Modified

| File | Change |
|------|--------|
| `base/fs_plugin_manager.php` | Added `$lastEnableWizard` property, `getLastEnableWizard()`, `isAjaxRequest()`. Guard AJAX in wizard branch of `enableWithoutDependencyResolution()`. |
| `src/Core/Plugin/PluginEnableOrchestrator.php` | Added `getLastEnableWizard()` delegate method. |
| `model/fs_rol_access.php` | Defensive `?? false` on `$data['allow_delete']` read. |
| `plugins/factura_pdf1/Services/LegacyRolePermissionsGateway.php` | Added explicit `'allow_delete' => false` in `roleHasPageAccess()`. |
| `tests/Core/PluginEnableAjaxSafetyTest.php` | New — 5 regression tests covering AJAX wizard safety contract. |

## Pending Items

- **T8–T9** (Wave 3): Consumo del campo `wizard` en `system_updater` — es un cambio del plugin consumidor, fuera del scope core. El contrato `getLastEnableWizard()` está listo para su uso.
- **T12**: Verificación manual de warnings eliminados (requiere activar/desactivar `factura_pdf1` en DDEV con la tienda).
- **T14**: Verificación manual end-to-end en DDEV (requiere sesión de navegador).
