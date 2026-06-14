# Design: ventas_clientes dispatch regression test

## Technical Approach

Two-part change to the post-fix controller on `main` (commit `331daf96`):

1. **Refactor** `plugins/clientes_core/controller/ventas_clientes.php`: extract the if/elseif dispatch chain (lines 72-92) into a new public `dispatch(): array`; extract `nuevo_cliente()` minus `header()`+`exit()` into a new private `nuevo_cliente_pure(): ?cliente`; refactor `nuevo_cliente()` into a 6-line wrapper. `private_core()` keeps the setup and delegates to `$this->dispatch()`.
2. **Add** `plugins/clientes_core/tests/Controller/VentasClientesDispatchTest.php` (~170 lines). 6 test methods (1:1 with VCT-01.a..f) call `dispatch()` on a reflection-built controller with `Symfony\Component\HttpFoundation\Request` + autoloader stubs for `cliente` and `grupo_clientes`.

Refactor is mechanical. HTTP behavior unchanged: `nuevo_cliente()` still emits `header('Location: ...')` + `exit()` on success in production.

## Architecture Decisions

### AD-1: `dispatch(): array` is the public dispatch entry point

**Choice**: Public method returns `['action' => ?string, 'cliente_codcliente' => ?string, 'redirect_url' => ?string, 'errors' => array]` (plain array, not value object).

**Rationale**: The test needs to call dispatch logic without `header()`/`exit()` killing the runner. A public `dispatch()` returning a structured result is the testable seam.

**Rejected**: real HTTP via `php -S` (too heavy); `uopz`/`runkit` stubs (extensions not installed); making `nuevo_cliente()` return the cliente directly (changes the production call site). Plain array matches existing controller conventions.

### AD-2: Test stubs via `eval()` + prepend autoloader

**Choice**: `setUp()` calls `spl_autoload_register($cb, true, true)` for `cliente` and `grupo_clientes`. The callback declares stubs via `eval()` only when the class is not already loaded. `tearDown()` unregisters.

**Rationale**: The controller calls `new cliente()` / `new grupo_clientes()` (global names). The real `fs_model_autoloader` resolves them to namespaced models via `class_alias()`. To intercept, the test must prepend its own callback. `eval()` declares minimal stubs extending `\fs_model`.

**Rejected**: anon classes (no usable global name); `class_alias()` of the real namespaced class (points to real class with DB-touching methods); temp `.php` files (more complex teardown).

### AD-3: Stub `cliente::test()` mirrors the real regex

**Choice**: Default stub `test()` returns `true` and mirrors the real auto-generation (null `codcliente` → `'000001'`). For VCT-01.f, a second stub `cliente_strict_format` returns `false` for any `codcliente` not matching `/^[A-Z0-9]{1,6}$/i` (mirroring `cliente.php:454`); `save()` is never called.

**Rationale**: For VCT-01.f to exercise the rejection path, the stub must reject `TOOLONG123`. Mirroring the real regex keeps the stub honest.

### AD-4: `nuevo_cliente_pure()` is `private`; test calls `dispatch()` directly

**Choice**: Private helper. Test builds controller via `ReflectionClass::newInstanceWithoutConstructor()` and calls `dispatch()` (not `private_core()`).

**Rationale**: `private_core()` runs the full fs_controller bootstrap (DB connect, user load, menu, `pre_private_core()` → `validateCsrf()`) — needs real state. `dispatch()` is the smallest unit without the bootstrap.

## Data Flow

```
private_core() ◄───(prod) HTTP request       (test) ReflectionClass::newInstanceWithoutConstructor() → set props → dispatch()
    │                                              │
    ▼                                              ▼
dispatch()  (same method, two callers)
    ├── buscar_cliente / delete_grupo / nuevo_grupo / delete → requireCsrf + handler
    ├── nuevo_cliente → requireCsrf
    │     ├── (prod) nuevo_cliente() → nuevo_cliente_pure() → header('Location: ...') + exit()
    │     └── (test) nuevo_cliente_pure() returns cliente → dispatch() sets codcliente + url
    └── else → load_clientes()   (action=null)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `plugins/clientes_core/controller/ventas_clientes.php` | Modify | Add `dispatch(): array` and `nuevo_cliente_pure(): ?cliente`; refactor `nuevo_cliente()` to 6-line wrapper; shorten `private_core()`. Net: +50-60 lines. |
| `plugins/clientes_core/tests/Controller/VentasClientesDispatchTest.php` | Create | New test class, ~170 lines. 6 test methods, autoloader stubs + reflection. |

## Interfaces / Contracts

**`ventas_clientes::dispatch(): array`** (new, public): 4-key array `action`, `cliente_codcliente`, `redirect_url`, `errors`. Not idempotent — production calls it exactly once from `private_core()`. Full shape in the test's `@return` PHPDoc.

**`ventas_clientes::nuevo_cliente_pure(): ?cliente`** (new, private): returns saved `\FSFramework\model\cliente` on success or `null` on failure. Does NOT emit `header()`/`exit()`. The thin wrapper `nuevo_cliente(): void` (also `private`) calls it and emits the HTTP side effects on success.

**Test stubs** (declared via eval in setUp, unregistered in tearDown): two stub classes (with a strict-format variant for VCT-01.f). Each extends `\fs_model`, implements `delete()`/`exists()` returning `false`, and provides `test()`, `save()`, `all()`, `url()`, `get_errors()`. Default `cliente` auto-generates `codcliente='000001'` when null. `cliente_strict_format` returns `false` from `test()` for invalid codes.

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit (PHPUnit CLI) | 6 dispatch scenarios VCT-01.a..f | `VentasClientesDispatchTest` with reflection + `Symfony\Request` + autoloader stubs. |
| Integration (real HTTP) | Production unchanged | `curl` smoke (V7 from previous change): POST with valid CSRF + `action=nuevo_cliente&nombre=AfterRefactor&cifnif=B-REFAC` → expect HTTP 302, redirect to `ventas_cliente&cod=...`, row in DB. Cleanup with `DELETE FROM clientes WHERE nombre='AfterRefactor'`. |
| Regression (full suites) | No existing test breaks | `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml` (27 + 6 = 33). `ddev exec php vendor/bin/phpunit` (modulo 1 pre-existing `CsrfTokenTest::expiredTokenIsRejected` failure in `system_updater`). |

## Migration / Rollout

No migration. `clientes` schema unchanged. HTTP behavior unchanged for all valid POST bodies. The 6 new tests are additive. Rollback is a single `git revert`.

## Open Questions

None. All 4 critical discoveries from the proposal (autoloader stubs, `$_POST` fallback, `grupo_clientes` stub, `cliente::test()` mirror) are addressed in AD-2, AD-3.
