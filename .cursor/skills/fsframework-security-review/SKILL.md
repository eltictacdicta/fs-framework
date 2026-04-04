---
name: fsframework-security-review
description: >-
  Audit PHP code for FSFramework-specific security vulnerabilities: SQL injection,
  XSS, CSRF, insecure password hashing, unsafe file uploads, and open redirects.
  Use when reviewing code, before committing changes, during pull request review,
  or when the user asks for a security audit.
---

# FSFramework Security Review

## Quick Start

Run this audit against changed files or a target directory. Check each category
sequentially — stop and report as soon as a critical issue is found.

## Audit Checklist

```
Security Audit:
- [ ] 1. SQL Injection
- [ ] 2. XSS (Cross-Site Scripting)
- [ ] 3. CSRF Protection
- [ ] 4. Password Handling
- [ ] 5. File Uploads
- [ ] 6. Open Redirects
- [ ] 7. Session Security
- [ ] 8. Input Validation
- [ ] 9. Error Exposure
```

## 1. SQL Injection (CRITICAL)

**Search for**: direct string concatenation in SQL queries.

Patterns to flag:

```php
// VULNERABLE — variable directly in SQL string
$this->db->select("SELECT * FROM users WHERE nick = '$nick'");
$this->db->select("SELECT * FROM users WHERE id = " . $id);
$this->db->exec("DELETE FROM items WHERE id = " . $_GET['id']);
```

Safe alternatives:

```php
// Safe — var2str escapes values
$this->db->select("SELECT * FROM t WHERE nick = " . $this->var2str($nick));

// Safe — prepared statement
$stmt = $this->db->prepare("SELECT * FROM t WHERE nick = :nick");
$stmt->bindParam(':nick', $nick, PDO::PARAM_STR);
```

**Search commands**:

```bash
# Find potential SQL injection in PHP files
rg --type php "select\(.*\\\$" plugins/ src/
rg --type php "exec\(.*\\\$" plugins/ src/
rg --type php "\"(SELECT|INSERT|UPDATE|DELETE)[^\"]*\\\$" plugins/ src/
```

Review the matches from the last command manually and discard safe cases where the value is wrapped with `$this->var2str(...)` or `$this->db->var2str(...)` before concatenation.

## 2. XSS (CRITICAL)

**In Twig templates**: `{{ }}` auto-escapes. Flag any use of `|raw` with user data.

```bash
# Find raw filter usage in templates
rg "\|raw" plugins/ themes/ --glob "*.twig"
```

**In PHP**: Flag any `echo $variable` without `htmlspecialchars`.

```bash
# Find unescaped echo in PHP
rg --type php "echo \\\$" plugins/ src/ controller/
```

Safe: `$this->no_html($variable)` or `htmlspecialchars($var, ENT_QUOTES | ENT_HTML5, 'UTF-8')`.

## 3. CSRF Protection (CRITICAL)

**Every POST form** must include `{{ csrf_field() }}`.

```bash
# Find forms missing csrf_field
rg "method=\"post\"" plugins/ themes/ --glob "*.twig" -l
rg "csrf_field" plugins/ themes/ --glob "*.twig" -l
# Compare: any file in the first list missing from the second is a problem
```

**Every controller processing POST** must validate CSRF:

```php
if (!$this->isCsrfValid()) {
    $this->new_error_msg('Token CSRF inválido');
    return;
}
```

**AJAX and API endpoints** must send the token in a header such as `X-CSRF-Token`, then read it with `getRequest()->headers->get('X-CSRF-Token')` and validate it with the existing CSRF helpers (`validateCsrfToken()` or `isCsrfValid()`). Reject the request with a 403 or equivalent error response if validation fails.

```php
$token = $this->getRequest()->headers->get('X-CSRF-Token');

if (!$token || !$this->validateCsrfToken($token)) {
  if (!$this->isCsrfValid()) {
    return $this->forbidden('Token CSRF inválido');
  }
}
```

## 4. Password Handling (CRITICAL)

Flag any use of `md5()`, `sha1()`, or `sha256` for passwords.

```bash
rg --type php "(md5|sha1|sha256)\(.*pass" plugins/ src/ base/
```

Only accept: `password_hash()` / `password_verify()` or `Container::passwordHasher()`.

## 5. File Uploads

Check for:
- MIME type validation (not just extension)
- Random filename generation
- Storage outside webroot

```bash
rg --type php "\\\$_FILES" plugins/ src/
rg --type php "move_uploaded_file" plugins/ src/
```

Every upload handler must:
1. Validate MIME with `finfo_file()` against an allowlist
2. Generate name with `bin2hex(random_bytes(16))`
3. Set `chmod(0644)` on the destination

## 6. Open Redirects

Search for redirects that trust request parameters such as `return_to`, `redirect`, `next`, `url`, or `target` without validation.

Patterns to review:

```bash
rg --type php "return_to|redirect|next|target|url" plugins/ src/ controller/ base/
rg --type php "header\(['\"]Location:|->redirect\(|redirectToPage\(" plugins/ src/ controller/ base/
```

Examples of vulnerable flows:

```php
return $this->redirect($_GET['next']);
header('Location: ' . $_REQUEST['return_to']);
$target = $request->query->get('redirect');
return new RedirectResponse($target);
```

Payloads to test:

```text
?next=https://evil.example
?redirect=//evil.example
?return_to=%2F%2Fevil.example
?url=https:%2f%2fevil.example
```

Mitigations:
- Only allow internal routes or route names, not arbitrary external URLs.
- Validate and whitelist destination paths before redirecting.
- Normalize and reject protocol-relative URLs, alternate encodings, and unexpected schemes.
- Do not trust redirect targets from query params, POST bodies, or headers without explicit validation.

## 7. Session Security

Flag any session usage without `session_regenerate_id(true)` after login.

```bash
rg --type php "session_start|session_regenerate" plugins/ src/ base/
```

Review the bootstrap and session handlers for these hardening settings and APIs:
- `session_regenerate_id(true)` immediately after successful login or privilege elevation.
- `session_destroy()` during logout, plus removal of session cookies.
- `session.cookie_httponly = 1` to block JavaScript access.
- `session.cookie_secure = 1` when the application runs over HTTPS.
- `session.cookie_samesite = Lax` or `Strict` depending on the flow.
- `session.use_strict_mode = 1` to reject uninitialized session IDs.
- `session.gc_maxlifetime` aligned with the intended inactivity timeout.

Check for inactivity timeout handling with a timestamp such as `$_SESSION['last_activity']`: update it on each request, expire the session after the configured idle period, regenerate the session when needed, and force re-authentication after timeout.

Recommended logout flow:

```php
session_regenerate_id(true);
$_SESSION = [];

if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}

session_destroy();
```

Storage guidance:
- Filesystem-backed sessions are acceptable for simple single-node deployments with secure permissions.
- Redis or another centralized store is preferable for multi-node deployments and tighter expiry control.
- Enable secure cookies only when the site is actually served over HTTPS end to end.

## 8. Input Validation

All user input must be sanitized:
- `$this->no_html()` for text
- `filter_var($val, FILTER_VALIDATE_EMAIL)` for emails
- `$request->query->getInt()` for integers
- `filter_var($val, FILTER_VALIDATE_INT)` for integer validation

```bash
rg --type php "\\\$_GET|\\\$_POST|\\\$_REQUEST" plugins/ src/
```

Prefer Symfony Request: `$this->getRequest()->request->getString('field')`.

## 9. Error Exposure

Ensure no stack traces or raw error details are shown to users.

```bash
rg --type php "var_dump|print_r|debug_backtrace" plugins/ src/
rg --type php "display_errors.*=.*1" plugins/ src/
```

## Report Format

After the audit, produce a summary:

```markdown
## Security Audit Report

### Critical Issues
- **[SQL Injection]** `file.php:42` — $variable concatenated in SELECT
- **[CSRF Missing]** `form.html.twig:15` — POST form without csrf_field()

### Warnings
- **[Raw Filter]** `template.twig:30` — |raw used (verify source is trusted)

### Passed
- Password hashing: OK (uses PasswordHasherService)
- File uploads: N/A (no upload handlers found)
- Session: OK (regenerate_id after login)
```

Severity levels:
- **CRITICAL**: Must fix before merge (SQL injection, missing CSRF, plaintext passwords)
- **WARNING**: Review and justify (|raw usage, $_GET access, echo without escape)
- **INFO**: Best practice suggestion
