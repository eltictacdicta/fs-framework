# Proposal: Add regression test for ventas_clientes dispatch

## Why

The silent-failure dispatch bug in `ventas_clientes` was fixed in commit `331daf96` (change `ventas-clientes-controller-dedup`), but **no automated regression test was added**. The original test approach (autoloader stubs + `headers_list()` capture) is structurally infeasible in PHPUnit CLI because:

1. `filter_input(INPUT_POST, ...)` is a no-op in CLI (returns `null` even when `$_POST` is set manually). The previous `ventas-clientes-controller-dedup` change mitigated this by migrating reads to `$this->request->request->get(...)`, so the dispatch gate is now testable via a `Symfony\Component\HttpFoundation\Request` instance assigned to the controller.
2. `header('Location: ...')` is silently dropped in CLI; `headers_list()` returns empty.

Without a persistent regression test, a future refactor of the dispatch chain could silently reintroduce the bug. The 11/11 real-HTTP curl scenarios in the previous change's `verify-report.md` provide temporary coverage but are not part of the persistent PHPUnit suite. That verify report explicitly flagged the regression-test gap as WARNING #1 and recommended this follow-up.

The orchestrator's approach (see SUGGESTION #4 in the previous verify report) is to **minimally refactor the controller so the dispatch path is unit-testable in CLI without `php -S`**: expose a `dispatch(): array` method that returns a structured result, and split `nuevo_cliente()` into a pure helper that returns the saved `cliente` (or `null`) plus a thin wrapper that emits the existing `header()` + `exit()` side effects.

## What changes

- **Refactor** `plugins/clientes_core/controller/ventas_clientes.php` to expose a public `dispatch(): array` method that returns a structured result describing the dispatch outcome. The result keys: `action` (the branch taken, e.g. `'nuevo_cliente'` or `null`), `cliente_codcliente` (the saved cliente's code, or `null`), `redirect_url` (the `Location:` URL, or `null`), `errors` (collected error messages).
- **Refactor** `nuevo_cliente()` into `nuevo_cliente_pure(): ?cliente` (returns the saved cliente or `null`; no `header()`/`exit()`) plus a thin `nuevo_cliente()` wrapper that calls `nuevo_cliente_pure()` and emits the existing `header('Location: ' . $cliente->url())` + `exit()` side effects.
- **Update** `private_core()` to delegate the dispatch chain to the new public `dispatch()` method. Side effects (header/exit) for the HTTP path are preserved.
- **Add** a new PHPUnit test class `plugins/clientes_core/tests/Controller/VentasClientesDispatchTest.php` that exercises the dispatch path via `ReflectionClass::newInstanceWithoutConstructor()` + a `Symfony\Component\HttpFoundation\Request` + autoloader stubs for `cliente` and `grupo_clientes`. The test asserts on the structured return value of `dispatch()`, not on HTTP side effects.

## Scope

### In scope

- Refactor `private_core()` of `ventas_clientes` to delegate the dispatch chain to a new public `dispatch(): array` method.
- Extract `nuevo_cliente()` body into `nuevo_cliente_pure(): ?cliente`; keep the existing `nuevo_cliente()` as a thin wrapper that emits the `header()` + `exit()` side effects.
- New PHPUnit test class covering 6 scenarios (see `spec.md`): empty codcliente auto-generates, typed codcliente honored, legacy `codigo` field accepted, missing action falls through to listing, CSRF rejection prevents dispatch, invalid codcliente format rejected.

### Out of scope

- Refactoring `delete_grupo()`, `nuevo_grupo()`, `delete_cliente()`, `buscar_cliente_json()`, `load_clientes()` similarly. These can be refactored in future changes if their branches need regression tests. The first iteration of this change guards only the `nuevo_cliente` branch — the exact branch the original bug lived in.
- The `facturacion_base` plugin (separate repository; not under this repo's `.gitignore` allow-list).
- The `cliente` model and the `clientes` table schema.
- The three session/cookie fixes already applied in `src/Security/SessionManager.php` and `src/Core/StealthMode.php` (archived in their own change).
- The empty `plugins/clientes_core/tests/Controller/` directory that was created and cleaned up by the previous T1 attempt — already removed.
- The duplicate error message in `nuevo_cliente()` (SUGGESTION #1 in the previous verify report). Pre-existing, not part of this regression-test change.

## Affected areas

| Area | Impact | Description |
|------|--------|-------------|
| `plugins/clientes_core/controller/ventas_clientes.php` | Modified | Refactor `private_core()` to call new `dispatch()`; extract `nuevo_cliente_pure()`; thin wrapper `nuevo_cliente()`. |
| `plugins/clientes_core/tests/Controller/VentasClientesDispatchTest.php` | New | New PHPUnit test class with 6 scenarios; uses `ReflectionClass::newInstanceWithoutConstructor()`, `Symfony\Request`, and autoloader stubs for `cliente` and `grupo_clientes`. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| The `dispatch()` method is called twice in the same request, re-executing model saves. | Low | The method is only called from `private_core()` in production, once per request. The test always builds a fresh controller per test method. Document the non-idempotency in the method's docblock. |
| Autoloader stubs for `cliente` and `grupo_clientes` leak across tests in the same PHPUnit process. | Low | Use `spl_autoload_register($cb, true, true)` (prepend) and `spl_autoload_unregister` in `tearDown()`. The existing `ClienteModelTest` and `GrupoClientesModelTest` in the same suite use `new class() extends \FSFramework\model\cliente` (different fully-qualified class names) so they coexist with the `cliente` (global) autoloader stub. |
| `fs_filter_input_req()` in `load_clientes()` reads from `Kernel::request()` (a static singleton), not from `$this->request`. | Low | The Kernel singleton is a separate object from the controller's `$this->request`. In CLI without Kernel booted, `Kernel::request()` throws and the function falls back to `$_REQUEST[$name]`. Setting `$_POST` (which `$_REQUEST` reads) in the test setup covers this. The dispatch gate itself reads `$this->request->request->get(...)` so the `Symfony\Request` we inject is what drives dispatch. |
| The `exit()` call inside `nuevo_cliente()` kills the test runner. | Low | The test calls `dispatch()` directly (not `private_core()`), so the thin `nuevo_cliente()` wrapper with `exit()` is never invoked. `dispatch()` calls `nuevo_cliente_pure()` instead, which has no `exit()`. |
| Refactor changes the production dispatch behavior in a subtle way. | Low | The refactor is a pure extraction: `dispatch()` is the same if/else chain that `private_core()` runs today, just wrapped in a method that returns a structured result. `private_core()` is updated to call `dispatch()` and rely on the side effects the dispatch chain already produces (header/exit are emitted by `nuevo_cliente()` which is still called from `dispatch()`). |

## Rollback plan

Revert the change with `git revert <commit>`. The refactor preserves the public dispatch behavior (same HTTP responses for the same POST bodies), so reverting is safe. No data migration is involved; the `clientes` table schema is unchanged.

## Dependencies

- None new. The test uses the existing PHPUnit 11 setup, the existing `Symfony\Component\HttpFoundation\Request` (already a Composer dependency), and the existing `ReflectionClass` and `spl_autoload_register` PHP standard library primitives.

## Success criteria

- [ ] `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml --filter VentasClientesDispatch` shows 6 new tests, all passing.
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` shows the full plugin suite passing (existing tests + 6 new = no regressions).
- [ ] `ddev exec php vendor/bin/phpunit` shows the full project suite passing (modulo the 1 pre-existing `CsrfTokenTest::expiredTokenIsRejected` failure in `system_updater`).
- [ ] Real-HTTP behavior is unchanged: a POST to `index.php?page=ventas_clientes` with `action=nuevo_cliente&nombre=Test&cifnif=B12345` (and a valid CSRF token) still creates a row in `clientes` and 302-redirects to `ventas_cliente&cod=<code>`.
- [ ] The 6 spec scenarios in `spec.md` (VCT-01.a through VCT-01.f) are all covered by named test methods and pass.

## Capabilities

### New Capabilities
- `ventas-clientes-dispatch`: The dispatch logic in `ventas_clientes::dispatch()` and the regression test that exercises it. Lives at `plugins/clientes_core/controller/ventas_clientes.php` and `plugins/clientes_core/tests/Controller/VentasClientesDispatchTest.php`.

### Modified Capabilities
- None. The dispatch change from the previous change (`ventas-clientes-controller-dedup`) is preserved at the HTTP-behavior level; this change only extracts `dispatch()` as a new public method without altering dispatch semantics.

## nextRecommended

`design` — the next phase is `sdd-design` to write the exact diff for the controller refactor and the test file, with the precise autoloader stub shape, the `dispatch()` return-value contract, and the test-method list mapped 1:1 to the spec scenarios.
