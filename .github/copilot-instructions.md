# Copilot Instructions

## Shared Instruction Baseline

- `AGENTS.md` is the canonical source for the shared project rules.
- Keep this file and `.cursor/rules/*.mdc` aligned with `AGENTS.md` whenever a shared or permanent rule changes.
- Use this file for the concise derived baseline, `AGENTS.md` for the detailed guide, `.cursor/rules/fs-framework-general.mdc` for the always-on Cursor baseline, and specialized Cursor rules for topic-specific operational detail.
- Do not allow shared guidance to drift between VS Code/Copilot and Cursor.

## Development Environment

- This repository uses `ddev` as the mandatory local development environment.
- Do not suggest or run plain `php`, `composer`, or `vendor/bin/phpunit` on the host unless the user explicitly asks for it.
- For PHP scripts and commands, always use `ddev exec php ...`.
- For Composer commands, prefer `ddev exec composer ...`.
- For PHPUnit, prefer `ddev exec php vendor/bin/phpunit ...`.
- If the task depends on the local web environment or services, assume `ddev start` must be running.

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
- Keep plugin-specific tests inside `plugins/<PluginName>/tests/`; the root PHPUnit configuration auto-discovers them when the plugin is present.
- Keep changes minimal, consistent with FSFramework conventions, and compatible with the existing plugin architecture.

## Maintenance Rule

- Update `AGENTS.md` first when changing shared guidance, then synchronize this file and the affected Cursor rule files in the same task.
- If a change only affects a specialized topic, update the owning Cursor rule and touch this file only when the shared baseline changes.