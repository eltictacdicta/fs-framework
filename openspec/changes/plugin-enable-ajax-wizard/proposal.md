# Proposal: Activación AJAX de plugins con wizard sin redirección 302

## Intent

Al desactivar y reactivar `factura_pdf1` desde la tienda de plugins (`system_updater`), la UI muestra un error de activación, pero el plugin queda activo tras recargar la página. El resultado es un falso negativo que confunde al operador y rompe la promesa de la activación en cascada: el trabajo se hizo, pero la respuesta al cliente es incorrecta.

**Causa raíz (probada)**: `factura_pdf1/fsframework.ini` declara `wizard = admin_factura_pdf1`. En `base/fs_plugin_manager.php::enableWithoutDependencyResolution()` (~líneas 323-332) la rama del wizard emite `header('Location: index.php?page=' . $wizard)` **de forma incondicional** cuando `$runWizard` es verdadero, sin distinguir si la petición es AJAX:

```php
if ($wizard) {
    $this->core_log->new_advice('Ya puedes <a href="index.php?page=' . $wizard . '">configurar el plugin</a>.');
    if ($runWizard) {
        header('Location: index.php?page=' . $wizard);   // ← sin guard AJAX
        ...
        return true;
    }
}
```

La tienda activa plugins por AJAX (`admin_plugin_store&action=activate_step&ajax=1`, ver `plugins/system_updater/view/js/plugin_cascade_ajax.js` → `activateCascade`/`confirmDownloadThenActivate`) y espera JSON. Una sonda decisiva en DDEV demostró que `header('Location: ...')` seguido de `http_response_code(200)` + JSON (lo que hace `sendJson`) **sigue devolviendo HTTP 302** con body JSON. Por lo tanto `activate_step` responde 302 → jQuery sigue el redirect hacia el HTML del wizard → `dataType:'json'` falla el parse → la tienda muestra el alert de error, aunque el `save()` ya se ejecutó antes del redirect y la activación quedó persistida.

Este change corrige el **core**: cuando la petición de activación es AJAX, no se emite `Location`; en su lugar se devuelve el nombre/URL del wizard dentro del JSON para que la tienda navegue client-side. Los callers no-AJAX conservan la redirección actual.

## Evidencia

- `tmp/audit/plugin_audit.log` (2026-09-19T13:58:25): `{"action":"enable","plugin":"factura_pdf1",...,"context":{"success":true,"wizard":"admin_factura_pdf1"}}` — la activación se persistió.
- nginx: `GET /index.php?page=admin_factura_pdf1` un segundo después, con referrer `admin_home` — es el AJAX siguiendo el 302.
- Sonda DDEV: `HTTP/1.1 302 Moved Temporarily` + header `Location` + body JSON en la misma respuesta.
- Código: `base/fs_plugin_manager.php` (rama wizard); `plugins/system_updater/view/js/plugin_cascade_ajax.js` (`activateCascade`, `confirmDownloadThenActivate`); `plugins/system_updater/controller/admin_plugin_store.php` (`ajaxActivateStep`, `sendJson`).

## Scope

### In Scope (core)

- Guard AJAX en la rama wizard de `enableWithoutDependencyResolution()`: no emitir `header('Location: ...')` cuando la petición es AJAX.
- Detección de AJAX sin acoplar el core a jQuery: `X-Requested-With: XMLHttpRequest` y/o parámetro `ajax=1`.
- Exponer de forma estructural el resultado de la activación (incluido el `wizard`) para que el orquestador y el controlador AJAX lo incluyan en su JSON de respuesta.
- Mantener sin cambios el comportamiento de redirección para callers no-AJAX.
- Tests de regresión: wizard vía AJAX, wizard vía request normal, plugin sin wizard vía AJAX.

### Out of Scope (core)

- El fix **plugin-local** de `factura_pdf1` sobre el gateway de permisos de rol (`Services/LegacyRolePermissionsGateway.php`) y la lectura defensiva en `model/fs_rol_access.php`. Se referencia y se entrega por separado (ver "Follow-up plugin-local").
- Consumo del campo `wizard` en la JS de la tienda (`system_updater`): cambio del plugin consumidor, dependiente de este contrato.
- Rediseño del wizard en sí o de su pantalla.
- Migrar otras rutas de `header('Location')` a un patrón AJAX.

## Follow-up plugin-local (factura_pdf1)

Además del falso error, la activación emite ~18 warnings PHP `Undefined array key "allow_delete"`. `plugins/factura_pdf1/Services/LegacyRolePermissionsGateway.php:48` (`roleHasPageAccess`) construye `new \fs_rol_access(['codrol' => ..., 'fs_page' => ...])` **sin `allow_delete`**, y `model/fs_rol_access.php:40` lee `$data['allow_delete']` sin guarda. Plan: añadir `'allow_delete' => false` en el gateway (y opcionalmente `?? false` defensivo en `fs_rol_access`).

Este trabajo es **plugin-local** y NO forma parte de los requirements de core más allá del requirement de trazabilidad. Se entregará en `plugins/factura_pdf1/openspec/` o como fix directo pequeño, referenciado desde este change.

## Capabilities

### New

- `plugin-enable-ajax-safety`: activación vía AJAX que nunca responde con redirect y comunica el wizard de forma observable.

### Modified

- Contrato de `fs_plugin_manager::enableWithoutDependencyResolution()`: deja de redirigir incondicionalmente en la rama wizard y respeta el contexto AJAX.

## Affected Areas

| Area | Impact |
|------|--------|
| `base/fs_plugin_manager.php` | Modified — guard AJAX + resultado estructurado del wizard |
| `src/Core/Plugin/PluginEnableOrchestrator.php` | Modified — propaga el resultado (wizard) al caller |
| `plugins/system_updater/controller/admin_plugin_store.php` | Consumer (referenced) — incluye `wizard` en `sendJson` |
| `plugins/system_updater/view/js/plugin_cascade_ajax.js` | Consumer (referenced) — navega client-side al wizard |
| `tests/Core/PluginEnableAjaxSafetyTest.php` | New |
| `plugins/factura_pdf1/Services/LegacyRolePermissionsGateway.php` | Plugin-local follow-up (no core) |
| `model/fs_rol_access.php` | Plugin-local follow-up (no core) |

## Risks

| Risk | Mitigation |
|------|------------|
| Regresión en callers no-AJAX que esperan 302 | Escenario explícito de no-regresión; tests de redirect preservado |
| Detección de AJAX frágil | Aceptar `X-Requested-With` o `ajax=1`, mismo criterio que `fs_controller::isAjax()` |
| El core se acopla al JavaScript del store | El core expone datos; la navegación client-side queda en el plugin consumidor |
| Respuesta AJAX sin `wizard` rompe al consumidor | Campo opcional/nullable; el consumidor tolera ausencia | 

## Success Criteria

- [ ] Activar un plugin con wizard vía AJAX devuelve JSON con `wizard`, sin código 3xx.
- [ ] Activar un plugin con wizard vía request normal conserva el 302 y la redirección al wizard.
- [ ] Activar un plugin sin wizard vía AJAX mantiene la respuesta JSON actual sin cambios.
- [ ] La tienda deja de mostrar el error y puede navegar al wizard sin carrera de recarga.
- [ ] Sin warnings `Undefined array key "allow_delete"` tras el follow-up plugin-local.

## Dependencies

- Continúa el trabajo de `openspec/changes/plugin-cascade-activation/` (el orquestador y `enableWithoutDependencyResolution()` son su resultado).
- El fix plugin-local del gateway de `factura_pdf1` es independiente y puede entregarse en paralelo.
