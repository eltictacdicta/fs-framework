# View Hooks Specification

## Purpose

Core view hook registry enabling plugins to inject Twig content into any controller's view via named hook points. Replaces plugin-scoped registry with a framework-level primitive.

## Requirements

### Requirement: ViewHookRegistry Static API

The system SHALL provide a static `ViewHookRegistry` class in `FSFramework\View` namespace with three methods: `register(hook, template)`, `has(hook)`, and `render(twig, hook, context)`.

#### Scenario: Register a hook template

- GIVEN a hook name `'my_hook'` and template path `'@plugin/template.html.twig'`
- WHEN `ViewHookRegistry::register('my_hook', '@plugin/template.html.twig')` is called
- THEN `ViewHookRegistry::has('my_hook')` returns `true`

#### Scenario: Register same template twice

- GIVEN a hook `'my_hook'` with template already registered
- WHEN `ViewHookRegistry::register('my_hook', same_template)` is called again
- THEN the template appears only once in the hook's template list

#### Scenario: Register multiple templates per hook

- GIVEN hook `'my_hook'` with templates `['@a/a.html.twig', '@b/b.html.twig']`
- WHEN `ViewHookRegistry::has('my_hook')` is called
- THEN it returns `true`

### Requirement: Render Hook Output

The system SHALL concatenate rendered output from ALL templates registered to a hook, passing the provided context to each template.

#### Scenario: Render hook with registered templates

- GIVEN hook `'my_hook'` has two templates registered
- WHEN `ViewHookRegistry::render($twig, 'my_hook', $context)` is called
- THEN both templates are rendered with `$context` and output is concatenated

#### Scenario: Render unknown hook

- GIVEN hook `'nonexistent'` has no templates registered
- WHEN `ViewHookRegistry::render($twig, 'nonexistent')` is called
- THEN an empty string is returned

### Requirement: Error Handling

The system SHALL catch all `\Throwable` exceptions during template rendering, log them via `error_log`, and continue rendering remaining templates. Rendering errors MUST NOT propagate to the caller.

#### Scenario: Template throws exception

- GIVEN hook `'my_hook'` has templates `['@good.html.twig', '@bad.html.twig', '@also_good.html.twig']`
- WHEN `@bad.html.twig` throws a `\Twig\Error\RuntimeError`
- THEN the error is logged via `error_log` with hook and template info
- AND rendering continues with `@also_good.html.twig`
- AND the output contains only the successfully rendered templates

#### Scenario: All templates fail

- GIVEN hook `'my_hook'` has one template that throws
- WHEN `ViewHookRegistry::render()` is called
- THEN an empty string is returned and error is logged

### Requirement: PSR-4 Autoloading

The class file SHALL reside at `src/View/ViewHookRegistry.php` under the `FSFramework\View` namespace. No `require_once` or manual include is needed — Composer PSR-4 autoloading handles class resolution.

#### Scenario: Class resolved via autoloader

- GIVEN `ViewHookRegistry` is in `src/View/ViewHookRegistry.php`
- WHEN any code references `FSFramework\View\ViewHookRegistry`
- THEN Composer autoloader resolves the class without explicit includes
