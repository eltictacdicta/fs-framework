# Spec: ventas_clientes dispatch regression test

## Purpose

Add a persistent regression test for the dispatch bug fixed in commit `331daf96` (change `ventas-clientes-controller-dedup`). The test exercises the dispatch path in PHPUnit CLI without requiring a real HTTP server. It does this via a small refactor of the controller that exposes a pure `dispatch(): array` method returning a structured result, and an extracted `nuevo_cliente_pure(): ?cliente` helper that returns the saved cliente (or `null`) without emitting `header()`/`exit()` side effects.

## Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| VCT-01 | A new PHPUnit test class **MUST** cover the 6 scenarios listed in Requirement VCT-01 below | MUST |
| VCT-02 | The test class **MUST** use `ReflectionClass::newInstanceWithoutConstructor()` to bypass the controller constructor (which requires a real DB) | MUST |
| VCT-03 | The test class **MUST** use a `Symfony\Component\HttpFoundation\Request` instance to drive the controller's POST reads via `$this->request->request->get(...)` | MUST |
| VCT-04 | The test class **MUST** use autoloader stubs for `cliente` and `grupo_clientes` so no real DB is touched | MUST |
| VCT-05 | The controller **MUST** be refactored to expose a public `dispatch(): array` method that returns a structured result | MUST |
| VCT-06 | The controller's existing `nuevo_cliente()` method **MUST** continue to emit the `header('Location: ...')` + `exit()` side effects when called from the production dispatch path | MUST |
| VCT-07 | The full plugin suite (`ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml`) **MUST** pass after the change | MUST |
| VCT-08 | The full project suite (`ddev exec php vendor/bin/phpunit`) **MUST** pass after the change, modulo the 1 pre-existing `CsrfTokenTest::expiredTokenIsRejected` failure in `system_updater` | MUST |

## Requirement VCT-01: Six regression test scenarios

The new test class lives at `plugins/clientes_core/tests/Controller/VentasClientesDispatchTest.php` (the `Controller/` subdirectory is already created or is created in this change). It has 6 test methods, one per scenario.

#### Scenario VCT-01.a: Empty codcliente with action sentinel auto-generates a cliente

- **GIVEN** an admin user authenticated with access to `ventas`
- **AND** `csrf_valid = true` is pre-set on the controller via reflection
- **AND** the autoloader stub for `cliente` provides an instance whose `save()` returns `true` and whose `url()` returns `'index.php?page=ventas_cliente&cod=000001'`
- **WHEN** `dispatch()` is called with a `Request` whose POST body is `action=nuevo_cliente&nombre=Test&cifnif=B12345`
- **THEN** the returned array has `action='nuevo_cliente'`
- **AND** `cliente_codcliente` is non-null (the stub's auto-generated code, e.g. `'000001'`)
- **AND** `redirect_url` is non-null

#### Scenario VCT-01.b: User-typed codcliente is honored

- **GIVEN** the same setup
- **WHEN** `dispatch()` is called with `action=nuevo_cliente&codcliente=CUSTOM1&nombre=Test`
- **THEN** the returned array has `cliente_codcliente='CUSTOM1'`
- **AND** `redirect_url` is non-null

#### Scenario VCT-01.c: Legacy `codigo` field is accepted

- **GIVEN** the same setup
- **WHEN** `dispatch()` is called with `action=nuevo_cliente&codigo=LEGACY1&nombre=Test` (no `codcliente`)
- **THEN** the returned array has `cliente_codcliente='LEGACY1'`
- **AND** `redirect_url` is non-null

#### Scenario VCT-01.d: Missing action sentinel falls through to listing

- **GIVEN** the same setup
- **WHEN** `dispatch()` is called with a `Request` whose POST body is `nombre=Test&codcliente=` (no `action` field)
- **THEN** the returned array has `action=null`
- **AND** `cliente_codcliente=null`
- **AND** `redirect_url=null`
- **AND** `errors` is empty

> **Implementation note**: the listing branch calls `load_clientes()` which in turn calls `new cliente()` and `fs_filter_input_req('query', '')`. The test stub for `cliente` (returning a non-`null` codcliente from `url()`) and the `$_POST = []` superglobal setup cover both. The `grupo_clientes` stub returns `[]` from `all()`. The `$this->db` and `$this->default_items` properties on the controller are not used by the listing branch in this scope.

#### Scenario VCT-01.e: CSRF rejection prevents dispatch

- **GIVEN** `csrf_valid = false` is pre-set on the controller via reflection (overriding the default)
- **WHEN** `dispatch()` is called with `action=nuevo_cliente&nombre=Test`
- **THEN** the returned array has `action='nuevo_cliente'` (the action sentinel was detected and the dispatch chain entered the branch)
- **AND** `cliente_codcliente=null` (CSRF rejected the request before `nuevo_cliente_pure()` was called)
- **AND** `redirect_url=null`
- **AND** `errors` is non-empty (the `requireMutationCsrf()` path records an error via `new_error_msg`)

> **Implementation note**: `requireMutationCsrf()` with `csrf_valid = false` invokes the `$onFailure` callback (which is `fn() => $this->load_clientes()` in production). The test asserts that `errors` is non-empty because `requireCsrf()` itself calls `new_error_msg(...)` when validation fails (line 434 of `fs_controller.php`).

#### Scenario VCT-01.f: Invalid codcliente format is rejected

- **GIVEN** the autoloader stub for `cliente` provides an instance whose `test()` returns `false` for any `codcliente` that does not match `/^[A-Z0-9]{1,6}$/i` (mirroring the real model's `validateFields()`), and whose `save()` is only called when `test()` passes
- **WHEN** `dispatch()` is called with `action=nuevo_cliente&codcliente=TOOLONG123&nombre=Test`
- **THEN** the returned array has `cliente_codcliente=null`
- **AND** `redirect_url=null`
- **AND** `errors` is non-empty (the model's `new_error_msg(...)` is propagated)

## Requirement VCT-02..VCT-04: Test infrastructure

The test class follows the existing FSFramework test conventions:

- **Namespace**: `Tests\ClientesCore\Controller` (or whichever matches the existing `plugins/clientes_core/tests/` layout — the existing tests use `Tests\ClientesCore`).
- **setUp()**:
  1. `require_once` the `fs_core_log`, `fs_model`, `fs_controller`, `clientes_controller`, and `ventas_clientes` files.
  2. Reset the `fs_core_log` static state via `ReflectionProperty` (mirroring `ClienteModelTest::setUp()`).
  3. Register two `spl_autoload_register($cb, true, true)` stubs (prepend=true) for `cliente` and `grupo_clientes`. The stubs declare the test-only `cliente` and `grupo_clientes` classes via `eval` (or by including a fixture file) so the real model files are not autoloaded.
  4. Build the controller via `ReflectionClass::newInstanceWithoutConstructor()`.
  5. Assign `$this->controller->user` to a stub anonymous class with `allow_delete_on(...)` returning `false` (the controller calls this on line 48 of `private_core()`).
  6. Pre-set `csrf_valid = true` on the controller via `ReflectionProperty::setValue()` (test VCT-01.e overrides this to `false` in its own setup).
  7. Pre-set `$this->controller->request` to a `Symfony\Component\HttpFoundation\Request` instance (initially empty; individual tests set the desired POST body).
  8. Set `$_POST = []` (the fallback in `fs_filter_input_req` reads from `$_REQUEST` in CLI).
  9. Start an output buffer (`ob_start()`) to prevent any `header()`/`echo` from leaking into the test runner.
- **tearDown()**:
  1. Clear `$_POST`.
  2. Unregister the autoloader stubs via `spl_autoload_unregister($cb)`.
  3. End output buffers (`while (ob_get_level() > 0) { ob_end_clean(); }`).

## Requirement VCT-05: Refactor the controller

The `ventas_clientes` controller's `private_core()` is refactored to delegate the dispatch chain to a new public `dispatch()` method. The exact diff is in `design.md`. The shape of the refactor:

```php
/**
 * Pure dispatch logic, extracted from private_core().
 * Returns an array describing the result; no HTTP side effects beyond
 * the same calls nuevo_cliente_pure() makes in production.
 *
 * NOTE: not idempotent. Calling dispatch() twice would re-execute the
 * action (including any model save). Production calls it exactly once,
 * from private_core().
 *
 * @return array{
 *   action: string|null,
 *   cliente_codcliente: string|null,
 *   redirect_url: string|null,
 *   errors: array<string>,
 * }
 */
public function dispatch(): array
{
    $result = [
        'action' => null,
        'cliente_codcliente' => null,
        'redirect_url' => null,
        'errors' => [],
    ];

    $action = $this->request->request->get('action');
    $buscarCliente = $this->request->request->get('buscar_cliente');
    $nuevoGrupo = $this->request->request->get('nuevo_grupo');

    if ($buscarCliente) {
        $result['action'] = 'buscar';
        $this->buscar_cliente_json();
        return $result;
    } elseif ($action === 'delete_grupo') {
        $result['action'] = 'delete_grupo';
        if ($this->requireMutationCsrf(fn() => $this->load_clientes())) {
            $this->delete_grupo();
        }
        return $result;
    } elseif ($nuevoGrupo) {
        $result['action'] = 'nuevo_grupo';
        if ($this->requireMutationCsrf(fn() => $this->load_clientes())) {
            $this->nuevo_grupo();
        }
        return $result;
    } elseif ($action === 'nuevo_cliente') {
        $result['action'] = 'nuevo_cliente';
        if ($this->requireMutationCsrf(fn() => $this->load_clientes())) {
            $cliente = $this->nuevo_cliente_pure();
            if ($cliente !== null) {
                $result['cliente_codcliente'] = $cliente->codcliente;
                $result['redirect_url'] = $cliente->url();
            }
        }
        return $result;
    } elseif ($action === 'delete') {
        $result['action'] = 'delete';
        if ($this->requireMutationCsrf(fn() => $this->load_clientes())) {
            $this->delete_cliente();
        }
        return $result;
    }

    // No action matched: listing.
    $this->load_clientes();
    return $result;
}
```

The `private_core()` body is shortened to call `dispatch()` after the existing setup (the `allow_delete` assignment, the `offset`/`orden` parsing, and the `$this->grupos = (new grupo_clientes())->all()` call remain as-is).

## Requirement VCT-06: Side effects preserved

The existing `nuevo_cliente()` method is kept as a thin wrapper around `nuevo_cliente_pure()`:

```php
private function nuevo_cliente(): void
{
    $cliente = $this->nuevo_cliente_pure();
    if ($cliente !== null) {
        header('Location: ' . $cliente->url());
        exit();
    }
}

/**
 * @return cliente|null  The saved cliente on success, null on failure.
 */
private function nuevo_cliente_pure(): ?cliente
{
    $cliente = new cliente();
    $cliente->codcliente = $this->request->request->get('codcliente')
        ?: $this->request->request->get('codigo')
        ?: null;
    $cliente->nombre = $this->request->request->get('nombre') ?? '';
    $cliente->razonsocial = $this->request->request->get('razonsocial')
        ?: $this->request->request->get('nombre')
        ?: '';
    $cliente->cifnif = $this->request->request->get('cifnif') ?? '';
    $cliente->telefono1 = $this->request->request->get('telefono1') ?? '';
    $cliente->email = $this->request->request->get('email') ?? '';
    $cliente->codgrupo = !empty($this->request->request->get('codgrupo'))
        ? $this->request->request->get('codgrupo')
        : null;

    if ($cliente->save()) {
        return $cliente;
    }
    foreach ($cliente->get_errors() as $error) {
        $this->new_error_msg($error);
    }
    if (empty($cliente->get_errors())) {
        $this->new_error_msg('Error al guardar el cliente. Verifique los datos e inténtelo de nuevo.');
    }
    $this->load_clientes();
    return null;
}
```

For production HTTP requests, this behaves identically to the previous implementation (the `header()` + `exit()` side effects are unchanged). The test calls `dispatch()` directly, which goes through `nuevo_cliente_pure()` (not the wrapper), so the test runner is never `exit()`-ed.

## Requirement VCT-07: Full plugin suite green

After the change, `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` passes all tests, including:
- All existing tests in `ClienteModelTest.php`, `DireccionClienteModelTest.php`, `GrupoClientesModelTest.php`.
- All 6 new test methods in `VentasClientesDispatchTest.php`.

## Requirement VCT-08: Full project suite green

After the change, `ddev exec php vendor/bin/phpunit` passes all tests, including:
- All plugin suites.
- All core suites.
- The 1 pre-existing `CsrfTokenTest::expiredTokenIsRejected` failure in `system_updater` is permitted (pre-existing on master, last modified 2026-06-12 — confirmed in the previous verify report).

## Out of scope

- Refactoring `delete_grupo()`, `nuevo_grupo()`, `delete_cliente()`, `buscar_cliente_json()`, `load_clientes()` similarly. The first iteration of this change guards only the `nuevo_cliente` branch.
- The `facturacion_base` plugin (separate repository; not under this repo's `.gitignore` allow-list).
- The `cliente` model and the `clientes` table schema.
- The three session/cookie fixes already applied in `src/Security/SessionManager.php` and `src/Core/StealthMode.php` (archived in their own change).
- The duplicate error message in `nuevo_cliente()` (SUGGESTION #1 in the previous verify report). Pre-existing, not part of this regression-test change.

## Rollback plan

Revert the change with `git revert <commit>`. The refactor preserves the public dispatch behavior (same HTTP responses for the same POST bodies), so reverting is safe. No data migration is involved.
