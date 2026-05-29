# Proposal: Arreglo de Bugs en Login, CSRF y Cookies

## Intent

Arreglar 7 bugs interconectados en el sistema de login, CSRF y cookies del FSFramework. El más grave (#1): el formulario de login envía POST a una URL sin `page=login`, por lo que `index.php` instancia `fs_controller` base en lugar del controlador `login`. Toda la lógica de errores, mensajes y redirección en `controller/login.php` es código muerto. Bugs #2-#4 bloquean o degradan CSRF, sesiones y cookies.

## Scope

### In Scope
- Arreglar `loginActionUrl()` para que el POST incluya `page=login` y el controlador correcto se ejecute
- Implementar métodos `login()`, `logout()`, `login_from_cookie()` en `fs_user` (referenciados pero inexistentes)
- Agregar `exit()` tras redirects en `select_default_page()` y `handleCredentialLogin()`
- Validar CSRF durante el flujo de login (actualmente saltado en el path base de `fs_controller`)
- Refrescar token CSRF tras regeneración de sesión en `save_session_data()`
- Rotar `log_key` al cambiar contraseña en `fs_user::set_password()`
- Proteger `fs_user::save()` contra guardar `log_key` NULL

### Out of Scope
- Refactor completo del sistema de autenticación
- Migración a Symfony Security full-stack
- Tests end-to-end del flujo de login
- Rediseño del sistema de cookies

## Capabilities

### New Capabilities
- `login-csrf`: Login form routing al controlador correcto, validación CSRF en login, integridad de redirects
- `session-integrity`: Regeneración de sesión con refresh de token CSRF, invalidación de sesiones al cambiar contraseña
- `user-auth-methods`: Métodos de autenticación en `fs_user` (`login`, `logout`, `login_from_cookie`), protección de `log_key` NULL

### Modified Capabilities
- None — no existen specs previas para las capacidades afectadas

## Approach

**3 fases independientes, cada una autónoma y reversible:**

| Fase | Prioridad | Bugs | Cambios clave |
|------|-----------|------|---------------|
| 1 | CRÍTICO | #1, #2, #4 | `loginActionUrl()` + page, métodos `fs_user`, `exit()` en redirects |
| 2 | ALTO | #3, #5 | CSRF en path base, refresh token post-`migrate(true)` |
| 3 | MEDIO | #6, #7 | Rotar `log_key` en `set_password()`, generar `log_key` si NULL en `save()` |

Cada fase se implementa, prueba y commitea como unidad independiente.

## Affected Areas

| Archivo | Cambio |
|---------|--------|
| `controller/login.php:215-221` | Arreglar `loginActionUrl()` — mantener `page=login` |
| `model/core/fs_user.php` | Agregar `login()`, `logout()`, `login_from_cookie()`; rotar `log_key` en `set_password()`; proteger `save()` |
| `base/fs_controller.php:228-229,634,653` | Validar CSRF en path `$name==__CLASS__`; `exit()` tras redirect |
| `base/fs_login.php:512-516` | Refrescar CSRF post `session->migrate(true)` |
| `themes/AdminLTE/view/login/default.html.twig:76` | Verificar que el action incluya `page=login` |

## Risks

| Riesgo | Probabilidad | Mitigación |
|--------|-------------|------------|
| Romper login para usuarios con cookies antiguas | Baja | Fases incrementales; rollback por fase |
| CSRF estricto bloquea forms legítimos durante transición | Media | Definir `FS_CSRF_SOFT=true` temporalmente en Fase 2 |
| Métodos nuevos en `fs_user` rompen plugins que extienden la clase | Baja | Métodos nuevos, no overriding; sin cambio de firma existente |

## Rollback Plan

Revertir commits en orden inverso de fase (3 → 2 → 1). Cada fase es autónoma: revertir una no afecta a las demás. Si Fase 1 se revierte, el sistema vuelve al comportamiento actual (login vía `fs_controller` base).

## Dependencies

- `FsLogin` (base/fs_login.php) ya contiene la lógica de autenticación que los nuevos métodos de `fs_user` delegarán
- `CsrfManager` y `SessionManager` (src/Security/) ya existen y son funcionales

## Success Criteria

- [ ] Login POST ejecuta `controller/login.php` (no `fs_controller` base)
- [ ] Errores de login se muestran en el formulario (no página en blanco)
- [ ] CSRF se valida durante el login (token del form requerido)
- [ ] Admin pages no producen errores CSRF en cascada tras login
- [ ] Cambio de contraseña invalida sesiones/cookies existentes
- [ ] `log_key` nunca es NULL al persistir un usuario
- [ ] `ddev exec php vendor/bin/phpunit` — todos los tests existentes pasan
