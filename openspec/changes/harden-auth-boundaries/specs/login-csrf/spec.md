# Delta for login-csrf

> **Nota de merge (idioma):** el spec canónico `openspec/specs/login-csrf/spec.md` está
> escrito en español. Este delta se escribe en español para que el archivo fusionado no
> quede bilingüe; no se traduce ni se modifica el canónico.
>
> **Nota de merge (alcance):** `LC-02` **NO** se redefine ni se enmienda en este delta.
> Su enmienda de transporte/almacenamiento ya está fusionada en el canónico (archivada el
> 2026-10-25). Una segunda enmienda al mismo ID es exactamente el riesgo de delta
> competidor que el proposal señala: `LC-02` es una cláusula de *transporte* (de qué
> sesión se lee el token y que sobrevive al renombrado de cookie) y lo que agrega este
> cambio es una cláusula de *enforcement* (qué hace el veredicto). IDs separados son
> independientemente testeables y no pueden perder la cláusula del otro al archivar.

## ADDED Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| LC-06 | El veredicto CSRF es autoritativo y una submission de credenciales se evalúa como máximo una vez por petición | MUST |
| LC-07 | En la ruta moderna (FS2025), un POST con credenciales sin token CSRF válido **MUST NOT** cambiar la sesión de la víctima | MUST |

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
- AND se registra exactamente un `set_attempt()` para esa submission

#### Scenario: Sin campo de token se comporta igual que con token inválido

- GIVEN el mismo POST sin ningún campo de token CSRF
- WHEN la petición se procesa
- THEN el resultado observable es idéntico al del token inválido
- AND se registra exactamente un `set_attempt()`

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

## MODIFIED Requirements

(Ninguno. `LC-02` no se redefine en este delta — ver la nota de merge de alcance.)

## REMOVED Requirements

(Ninguno)
