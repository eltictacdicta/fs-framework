# Copilot Instructions

## Shared Instruction Baseline

- Keep this file, `AGENTS.md`, and `.cursor/rules/*.mdc` aligned on the shared project rules.
- If a task updates shared agent guidance in one of those locations, reflect the equivalent change in the other two during the same work session.
- Use this file for the concise baseline, `AGENTS.md` for the detailed guide, and Cursor rules for operational and context-specific instructions.
- Do not allow core guidance to drift between VS Code/Copilot and Cursor.

## Development Environment

- This repository uses `ddev` as the mandatory local development environment.
- Do not suggest or run plain `php`, `composer`, or `vendor/bin/phpunit` on the host unless the user explicitly asks for it.
- For PHP scripts and commands, always use `ddev exec php ...`.
- For Composer commands, prefer `ddev exec composer ...`.
- For PHPUnit, prefer `ddev exec php vendor/bin/phpunit ...`.
- If the task depends on the local web environment or services, assume `ddev start` must be running.
- When giving command examples for this repository, prefer `ddev`-based commands.

## FSFramework Baseline

- Stack baseline: PHP 8.2+ (prefer 8.3), Symfony 7.4, Twig 3, PHPUnit 11.
- Respect the split between legacy code in `base/`, `controller/`, `model/` and modern PSR-4 code in `src/`.
- Prefer Twig for new views, Symfony-based services and the container for modern code, and keep legacy naming and compatibility conventions where touching old code.
- Prefer framework helpers already present in the project: `ValidatorTrait`, response helpers, event dispatcher, translator, cache manager, and the plugin system conventions.

## Security And Quality

- Never concatenate unsanitized user input into SQL; use prepared statements or the framework escaping helpers when working with legacy queries.
- Escape output by default. In Twig use `{{ }}` and only allow raw HTML from trusted sources.
- Keep CSRF protection in mutating POST flows.
- Add or update PHPUnit coverage for business logic changes, especially in `src/` and reusable model/service code.
- Keep changes minimal, consistent with FSFramework conventions, and compatible with the existing plugin architecture.

Examples:

```bash
ddev exec php -v
ddev exec composer install
ddev exec php vendor/bin/phpunit
ddev exec php some_script.php
```