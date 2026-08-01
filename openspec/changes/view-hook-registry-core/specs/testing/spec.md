# Testing Specification

## Purpose

Define test coverage requirements for ViewHookRegistry and Twig integration.

## Requirements

### Requirement: ViewHookRegistry Unit Tests

The system SHALL include unit tests at `tests/View/ViewHookRegistryTest.php` covering register, has, render, and error handling. Tests MUST run without database or network dependencies.

#### Scenario: Register and has

- GIVEN a fresh ViewHookRegistry (static state reset)
- WHEN `register('hook', 'template')` is called
- THEN `has('hook')` returns `true`
- AND `has('other')` returns `false`

#### Scenario: Render with templates

- GIVEN hook `'test_hook'` registered with a mock template
- WHEN `render($twig, 'test_hook', [])` is called
- THEN the template is rendered and output returned

#### Scenario: Render unknown hook

- GIVEN no hooks registered
- WHEN `render($twig, 'unknown')` is called
- THEN empty string is returned

#### Scenario: Error swallowing

- GIVEN hook `'bad_hook'` with a template that throws `\Throwable`
- WHEN `render($twig, 'bad_hook')` is called
- THEN empty string is returned
- AND no exception propagates

#### Scenario: Multiple templates per hook

- GIVEN hook with templates `['@a/a.html.twig', '@b/b.html.twig']`
- WHEN `render()` is called
- THEN both templates are rendered and output is concatenated

### Requirement: Static State Isolation in Tests

Tests MUST reset `ViewHookRegistry` static state between test methods to prevent cross-test contamination. Use Reflection to clear the `$hooks` property.

#### Scenario: Test isolation

- GIVEN test A registers hook `'hook_a'`
- WHEN test B runs
- THEN `has('hook_a')` returns `false` in test B

### Requirement: Twig Function Registration Tests

Tests SHALL verify that `render_hook` and `clientes_render_hook` are registered as Twig functions and delegate correctly to `ViewHookRegistry`.

#### Scenario: render_hook available in Twig

- GIVEN a Twig environment with `render_hook` registered
- WHEN a template calls `{{ render_hook('test') }}`
- THEN the output matches `ViewHookRegistry::render()` output
