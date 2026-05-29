# user-auth-methods Specification

## Purpose

Métodos de autenticación en `fs_user` requeridos por `controller/login` y protección de integridad de `log_key`.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| UA-01 | `fs_user::login(string $nick, string $password): bool` **MUST** autenticar delegando en `fs_login` | MUST |
| UA-02 | `fs_user::logout(): void` **MUST** cerrar sesión y limpiar cookies legacy | MUST |
| UA-03 | `fs_user::login_from_cookie(string $value): bool` **MUST** restaurar sesión desde cookie | MUST |
| UA-04 | `fs_user::save()` **MUST** generar `log_key` automáticamente si es NULL al persistir | MUST |

### Scenario: login() delegates to fs_login

- **GIVEN** `$user->login('admin', 'secret')` invocado
- **WHEN** `fs_login::log_in_user()` verifica credenciales
- **THEN** retorna `true` con `$user->logged_on = true` si son correctas
- **AND** retorna `false` si son inválidas

### Scenario: logout() clears session and cookies

- **GIVEN** `$user->logout()` invocado con sesión activa
- **THEN** cookies legacy se limpian via `LegacyAuthBridge::clearLegacyCookies()`
- **AND** la sesión Symfony se invalida

### Scenario: login_from_cookie() restores session

- **GIVEN** `$user->login_from_cookie('abc123')` invocado
- **WHEN** `fs_login::log_in_from_cookie()` verifica la cookie firmada
- **THEN** si es válida, restaura sesión y retorna `true`
- **AND** si es inválida, retorna `false`

### Scenario: log_key never persists as NULL

- **GIVEN** `save()` llamado con `$this->log_key === NULL`
- **WHEN** el registro se inserta o actualiza en BD
- **THEN** `rotate_logkey()` se invoca antes del SQL, generando hex 64-char
- **AND** el valor persistido nunca es NULL
