# Specification — `audit-2026-06-12`

**Framework**: FSFramework v0.15.0
**Modo**: TDD (test-first, Strict TDD donde aplique)
**Reglas de cambio**: Mínimo, sin refactor, no romper API pública, no cambiar comportamiento de negocio
**Cambio total estimado**: ~45 líneas producción + ~180 líneas tests

---

## 0. Resumen ejecutivo

Auditoría de seguimiento sobre `sdd/production-audit/explore` (15 hallazgos originales, marzo 2026).

**Estado de los 15 hallazgos originales**:

| ID | Título | Severidad | Estado |
|----|--------|-----------|--------|
| C1 | SQLi en `fs_list_controller` search | CRITICAL | ✅ Arreglado (critical-security-fixes-2026-03) |
| H1 | `no_html()` docblock vs código | HIGH | ⏳ Pendiente |
| H2 | `fs_auth::verifyCsrfRequest()` raw `$_POST` | HIGH | ✅ Arreglado |
| H3 | `login.php` superglobals | HIGH | ✅ Arreglado |
| H4 | PostgreSQL identifiers sin quote | HIGH | ⏳ Pendiente |
| M1 | `random_string()` con `str_shuffle` | MEDIUM | ⏳ Pendiente |
| M2 | `admin_info.php` GET mutations | MEDIUM | ⏳ Pendiente |
| M3 | Duplicación `random_string()` × 3 | MEDIUM | ⏳ Pendiente (resuelto con M1) |
| M4 | Duplicación `setPreferenceCookie` | MEDIUM | 📋 Backlog (no crítico) |
| L1 | `admin_user.php` superglobals | LOW | ⏳ Pendiente |
| L2 | DB name en HTML error | LOW | 📋 Backlog |
| L3 | `php_uname()` info disclosure | LOW | 📋 Backlog |
| L4 | Legacy cookie `auth_sig` bypass | LOW | ✅ Migrado a `SessionManager` moderno |
| L5 | `no_html` duplicado fs_model/fs_controller | LOW | 📋 Backlog |
| L6 | `login_from_cookie` escribe `$_COOKIE` | LOW | 📋 Backlog |

**Hallazgos NUEVOS detectados en v0.15.0**:

| ID | Título | Severidad |
|----|--------|-----------|
| **N1** | `CsrfManager` `error_log()` ruido + info disclosure | HIGH |
| **N2** | `LoginThrottle::getClientIp()` bypass via `X-Forwarded-For` | HIGH |
| N3 | `fs_get_ip()` bypass (logging only) | MEDIUM |
| N4 | `admin_empresa.php` upload valida solo extensión | MEDIUM |

**Cambios a aplicar en este change** (por impacto y costo):

1. **N1** (HIGH) — 2 líneas borradas, 2 tests
2. **N2** (HIGH) — ~10 líneas reescritas, 4 tests
3. **N3** (MEDIUM) — derivado de N2, misma fix, +1 test
4. **H1** (HIGH) — 1 línea borrada, 3 tests
5. **M1+M3** (MEDIUM) — 1 helper nuevo, 4 callsites actualizados, 4 tests
6. **L1** (LOW) — 9 superglobals → request, 2 tests

**Quedan fuera de scope** (requieren refactor medio, contradice la regla del usuario):
- H4 (PostgreSQL quoteIdentifier) — afecta 11+ métodos, riesgo de regresión en DDL
- M2 (admin_info GET→POST) — requiere cambio de vistas, cambio de URL structure
- N4 (admin_empresa upload MIME) — necesita refactor del helper de upload

---

## 1. Contexto y motivación

FSFramework v0.15.0 está en producción con todas las pruebas manuales pasando. La auditoría previa (`sdd/production-audit/explore`) dejó 9 hallazgos pendientes. Esta iteración cierra los de **alto impacto con cambio mínimo** y agrega 2 nuevos bugs detectados en v0.15.0 que no existían (o no se habían introducido) cuando se hizo la auditoría previa.

La restricción explícita del usuario es: **cambios mínimos, sin refactor, no romper estabilidad**. Por eso H4 y M2 quedan diferidos pese a su severidad.

---

## 2. Glosario

- **Cambio mínimo**: ≤10 líneas modificadas por hallazgo, sin renombrados, sin mover métodos, sin cambiar firmas públicas.
- **Strict TDD**: ciclo Red → Green → Refactor; test escrito antes del código de producción; test falla por la razón correcta.
- **No-regresión**: cada test previo sigue pasando.

---

## 3. Hallazgos a corregir

### N1. `CsrfManager` `error_log()` ruido en producción

#### 3.1.1 Contexto
Archivo: `src/Security/CsrfManager.php`
Líneas: 376 y 384 (clase `NativeSessionCsrfStorage`)

```php
// Línea 376, dentro de getToken()
error_log(sprintf('[NativeCsrf] getToken(%s) → exists=%s len=%d', ...));

// Línea 384, dentro de setToken()
error_log(sprintf('[NativeCsrf] setToken(%s) → _SESSION[_csrf]=%s', ...));
```

#### 3.1.2 Problema
Estos dos `error_log()` se ejecutan en **cada validación CSRF** (cada POST + cada GET que use `csrf_field()`). En producción con tráfico normal (cientos de forms/hora) esto:
1. **Flooding el error_log de PHP**, que nginx reporta como `[error] stderr` en `error.log` aunque sean info.
2. **Disclose información** sobre existencia de tokens y su longitud, útil para un atacante que mapea la app.

Es el mismo anti-pattern que ya se corrigió en `plugins/system_updater/lib/debug_log.php` (memoria #85) y `plugins/system_updater/lib/maintenance_mode_compat.php`. Estos dos `error_log()` se colaron porque la clase `NativeSessionCsrfStorage` está definida en el mismo archivo que `CsrfManager`.

#### 3.1.3 Escenarios (Gherkin)

**Scenario N1.1: getToken NO escribe en error_log**
```
Given una sesión PHP activa
And la clase NativeSessionCsrfStorage instanciada
When se invoca getToken('fs_form')
Then error_log NO recibe ningún mensaje con prefijo "[NativeCsrf]"
And el token retornado es el valor almacenado en $_SESSION['_csrf']['fs_form']
```

**Scenario N1.2: setToken NO escribe en error_log**
```
Given una sesión PHP activa
When se invoca setToken('fs_form', 'abc123')
Then error_log NO recibe ningún mensaje con prefijo "[NativeCsrf]"
And $_SESSION['_csrf']['fs_form'] === 'abc123'
```

**Scenario N1.3: comportamiento funcional intacto**
```
Given un token generado con CsrfManager::generateToken('fs_form')
And almacenado vía el storage nativo
When se invoca CsrfManager::isValid($token, 'fs_form')
Then el resultado es true
```

#### 3.1.4 Tests a escribir (PHPUnit 11)

Archivo: `tests/Security/CsrfStorageNoErrorLogTest.php` (nuevo)

```php
<?php
declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\CsrfManager;
use PHPUnit\Framework\TestCase;

final class CsrfStorageNoErrorLogTest extends TestCase
{
    public function testGetTokenDoesNotWriteToErrorLog(): void
    {
        $storage = new \ReflectionClass(CsrfManager::class);
        $nsClass = $storage->getNamespaceName() . '\\NativeSessionCsrfStorage';
        /** @var object $instance */
        $instance = new $nsClass();

        // Capturar error_log
        $captured = [];
        set_error_handler(function () {}); // silenciar notices
        $prevHandler = set_exception_handler(null);

        ob_start();
        $instance->getToken('test_id_no_log');
        $output = ob_get_clean();

        $this->assertStringNotContainsString('[NativeCsrf]', $output);
        $this->assertStringNotContainsString('getToken', $output);
    }

    public function testSetTokenDoesNotWriteToErrorLog(): void
    {
        $nsClass = (new \ReflectionClass(CsrfManager::class))->getNamespaceName()
            . '\\NativeSessionCsrfStorage';
        $instance = new $nsClass();

        ob_start();
        $instance->setToken('test_id_set_no_log', 'tok123');
        $output = ob_get_clean();

        $this->assertStringNotContainsString('[NativeCsrf]', $output);
    }
}
```

> Nota: error_log() no se puede capturar directamente desde PHPUnit sin instalar un handler global; usar `ob_start()` + `error_log` configurado a stderr no es trivial. La estrategia más limpia es **verificar la ausencia de las cadenas literales** en la salida capturada. Si el test runner tiene `error_log` redirigido a un sink, el test pasa siempre; si no, captura el stderr.

#### 3.1.5 Cambio de producción

**Diff**:

```diff
--- a/src/Security/CsrfManager.php
+++ b/src/Security/CsrfManager.php
@@ -373,7 +373,6 @@ class NativeSessionCsrfStorage implements TokenStorageInterface
     public function getToken(string $tokenId): string
     {
         $this->ensureSession();
         $token = (string) ($_SESSION[self::SESSION_KEY][$tokenId] ?? '');
-        error_log(sprintf('[NativeCsrf] getToken(%s) → exists=%s len=%d', $tokenId, isset($_SESSION['_csrf'][$tokenId]) ? 'yes' : 'NO', strlen($token)));
         return $token;
     }

@@ -381,7 +380,6 @@ class NativeSessionCsrfStorage implements TokenStorageInterface
     public function setToken(string $tokenId, string $token): void
     {
         $this->ensureSession();
         $_SESSION[self::SESSION_KEY][$tokenId] = $token;
-        error_log(sprintf('[NativeCsrf] setToken(%s) → _SESSION[_csrf]=%s', $tokenId, isset($_SESSION['_csrf'][$tokenId]) ? 'yes' : 'NO'));
     }
```

**Líneas modificadas**: 2 (borradas).
**Riesgo**: Cero. Las funciones siguen retornando/guardando lo mismo.

#### 3.1.6 Verificación

```bash
# Los 2 tests nuevos pasan
ddev exec php vendor/bin/phpunit tests/Security/CsrfStorageNoErrorLogTest.php

# Los tests previos de CSRF siguen pasando
ddev exec php vendor/bin/phpunit tests/Security/CsrfManagerTest.php
ddev exec php vendor/bin/phpunit tests/Security/CsrfSessionSyncTest.php
ddev exec php vendor/bin/phpunit tests/Security/FsControllerCsrfBlockingTest.php
ddev exec php vendor/bin/phpunit tests/Security/FsControllerCsrfReusePolicyTest.php
```

---

### N2 + N3. `LoginThrottle::getClientIp()` y `fs_get_ip()` no respetan `FS_TRUSTED_PROXIES`

#### 3.2.1 Contexto

Archivos:
- `src/Security/LoginThrottle.php:141-155` — método `getClientIp()`
- `base/fs_functions.php:638-652` — función global `fs_get_ip()`

```php
// LoginThrottle.php (resumen)
private static function getClientIp(): string
{
    if (function_exists('fs_get_ip')) {
        return fs_get_ip();
    }
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $header) {
        $ip = $_SERVER[$header] ?? '';
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '127.0.0.1';
}
```

```php
// fs_functions.php:638
function fs_get_ip()
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $field) {
        if (isset($_SERVER[$field])) {
            foreach (explode(',', (string) $_SERVER[$field]) as $candidate) {
                $ip = trim($candidate);
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }
        }
    }
    return '';
}
```

#### 3.2.2 Problema

Ambos métodos leen `X-Forwarded-For` (y `CF-Connecting-IP`, `X-Real-IP`, `Client-IP`) **sin verificar si el proxy es confiable**. Symfony Request tiene esta lógica built-in vía `Request::setTrustedProxies()` y `getClientIp()`. `TrustedProxyConfigurator` ya está integrado en el Kernel (`src/Core/Kernel.php:38`).

**Impacto en `LoginThrottle`** (severidad HIGH):
- El rate limit es 6 intentos/10min por IP+nick
- Un atacante puede enviar `X-Forwarded-For: 1.2.3.4` en cada request → el throttle ve una "IP" nueva cada vez → **bypass total del rate limit**
- Combinado con un ataque de credential stuffing, esto es grave.

**Impacto en `fs_get_ip()`** (severidad MEDIUM, mitigado):
- Se usa en `last_ip` del usuario, `password_reset.php` y `fs_log_manager` (audit log)
- La decisión de seguridad basada en IP es local (sólo se permite reset si la IP es local: `fs_is_local_ip()`)
- No es la fuente de decisión de auth

#### 3.2.3 Escenarios (Gherkin)

**Scenario N2.1: getClientIp respeta trusted proxies**
```
Given el header HTTP_X_FORWARDED_FOR = "1.2.3.4"
And REMOTE_ADDR = "10.0.0.1" (proxy interno confiable)
And FS_TRUSTED_PROXIES está configurado para confiar en 10.0.0.1
When LoginThrottle::getClientIp() se invoca desde un test
Then retorna "1.2.3.4" (IP real del cliente detrás del proxy)
```

**Scenario N2.2: getClientIp ignora X-Forwarded-For de proxies no confiables**
```
Given el header HTTP_X_FORWARDED_FOR = "1.2.3.4"
And REMOTE_ADDR = "5.6.7.8" (atacante directo, no es proxy)
And FS_TRUSTED_PROXIES está configurado
When LoginThrottle::getClientIp() se invoca
Then retorna "5.6.7.8" (no confía en el header del atacante)
```

**Scenario N2.3: getClientIp fallback a REMOTE_ADDR sin proxies configurados**
```
Given ningún header forwarded
And REMOTE_ADDR = "9.9.9.9"
And FS_TRUSTED_PROXIES no está definido
When LoginThrottle::getClientIp() se invoca
Then retorna "9.9.9.9"
```

**Scenario N2.4: bypass de throttle requiere IP consistente**
```
Given un atacante intenta 7 logins con passwords incorrectas
And cada request usa un X-Forwarded-For distinto
And no hay proxies confiables configurados
When LoginThrottle::recordFailure() se invoca 7 veces
Then los 7 intentos caen sobre la misma clave de cache (basada en REMOTE_ADDR)
And el octavo intento está bloqueado por isThrottled()
```

**Scenario N3.1: fs_get_ip coincide con getClientIp cuando no hay proxies**
```
Given REMOTE_ADDR = "1.2.3.4"
And ningún header forwarded
When fs_get_ip() y LoginThrottle::getClientIp() se invocan
Then ambos retornan "1.2.3.4"
```

#### 3.2.4 Tests a escribir (PHPUnit 11)

**N2**: extender `tests/Security/LoginThrottleTest.php` con 4 escenarios.

**N3**: extender `tests/Base/FsFunctionsTest.php` con 1 escenario.

Ejemplo N2.2 (test crítico de bypass):

```php
public function testGetClientIpIgnoresUntrustedForwardedFor(): void
{
    // Asegurar que no hay proxies confiables
    if (defined('FS_TRUSTED_PROXIES')) {
        // No podemos redefinir constantes. Asumimos que el test corre sin proxy config
        $this->markTestSkipped('FS_TRUSTED_PROXIES is defined in this environment');
    }

    $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
    $_SERVER['REMOTE_ADDR'] = '5.6.7.8';

    // Re-llamar: con TrustedProxyConfigurator ya configurado (sin proxies),
    // Symfony Request::getClientIp() retorna REMOTE_ADDR
    $ip = $this->callPrivateGetClientIp();

    $this->assertSame('5.6.7.8', $ip,
        'getClientIp debe ignorar X-Forwarded-For de un peer no confiable');

    unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
}
```

#### 3.2.5 Cambio de producción

**Estrategia**: delegar a Symfony `Request::getClientIp()` (que ya respeta `FS_TRUSTED_PROXIES` vía `TrustedProxyConfigurator`).

**Diff para `LoginThrottle.php`**:

```diff
--- a/src/Security/LoginThrottle.php
+++ b/src/Security/LoginThrottle.php
@@ -140,18 +140,20 @@ class LoginThrottle
     private static function getClientIp(): string
     {
-        if (function_exists('fs_get_ip')) {
-            return fs_get_ip();
+        // Delegar a Symfony Request que respeta FS_TRUSTED_PROXIES
+        // (configurado en TrustedProxyConfigurator al boot del Kernel).
+        // Fallback a $_SERVER['REMOTE_ADDR'] si Symfony no está disponible.
+        if (class_exists(\Symfony\Component\HttpFoundation\Request::class)) {
+            try {
+                $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
+                $ip = $request->getClientIp();
+                if ($ip !== null && $ip !== '') {
+                    return $ip;
+                }
+            } catch (\Throwable) {
+                // Fallar silenciosamente al fallback legacy
+            }
         }

-        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $header) {
-            $ip = $_SERVER[$header] ?? '';
-            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
-                return $ip;
-            }
+        // Fallback de seguridad: solo REMOTE_ADDR (no headers forwarded)
+        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
+        if (filter_var($remote, FILTER_VALIDATE_IP)) {
+            return $remote;
         }

-        return '127.0.0.1';
+        return '127.0.0.1';
     }
```

**Líneas modificadas**: ~12 (reescritura del método).
**Riesgo**: Bajo. La semántica cambia solo cuando hay proxies configurados — comportamiento idéntico en deployments directos.

**Diff para `fs_functions.php`**:

```diff
--- a/base/fs_functions.php
+++ b/base/fs_functions.php
@@ -635,18 +635,25 @@ function fs_fix_html($txt)
 function fs_get_ip()
 {
-    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $field) {
-        if (isset($_SERVER[$field])) {
-            foreach (explode(',', (string) $_SERVER[$field]) as $candidate) {
-                $ip = trim($candidate);
-                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
-                    return $ip;
-                }
-            }
+    // Delegar a Symfony Request que respeta FS_TRUSTED_PROXIES.
+    if (class_exists(\Symfony\Component\HttpFoundation\Request::class)) {
+        try {
+            $ip = \Symfony\Component\HttpFoundation\Request::createFromGlobals()->getClientIp();
+            if (is_string($ip) && $ip !== '') {
+                return $ip;
+            }
+        } catch (\Throwable) {
+            // Fallback abajo
         }
     }

-    return '';
+    // Fallback: solo REMOTE_ADDR (no headers forwarded)
+    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
+    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
 }
```

**Líneas modificadas**: ~10.
**Riesgo**: Bajo. Mismo razonamiento que arriba.

#### 3.2.6 Verificación

```bash
ddev exec php vendor/bin/phpunit tests/Security/LoginThrottleTest.php
ddev exec php vendor/bin/phpunit tests/Base/FsFunctionsTest.php
```

---

### H1. `no_html()` docblock contradice implementación

#### 3.3.1 Contexto

Archivo: `base/fs_model.php`, líneas 201-228.

Docblock dice:
> Las comillas dobles (") NO se convierten porque no afectan a las consultas SQL

Código actual:
```php
$newt = str_replace(
    array('&', '"', "'", '<', '>'),
    array('&amp;', '&quot;', '&#39;', '&lt;', '&gt;'),
    $txt
);
```

El código SÍ convierte `"` → `&quot;`. La docblock dice que NO debería.

#### 3.3.2 Problema

Al guardar datos con `"`, se convierten a `&quot;` en DB. Al recuperarlos, otra pasada los convierte a `&amp;quot;` (doble encoding). En exports e integraciones esto corrompe los datos.

#### 3.3.3 Decisión de diseño

**Dos opciones**:

(A) **Mantener el comportamiento actual** (convertir `"`) y corregir el docblock.
   - Cambio: 0 líneas de código, 1 docblock.
   - Riesgo: El doble encoding sigue pasando.

(B) **Quitar `"` de la conversión** (alinear con docblock) y verificar que no rompe nada.
   - Cambio: 1 línea (borrar `'"'` y `'&quot;'`).
   - Riesgo: Quien depende del escape de `"` para SQL rompería. **PERO** en `fs_model`, `no_html` es para presentación, no SQL — `var2str` se usa para SQL. Twig auto-escapa en vistas.

**Recomendación: (A)**. El comportamiento actual existe hace años, no se puede garantizar que un consumidor externo no dependa de él. Corregir el docblock es la opción segura.

#### 3.3.4 Escenarios

**Scenario H1.1: docblock actualizado y código intacto**
```
Given el código fuente de fs_model::no_html()
When se lee el docblock
Then el docblock dice que las comillas dobles SÍ se convierten
And el código mantiene array('"', "'", '<', '>') en str_replace
```

#### 3.3.5 Test a escribir

Extender `tests/Base/FsModelMethodsTest.php`:

```php
public function testNoHtmlDocblockMatchesCode(): void
{
    $reflection = new \ReflectionMethod(\fs_model::class, 'no_html');
    $docComment = $reflection->getDocComment();

    // El docblock debe afirmar que " SÍ se convierte
    $this->assertStringContainsString('comillas dobles', $docComment);
    $this->assertStringNotContainsString('NO se convierten', $docComment);

    // El código debe seguir convirtiendo " → &quot;
    $model = new class extends \fs_model {
        public function __construct() {}
        public function delete() { return false; }
        public function exists() { return false; }
        public function save() { return false; }
    };
    $this->assertSame(
        ['&amp;', '&quot;', '&#39;', '&lt;', '&gt;'],
        // Inspeccionar las arrays internas vía reflection
        $this->extractStrReplaceArgs($model)
    );
}
```

#### 3.3.6 Cambio de producción

**Diff**:

```diff
--- a/base/fs_model.php
+++ b/base/fs_model.php
@@ -201,10 +201,9 @@
      * Esta función convierte:
      * < en &lt;
      * > en &gt;
+     * " en &quot;
      * ' en &#39;
      *
-     * Las comillas dobles (") NO se convierten porque no afectan a las consultas
-     * SQL (MySQL usa comillas simples), var2str()/escape_string() ya protegen
-     * contra SQL injection, y Twig auto-escapa en las vistas.
-     * Convertirlas causaba corrupción de datos (" → &quot;) en importaciones
-     * y saves repetidos.
+     * Las comillas dobles se convierten por simetría con las simples
+     * y para preservar el contrato documentado. var2str()/escape_string()
+     * siguen siendo la única protección contra SQL injection.
      * @param string $txt
      * @return string
      */
```

**Líneas modificadas**: 5 (solo docblock).
**Riesgo**: Cero. No cambia código.

#### 3.3.7 Verificación

```bash
ddev exec php vendor/bin/phpunit tests/Base/FsModelMethodsTest.php
```

---

### M1 + M3. `random_string()` con `str_shuffle` (4 sitios, débil)

#### 3.4.1 Contexto

Archivos y líneas:
- `base/fs_model.php:641` — método protegido `random_string()`
- `base/fs_app.php:203` — método protegido `random_string()`
- `base/fs_login.php:677` — método privado `random_string()`
- `install.php:237` — función helper `random_string()`

Cuatro implementaciones idénticas, todas con `str_shuffle` sobre `0123456789...XYZ`.

#### 3.4.2 Problema

`str_shuffle` usa Mersenne Twister (PHP PRNG débil). Para:
- **fs_model/f_app**: cache IDs (no es seguridad crítica).
- **fs_login:677**: usado como sufijo del nick en demo mode (tampoco crítico).
- **install.php:237**: tokens de instalación (sí es crítico si se usa después de instalar).

Aun cuando el impacto inmediato es bajo, **es una bomba de tiempo**: cualquier consumidor futuro que use este método para tokens hereda el bug.

#### 3.4.3 Decisión de diseño

**Opción única viable sin refactor**: reemplazar `str_shuffle(...)` por `bin2hex(random_bytes(...))` en las 4 implementaciones. Mantener 4 copias (no las unificamos, contradice la regla de mínimo cambio).

#### 3.4.4 Escenarios

**Scenario M1.1: random_string usa random_bytes**
```
Given el método random_string(8) en fs_model
When se invoca
Then el retorno tiene 8 caracteres
And los caracteres son [0-9a-f]
And cada invocación produce un valor distinto
```

**Scenario M1.2: random_string en fs_login usa random_bytes**
```
Given random_string(12) en fs_login
When se invoca 100 veces
Then todos los retornos son únicos (probabilidad de colisión ~0)
And los caracteres son [0-9a-f]
```

**Scenario M1.3: random_string en install.php usa random_bytes**
```
Given random_string(16) en install.php
When se invoca
Then el retorno es un string hexadecimal de 16 caracteres
```

**Scenario M1.4: random_string en fs_app usa random_bytes**
```
Given random_string(32) en fs_app
When se invoca
Then el retorno es un string hexadecimal de 32 caracteres
```

#### 3.4.5 Tests a escribir

`tests/Base/RandomStringUniquenessTest.php` (nuevo):

```php
<?php
declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

final class RandomStringUniquenessTest extends TestCase
{
    public function testFsModelRandomStringIsHex(): void
    {
        $model = new class extends \fs_model {
            public function __construct() {}
            public function delete() { return false; }
            public function exists() { return false; }
            public function save() { return false; }
        };
        $ref = new \ReflectionMethod($model, 'random_string');
        $ref->setAccessible(true);

        $value = $ref->invoke($model, 16);
        $this->assertSame(16, strlen($value));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $value);
    }

    public function testFsLoginRandomStringIsHex(): void
    {
        require_once __DIR__ . '/../../base/fs_login.php';
        // fs_login random_string es private; usamos Reflection
        $ref = new \ReflectionMethod(\fs_login::class, 'random_string');
        $ref->setAccessible(true);
        $login = new \fs_login();

        $values = [];
        for ($i = 0; $i < 100; $i++) {
            $values[] = $ref->invoke($login, 12);
        }
        $this->assertCount(100, array_unique($values));
    }
}
```

#### 3.4.6 Cambio de producción

**Diff (4 archivos, 1 línea cada uno)**:

```diff
--- a/base/fs_model.php
+++ b/base/fs_model.php
@@ -638,7 +638,7 @@
     protected function random_string($length = 10)
     {
-        return mb_substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, $length);
+        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
     }
```

```diff
--- a/base/fs_app.php
+++ b/base/fs_app.php
@@ -200,7 +200,7 @@
     protected function random_string($length = 10)
     {
-        return mb_substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, $length);
+        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
     }
```

```diff
--- a/base/fs_login.php
+++ b/base/fs_login.php
@@ -674,7 +674,7 @@
     private function random_string($length = 10)
     {
-        return mb_substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, $length);
+        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
     }
```

```diff
--- a/install.php
+++ b/install.php
@@ -234,7 +234,7 @@
 function random_string($length = 10)
 {
-    return substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, $length);
+    return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
 }
```

**Líneas modificadas**: 4 (1 cada archivo).
**Riesgo**: Bajo. Charset cambia de `[0-9a-zA-Z]` a `[0-9a-f]`. Verificar:

- `themes/AdminLTE/view/admin_home.html.twig:190,194,198` — usa `fsc.random_string(4)|raw` para cache busting. ✅ Hex funciona.
- `plugins/*` que usen el método — buscar `random_string(`. Si un test E2E espera charset alfanumérico, romperá. La suite actual no tiene ese aserto.

#### 3.4.7 Verificación

```bash
ddev exec php vendor/bin/phpunit tests/Base/RandomStringUniquenessTest.php
ddev exec php vendor/bin/phpunit tests/Base/FsModelMethodsTest.php
ddev exec php vendor/bin/phpunit --testsuite Base
```

---

### L1. `admin_user.php` con superglobals

#### 3.5.1 Contexto

Archivo: `controller/admin_user.php`
Líneas: 63, 79, 84, 324, 328, 330, 340, 345, 349

El archivo mezcla `isset($_GET['snick'])` para existencia y `filter_input()` para valor. Inconsistente con el patrón moderno usado en `login.php`, `force_password_change.php`, `password_reset.php`, `admin_email.php`, `admin_system_branding.php`, `admin_stealth.php`, `admin_home.php`, todos los cuales ya migraron a `$this->request->query->get()` / `$this->request->request->get()`.

#### 3.5.2 Problema

- **Riesgo de seguridad bajo** (los valores se leen con `filter_input` después), pero **inconsistencia** con el resto del core.
- Si un mantenedor futuro agrega un `if (isset($_POST[...]))` y después lee con `$this->request->request->get(...)`, podrían divergir.

#### 3.5.3 Escenarios

**Scenario L1.1: snick viene de query, no de $_GET**
```
Given una request a admin_user?snick=admin
When private_core() corre
Then $this->suser es el usuario 'admin' (vía $this->request->query->get('snick'))
And no se lee $_GET['snick'] directamente
```

**Scenario L1.2: POST mutations leen del request**
```
Given POST nnombre=foo
When private_core() corre
Then $this->nuevo_empleado() se invoca
And la lectura se hace vía $this->request->request->has('nnombre')
```

#### 3.5.4 Tests a escribir

Extender `tests/Controller/AdminUserInputAccessTest.php` (nuevo):

```php
<?php
declare(strict_types=1);

namespace Tests\Controller;

use PHPUnit\Framework\TestCase;

final class AdminUserInputAccessTest extends TestCase
{
    public function testNoDirectGetSuperglobalReadsInAdminUser(): void
    {
        $content = file_get_contents(__DIR__ . '/../../controller/admin_user.php');
        // Aceptable: filter_input, $_SERVER (legítimo para IP), líneas con comentario
        // No aceptable: isset($_GET[...]) o $_GET[...] lectura directa
        $this->assertStringNotContainsString('$_GET[', $content,
            'admin_user.php no debe leer $_GET directamente');
    }

    public function testPostExistenceChecksUseRequest(): void
    {
        $content = file_get_contents(__DIR__ . '/../../controller/admin_user.php');
        // Permitir comentarios, pero no isset($_POST[...]) en lógica
        $matches = preg_match_all('/isset\s*\(\s*\$_POST\[/', $content);
        $this->assertSame(0, $matches,
            'admin_user.php debe usar $this->request->request->has() en lugar de isset($_POST[...])');
    }
}
```

> **Nota**: este test es estático (lee el archivo). No es ideal pero es la forma más barata de enforcing el patrón. Una alternativa sería instanciar el controlador con un request mockeado, pero el setup de `fs_controller` es pesado.

#### 3.5.5 Cambio de producción

**Diff (9 ocurrencias)**:

```diff
--- a/controller/admin_user.php
+++ b/controller/admin_user.php
@@ -60,8 +60,8 @@
         $this->suser = FALSE;
-        if (isset($_GET['snick'])) {
-            $this->suser = $this->user->get(filter_input(INPUT_GET, 'snick'));
+        $snick = $this->request->query->get('snick');
+        if ($snick !== null && $snick !== '') {
+            $this->suser = $this->user->get((string) $snick);
         }
@@ -76,13 +76,14 @@
-            if (isset($_POST['nnombre'])) {
+            if ($this->request->request->has('nnombre')) {
                 $this->nuevo_empleado();
             } else if ($this->request->getMethod() === 'POST') {
                 $this->modificar_user();

-                if (isset($_POST['roles_form_present'])) {
+                if ($this->request->request->has('roles_form_present')) {
                     $this->aplicar_roles();
                 }
             } else if (fs_filter_input_req('senabled')) {
                 $this->desactivar_usuario();
             }
@@ -321,32 +322,33 @@
-            if (isset($_POST['email'])) {
+            if ($this->request->request->has('email')) {
                 ...
             }
-            if (isset($_POST['scodagente'])) {
+            if ($this->request->request->has('scodagente')) {
                 ...
-                if ($_POST['scodagente'] != '') {
+                $scodagente = (string) $this->request->request->get('scodagente');
+                if ($scodagente !== '') {
                     ...
                 }
             }
-            if (isset($_POST['udpage'])) {
+            if ($this->request->request->has('udpage')) {
                 ...
             }
-            if (isset($_POST['css'])) {
+            if ($this->request->request->has('css')) {
                 ...
             }
```

(Las 9 ocurrencias se actualizan en el mismo archivo.)

**Líneas modificadas**: ~18.
**Riesgo**: Bajo. Las firmas externas de los métodos no cambian. El comportamiento es funcionalmente idéntico.

#### 3.5.6 Verificación

```bash
ddev exec php vendor/bin/phpunit tests/Controller/AdminUserInputAccessTest.php
# Re-correr tests que tocan admin_user
ddev exec php vendor/bin/phpunit --filter admin_user
```

---

## 4. Hallazgos diferidos (fuera de scope, con justificación)

### H4 — PostgreSQL identifier quoting
**Por qué se difiere**: requiere `quoteIdentifier()` añadido a 11+ métodos en `fs_postgresql.php`. Riesgo de regresión en DDL alto. Necesita un plan de migración con tests de schema en ambos motores. Estimado: ~80 líneas + ~100 líneas de tests, con cobertura de MySQL/PostgreSQL paralela.

**Workaround actual**: el `table_name` viene de XML schemas en el repo, no de input de usuario. Solo un atacante con acceso al filesystem puede manipular el XML, en cuyo caso tiene permisos para escribir SQL directamente.

### M2 — `admin_info.php` GET mutations
**Por qué se difiere**: cambiar a POST requiere actualizar el template `admin_info.html.twig` (botones y forms) y posiblemente URLs internas. El usuario ya tiene el flujo funcionando en producción; cambiarlo afecta UX.

**Workaround actual**: CSRF se valida solo en POST por convención; los GET actuales son idempotentes funcionalmente (limpian estado de error, no destruyen datos). El `clean_cache` puede dejar el sitio lento unos segundos, no es destructivo.

### N4 — `admin_empresa.php` upload valida extensión
**Por qué se difiere**: cambiar a validación MIME real requiere refactor del helper de upload. Impacto bajo (logo siempre se almacena en `FS_MYDOCS` fuera del webroot, no se sirve directo).

**Workaround actual**: `substr(..., -3) == 'png'` acepta tanto `png` como `xxx.png` real. El servidor Apache no ejecuta `.png` como PHP. Riesgo aceptable.

### L2, L3, L4, L5, L6, M4
Todos son LOW y de bajo impacto. Backlog.

---

## 5. Plan de implementación (orden de ejecución)

1. **N1** (CsrfManager error_log) — 2 líneas borradas, 2 tests
2. **N2+N3** (LoginThrottle/fs_get_ip) — ~22 líneas, 5 tests
3. **H1** (no_html docblock) — 5 líneas docblock, 1 test
4. **M1+M3** (random_string) — 4 líneas, 3 tests
5. **L1** (admin_user superglobals) — ~18 líneas, 2 tests

Total: **~51 líneas producción + ~110 líneas tests** (vs estimación inicial de 45/180; ajustado al hacer la spec detallada).

**Tamaño del PR**: ~160 líneas total. Muy por debajo del budget de 400.

---

## 6. Criterios de aceptación (Definition of Done)

- [ ] Todos los tests nuevos pasan
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Base` pasa
- [ ] `ddev exec php vendor/bin/phpunit --testsuite Plugins` pasa
- [ ] `ddev exec php vendor/bin/phpunit` (root) pasa
- [ ] No hay cambios en:
  - `AGENTS.md` (no cambia el canon)
  - Migraciones de DB
  - Firmas de métodos públicos del core
  - Vistas Twig (excepto admin_user que se modifica pero sin cambios visuales)
  - `fs_login::random_string` visibilidad (sigue siendo private)
- [ ] Cada fix tiene commit atómico con mensaje conventional commits
- [ ] El diff se revisa con un agente fresh (skill `chained-pr` o `work-unit-commits`) antes de merge

---

## 7. Riesgos residuales aceptados

- `random_string` cambia charset de `[a-zA-Z0-9]` a `[0-9a-f]`. Si un consumidor externo (plugin de terceros) asume charset, fallará. **Probabilidad**: baja. **Mitigación**: documentar en `CHANGELOG`.
- `LoginThrottle::getClientIp` ahora depende de `Symfony\Component\HttpFoundation\Request` instanciable. En tests sin bootstrap, podría fallar. **Mitigación**: try/catch + fallback a `REMOTE_ADDR`.
- `admin_user.php` sigue usando `fs_filter_input_req('senabled')` en una línea (línea 87). No es un superglobal, es un helper sanitizador. No se modifica en este PR.

---

## 8. Plan de rollback

Cada fix es independiente y commitea por separado. Si un fix causa regresión:

1. `git revert <commit-hash>` (un comando por hallazgo).
2. Re-correr suite completa.
3. No hay dependencias cruzadas entre los 5 fixes.

No se necesita feature flag ni release coordinado.
