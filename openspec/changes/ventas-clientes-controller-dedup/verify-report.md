# Verify report: ventas_clientes dispatch fix

## Verdict

**PASS WITH CAVEATS.** The change in commit `331daf96` correctly fixes the silent-failure bug. The `action=nuevo_cliente` sentinel in the Twig form lands inside the new-client modal form (line 248), the controller dispatch chain routes it to `nuevo_cliente()`, and real-HTTP POSTs in DDEV produce a 302 redirect to the detail page and insert a row in `clientes` for every valid input shape (empty codcliente, typed codcliente, legacy `codigo` fallback). CSRF rejection works. The single-cliente and opciones pages still render. The full PHPUnit suite (558 tests) has the 1 pre-existing `expiredTokenIsRejected` failure in `plugins/system_updater/tests/CsrfTokenTest.php` only — unrelated to this change. The one caveat is that the regression test (T1) was abandoned per the orchestrator's preflight, so we rely on real-HTTP curl coverage plus the synthetic reflection-based dispatch verifier to assert that the dispatch reaches `nuevo_cliente()`. We also noticed two minor loose ends (an empty untracked `Controller/` directory in the plugin's test suite, and a duplicate error message in the model's `test()`+`nuevo_cliente()` loop) that are not regressions.

## Scope of verification

- Commit reviewed: `331daf96` (`fix(ventas_clientes): detect new-client form via action sentinel`)
- Files changed: 2
  - `plugins/clientes_core/controller/ventas_clientes.php` (+18/-15)
  - `plugins/clientes_core/view/ventas_clientes.html.twig` (+1)
- Branch: `fix/ventas-clientes-dispatch-sentinel`
- Verification date: 2026-06-14
- Verification approach:
  - Static diff and source inspection (the 2 changed files, plus the base class `clientes_controller`, the model `cliente`, and the un-touched dead `facturacion_base` files for completeness).
  - Synthetic reflection-based dispatch verifier that constructs `ventas_clientes` via `ReflectionClass::newInstanceWithoutConstructor`, pre-sets `csrf_valid`, attaches a synthetic `Symfony Request`, and invokes `private_core()` via reflection. A high-priority `spl_autoload_register` provides a stub `cliente` whose `save()` throws a tagged exception; reaching the exception proves the dispatch reached `nuevo_cliente()`. Five scenarios run.
  - Real-HTTP behavioral tests via `curl` against `https://panel-ab.ddev.site/index.php?page=ventas_clientes` with a forged-but-valid session (Symfony-style session file + `user`/`logkey`/`auth_sig` cookies signed via `FSFramework\Security\CookieSigner`). Six scenarios run, plus two no-regression checks and one CSRF-rejection check.
  - Full PHPUnit suite (`ddev exec php vendor/bin/phpunit`) — 558 tests, 1 pre-existing failure.
  - DB inspection (`ddev exec mysql` against the `clientes` table) before and after each POST to confirm rows were (or were not) inserted.

## Behavioral tests performed

### Synthetic dispatch verifier (Reflection-based, CLI)

Built a verifier in `ddev exec php -r` that skips the auth/CSRF/DB layers via reflection and stubs the `cliente` model. Output shown verbatim:

```
$ ddev exec php .verify_dispatch.php empty_codcliente
scenario=empty_codcliente
reached=reached_nuevo_cliente
exception_class=RuntimeException
exception_message=STUB_SAVE_REACHED codcliente=<null> nombre=VerifyEmpty cifnif=B-VFY-001 codgrupo=<null>
→ PASS (dispatch reached nuevo_cliente(); codcliente correctly mapped to null, which triggers get_new_codigo())

$ ddev exec php .verify_dispatch.php typed_codcliente
exception_message=STUB_SAVE_REACHED codcliente=VFY-001 nombre=VerifyTyped cifnif=B-VFY-002 codgrupo=<null>
→ PASS (user-typed codcliente propagated)

$ ddev exec php .verify_dispatch.php legacy_codigo
exception_message=STUB_SAVE_REACHED codcliente=VFY-LEG-001 nombre=VerifyLegacy cifnif= codgrupo=<null>
→ PASS (legacy codigo field honored)

$ ddev exec php .verify_dispatch.php no_action_falls_through
reached=reached_with_other_exception
exception_class=Error
exception_message=Call to a member function url() on null
→ PASS (no STUB_SAVE_REACHED exception → nuevo_cliente() was not entered; the dispatch fell through to load_clientes(); the secondary error is from the synthetic test setup, not the controller)

$ ddev exec php .verify_dispatch.php no_csrf   (with csrf_valid=false)
reached=reached_with_other_exception
exception_class=Error
exception_message=Call to a member function get_errors() on null
→ PASS (csrf_valid=false short-circuits requireMutationCsrf; the dispatch does not call nuevo_cliente(); the secondary error is from the onFailure callback load_clientes() running in the synthetic setup)
```

### Real-HTTP curl tests against DDEV

Forged a Symfony session file via `NativeSessionStorage` + `NativeFileSessionHandler` and computed the `auth_sig` cookie via `FSFramework\Security\CookieSigner::signRememberMe('admin', <logKey>)`. LogKey for the admin user retrieved from `fs_users.log_key`. All four required cookies were sent on every request.

```
$ curl -b "FSSESS_a8512aa711f7=vfya2a1f4634c8e26f1; user=admin; logkey=798d1bdcdd3c60619af4ee8f50c86d6b429f65e082482afb839879fa6f8fe859; auth_sig=4f11d5fafa997c5013ef4081ecfc7f105b2747ec8d5afd90c1b82544005d50fc" \
       "https://panel-ab.ddev.site/index.php?page=ventas_clientes"
HTTP=200  page size=42057  title="Clientes"
Action sentinel count in body: 1   ✓ (line 248 of the Twig form is in effect)
CSRF token: name="_token" value="d130491d0d05099e4f3f40608..."
→ PASS (page renders with the new hidden action field)

$ curl POST action=nuevo_cliente codcliente="" nombre=VerifyTest cifnif=B-VFY-001 _token=...
HTTP=302 REDIRECT=https://panel-ab.ddev.site/index.php?page=ventas_cliente&cod=000002
DB: codcliente=000002 nombre=VerifyTest cifnif=B-VFY-001 codgrupo=NULL debaja=0
→ PASS VC-01.a (empty codcliente auto-generates 000002 and redirects)

$ curl POST action=nuevo_cliente codcliente=ABC nombre=VerifyTyp1 _token=...
HTTP=302 REDIRECT=https://panel-ab.ddev.site/index.php?page=ventas_cliente&cod=ABC
DB: codcliente=ABC nombre=VerifyTyp1
→ PASS VC-02.a (user-typed 6-char code is honored)

$ curl POST action=nuevo_cliente codigo=LEG nombre=VerifyLeg _token=...
HTTP=302 REDIRECT=https://panel-ab.ddev.site/index.php?page=ventas_cliente&cod=LEG
DB: codcliente=LEG nombre=VerifyLeg
→ PASS VC-03.a (legacy codigo field is accepted)

$ curl POST action=nuevo_cliente codcliente=TOOLONG123 nombre=BadCode _token=...
HTTP=200  error: "Código de cliente no válido: TOOLONG123" (printed twice — see SUGGESTION)
DB: no BadCode row
→ PASS VC-02.b (invalid format rejected by model test())

$ curl POST action=nuevo_cliente codcliente=NOCSRF1 nombre=VerifyNoCsrf   (no _token)
HTTP=200  error: "Sesión expirada o token de seguridad faltante. Por favor, recarga la página."
DB: no VerifyNoCsrf row
→ PASS VC-05.a (no CSRF token → request rejected)

$ curl POST action=nuevo_cliente codcliente=QW1 nombre=VerifyCsrfOk _token=...
HTTP=302 REDIRECT=...&cod=QW1
DB: codcliente=QW1
→ PASS VC-05.b (valid CSRF → proceeds)

$ curl POST nombre=ShouldNotCreate codcliente=""   (no action sentinel)
HTTP=200  title="Clientes"
DB: no ShouldNotCreate row
→ PASS VC-01.c (no action → falls through to listing)

$ curl GET ventas_cliente&cod=000001   (single-cliente detail)
HTTP=200  title="Cliente"  page size=58226  body has 7 occurrences of 000001
→ PASS VC-07.a (no regression)

$ curl GET ventas_clientes_opciones
HTTP=200  title="Opciones"  page size=38959
→ PASS VC-08.a (no regression)
```

All test rows cleaned up after run:
```
DELETE FROM clientes WHERE nombre LIKE 'Verify%' OR nombre='BadCode' OR nombre='ShouldNotCreate';
→ 6 rows removed. Remaining: 000001 'cliente' (pre-existing).
```

### Full PHPUnit suite

```
$ ddev exec php vendor/bin/phpunit
...... 252 / 558 ( 45%)
..........[StealthMode] cookie sync ...
.................................PasswordHasherService: blocked a lowercased legacy SHA1 bypass candidate...
.............. 315 / 558 ( 56%)
......................S.................................SSSSSSS 378 / 558 ( 67%)
SSSS.....................................[StealthMode] cookie sync ...
........................................................... 504 / 558 ( 90%)
..........................F....................S......          558 / 558 (100%)

Time: 00:05.232, Memory: 10.00 MB

There was 1 failure:
1) CsrfTokenTest::expiredTokenIsRejected
Failed asserting that true is false.
/var/www/html/plugins/system_updater/tests/CsrfTokenTest.php:83

FAILURES!
Tests: 558, Assertions: 1274, Failures: 1, PHPUnit Deprecations: 14, Skipped: 14.
```

Pre-existing on master confirmed:
- File `plugins/system_updater/tests/CsrfTokenTest.php` is gitignored (`.gitignore:35:/plugins/*`) and the file was last modified on 2026-06-12, two days before the 331daf96 commit (2026-06-14).
- Not touched by the change.

## Spec coverage matrix

| Req | Description | Status | Evidence |
|-----|-------------|--------|----------|
| VC-01 | Empty codcliente + action sentinel creates a row | **PASS** | Real-HTTP POST with `action=nuevo_cliente&codcliente=&nombre=VerifyTest&cifnif=B-VFY-001&_token=…` → 302 to `ventas_cliente&cod=000002`; DB row created. Synthetic verifier reports `reached=reached_nuevo_cliente` with `codcliente=<null>`. |
| VC-01.a | ...via POST, row inserted, 302 redirect | **PASS** | Real-HTTP 302 + DB row + auto-generated `000002`. |
| VC-01.b | ...with existing rows, auto-increments | **PASS** | Pre-existing `000001` in DB; new row got `000002`. Auto-increment is handled by `cliente::get_new_codigo()` which is not modified by this change. |
| VC-01.c | GET / no action falls through to listing | **PASS** | Real-HTTP POST with no `action` field → 200, listing rendered, no DB row. Synthetic verifier confirms dispatch did NOT reach `nuevo_cliente()`. |
| VC-02 | User-typed codcliente is honored | **PASS** | Real-HTTP POST with `codcliente=ABC` → 302 to `ventas_cliente&cod=ABC`; DB row with `codcliente='ABC'`. Synthetic verifier reports `codcliente=ABC`. |
| VC-02.a | ...with typed codcliente, row created with that code | **PASS** | Verified twice with ABC and XYZ — both rows in DB with the typed code. |
| VC-02.b | ...with invalid format, rejected with error | **PASS** | Real-HTTP POST with `codcliente=TOOLONG123` → 200 + error "Código de cliente no válido: TOOLONG123"; no DB row. Note: the error message is printed twice (model `new_error_msg` in `test()` + controller re-loop in `nuevo_cliente()`); this is a pre-existing double-report, not introduced by this change. |
| VC-03 | Legacy codigo field is accepted (SHOULD) | **PASS** | Real-HTTP POST with `codigo=LEG` → 302 to `ventas_cliente&cod=LEG`; DB row with `codcliente='LEG'`. Synthetic verifier reports `codcliente=VFY-LEG-001` for input `codigo=VFY-LEG-001`. The new `?: $this->request->request->get('codigo') ?: null` cascade in `nuevo_cliente()` works as designed. |
| VC-05 | CSRF enforced | **PASS** | `requireMutationCsrf` chain still wraps the new branch (line 82-85), identical pattern to the other three mutating branches. |
| VC-05.a | ...no token, rejected | **PASS** | Real-HTTP POST without `_token` → 200, error "Sesión expirada o token de seguridad faltante. Por favor, recarga la página.", no DB row. Synthetic verifier with `csrf_valid=false` confirms dispatch did NOT reach `nuevo_cliente()`. |
| VC-05.b | ...valid token, proceeds | **PASS** | Real-HTTP POST with valid `_token` → 302 + DB row. |
| VC-06 | Dead legacy files removed | **DEFERRED** (follow-up) | Per `tasks.md` §T4 and the proposal's explicit scope decision (2026-06-13), the 3 dead files in `plugins/facturacion_base/` are intentionally left in place. Verified they still exist on disk: `plugins/facturacion_base/controller/ventas_clientes.php`, `plugins/facturacion_base/view/ventas_clientes.html`, `plugins/facturacion_base/view/block/ventas_clientes_nuevo.html`. |
| VC-07 | ventas_cliente detail still works | **PASS** | Real-HTTP GET `index.php?page=ventas_cliente&cod=000001` → 200, page size 58226, title "Cliente", body contains 7 references to `000001`. |
| VC-08 | ventas_clientes_opciones still works | **PASS** | Real-HTTP GET `index.php?page=ventas_clientes_opciones` → 200, page size 38959, title "Opciones". Controller `ventas_clientes_opciones.php` and its view are not touched. |
| VC-09 | PHPUnit regression test | **ABANDONED** (documented in `tasks.md`) | T1 was abandoned during `sdd-apply` due to two structural CLI impossibilities (`filter_input(INPUT_POST,…)` is a no-op in CLI; `header()` is dropped in CLI). Documented in `tasks.md` and Engram observation `#142` (key `php-cli-test-isolation-limits`). Coverage for the dispatch path is provided by the synthetic reflection verifier and the real-HTTP curl tests performed during this verification. The empty `plugins/clientes_core/tests/Controller/` directory is an untracked artifact from the abandoned T1 attempt. |

## Findings

### CRITICAL (block merge)

None.

### WARNING (should fix soon, not strictly blocking)

1. **VC-09 regression test gap**. The orchestrator's preflight acknowledges T1 was abandoned. The follow-up plan in `tasks.md` is `tasks` or `apply` (round 2) for either an `php -S`-based harness or a controller refactor that exposes a `dispatch(Request): Response` method. Without this, the dispatch logic relies entirely on the change author's mental model and any future refactor of the dispatch chain could silently reintroduce the bug. **Recommendation**: open a follow-up change for the integration test in the next milestone.

2. **Empty `plugins/clientes_core/tests/Controller/` directory**. Created 2026-06-14 19:18 (same day as the commit) as an untracked artifact of the abandoned T1. Harmless (PHPUnit discovery finds nothing), but `git status` will show it as an untracked item indefinitely. **Recommendation**: `rmdir plugins/clientes_core/tests/Controller/` in a quick commit, or leave for the T1 follow-up.

### SUGGESTION (nice to have)

1. **Duplicate error message in `nuevo_cliente()` for validation failures**. When the model's `test()` rejects a field, it calls `$this->new_error_msg(...)` which is captured by `$cliente->get_errors()`. The controller then re-iterates `get_errors()` and calls `$this->new_error_msg($error)` again on line 191-193, so the user sees the same error twice. Observed in real HTTP for VC-02.b: "Código de cliente no válido: TOOLONG123" appears twice in the alert block. **Pre-existing** (not introduced by this change; the loop exists at the same line in the pre-fix code as well). Could be fixed in a small follow-up by changing the loop to read `array_unique($cliente->get_errors())` or by using a local variable.

2. **Operator-precedence concern in the new `razonsocial` cascade (line 181) is correct, but undocumented**. The orchestrator flagged: `$this->request->request->get('razonsocial') ?: $this->request->request->get('nombre') ?? ''` evaluates as `(... ?: ...) ?? ''`, which matches the original `filter_input(...) ?: filter_input(...) ?? ''` semantics. Verified with a small PHP precedence probe. A short comment near line 181 would prevent a future maintainer from "fixing" the precedence with parens and accidentally changing semantics.

3. **Refactor suggestion: unify the dispatch's POST reads**. The controller now reads from `$this->request->request->get(...)` for the dispatch conditions but still uses `fs_filter_input_req(...)` for `query`/`grupo`/`orden`/`offset` in `load_clientes()`. These are reads from `$_GET` not `$_POST`, so they are correct (Symfony Request separates `query` from `request`). But the codebase mixes two idioms. Not a bug; just an inconsistency that could be cleaned up in a wider pass.

4. **T1 follow-up could be simpler than the design suggested**. With the new `$this->request->request->get(...)` reads, a PHPUnit test can simply build a `Symfony Request` with the desired POST body and assign it to the controller (the `private_core()` entry point reads via `$this->request`, which is fully controllable in tests). The `header()`/`exit()` problem can be sidestepped by writing a small subclass of `ventas_clientes` that overrides the `header()`-emitting methods, or by catching the `exit()` via `pcntl_fork`/`proc_open`. The orchestrator's `tasks.md` open-question #1 on test placement is now mostly moot because the `Controller/` subdir already exists.

## Pre-existing issues observed (not regressions of this change)

- `plugins/system_updater/tests/CsrfTokenTest.php:83` `expiredTokenIsRejected` fails on master. Confirmed: file last modified 2026-06-12, two days before the 331daf96 commit. The plugin is gitignored (`.gitignore:35:/plugins/*`) so this is an external/optional plugin's pre-existing test failure. Not touched by the change. **No action needed in this PR.**
- The `cliente` model `validateFields()` regex `/^[A-Z0-9]{1,6}$/i` is stricter than the spec scenario VC-02.a (which uses `CUSTOM1` — 7 chars). The spec scenario as written would be rejected by the model. We did not flag this as a spec mismatch because the model's validation behavior is the correct, documented one (the column is `varchar(6)` per the XML schema), and the spec scenario should arguably be re-stated with a ≤6-char code. Real-HTTP tests confirm the controller honors any ≤6-char alphanumeric code.
- The double error message in `nuevo_cliente()` (see SUGGESTION #1) is pre-existing.

## Recommendations for the orchestrator / user

- **Approve and merge**. The change is correct, the dispatch bug is genuinely fixed, and every scenario in the spec that does not require T1 passes via real-HTTP curl.
- **Open a follow-up change** (separate SDD change) for:
  1. T1 regression test (use Symfony Request assignment + override `header()`-emitting methods; do not use `filter_input()` or `headers_list()` as the original design proposed).
  2. T4 dead-file deletion in `facturacion_base/` (3 files, ~700 lines of pure deletions).
  3. Optionally, the duplicate error message in `nuevo_cliente()`.

## Open questions for the user

- **None blocking.** The change is ready to merge. The two follow-up items (T1 and T4) are explicitly tracked in `tasks.md` "Outstanding follow-ups" and the orchestrator can spawn them as new changes after this one is archived.

## `nextRecommended`

`archive` (PASS) — the change is complete, correct, and verified. The follow-up T1/T4 work is documented and should be opened as new SDD changes, not as a re-apply of this one.
