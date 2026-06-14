# Spec: ventas_clientes dispatch fix and dead-code removal

## Purpose

Fix the silent-failure bug in `ventas_clientes` (POST to the new-client modal does nothing) and remove the dead legacy duplicate controller and view that misled a previous diagnosis. The fix is local to one controller's dispatch chain and one hidden form field; the cleanup deletes three unreachable files.

## Requirements

| ID | Requirement | Strength | Delivery |
|----|-------------|----------|----------|
| VC-01 | Submitting the new-client modal with an empty `codcliente` **MUST** create a row in `clientes` (auto-generated code via `get_new_codigo()`) | MUST | This PR |
| VC-02 | Submitting the new-client modal with a user-typed `codcliente` **MUST** create a row with that code | MUST | This PR |
| VC-03 | Submitting the new-client modal with a user-typed `codigo` (legacy field) **MUST** create a row with that code (transitional compat) | SHOULD | This PR |
| VC-04 | The new-client form **MUST** include a hidden `action=nuevo_cliente` field that drives the dispatch | MUST | This PR |
| VC-05 | A POST without a valid CSRF token **MUST** be rejected with an error message and no row created | MUST | This PR |
| VC-06 | The dead legacy files `facturacion_base/controller/ventas_clientes.php`, `facturacion_base/view/ventas_clientes.html`, and `facturacion_base/view/block/ventas_clientes_nuevo.html` **MUST** be removed | MUST | **Deferred** (follow-up change) |
| VC-07 | The single-cliente page `ventas_cliente` (singular) **MUST** continue to work unchanged | MUST | This PR |
| VC-08 | The options page `ventas_clientes_opciones` **MUST** continue to work unchanged | MUST | This PR |
| VC-09 | A new PHPUnit test **MUST** cover the empty-codcliente dispatch path and fail on master, pass after the fix | MUST | This PR |

> **Scope decision (2026-06-13)**: VC-06 is reserved for a follow-up change to keep this PR under the 400-line review budget. The 3 dead files total ~700 lines. The dispatch fix is the urgent value; cleanup ships on its own.

## Requirement VC-01 + VC-04: Empty codcliente auto-generates and the action sentinel drives dispatch

The system MUST treat a POST to `index.php?page=ventas_clientes` whose body contains `action=nuevo_cliente` (with or without `codcliente`) as a new-client create request, and MUST invoke `nuevo_cliente()`. The dispatched method MUST allow `codcliente` to be empty/null and MUST rely on `cliente::get_new_codigo()` to assign the next available code.

#### Scenario VC-01.a: POST with action sentinel and empty codcliente creates a cliente

- **GIVEN** an admin user authenticated with access to `ventas`
- **AND** the `clientes` table contains 0 rows
- **WHEN** a POST is sent to `index.php?page=ventas_clientes` with a valid CSRF token and body `action=nuevo_cliente&nombre=Test%20User&cifnif=B12345678`
- **THEN** the dispatch enters the `nuevo_cliente` branch
- **AND** a row is inserted in `clientes` with `codcliente='000001'`, `nombre='Test User'`, `cifnif='B12345678'`
- **AND** the response is a 302 redirect to `index.php?page=ventas_cliente&cod=000001`

#### Scenario VC-01.b: POST with action sentinel and existing rows auto-increments

- **GIVEN** the `clientes` table contains rows with `MAX(codcliente)='000042'`
- **WHEN** a POST is sent to `index.php?page=ventas_clientes` with `action=nuevo_cliente&nombre=Another%20User`
- **THEN** a row is inserted with `codcliente='000043'`

#### Scenario VC-01.c: GET request without action sentinel falls through to listing

- **GIVEN** an admin user authenticated with access to `ventas`
- **WHEN** a GET is sent to `index.php?page=ventas_clientes` (no POST body)
- **THEN** the dispatch falls through to `load_clientes()`
- **AND** the page renders the clientes listing (HTTP 200, no client created)

## Requirement VC-02: User-typed codcliente is honored

The system MUST use a non-empty `codcliente` value from the POST body as the cliente's primary key.

#### Scenario VC-02.a: POST with user-typed codcliente creates a cliente with that code

- **GIVEN** an admin user authenticated with access to `ventas`
- **WHEN** a POST is sent with `action=nuevo_cliente&codcliente=CUSTOM1&nombre=Test`
- **THEN** a row is inserted with `codcliente='CUSTOM1'`
- **AND** the response redirects to `index.php?page=ventas_cliente&cod=CUSTOM1`

#### Scenario VC-02.b: POST with invalid codcliente format is rejected

- **GIVEN** the cliente model's `test()` rejects codes not matching `/^[A-Z0-9]{1,6}$/i`
- **WHEN** a POST is sent with `action=nuevo_cliente&codcliente=TOOLONG123&nombre=Test`
- **THEN** no row is inserted
- **AND** an error message "Código de cliente no válido" is shown
- **AND** the listing is re-rendered

## Requirement VC-03: Legacy codigo field is accepted (transitional)

The system SHOULD accept a non-empty `codigo` field (the legacy facturacion_base form field name) as a fallback for `codcliente` to support in-flight submissions during the deprecation window. Once the legacy view is removed, this fallback MAY be dropped in a follow-up.

#### Scenario VC-03.a: POST with codigo and no codcliente creates a cliente with that code

- **GIVEN** an admin user authenticated with access to `ventas`
- **WHEN** a POST is sent with `action=nuevo_cliente&codigo=LEGACY1&nombre=Test`
- **THEN** a row is inserted with `codcliente='LEGACY1'`

## Requirement VC-05: CSRF is enforced

The system MUST validate the CSRF token on every POST to `ventas_clientes` before invoking any mutation.

#### Scenario VC-05.a: POST without CSRF token is rejected

- **GIVEN** an admin user authenticated with access to `ventas`
- **WHEN** a POST is sent with `action=nuevo_cliente&nombre=Test` and no CSRF token
- **THEN** the response is HTTP 200 with the error message "Token de seguridad inválido. Por favor, recarga la página."
- **AND** no row is inserted

#### Scenario VC-05.b: POST with valid CSRF proceeds

- **GIVEN** a fresh CSRF token obtained from a previous GET to `ventas_clientes`
- **WHEN** a POST is sent with that token and `action=nuevo_cliente&nombre=Test`
- **THEN** the request proceeds to `nuevo_cliente()`
- **AND** a row is inserted

## Requirement VC-06: Dead legacy files are removed

The system MUST delete the three files that constitute the unreachable legacy duplicate.

#### Scenario VC-06.a: The three legacy files no longer exist on disk

- **GIVEN** the change is applied
- **WHEN** the filesystem is inspected
- **THEN** `plugins/facturacion_base/controller/ventas_clientes.php` does not exist
- **AND** `plugins/facturacion_base/view/ventas_clientes.html` does not exist
- **AND** `plugins/facturacion_base/view/block/ventas_clientes_nuevo.html` does not exist

#### Scenario VC-06.b: No other file references the removed paths

- **GIVEN** the change is applied
- **WHEN** the codebase is grepped for `ventas_clientes_nuevo` and the legacy modal's `name="codigo"` and `name="scodgrupo"`
- **THEN** no matches are found in `.html`, `.twig`, or `.js` files

## Requirement VC-07 + VC-08: Unrelated pages continue to work

The change MUST NOT regress the single-cliente detail page or the options page.

#### Scenario VC-07.a: ventas_cliente detail page is unchanged

- **GIVEN** a cliente exists with `codcliente='000001'`
- **WHEN** a GET is sent to `index.php?page=ventas_cliente&cod=000001`
- **THEN** the response is HTTP 200 with the cliente detail rendered

#### Scenario VC-08.a: ventas_clientes_opciones page is unchanged

- **GIVEN** an admin user authenticated with access to `ventas`
- **WHEN** a GET is sent to `index.php?page=ventas_clientes_opciones`
- **THEN** the response is HTTP 200 with the options page rendered
- **AND** the corresponding controller and view in `facturacion_base/` are untouched

## Requirement VC-09: Regression test in the plugin suite

A new PHPUnit test MUST live in `plugins/clientes_core/tests/` and cover the dispatch-condition bug. The test MUST fail on master (because the dispatch falls through) and pass after the fix. The test SHOULD follow the conventions of the existing `plugins/clientes_core/tests/ClienteModelTest.php` (anonymous subclass of `fs_model` for the mock, no real DB).

#### Scenario VC-09.a: The new test fails on master

- **GIVEN** a checkout of the codebase as of the change creation date (before the fix is applied)
- **WHEN** `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml --filter VentasClientesDispatch` is run
- **THEN** the test fails with a message indicating the dispatch did not reach `nuevo_cliente()` (e.g., "expected 1 cliente, found 0")

#### Scenario VC-09.b: The new test passes after the fix

- **GIVEN** the fix from VC-01, VC-02, VC-04 is applied
- **WHEN** the same test is run
- **THEN** the test passes

#### Scenario VC-09.c: The full plugin suite remains green

- **GIVEN** the change is fully applied
- **WHEN** `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` is run
- **THEN** all existing tests in the suite still pass
- **AND** the new test in scenario VC-09.b passes
- **AND** the total number of tests increases by 1 (or more) compared to master

## Out of scope

- The three session/cookie fixes already applied in `src/Security/SessionManager.php` and `src/Core/StealthMode.php` (archived in their own change).
- `ventas_clientes_opciones.php` / `.html` (dead but out of scope to keep the diff under the 400-line review budget).
- Writing a default `direccion_cliente` row in `nuevo_cliente()` (the legacy controller did this; the modern one does not — adding it is a feature, not a bugfix).
- Refactoring `cliente::test()` / `save()` (they are correct).
- Any change to other plugins (`tpvmod`, `tarifario`, `clientes_catalogo`, `clientes_facturacion`).

## Rollback plan

Revert the change with `git revert <commit>`. The legacy files in `facturacion_base/` can be restored from git history if needed. No data migration is involved; the `clientes` table schema is unchanged.
