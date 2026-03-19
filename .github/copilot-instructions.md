# Copilot Instructions

- This repository uses `ddev` as the mandatory local development environment.
- Do not suggest or run plain `php`, `composer`, or `vendor/bin/phpunit` on the host unless the user explicitly asks for it.
- For PHP scripts and commands, always use `ddev exec php ...`.
- For PHPUnit, prefer `ddev exec php vendor/bin/phpunit ...`.
- If the task depends on the local web environment or services, assume `ddev start` must be running.
- When giving command examples for this repository, prefer `ddev`-based commands.

Examples:

```bash
ddev exec php -v
ddev exec php vendor/bin/phpunit
ddev exec php some_script.php
```