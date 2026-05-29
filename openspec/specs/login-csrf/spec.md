# login-csrf Specification

## Purpose

Routing del formulario de login al controlador correcto, validación CSRF en el flujo de login, e integridad de redirects.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| LC-01 | El POST del formulario de login **MUST** rutear a `controller/login.php` (no a `fs_controller` base) | MUST |
| LC-02 | El flujo de login **MUST** validar token CSRF en el POST | MUST |
| LC-03 | Los redirects post-login **MUST** detener ejecución con `exit()` | MUST |
| LC-04 | Los errores de login **SHOULD** mostrarse en el formulario | SHOULD |

### Scenario: Login POST reaches correct controller

- **GIVEN** usuario no autenticado en `/login`
- **WHEN** envía el formulario con POST
- **THEN** `index.php` instancia `controller/login`, no `fs_controller`
- **AND** `private_core()` del controlador `login` se ejecuta

### Scenario: CSRF validated during login

- **GIVEN** POST al formulario de login sin token CSRF o con token inválido
- **WHEN** `pre_private_core()` se ejecuta en `fs_controller` (path base o login)
- **THEN** el login es rechazado (`validateCsrf()` retorna `false`)
- **AND** el error se muestra al usuario en el formulario

### Scenario: Redirect stops execution

- **GIVEN** login exitoso con POST al controlador correcto
- **WHEN** `select_default_page()` o `redirectToSafeUrl()` emite `header('Location: ...')`
- **THEN** la ejecución se detiene con `exit()`
- **AND** no se renderiza template adicional

### Scenario: Login errors displayed

- **GIVEN** credenciales inválidas enviadas vía POST
- **WHEN** `handleCredentialLogin()` falla
- **THEN** el formulario de login se re-renderiza con `mensaje_login` visible
