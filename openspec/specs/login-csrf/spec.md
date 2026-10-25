# login-csrf Specification

## Purpose

Routing del formulario de login al controlador correcto, validación CSRF en el flujo de login, e integridad de redirects.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| LC-01 | El POST del formulario de login **MUST** rutear a `controller/login.php` (no a `fs_controller` base) | MUST |
| LC-02 | El flujo de login **MUST** validar el token CSRF en el POST bajo el nombre de sesión unificado (`FSSESS_xxx`), con lecturas consistentes en cualquier backend de sesión y sin desajuste de token por cookies `PHPSESSID` heredadas | MUST |
| LC-03 | Los redirects post-login **MUST** detener ejecución con `exit()` | MUST |
| LC-04 | Los errores de login **SHOULD** mostrarse en el formulario | SHOULD |
| LC-05 | Todos los inputs HTTP en `controller/login.php` **MUST** leerse via `$this->request` (Symfony Request), no superglobals | MUST |
| LC-06 | El veredicto CSRF es autoritativo y una submission de credenciales se evalúa como máximo una vez por petición | MUST |
| LC-07 | En la ruta moderna (FS2025), un POST con credenciales sin token CSRF válido **MUST NOT** cambiar la sesión de la víctima | MUST |

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

### Requirement: LC-02 — Validación CSRF durante el login

El flujo de login **MUST** validar el token CSRF en el POST bajo el nombre de sesión unificado (`FSSESS_xxx`). Las lecturas del token CSRF **MUST** ser consistentes independientemente de si el backend de sesión es `NativeSessionStorage` o `PhpBridgeSessionStorage`, porque el almacenamiento CSRF lee directamente la sesión PHP (`$_SESSION['_csrf']`) en lugar de un session bag de Symfony. Las cookies `PHPSESSID` heredadas **MUST NOT** provocar un desajuste de token después de que la cookie heredada sea descartada (SC-04).

#### Scenario: CSRF validado bajo la sesión unificada

- **GIVEN** POST al formulario de login desde acceso admin stealth (`?adminpanel=HASH`)
- **WHEN** `pre_private_core()` llama a `validateCsrf()`
- **THEN** el token se lee de la sesión `FSSESS_xxx` (no de `PHPSESSID`)
- **AND** la validación coincide con el token embebido en el formulario

#### Scenario: El CSRF sobrevive a la transición de stealth a normal

- **GIVEN** el usuario accedió al panel vía enlace stealth (cookie `PHPSESSID`)
- **WHEN** envía el formulario de login (POST con token CSRF)
- **THEN** la petición se ejecuta sobre la sesión `FSSESS_xxx` abierta por `StealthMode`
- **AND** cualquier `PHPSESSID` obsoleta se descarta sin ser leída
- **AND** `validateCsrf()` retorna `true` para el token enviado
- **AND** el usuario no ve el error "Token de seguridad inválido"

#### Scenario: Desajuste de token con cookies duales

- **GIVEN** la petición lleva ambas cookies, `PHPSESSID` (obsoleta) y `FSSESS_xxx`
- **WHEN** `validateCsrf()` lee el token
- **THEN** solo se usan los datos de sesión de `FSSESS_xxx`
- **AND** `PHPSESSID` se expira en la respuesta

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

### Requirement: LC-06 — El veredicto CSRF es autoritativo; la credencial se evalúa una sola vez

El veredicto de `validateCsrf()` **MUST** ser autoritativo y una submission de credenciales
**MUST** evaluarse como máximo una vez por petición.

Un POST que transporte credenciales (`password` y (`user` o `nick`)) y no supere
`validateCsrf()` **MUST NOT** llegar a la evaluación de credenciales por ninguna ruta
posterior de la misma petición: el `false` devuelto por `fs_controller::log_in()` es
definitivo y **MUST NOT** ser revertido por una segunda llamada al mismo flujo.

El guard **MUST** ubicarse dentro de `login::process_login_logic()`, **después** del
redirect de login público registrado por plugins y **antes** de la rama de credenciales.
De ese modo el manejo de GET, `?autologin=`, los redirects de login público de plugins
(incluida su forma POST), `restoreBufferedVariables()`, `switchDatabaseIfRequested()`, el
manejo de `?logout` y la rama de contraseña incorrecta **MUST** permanecer intactos. Un
guard al comienzo de `public_core()` **MUST NOT** usarse, porque bloquearía también el
redirect de login público de plugins.

El predicado del guard **MUST** leer `isCsrfValid()`, no `requireCsrf()`: `csrf_valid ===
false` sólo lo fija un `validateCsrf()` que ya corrió, mientras que `requireCsrf()`
validaría cuando nadie lo hizo todavía y rechazaría un POST a `page=login` que
legítimamente no lleva token ni credenciales.

La evaluación de credenciales **MUST** ocurrir como máximo una vez por petición.
`fs_controller::log_in()` **MUST** registrar que entregó el POST de credenciales a
`fs_login::log_in()`, y `process_login_logic()` **MUST** omitir la evaluación de
credenciales cuando ese registro está presente. En consecuencia, una submission fallida
con token válido **MUST** producir exactamente un intento de throttle y exactamente un
mensaje de error de credenciales.

Efecto observable intencional, a documentar en el cuerpo del PR: el contador de ban por IP
(`MAX_ATTEMPTS = 5`, `is_banned()` es `count > 5`) y `LoginThrottle` (`MAX_ATTEMPTS = 6`)
hoy se incrementan **dos** veces por submission fallida; tras este cambio, una vez. El
lockout pasa a requerir 6 submissions fallidas reales en lugar de 3. Es el comportamiento
pedido ("el throttle debe reflejar submissions reales"), no una regresión.

#### Scenario: Token inválido no llega a la autenticación

- GIVEN un POST a `page=login` con `user`/`password` y un token CSRF inválido
- WHEN la petición se procesa
- THEN no se autentica ninguna cuenta
- AND la respuesta contiene el error de CSRF
- AND la respuesta NO contiene ningún mensaje de evaluación de credenciales (`Usuario o contraseña incorrectos.`, `baneada`, `desactivado`)
- AND NO se registra ningún `set_attempt()` para esa submission — el veredicto CSRF es autoritativo (LC-06), así que la petición nunca alcanza `fs_login::log_in()`, que es donde vive `set_attempt()`. Registrar un intento en el camino de rechazo permitiría que un POST cross-site baneara la IP de una víctima, y contradice LTI-03 (*"una submission vacía no incrementa ningún contador"*).
  - *Corregido durante PR 2.* El texto original exigía "exactamente un `set_attempt()`" en el camino de rechazo, que es inalcanzable bajo el guard que este mismo delta manda y que habría introducido un vector de ban cross-site. La implementación asserta el camino de rechazo como inerte (0 intentos); esta cláusula ahora coincide con ella.

#### Scenario: Sin campo de token se comporta igual que con token inválido

- GIVEN el mismo POST sin ningún campo de token CSRF
- WHEN la petición se procesa
- THEN el resultado observable es idéntico al del token inválido
- AND no se registra ningún `set_attempt()` — el camino de rechazo es inerte, idéntico al del token inválido (ver la corrección de PR 2 en el scenario anterior)

#### Scenario: Token válido y credenciales incorrectas evalúan una sola vez

- GIVEN un POST a `page=login` con `user`/`password` incorrectos y un token CSRF válido
- WHEN la petición se procesa
- THEN el mensaje de evaluación de credenciales aparece exactamente una vez en la respuesta (antes: dos)
- AND se registra exactamente un intento de throttle

#### Scenario: Token válido y credenciales correctas inician sesión como hoy

- GIVEN un POST a `page=login` con credenciales correctas y un token CSRF válido
- WHEN la petición se procesa
- THEN el login se completa y el redirect es el mismo que hoy

#### Scenario: El GET del formulario no cambia

- GIVEN un `GET /index.php?page=login`
- WHEN la petición se procesa
- THEN el formulario se renderiza igual que hoy
- AND `?autologin=` sigue funcionando

#### Scenario: El logout no cambia

- GIVEN un `GET /index.php?page=login&logout=1`
- WHEN la petición se procesa
- THEN el comportamiento es el mismo que hoy

#### Scenario: El redirect de login público de un plugin se sigue honrando

- GIVEN un plugin que registró un redirect de login público
- WHEN un POST anónimo llega a `page=login`
- THEN el redirect registrado se honra, sin cambios
- AND el guard CSRF no lo bloquea

#### Scenario: El guard no usa requireCsrf

- GIVEN un POST a `page=login` sin credenciales y sin token
- WHEN `process_login_logic()` se ejecuta
- THEN la petición no es rechazada por el guard
- AND el predicado consultado es `isCsrfValid()`

### Requirement: LC-07 — Guard CSRF de credenciales en la ruta moderna (FS2025)

`FSFramework\Core\Base\Controller::initializeConnectedSession()` **MUST** rechazar un POST
que transporte credenciales (`password` y (`user` o `nick`)) sin un token CSRF válido,
antes de entregarlo a `fs_login::log_in()`. Es el mismo control de login CSRF en una
segunda puerta: hoy un POST con credenciales se entrega a `fs_login` sin guard y sin
condición sobre la forma del request, de modo que una víctima **autenticada** que POSTee
`user`/`password` del atacante a cualquier ruta FS2025 cambia su propia sesión a la cuenta
del atacante, sin token. La forma anónima está bloqueada por el gate, que es por lo que
ninguna prueba no autenticada la alcanzaba.

El predicado **MUST** ser la definición compartida
(`fs_auth_request::hasCredentialSubmission`) y la validación **MUST** usar el helper CSRF
del propio controlador. Un POST con token válido **MUST** comportarse como hoy; un POST
moderno legítimo que transporte `nick`/`password` con token válido **MUST NOT** verse
afectado.

> **Justificación de ID propio:** `LC-07` es una puerta distinta (la base de todos los
> controladores FS2025, no el wrapper legacy) con su propio observable, y es
> independientemente revertible dentro del mismo workstream. Un ID separado evita que la
> cláusula moderna se pierda al archivar junto con la legacy, que es el mismo argumento de
> `LC-06` frente a `LC-02`.

#### Scenario: Un POST autenticado con credenciales ajenas y token inválido no cambia la sesión

- GIVEN una víctima con sesión autenticada
- WHEN envía un POST a una ruta FS2025 con `user`/`password` del atacante y un token CSRF inválido
- THEN la sesión de la víctima NO cambia de cuenta
- AND no se entrega el POST a `fs_login::log_in()`

#### Scenario: El mismo POST sin campo de token no cambia la sesión

- GIVEN el mismo POST sin ningún campo de token CSRF
- WHEN la petición se procesa
- THEN la sesión de la víctima NO cambia de cuenta

#### Scenario: Un POST con token válido se comporta como hoy

- GIVEN el mismo POST con un token CSRF válido
- WHEN la petición se procesa
- THEN el comportamiento es el mismo que hoy

#### Scenario: Un formulario moderno legítimo no se ve afectado

- GIVEN un POST legítimo de un formulario FS2025 que transporta `nick` y `password` con un token válido
- WHEN la petición se procesa
- THEN el formulario se procesa con normalidad

#### Scenario: El predicado de credenciales es el compartido

- GIVEN el guard de la ruta moderna
- WHEN decide si el POST transporta credenciales
- THEN usa la definición compartida de "submission con credenciales"
- AND no mantiene una condición local duplicada

