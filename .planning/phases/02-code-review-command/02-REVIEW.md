---
phase: 02-code-review-command
reviewed: 2026-05-18T12:00:00Z
depth: deep
files_reviewed: 12
files_reviewed_list:
  - src/Security/CsrfManager.php
  - src/Security/SafeRedirect.php
  - src/Security/SecurityHeaders.php
  - base/fs_controller.php
  - base/fs_schema.php
  - controller/admin_agentes.php
  - controller/admin_home.php
  - controller/force_password_change.php
  - controller/login.php
  - plugins/clientes_core/controller/ventas_clientes.php
  - plugins/clientes_core/model/core/cliente.php
  - themes/AdminLTE/view/header.html.twig
findings:
  critical: 4
  warning: 5
  info: 3
  total: 12
status: issues_found
---

# Phase 02: Code Review Report

**Reviewed:** 2026-05-18T12:00:00Z
**Depth:** deep
**Files Reviewed:** 12
**Status:** issues_found

## Summary

This review investigated 5 reported issues in FSFramework. All 5 were confirmed as genuine bugs or security gaps. The review uncovered 4 critical issues (fatal error, missing CSRF enforcement, double CSRF validation, silent data loss), 5 warnings (CSP gaps, missing input sanitization, dead code, inconsistent CSRF checking), and 3 informational findings.

The most severe finding is that `SafeRedirect::redirect()` does not exist as a method — causing fatal errors in production. The CSRF system has a double-validation anti-pattern where `pre_private_core()` consumes the token, then individual controller checks fail because the token is already removed from storage. Client creation fails silently because error messages from the model are never propagated to the controller.

## Critical Issues

### CR-01: Fatal Error — SafeRedirect::redirect() Method Does Not Exist

**File:** `controller/admin_agentes.php:108`, `controller/admin_agentes.php:127`, `controller/force_password_change.php:99`

**Issue:** Three call sites invoke `SafeRedirect::redirect()`, but this static method does not exist in `src/Security/SafeRedirect.php`. The class only exposes `validate()` and `getFromRequest()`. This causes a fatal error at runtime whenever an agent is successfully created, updated, or a password is changed.

The AGENTS.md documentation (lines 772-779) describes `SafeRedirect::redirect()` as the intended API, but the implementation was never written. The documentation and implementation are out of sync.

**Impact:** Fatal error crashes the application on every successful agent create/update and password change. Users cannot manage agents.

**Fix:**
```php
// In controller/admin_agentes.php, replace lines 108 and 127:
// BEFORE:
\FSFramework\Security\SafeRedirect::redirect($agente_obj->url(), 'index.php?page=admin_agentes');

// AFTER:
$safeUrl = \FSFramework\Security\SafeRedirect::validate($agente_obj->url(), 'index.php?page=admin_agentes');
header('Location: ' . $safeUrl);
exit();

// In controller/force_password_change.php, replace line 99:
// BEFORE:
SafeRedirect::redirect('index.php');

// AFTER:
header('Location: index.php');
exit();

// Alternatively, implement the missing method in src/Security/SafeRedirect.php:
public static function redirect(?string $url, string $fallbackUrl = 'index.php', int $httpCode = 302): void
{
    $safeUrl = self::validate($url, $fallbackUrl);
    http_response_code($httpCode);
    header('Location: ' . $safeUrl);
    exit();
}
```

### CR-02: CSRF Double-Validation Anti-Pattern — Token Consumed on First Check, Second Check Always Fails

**File:** `base/fs_controller.php:907`, `plugins/clientes_core/controller/ventas_clientes.php:79,85`

**Issue:** `pre_private_core()` calls `$this->validateCsrf()` at line 907 for every POST request. Inside `validateCsrf()`, the token is validated via `CsrfManager::isValidWithReuseCheck()`, which calls `CsrfManager::isValid()`, which calls Symfony's `CsrfTokenManager::isTokenValid()`. Symfony's `isTokenValid()` **removes the token from storage** as a side effect (one-time use by design).

After `pre_private_core()` consumes the token, `ventas_clientes::private_core()` performs its own CSRF checks at lines 79 and 85:
```php
// Line 79 — ALWAYS fails because token was already consumed:
} else if (filter_input(INPUT_POST, 'action') === 'delete_grupo' 
    && CsrfManager::isValid(filter_input(INPUT_POST, '_csrf_token') ?? '')) {
// Line 85 — ALWAYS fails because token was already consumed:
} else if (filter_input(INPUT_POST, 'action') === 'delete' 
    && CsrfManager::isValid(filter_input(INPUT_POST, '_csrf_token') ?? '')) {
```

These `CsrfManager::isValid()` calls will **always return false** because the token was already removed from the session by `pre_private_core()`. The delete operations for clients and client groups are completely broken — they can never succeed.

**Impact:** Client deletion and group deletion are silently blocked. The form submits, the token passes in `pre_private_core()`, but the controller-level check fails because the token is gone. The user sees the page reload with no error message and no deletion occurs.

**Fix:**
```php
// Option A: Remove the redundant checks in ventas_clientes (preferred)
// Since pre_private_core() already validated the CSRF token, these checks are unnecessary.
// Replace the filter_input checks:
private function private_core()
{
    parent::private_core();

    // ... existing setup code ...

    if (filter_input(INPUT_POST, 'buscar_cliente')) {
        $this->buscar_cliente_json();
    } else if (filter_input(INPUT_POST, 'action') === 'delete_grupo') {
        // CSRF already validated in pre_private_core()
        $this->delete_grupo();
    } else if (filter_input(INPUT_POST, 'nuevo_grupo')) {
        $this->nuevo_grupo();
    } else if (filter_input(INPUT_POST, 'codcliente')) {
        $this->nuevo_cliente();
    } else if (filter_input(INPUT_POST, 'action') === 'delete') {
        // CSRF already validated in pre_private_core()
        $this->delete_cliente();
    } else {
        $this->load_clientes();
    }
}

// Option B: Use requireCsrf() instead of raw CsrfManager::isValid()
// requireCsrf() doesn't double-validate — it checks the request directly
} else if (filter_input(INPUT_POST, 'action') === 'delete' && $this->requireCsrf()) {
    $this->delete_cliente();
}
```

### CR-03: Missing CSRF Validation on Client Creation — Security Bypass

**File:** `plugins/clientes_core/controller/ventas_clientes.php:83`

**Issue:** The `nuevo_cliente()` method at line 83 is triggered when `codcliente` is present in POST data, but there is **no explicit CSRF check** for this code path. While `pre_private_core()` validates the token, it does not block the request on failure (it only logs and sets `$this->csrf_valid = false`). The controller never checks `$this->isCsrfValid()` before processing the client creation.

This means a forged POST request from an external site can create clients without a valid CSRF token, as long as the user is authenticated.

**Impact:** CSRF protection is effectively bypassed for client creation. An attacker can craft a form on an external site that submits to `ventas_clientes` and creates clients in the victim's account.

**Fix:**
```php
private function nuevo_cliente()
{
    if (!$this->isCsrfValid()) {
        $this->new_error_msg('Token de seguridad inválido. No se puede crear el cliente.');
        $this->load_clientes();
        return;
    }

    $cliente = new cliente();
    // ... rest of the method
}
```

### CR-04: Silent Client Creation Failure — Error Messages Lost

**File:** `plugins/clientes_core/controller/ventas_clientes.php:171-188`, `plugins/clientes_core/model/core/cliente.php:402-413`

**Issue:** When `$cliente->save()` fails in `nuevo_cliente()`, the error path is:
1. `save()` calls `test()` (line 496 of cliente.php)
2. `test()` calls `validateFields()` which calls `$this->new_error_msg()` (e.g., line 455-456)
3. The `new_error_msg()` method in `fs_model` logs the error to the model's own `core_log` instance
4. The controller's error display (`fsc.get_errors()`) reads from the **controller's** `core_log` instance
5. The model's errors are never transferred to the controller's error list

The result: the client creation fails (e.g., due to validation), the page reloads, and the user sees no error message. The form data is lost.

**Impact:** Users cannot create clients and receive no feedback about why. This is a data loss scenario — the user fills out the form, clicks save, and the page reloads with no indication of failure.

**Fix:**
```php
private function nuevo_cliente()
{
    $cliente = new cliente();
    $cliente->codcliente = filter_input(INPUT_POST, 'codcliente');
    $cliente->nombre = filter_input(INPUT_POST, 'nombre') ?? '';
    $cliente->razonsocial = filter_input(INPUT_POST, 'razonsocial') ?: filter_input(INPUT_POST, 'nombre') ?? '';
    $cliente->cifnif = filter_input(INPUT_POST, 'cifnif') ?? '';
    $cliente->telefono1 = filter_input(INPUT_POST, 'telefono1') ?? '';
    $cliente->email = filter_input(INPUT_POST, 'email') ?? '';
    $cliente->codgrupo = !empty(filter_input(INPUT_POST, 'codgrupo')) ? filter_input(INPUT_POST, 'codgrupo') : null;

    if ($cliente->save()) {
        header('Location: ' . $cliente->url());
    } else {
        // Transfer model errors to controller
        foreach ($cliente->get_errors() as $error) {
            $this->new_error_msg($error);
        }
        if (empty($cliente->get_errors())) {
            $this->new_error_msg('Error al guardar el cliente. Verifique los datos e inténtelo de nuevo.');
        }
        $this->load_clientes();
    }
}
```

## Warnings

### WR-01: CSP `connect-src 'self'` Blocks CDN Map Files

**File:** `src/Security/SecurityHeaders.php:51`

**Issue:** The CSP policy sets `connect-src 'self'`, but `script-src` allows `https://cdnjs.cloudflare.com` and `https://cdn.jsdelivr.net`. When bootstrap-select or Chart.js tries to fetch source maps or make XHR requests to these CDNs, the browser blocks them due to `connect-src` restriction. This produces console errors:
```
Refused to connect to 'https://cdn.jsdelivr.net/npm/bootstrap-select@1.13.14/dist/js/bootstrap-select.min.js.map' 
because it violates the following Content Security Policy directive: "connect-src 'self'"
```

**Fix:**
```php
// In SecurityHeaders.php, line 51:
// BEFORE:
"connect-src 'self'",
// AFTER:
"connect-src 'self' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net",
```

### WR-02: CSP Uses `unsafe-inline` Despite Nonce Infrastructure

**File:** `src/Security/SecurityHeaders.php:23`

**Issue:** The CSP policy includes `'unsafe-inline'` in `script-src` and `style-src`, even though the codebase generates nonces via `SecurityHeaders::nonce()` and applies them via `csp_nonce_attr()` in templates. The `unsafe-inline` directive completely negates the security benefit of nonces — any injected inline script will execute because `unsafe-inline` allows all inline scripts.

The comment at line 19-21 acknowledges this is intentional for now, but it should be tracked as technical debt.

**Fix:** Once all inline scripts use nonces (templates already do), remove `'unsafe-inline'`:
```php
private const SCRIPT_SRC_DIRECTIVE = "script-src 'self' 'nonce-{NONCE}' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://cdn.tailwindcss.com";
// Replace {NONCE} at runtime with the actual nonce value
```

### WR-03: admin_home Controller Does Not Enforce CSRF on State-Changing POST Operations

**File:** `controller/admin_home.php:347-369`

**Issue:** The `exec_actions()` method processes several state-changing POST operations without checking `$this->isCsrfValid()`:
- `modpages` (line 347) — enables/disables pages
- `install` (line 359) — installs plugins
- `cancel_pending_install` (line 340) — cancels pending installs

While `pre_private_core()` validates the token, it does not block execution on failure (it only sets `$this->csrf_valid = false`). The controller should check this flag before processing mutations.

**Fix:**
```php
private function exec_actions()
{
    // ... existing GET-based actions ...

    // Before processing POST mutations, check CSRF
    if ($this->request->isMethod('POST') && !$this->isCsrfValid()) {
        $this->new_error_msg('Token de seguridad inválido. La operación fue rechazada.');
        return;
    }

    // ... rest of the method
}
```

### WR-04: ventas_clientes Uses `$_GET` Directly Instead of Filtered Input

**File:** `plugins/clientes_core/controller/ventas_clientes.php:53,59,65`

**Issue:** The controller accesses `$_GET` directly instead of using `fs_filter_input_req()` or the Symfony Request object:
```php
if (isset($_GET['offset'])) {
    $this->offset = intval($_GET['offset']);
}
// ...
if (isset($_GET['orden'])) {
    if (isset($ordenes[$_GET['orden']])) {
```

While `intval()` provides type safety for `offset`, the `orden` value is used in SQL ORDER BY clauses. The whitelist check (`$ordenes` array) prevents injection, but direct `$_GET` access bypasses the framework's input sanitization layer.

**Fix:**
```php
$offset = fs_filter_input_req('offset', '0');
$this->offset = intval($offset);

$orden = fs_filter_input_req('orden', '');
if (isset($ordenes[$orden])) {
    $this->orden = $ordenes[$orden];
}
```

### WR-05: Foreign Key `ca_clientes_grupos` References Table That May Not Exist at Schema Sync Time

**File:** `plugins/clientes_core/model/table/clientes.xml:204-209`, `base/fs_schema.php:434`

**Issue:** The `clientes.xml` schema defines a foreign key `ca_clientes_grupos` referencing `gruposclientes`. The `gruposclientes` table is defined in `plugins/clientes_core/model/table/gruposclientes.xml`. During schema sync, if the `gruposclientes` table hasn't been created yet (e.g., plugin installation order, partial install), the foreign key is silently omitted with the warning:
```
Advertencia: Foreign key 'ca_clientes_grupos' omitida - tabla referenciada 'gruposclientes' no existe
```

This is a design issue: the foreign key validation in `fs_schema::addForeignKeyConstraint()` (line 434) checks `$db->table_exists($refTable)` at sync time. If the referenced table doesn't exist yet, the FK is permanently skipped until the next full schema re-sync.

**Fix:** Ensure `gruposclientes` table is synced before `clientes` by declaring the dependency explicitly, or run a post-sync pass that re-attempts skipped foreign keys:
```php
// In the plugin's Init.php or install hook:
public function init(): void
{
    // Ensure gruposclientes exists before clientes FK is applied
    $schema = new fs_schema();
    $schema->syncTable('gruposclientes', __DIR__ . '/../model/table/gruposclientes.xml');
    $schema->syncTable('clientes', __DIR__ . '/../model/table/clientes.xml');
}
```

## Info

### IN-01: Unused `require_once` for CsrfManager in ventas_clientes

**File:** `plugins/clientes_core/controller/ventas_clientes.php:21-23`

**Issue:** The file includes `require_once` for CsrfManager and a `use` statement, but as noted in CR-02, the direct `CsrfManager::isValid()` calls are broken. If CR-02 is fixed by removing the redundant checks, these imports become dead code.

**Fix:** Remove lines 21-23 if the redundant CSRF checks are removed per CR-02 fix.

### IN-02: `admin_home` View Contains Multiple `console.log` Debug Statements

**File:** `themes/AdminLTE/view/admin_home.html.twig:41-98`

**Issue:** The JavaScript in the admin_home template contains extensive `console.log` debugging statements (lines 41, 43, 47, 49, 52, 67, 73, 76, 81, 85, 97). These are development artifacts that should not be in production code. They leak internal implementation details to anyone who opens the browser console.

**Fix:** Remove all `console.log` and `console.error` calls from the production template.

### IN-03: `admin_home` JavaScript Functions Are Not CSP-Safe

**File:** `themes/AdminLTE/view/admin_home.html.twig:14-99`

**Issue:** The inline `<script>` block at line 14 defines functions like `fs_marcar_todo()`, `eliminar()`, `mostrar_modal_plugin()`, etc. These inline scripts work because the CSP includes `'unsafe-inline'`, but they will break once `unsafe-inline` is removed (per WR-02). The functions should be moved to an external JS file or use the nonce attribute.

**Fix:** Move the inline JavaScript to a separate file (e.g., `themes/AdminLTE/js/admin_home.js`) and load it with the nonce attribute.

---

_Reviewed: 2026-05-18T12:00:00Z_
_Reviewer: the agent (gsd-code-reviewer)_
_Depth: deep_
