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
- [ ] 6. Session Security
- [ ] 7. Input Validation
- [ ] 8. Error Exposure
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
rg --type php "\"(SELECT|INSERT|UPDATE|DELETE).*\\\$(?!this->(var2str|db->var2str))" plugins/ src/
```

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

## 6. Session Security

Flag any session usage without `session_regenerate_id(true)` after login.

```bash
rg --type php "session_start|session_regenerate" plugins/ src/ base/
```

## 7. Input Validation

All user input must be sanitized:
- `$this->no_html()` for text
- `filter_var($val, FILTER_VALIDATE_EMAIL)` for emails
- `$request->query->getInt()` for integers
- `filter_var($val, FILTER_VALIDATE_INT)` for integer validation

```bash
rg --type php "\\\$_GET|\\\$_POST|\\\$_REQUEST" plugins/ src/
```

Prefer Symfony Request: `$this->getRequest()->request->getString('field')`.

## 8. Error Exposure

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
