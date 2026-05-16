# Stack Research: Deferred Items Cleanup

**Focus:** Stack additions/changes needed for 4 deferred refactors

## Existing Stack (unchanged)
- PHP 8.2+, Symfony 7.4, PHPMailer 6.x, Twig 3, PHPUnit 11
- DDEV for local dev

## Item 1: MailService Delegation
- **No new dependencies** — `MailService` (`src/Core/MailService.php`) already exists and is fully operational
- Uses PHPMailer 6.x (already in composer.json)
- Integration point: `empresa.php` already `use PHPMailer\PHPMailer\PHPMailer` — just needs to delegate to MailService instead
- **What NOT to add:** No new mail libraries, no queue system

## Item 2: fs_mysql Decomposition
- **No new dependencies** — pure PHP refactoring within existing MySQL driver
- Target: extract schema-related methods into focused classes in `src/` or `base/`
- May use `declare(strict_types=1)` in new classes (following v0.10.8 pattern)

## Item 3: Plugin Management Extraction
- **No new dependencies** — moves logic from `admin_home.php` controller to dedicated class(es)
- `fs_plugin_manager` already handles core plugin operations
- New class(es) would be PSR-4 in `src/Core/` or `src/Controller/`

## Item 4: Test Failures
- **No new dependencies** — PHPUnit 11 already configured
- May need to adjust test isolation or environment mocks

## Integration Impact
All items are backward-compatible refactors. No library changes, no API breaks.
