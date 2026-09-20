# Tasks: plugin-enable-ajax-wizard (core)

## Wave 1 — Detección AJAX y contrato de resultado (TDD)

- [x] **T1** Test de regresión `tests/Core/PluginEnableAjaxSafetyTest.php`: wizard vía AJAX → sin 3xx, JSON con `wizard` (RED)
- [x] **T2** Test de regresión: wizard vía request normal → 302 + `Location: index.php?page={wizard}` preservado (RED)
- [x] **T3** Test de regresión: plugin sin wizard vía AJAX → JSON sin `wizard`, sin `Location` (RED)
- [x] **T4** Helper/contrato de detección AJAX en el core (`X-Requested-With: XMLHttpRequest` y/o `ajax=1`), sin acoplar a jQuery (GREEN)

## Wave 2 — Guard en la rama wizard del core

- [x] **T5** `base/fs_plugin_manager.php::enableWithoutDependencyResolution()`: no emitir `header('Location: ...')` cuando la petición es AJAX (GREEN)
- [x] **T6** Exponer el `wizard` y el resultado de activación de forma estructurada al caller AJAX
- [x] **T7** `src/Core/Plugin/PluginEnableOrchestrator.php`: propagar el resultado (wizard) hacia `enablePluginStep()` sin romper la firma pública existente

## Wave 3 — Consumo en el plugin store (referenciado, consumidor)

- [ ] **T8** `plugins/system_updater/controller/admin_plugin_store.php::ajaxActivateStep()`: incluir `wizard` en el `sendJson` cuando exista
- [ ] **T9** `plugins/system_updater/view/js/plugin_cascade_ajax.js`: navegar client-side al wizard usando el campo recibido, sin esperar redirect

## Wave 4 — Follow-up plugin-local factura_pdf1 (fuera del scope core)

- [x] **T10** `plugins/factura_pdf1/Services/LegacyRolePermissionsGateway.php::roleHasPageAccess()`: añadir `'allow_delete' => false` en la construcción de `fs_rol_access`
- [x] **T11** (Opcional) `model/fs_rol_access.php:40`: lectura defensiva `$data['allow_delete'] ?? false`
- [ ] **T12** Verificar que la activación de `factura_pdf1` ya no emite warnings `Undefined array key "allow_delete"`

## Wave 5 — Verify

- [x] **T13** `ddev exec php vendor/bin/phpunit tests/Core/PluginEnableAjaxSafetyTest.php` — verde
- [ ] **T14** Manual en DDEV: desactivar y reactivar `factura_pdf1` desde la tienda → sin error en UI, plugin activo, sin 302 en `activate_step`
- [x] **T15** verify-report.md

## Dependencies

- **Sigue a**: `openspec/changes/plugin-cascade-activation/` (introduce el orquestador y `enableWithoutDependencyResolution()` sobre los que se aplica este guard).
- **Bloquea a**: consumo del campo `wizard` en `system_updater` (plugin consumidor).
- **Independiente**: el fix plugin-local del gateway de `factura_pdf1` (T10–T12) puede entregarse en paralelo bajo `plugins/factura_pdf1/openspec/` o como fix directo.
