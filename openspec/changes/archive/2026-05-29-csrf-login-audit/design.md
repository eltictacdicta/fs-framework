# Design: CSRF, Login y Cookie Audit — 7 bugs

## Technical Approach

3 fases independientes, cada una autónoma y commiteable. La fase 1 (routing) desbloquea todo: al preservar `page=login` en la URL, el POST llega al controlador `login`, `pre_private_core()` valida CSRF automáticamente, y `private_core()` ejecuta el redirect. Las fases 2 y 3 refuerzan la integridad de sesión y cookies.

## Architecture Decisions

### Decision: Routing fix — no eliminar `page` en `loginActionUrl()`

| Opción | Tradeoff | Decisión |
|--------|----------|----------|
| **A**: Quitar `unset($query['page'])` | Mínimo cambio. POST llega al controlador `login`. `log_in()` en el constructor padre ya maneja la autenticación vía `fs_login`. | ✅ Elegida |
| B: Reemplazar action en template directamente | Acopla template a URL fija. Rompe si hay parámetros extra. | ❌ |
| C: Refactor completo del flujo de login | Alto riesgo, fuera de scope. | ❌ |

**Rationale**: El constructor `fs_controller::__construct()` (línea 227-236) distingue `$name == __CLASS__` para saltar `pre_private_core()`. Con `page=login`, `$name='login'` → entra en la rama `else` → CSRF se valida. El `log_in()` del padre ya autentica (lee `$_POST['user']`/`$_POST['nick']`). `handleCredentialLogin()` en `process_login_logic()` es redundante pero se preserva para mensajes de error.

### Decision: Métodos `login()`/`logout()`/`login_from_cookie()` en `fs_user`

| Opción | Tradeoff | Decisión |
|--------|----------|----------|
| **A**: Thin wrappers que instancian `fs_login` y delegan | Backward-compatible. `fs_login::log_in()` lee `$_POST` directamente — funciona porque `handleCredentialLogin()` se ejecuta con POST aún disponible. | ✅ Elegida |
| B: Refactor `fs_login` para aceptar parámetros | Más limpio pero rompe callers existentes. Fuera de scope. | ❌ |
| C: Eliminar `handleCredentialLogin()` | Simplifica pero elimina mensajes de error específicos. | ❌ |

**Rationale**: `fs_login::log_in()` acepta `&$controller_user` por referencia y muta `$this->logged_on`. Al llamarlo desde `fs_user::login()`, el propio `$this` de `fs_user` se pasa como `$controller_user`. `logout()` y `login_from_cookie()` siguen el mismo patrón.

### Decision: CSRF post-`migrate(true)` — refresh token

| Opción | Tradeoff | Decisión |
|--------|----------|----------|
| **A**: `CsrfManager::refreshToken()` tras `migrate(true)` | Limpio, usa API existente. Token nuevo se genera contra la nueva sesión. | ✅ Elegida |
| B: No hacer nada — `migrate(true)` preserva datos | Riesgo de desincronización en multi-tab / cargas asíncronas. | ❌ |

### Decision: `exit()` tras redirect en `select_default_page()`

**Rationale**: `header('Location: ...')` sin `exit()` permite que el script siga ejecutándose (renderiza template, envía headers adicionales). Añadir `exit()` después de cada `header()` en `select_default_page()` y en `redirectToSafeUrl()` (ya lo tiene).

## Data Flow

```
GET index.php?page=login
  │
  ▼
index.php ──► new login() ──► fs_controller::__construct()
  │                               │
  │   $this->log_in() ──► fs_login::log_in($this->user)
  │   (no POST → retorna FALSE)   │
  │                               ▼
  │   template = 'login/default'
  │   $this->public_core() ──► process_login_logic()
  │                               │
  ▼                               ▼
Render login form              Muestra form con csrf_field()
action={{ fsc.loginActionUrl() }}
       ↓ (AHORA incluye page=login)

POST index.php?page=login&nlogin=
  │
  ▼
index.php ──► new login() ──► fs_controller::__construct()
  │                               │
  │   $this->log_in() ──► fs_login::log_in() lee $_POST
  │   (user + password → log_in_user() → new_logkey())
  │                               │
  │   $user->logged_on = TRUE     ▼
  │                           save_session_data()
  │                           ├─ session->migrate(true)  ← regenera ID
  │                           ├─ CsrfManager::refreshToken() ← NUEVO
  │                           └─ save_cookie() → setcookie(...)
  │
  │   $name='login' != __CLASS__ → entra else
  │   pre_private_core() → validateCsrf() → token del form vs sesión
  │   private_core() → process_login_logic()
  │       │
  │       ▼ $user->logged_on = TRUE
  │   redirectToSafeUrl('index.php?page=admin_home') → exit()
  │
  ▼
GET index.php?page=admin_home
  │  (nueva sesión, CSRF refrescado — sin cascada)
  ▼
Render admin con token CSRF válido
```

## File Changes

| File | Action | Lines | Description |
|------|--------|-------|-------------|
| `controller/login.php` | Modify | 217 | Eliminar `unset($query['page'])` en `loginActionUrl()` |
| `model/core/fs_user.php` | Modify | +40 | Añadir `login()`, `logout()`, `login_from_cookie()` (delegan a `fs_login`); rotar `log_key` en `set_password()`; proteger `save()` contra `log_key` NULL |
| `base/fs_controller.php` | Modify | 634,653 | Añadir `exit()` tras `header('Location: ...')` en `select_default_page()` |
| `base/fs_login.php` | Modify | 514 | Añadir `CsrfManager::refreshToken()` tras `$this->session->migrate(true)` en `save_session_data()` |

## Interfaces / Contracts

```php
// fs_user — nuevos métodos (delegan a fs_login)
public function login(string $nick, string $password): bool;
public function logout(): void;
public function login_from_cookie(string $autologin): bool;

// fs_user::set_password() — añadir al final:
$this->rotate_logkey();

// fs_user::save() — añadir al inicio, antes del SQL:
if ($this->log_key === null || $this->log_key === '') {
    $this->rotate_logkey();
}

// fs_login::save_session_data() — añadir tras migrate(true):
\FSFramework\Security\CsrfManager::refreshToken();
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `fs_user::login()` delega correctamente | Mock `fs_login`, verificar `logged_on` |
| Unit | `fs_user::save()` con `log_key=null` → genera uno | Instanciar `fs_user`, `save()`, verificar `log_key` no null |
| Unit | `fs_user::set_password()` rota `log_key` | Verificar `log_key` cambia tras `set_password()` |
| Integration | Login POST con `page=login` ejecuta controlador correcto | `ddev exec php vendor/bin/phpunit` — tests existentes |
| Integration | CSRF token válido tras login + redirect | Verificar que admin pages no muestran errores CSRF en cascada |
| Security | `password_change` → `log_key` rotation → sesiones antiguas invalidadas | Test manual: login, cambiar password, verificar que cookie antigua no funciona |

## Migration / Rollout

No migration required. Las 3 fases son backward-compatible:
1. Fase 1 (routing): El login vía `fs_login::log_in()` sigue funcionando idéntico. Solo cambia qué controlador se instancia después.
2. Fase 2 (CSRF): `refreshToken()` tras `migrate(true)` no afecta usuarios sin sesión.
3. Fase 3 (cookies): `rotate_logkey()` en `set_password()` solo afecta cambios de contraseña nuevos.

Rollback: revertir commits en orden inverso (3→2→1).
