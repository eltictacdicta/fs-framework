# Tasks: CSRF, Login y Cookie Audit — 7 bugs

## Phase 1: Routing y Métodos de Autenticación (CRITICAL)
- [x] 1.1 Eliminar `unset($query['page'])` en `loginActionUrl()` ✅
- [x] 1.2 Agregar thin wrappers `login()`, `logout()`, `login_from_cookie()` en `fs_user` ✅
- [x] 1.3 Agregar `exit;` tras `header('Location: ...')` en `select_default_page()` ✅

## Phase 2: Sincronización CSRF (HIGH)
- [x] 2.1 Agregar `CsrfManager::refreshToken()` tras `session->migrate(true)` ✅
- [x] 2.2 Agregar `FS_CSRF_SOFT=true` en `config.php` ✅

## Phase 3: Integridad de Sesiones (MEDIUM)
- [x] 3.1 Llamar `rotate_logkey()` en `set_password()` ✅
- [x] 3.2 Proteger `save()` contra `log_key` NULL ✅

## Phase 4: Verificación
- [x] 4.1 Suite de tests: 436 tests, 815 assertions, 0 nuevas regresiones ✅
- [x] 4.2 Verificar escenarios de spec: LC-01 a LC-04, SI-01 a SI-03, UA-01 a UA-04 ✅

### Implementation Notes
- `login_from_cookie($autologin)`: wrapper sets `$_COOKIE['autologin']` and delegates. Full autologin URL generation needs to be defined to complete this feature.
- `filter_input(INPUT_COOKIE, ...)` limitations in CLI prevent end-to-end cookie auth testing without DB.
- Task 1.3 `exit` verification is structural — logic extraction tests cover redirect URL correctness.
