# session-integrity Specification

## Purpose

Sincronización del token CSRF tras regeneración de sesión. Invalidación de sesiones antiguas al cambiar contraseña.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| SI-01 | El token CSRF **MUST** refrescarse después de `session->migrate(true)` en `save_session_data()` | MUST |
| SI-02 | `fs_user::set_password()` **MUST** rotar `log_key` al cambiar la contraseña | MUST |
| SI-03 | Las sesiones/cookies previas al cambio de contraseña **MUST** quedar inválidas | MUST |

### Scenario: CSRF token refreshed after session regeneration

- **GIVEN** login exitoso donde `save_session_data()` regenera la sesión
- **WHEN** la sesión obtiene un ID nuevo (datos preservados)
- **THEN** el almacenamiento del token CSRF se sincroniza con la nueva sesión
- **AND** formularios cargados post-redirect tienen token CSRF válido

### Scenario: Password change invalidates old sessions

- **GIVEN** usuario A con sesión activa en navegador X
- **WHEN** contraseña de A se cambia via `set_password()` + `save()` desde navegador Y
- **THEN** `log_key` de A es rotado automáticamente
- **AND** las cookies de sesión del navegador X quedan inválidas

### Scenario: log_key rotation on password change

- **GIVEN** `set_password('new_secret')` llamado con contraseña válida (8-32 chars)
- **WHEN** el hash se genera correctamente
- **THEN** `rotate_logkey()` se invoca, regenerando `log_key` a hex de 64 chars
- **AND** el nuevo `log_key` se persiste en el siguiente `save()`
