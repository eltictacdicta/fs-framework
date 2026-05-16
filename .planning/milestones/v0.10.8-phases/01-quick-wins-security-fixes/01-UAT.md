---
status: testing
phase: 01-quick-wins-security-fixes
source: 01-SUMMARY.md
started: 2026-05-16T13:00:00Z
updated: 2026-05-16T13:00:00Z
---

## Current Test

number: 1
name: PHP Version Guards Show 8.2 Message
expected: |
  `grep "8\.2" index.php install.php` returns the version check lines.
  `index.php` shows "FSFramework necesita PHP 8.2 o superior" instead of the old "PHP 5.6" message.
  `grep "5\.6" index.php install.php` returns zero matches.
awaiting: user response

## Tests

### 1. PHP Version Guards Show 8.2 Message
expected: `grep "8\.2" index.php install.php` returns version_compare lines. `grep "5\.6" index.php install.php` returns zero matches. Message says "PHP 8.2 o superior" not "PHP 5.6".
result: pending

### 2. Weak Random Fallback Removed
expected: `grep -E "str_shuffle|random_string" install.php` returns zero matches. `random_secret_key()` uses `random_bytes()` with no fallback. Function has type hints `int $length = 64): string` and there are no non-cryptographic fallback implementations.
result: pending

### 3. PHPMailer 5.x and Compat Bridge Deleted
expected: `ls extras/phpmailer 2>&1` returns "No such file or directory". `ls extras/phpmailer_compat.php 2>&1` returns "No such file or directory". `grep "phpmailer_compat" src/Core/Kernel.php` returns zero matches. `empresa.php` uses `PHPMailer\PHPMailer\PHPMailer` namespace.
result: pending

### 4. No @ Error Suppression in Base Files
expected: `grep -rn '@[a-z]' base/*.php` filtered for filesystem ops (`@mkdir`, `@unlink`, `@file_put_contents`, `@rmdir`, `@rename`, `@copy`) returns zero matches. All filesystem operations use proper guards.
result: pending

### 5. Base Test Suite Passes (124/124)
expected: `ddev exec php vendor/bin/phpunit --testsuite Base --no-coverage` returns OK (124 tests, 230 assertions), no failures.
result: pending

### 6. PHPMailer Compat Bridge Not Loaded
expected: `src/Core/Kernel.php` constructor does NOT contain `phpmailer_compat` or `extras/phpmailer` references. Kernel no longer loads the compat layer.
result: pending

## Summary

total: 6
passed: 0
issues: 0
pending: 6
skipped: 0

## Gaps

[none yet]
