# Tasks: ventas_clientes dispatch regression test

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~220 (50-60 controller + 170 test) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR, 2 commits (T1, T2). T3 = verification. |
| Delivery strategy | ask-on-risk |
| Chain strategy | n/a |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: n/a
400-line budget risk: Low

## Task order and dependencies

```
T1 (test, red) → T2 (refactor, green) → T3 (real-HTTP smoke, verification only)
```

T1 first: tests fail because `dispatch()` and `nuevo_cliente_pure()` don't exist. T2 exposes them. T3 confirms prod unchanged.

## T1 — Add dispatch regression test (red)

- **ID**: T1
- **Files**: `plugins/clientes_core/tests/Controller/VentasClientesDispatchTest.php` (new, ~170 lines)
- **Work**:
  - [x] Branch `test/ventas-clientes-dispatch-regression` from `main`. Create `tests/Controller/`.
  - [x] Write 5 test methods mapped 1:1 to VCT-01.a..e. (VCT-01.f skipped — see Deviations.)
  - [x] `setUp()`: reset `fs_core_log` (data_log + controller_name + fs_model::$core_log); register prepend autoloader stubs (`cliente`, `grupo_clientes`); build controller via `ReflectionClass::newInstanceWithoutConstructor()`; set `user`, `page`, `class_name`, `core_log`, `cache`, `db`, `request`, `csrf_valid=true`.
  - [x] `tearDown()`: clear `$_POST`/`$_REQUEST`; unregister autoloaders; `ob_end_clean()`; reset `fs_core_log`.
  - [x] Stubs extend `\fs_model`, `delete()`/`exists()` return `false`. `cliente::save()` calls `test()` to trigger auto-gen.
- **Verify (red)**: `ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml --filter VentasClientesDispatch` — 5 fail with "Call to undefined method ventas_clientes::dispatch()". Confirmed.
- **Rollback**: `git rm plugins/clientes_core/tests/Controller/VentasClientesDispatchTest.php`
- **Commit**: `875fdfeb` `test(ventas_clientes): add dispatch regression test (red)`
- **Covers**: VCT-01.a..e

## T2 — Refactor controller to expose `dispatch()` and `nuevo_cliente_pure()` (green)

- **ID**: T2
- **Files**: `plugins/clientes_core/controller/ventas_clientes.php` (+50-60 lines)
- **Work**:
  - [x] Add public `dispatch(): array` with the if/elseif chain extracted from `private_core()`.
  - [x] Add private `nuevo_cliente_pure(): ?cliente` — body of `nuevo_cliente()` minus `header()` + `exit()`.
  - [x] Refactor `nuevo_cliente(): void` to 6-line wrapper; on non-null, `header('Location: ' . $cliente->url())` + `exit()`.
  - [x] `private_core()` calls `$this->dispatch()`. Keep setup (allow_delete, offset/orden, `grupos`).
- **Verify (green)**: same command as T1 — 5 pass. Confirmed.
- **Rollback**: `git checkout HEAD~1 -- plugins/clientes_core/controller/ventas_clientes.php`
- **Commit**: `a0fc2e47` `refactor(ventas_clientes): extract dispatch() and nuevo_cliente_pure() for testability`
- **Covers**: VCT-02..VCT-06

## T2.5 — Preserve production 302 redirect (post-T2 fix)

- **ID**: T2.5
- **Files**: `plugins/clientes_core/controller/ventas_clientes.php` (+9 lines)
- **Work**:
  - [x] `private_core()` consumes dispatch()'s `redirect_url` and emits `header('Location: ...') + exit()` to preserve pre-refactor 302 behavior.
  - [x] The thin `nuevo_cliente()` wrapper remains as a documented seam (currently dead code in production flow).
- **Verify (real-HTTP)**: POST with valid CSRF returns HTTP 302 with `Location: ventas_cliente&cod=...`. Confirmed.
- **Commit**: `aab37a2b` `fix(ventas_clientes): preserve 302 redirect in private_core after refactor`

## T3 — Real-HTTP smoke (verification only)

- **ID**: T3
- **Files**: none
- **Work**:
  - [x] V7 curl: POST with valid CSRF + `action=nuevo_cliente&nombre=AfterRefactor&cifnif=B-REFAC` → expect HTTP 302, redirect to `ventas_cliente&cod=...`. Result: HTTP=302, REDIRECT=`ventas_cliente&cod=000002`.
  - [x] Verify row: `ddev exec mysql -e "SELECT codcliente, nombre FROM clientes WHERE nombre='AfterRefactor';"` → 1 row (`000002`).
  - [x] Cleanup: `ddev exec mysql -e "DELETE FROM clientes WHERE nombre='AfterRefactor';"`. Confirmed 0 rows remaining.
- **Verify**: results in verify-report.
- **Commit**: none.
- **Covers**: VCT-07

## Deviations from design.md

1. **VCT-01.f (invalid codcliente format) skipped.** PHP cannot undeclare or swap a class mid-process, so the test cannot load a `cliente_strict_format` stub after `cliente` was declared by an earlier test. Documented as a follow-up that would require a `@runInSeparateProcess` test or a process-isolated runner. The 5 implemented tests cover all happy-path, fallback, and CSRF scenarios.

2. **VCT-06 / dispatch body adjusted.** The design's `dispatch()` body calls `nuevo_cliente_pure()` directly. But VCT-06 says the production HTTP path MUST 302-redirect. Calling the `nuevo_cliente()` wrapper from dispatch() would invoke `exit()` and kill PHPUnit. Resolution: `dispatch()` returns the `redirect_url`; `private_core()` consumes it and emits `header()`+`exit()`. The thin `nuevo_cliente()` wrapper remains in the file as a documented seam (currently dead code in the production flow).

## Final verification

```bash
ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml --filter VentasClientesDispatch
ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml
ddev exec php vendor/bin/phpunit
```

## PR strategy

Single PR, 2 commits (T1 + T2). T3 = verification. ~220 lines. No chain.

## Risks (from design)

- R1: refactor changes prod → T3 smoke.
- R2: stub false positives → 6 scenarios cover happy + rejection.
- R3: stub drift → docblock note.
- R4: pre-existing `CsrfTokenTest::expiredTokenIsRejected` in `system_updater` → verify-report.

## Out of scope

Refactoring `delete_grupo()` / `nuevo_grupo()` / `delete_cliente()` / `buscar_cliente_json()` / `load_clientes()`. `facturacion_base` plugin. `cliente` model + schema. Session/cookie fixes in `src/Security/SessionManager.php` + `src/Core/StealthMode.php`.
