# Twig Integration Specification

## Purpose

Register the `render_hook` Twig function globally so any template can invoke view hooks without plugin dependencies. Maintain backward compatibility via a deprecated alias.

## Requirements

### Requirement: render_hook Twig Function

The system SHALL register a `render_hook(name, context)` Twig function in `src/Core/Html.php::registerUtilityFunctions()`. The function delegates to `ViewHookRegistry::render()`.

#### Scenario: Call render_hook in template

- GIVEN a Twig template with `{{ render_hook('my_hook', {'key': 'value'}) }}`
- WHEN the template is rendered
- THEN `ViewHookRegistry::render()` is called with the Twig environment, hook name, and context
- AND the concatenated hook output is inserted at the call site

#### Scenario: Call render_hook with no context

- GIVEN a Twig template with `{{ render_hook('my_hook') }}`
- WHEN the template is rendered
- THEN `ViewHookRegistry::render()` is called with an empty context array

#### Scenario: Call render_hook with invalid name

- GIVEN a Twig template with `{{ render_hook(123) }}`
- WHEN the template is rendered
- THEN an empty string is returned (non-string hook names are rejected)

### Requirement: Deprecated clientes_render_hook Alias

The system SHALL maintain `clientes_render_hook` as a deprecated alias for `render_hook`. The alias MUST emit a PHP `E_USER_DEPRECATED` notice when invoked.

#### Scenario: Existing templates using clientes_render_hook

- GIVEN a Twig template using `{{ clientes_render_hook('my_hook') }}`
- WHEN the template is rendered
- THEN a deprecation notice is triggered
- AND the hook is rendered via `ViewHookRegistry::render()` with identical behavior

#### Scenario: New templates should use render_hook

- GIVEN a developer writing new template code
- WHEN choosing between `render_hook` and `clientes_render_hook`
- THEN `render_hook` is the canonical function with no deprecation overhead

### Requirement: Registration Location

The `render_hook` function SHALL be registered inside `Html::registerUtilityFunctions()` alongside existing utility functions (e.g., `csrf_field`, `csrf_meta`). Registration MUST be idempotent — calling it multiple times MUST NOT throw `LogicException`.

#### Scenario: Idempotent registration

- GIVEN `registerUtilityFunctions()` is called twice on the same Twig environment
- WHEN the second call attempts to add `render_hook`
- THEN the `LogicException` from duplicate function registration is silently caught
- AND the function remains operational
