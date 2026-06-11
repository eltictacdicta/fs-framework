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
| LC-05 | Todos los inputs HTTP en `controller/login.php` **MUST** leerse via `$this->request` (Symfony Request), no superglobals | MUST |

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

### Requirement: Symfony Request for All Input Access in login.php (H3)

The system MUST read all HTTP inputs via `$this->request` (Symfony Request) instead of raw `$_GET`/`$_POST` superglobals in `controller/login.php`. Zero direct superglobal accesses SHALL remain after the fix.

#### Scenario: Credential login with valid nick and password

- **GIVEN** a POST request to login with `nick=admin` and `password=secret`
- **WHEN** `handleCredentialLogin()` is called
- **THEN** `nick` is read via `$this->request->request->get('nick')`
- **AND** `password` is read via `$this->request->request->get('password')`
- **AND** authentication proceeds identically to current behavior

#### Scenario: Logout via GET parameter

- **GIVEN** an authenticated session with `?logout=1` in the URL
- **WHEN** `private_core()` executes
- **THEN** the logout flag is read via `$this->request->query->get('logout')`
- **AND** `$this->user->logout()` is called

#### Scenario: Database switch via POST or GET

- **GIVEN** a multi-DB setup with `cdb=newdb` in POST or GET
- **WHEN** `switchDatabaseIfRequested()` is called
- **THEN** the value is read via `$this->request->request->get('cdb')` with fallback to `$this->request->query->get('cdb')`
- **AND** the database switch proceeds normally

#### Scenario: Remember-me checkbox submitted

- **GIVEN** a login POST with `remember_me=1`
- **WHEN** credential login succeeds
- **THEN** `remember_me` is read via `$this->request->request->get('remember_me')`
- **AND** the session flag is set correctly

#### Scenario: Auto-login via cookie token in GET

- **GIVEN** a request with `?autologin=<token>` in the URL
- **WHEN** `handleAutoLogin()` is called
- **THEN** the token is read via `$this->request->query->get('autologin')`
- **AND** `login_from_cookie()` is invoked with that value

#### Scenario: Missing parameters return null gracefully

- **GIVEN** a login request with no POST body
- **WHEN** `handleCredentialLogin()` is called
- **THEN** `$this->request->request->get('nick')` returns `null`
- **AND** the method returns `false` (no credential login attempted)
