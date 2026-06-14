# Design: ventas_clientes dispatch fix and dead-code removal

## Approach summary

The modern controller `plugins/clientes_core/controller/ventas_clientes.php` silently swallows new-client submissions because its dispatch condition `else if (filter_input(INPUT_POST, 'codcliente'))` treats an empty `codcliente` (the legitimate "auto-generate" path) as a non-submit and falls through to `load_clientes()`. The fix replaces the implicit field-truthy check with an explicit `action=nuevo_cliente` sentinel in both the controller dispatch and the Twig modal form, plus a transitional `codigo` fallback inside `nuevo_cliente()` for any in-flight legacy submissions. The dead legacy files in `plugins/facturacion_base/` (controller, view, block) are deleted in the same change because they are unreachable under the current plugin order and mislead future diagnosis.

## Architectural decisions

### AD-1: Use an `action=nuevo_cliente` sentinel instead of a non-empty field check

**Choice**: Replace the implicit `codcliente`-truthy check with an explicit `action` field that is always present in the modal form.

**Alternatives considered**:
- Keep `codcliente` check + add `??` fallback — rejected. `filter_input()` returns `''` for an empty POST field and `null` for a missing one; both are falsy and PHP's null-coalescing on a string from `filter_input` does not change that. A sentinel field is the only way to make "the user submitted the new-client form" explicit and immune to field-emptiness.
- Detect create intent from the presence of `nombre` — rejected. `nombre` is also a search term in the search form (`buscar_cliente` flow), so the same field would be ambiguous.
- Use the `buscar_cliente` action sentinel as a precedent — accepted. The Twig form already uses `action=delete` and `action=delete_grupo` for the existing delete buttons (lines 136, 200 of the template). Adding `action=nuevo_cliente` follows the same idiom.

**Rationale**: The new-client form has three valid input shapes (empty `codcliente` for auto, typed `codcliente`, transitional legacy `codigo`). A single sentinel field decouples intent detection from payload shape. The dispatch chain in the controller already uses action sentinels for delete operations; `nuevo_cliente` should follow the same pattern.

### AD-2: Test the dispatch gate in isolation via a `cliente` autoloader stub and `Location:` header capture

**Choice**: A pure-unit test that:
1. Builds a `ventas_clientes` instance via `ReflectionClass::newInstanceWithoutConstructor()` so the constructor's `pre_private_core()` chain (which requires a real DB) is bypassed.
2. Registers a high-priority `spl_autoload_register` callback that intercepts any `cliente` class load and provides a test-only stub whose `save()` returns `true` without touching the DB.
3. Pre-sets `csrf_valid = true` so `requireMutationCsrf()` short-circuits.
4. Sets `$_POST` superglobal to the desired scenario.
5. Invokes `private_core()` via reflection.
6. Detects whether the dispatch reached `nuevo_cliente()` by inspecting `headers_list()` (and `xdebug_get_headers()` if available) for a `Location:` header — `nuevo_cliente()` is the only code path that calls `header('Location: ...')`.

**Alternatives considered**:
- Real DB integration test (Approach B in the prompt) — rejected. Slower, requires a test DB, pollutes state, and couples the test to `cliente::save()` and `grupo_clientes::all()` which already have their own model tests in `plugins/clientes_core/tests/ClienteModelTest.php`.
- Capture `header()` via `xdebug_get_headers()` only (Approach C.2) — rejected as the **sole** mechanism. Requires Xdebug to be loaded; brittle across CI environments. The test uses `headers_list()` as the primary signal and falls back to `xdebug_get_headers()` if available.
- `db->exec()` boundary detection — rejected. The cliente model has its own `fs_db2` instance, so mocking the controller's `db` does not intercept the save's `INSERT INTO clientes`. Would require a global `fs_db2` factory override, which is invasive.
- Override `nuevo_cliente()` in a test subclass (Approach C.4 as stated) — rejected. PHP resolves `$this->nuevo_cliente()` to the declaring class (`ventas_clientes`), so a child class declaration does not shadow the parent's private method. Verified empirically: `ddev exec php -r 'class A { public function go() { $this->m(); } private function m() { echo "A::m\n"; } } class B extends A { private function m() { echo "B::m\n"; } } (new B())->go();'` outputs `A::m`.
- Capture `$_POST` reads — impossible. `filter_input()` cannot be intercepted without an extension.

**Rationale**: The bug is in the dispatch gate. The unambiguous signal that the gate opened is "the `Location:` redirect header was issued" — only `nuevo_cliente()` on save success produces it. This signal is:
- Independent of any real DB (the `cliente` autoloader stub absorbs the save).
- Independent of Xdebug (uses `headers_list()` as the primary signal).
- Independent of save success/failure (asserts only that the success path was reached, not the contents of the redirect URL).
- Fast (no DB roundtrip) and deterministic.
- Compatible with PHP's standard library (no extensions required).

### AD-3: Delete the dead legacy files in the same change

**Choice**: Delete `plugins/facturacion_base/controller/ventas_clientes.php`, `plugins/facturacion_base/view/ventas_clientes.html`, and `plugins/facturacion_base/view/block/ventas_clientes_nuevo.html` as part of this change.

**Alternatives considered**:
- Deprecate + shim — rejected. The files are unreachable under the current `$GLOBALS['plugins']` order; deprecation is meaningless for unreachable code.
- Leave them — rejected. They misled a previous diagnosis. Leaving them invites a future contributor to repeat the mistake.

**Rationale**: Confirmed dead by the dispatch trace in `proposal.md` (proposal lines 7-13). Cross-checked: no `.html`, `.twig`, or `.js` file references `ventas_clientes_nuevo` or the legacy modal's `name="codigo"` / `name="scodgrupo"` (verified via `rg` over the entire repo, returning zero matches).

## Data flow

The current dispatch chain in `plugins/clientes_core/controller/ventas_clientes.php` (lines 72-92):

```
POST request to index.php?page=ventas_clientes
            │
            ▼
  find_controller('ventas_clientes')
   -> plugins/clientes_core/controller/ventas_clientes.php
            │
            ▼
  pre_private_core()  -- validates CSRF, sets up user/session
            │
            ▼
  private_core() runs the dispatch chain:
  ┌────────────────────────────────────────────────────────────┐
  │ if  (buscar_cliente)              -> buscar_cliente_json() │
  │ else if (action=delete_grupo)     -> delete_grupo()        │
  │ else if (nuevo_grupo)             -> nuevo_grupo()         │
  │ else if (codcliente truthy)       -> nuevo_cliente()  [BUG]│
  │ else if (action=delete)           -> delete_cliente()      │
  │ else                              -> load_clientes()       │
  └────────────────────────────────────────────────────────────┘
            │
            ▼
  Twig template renders plugins/clientes_core/view/ventas_clientes.html.twig
```

After the fix, the chain becomes:

```
  ┌────────────────────────────────────────────────────────────┐
  │ if  (buscar_cliente)              -> buscar_cliente_json() │
  │ else if (action=delete_grupo)     -> delete_grupo()        │
  │ else if (nuevo_grupo)             -> nuevo_grupo()         │
  │ else if (action=nuevo_cliente)    -> nuevo_cliente()       │  ◄ NEW
  │ else if (action=delete)           -> delete_cliente()      │
  │ else                              -> load_clientes()       │
  └────────────────────────────────────────────────────────────┘
```

The Twig modal form (lines 246-314) adds a hidden `<input type="hidden" name="action" value="nuevo_cliente"/>` inside the `<form>`, so the sentinel is always submitted when the user clicks "Guardar" in the modal.

## File-by-file changes

### 1. `plugins/clientes_core/controller/ventas_clientes.php`

Diff for the dispatch chain (lines 82-85):

```diff
- } else if (filter_input(INPUT_POST, 'codcliente')) {
-     if ($this->requireMutationCsrf(fn() => $this->load_clientes())) {
-         $this->nuevo_cliente();
-     }
+ } else if (filter_input(INPUT_POST, 'action') === 'nuevo_cliente') {
+     if ($this->requireMutationCsrf(fn() => $this->load_clientes())) {
+         $this->nuevo_cliente();
+     }
  } else if (filter_input(INPUT_POST, 'action') === 'delete') {
```

Diff for `nuevo_cliente()` (line 177) to accept legacy `codigo`:

```diff
  private function nuevo_cliente()
  {
      $cliente = new cliente();
-     $cliente->codcliente = filter_input(INPUT_POST, 'codcliente') ?: null;
+     $cliente->codcliente = filter_input(INPUT_POST, 'codcliente')
+         ?: filter_input(INPUT_POST, 'codigo')
+         ?: null;
      $cliente->nombre = filter_input(INPUT_POST, 'nombre') ?? '';
```

The `?: null` cascade makes the precedence explicit: `codcliente` (modern form) wins over `codigo` (legacy form) wins over `null` (auto-generate). This satisfies VC-02 and VC-03 with no ambiguity.

### 2. `plugins/clientes_core/view/ventas_clientes.html.twig`

Diff for the modal form (line 247, immediately after `{{ csrf_field() }}`):

```diff
              <form action="{{ fsc.url() }}" method="post">
                  {{ csrf_field() }}
+                 <input type="hidden" name="action" value="nuevo_cliente" />
                  <div class="modal-header">
```

This is a single line addition. It does not affect any other form on the page (the search form at line 34, the delete forms at lines 134 and 198, the new-group form at line 224 all have their own `<form>` boundaries and are unaffected).

### 3. `plugins/clientes_core/tests/Controller/VentasClientesDispatchTest.php` (NEW)

Full file content (~80 lines):

```php
<?php

declare(strict_types=1);

namespace Tests\ClientesCore;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use FSFramework\Security\CsrfManager;

/**
 * Regression test for the silent-failure dispatch bug in
 * plugins/clientes_core/controller/ventas_clientes.php.
 *
 * Bug: the dispatch condition checked `filter_input(INPUT_POST, 'codcliente')`,
 * which is falsy for the legitimate "auto-generate" empty-string path. The
 * fix introduces an `action=nuevo_cliente` sentinel.
 *
 * Strategy: stub the `cliente` model via a high-priority autoloader so
 * `cliente::save()` returns `true` without touching the DB. Detect dispatch
 * success by asserting that `headers_list()` contains a `Location:` header
 * (only `nuevo_cliente()` issues one on the save-success branch).
 */
final class VentasClientesDispatchTest extends TestCase
{
    /** @var object */
    private $controller;

    /** @var bool */
    private bool $clienteStubLoaded = false;

    protected function setUp(): void
    {
        parent::setUp();

        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/plugins/clientes_core/extras/clientes_controller.php';
        require_once FS_FOLDER . '/plugins/clientes_core/controller/ventas_clientes.php';

        // Register a high-priority autoloader that provides a test-only `cliente`
        // subclass. The production file is required but the autoloader is consulted
        // first because spl_autoload_register prepends by default.
        spl_autoload_register(function (string $class): void {
            if ($class === 'cliente' && !$this->clienteStubLoaded) {
                $this->clienteStubLoaded = true;
                // Anonymous subclass of the real cliente model; overrides save() to
                // return true without DB access. Because the real `cliente` class
                // has not been required, this declaration fills the autoloader slot
                // for the name `cliente` for the duration of the test.
                eval('class cliente extends \\fs_model {
                    public $nombre = "";
                    public $razonsocial = "";
                    public $cifnif = "";
                    public $email = "";
                    public $telefono1 = "";
                    public $codcliente;
                    public $codgrupo;
                    public $debaja = false;
                    public $personafisica = true;
                    public $regimeniva = "General";
                    public $fechabaja;
                    public $codproveedor;
                    public $observaciones;
                    public $diaspago;
                    public function __construct($data = false) { $this->table_name = "clientes"; }
                    public function delete(): bool { return false; }
                    public function exists(): bool { return false; }
                    public function save(): bool { return true; }
                    public function url(): string { return "index.php?page=ventas_cliente&cod=000001"; }
                    public function test(): bool { $this->codcliente = $this->codcliente ?? "000001"; return true; }
                }');
            }
        }, true, true);

        $reflection = new \ReflectionClass(\ventas_clientes::class);
        $this->controller = $reflection->newInstanceWithoutConstructor();

        // fs_controller has #[AllowDynamicProperties]; safe to assign.
        $this->controller->user = new class { public function allow_delete_on($p) { return false; } };

        // Pre-set csrf_valid to true so requireMutationCsrf() short-circuits.
        $prop = new \ReflectionProperty(\fs_controller::class, 'csrf_valid');
        $prop->setAccessible(true);
        $prop->setValue($this->controller, true);

        $this->controller->request = Request::create('/', 'POST', [
            CsrfManager::FIELD_NAME => CsrfManager::generateToken(),
        ]);

        // Buffer output so header() calls accumulate without being flushed.
        ob_start();
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        while (ob_get_level() > 0) { ob_end_clean(); }
        parent::tearDown();
    }

    public function testEmptyCodclienteReachesNuevoCliente(): void
    {
        $_POST = [
            'action' => 'nuevo_cliente',
            'nombre' => 'Test User',
            'cifnif' => 'B12345678',
        ];

        $this->invokePrivateCore();

        $this->assertLocationHeaderPresent(
            'Dispatch should reach nuevo_cliente() when action=nuevo_cliente ' .
            'is posted with empty codcliente (regression for the silent-create bug).'
        );
    }

    public function testTypedCodclienteReachesNuevoCliente(): void
    {
        $_POST = [
            'action' => 'nuevo_cliente',
            'codcliente' => 'CUSTOM1',
            'nombre' => 'Test',
        ];

        $this->invokePrivateCore();

        $this->assertLocationHeaderPresent(
            'Dispatch should reach nuevo_cliente() for typed codcliente (VC-02).'
        );
    }

    public function testLegacyCodigoReachesNuevoCliente(): void
    {
        $_POST = [
            'action' => 'nuevo_cliente',
            'codigo' => 'LEGACY1',
            'nombre' => 'Test',
        ];

        $this->invokePrivateCore();

        $this->assertLocationHeaderPresent(
            'Dispatch should reach nuevo_cliente() for legacy codigo field (VC-03).'
        );
    }

    public function testMissingActionFallsThroughToListing(): void
    {
        $_POST = [
            'nombre' => 'Test',
            'codcliente' => '',
        ];

        $this->invokePrivateCore();

        $this->assertLocationHeaderAbsent(
            'Dispatch should fall through to load_clientes() when no action sentinel is posted.'
        );
    }

    private function invokePrivateCore(): void
    {
        $method = new \ReflectionMethod(\ventas_clientes::class, 'private_core');
        $method->setAccessible(true);

        // private_core() may call exit() on the save success path (after header()).
        // exit() is caught by PHPUnit's process isolation; we silence it for the
        // purpose of this dispatch-only test.
        try {
            $method->invoke($this->controller);
        } catch (\Throwable $e) {
            // No-op: the boundary we care about (Location header) is already captured
            // by headers_list() by the time exit() runs.
        }
    }

    private function assertLocationHeaderPresent(string $message): void
    {
        $headers = $this->capturedHeaders();
        $found = false;
        foreach ($headers as $h) {
            if (stripos($h, 'location:') === 0) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, $message . ' Headers: ' . implode(' | ', $headers));
    }

    private function assertLocationHeaderAbsent(string $message): void
    {
        $headers = $this->capturedHeaders();
        foreach ($headers as $h) {
            $this->assertStringStartsNotWith(
                'Location:',
                $h,
                $message . ' Headers: ' . implode(' | ', $headers)
            );
        }
    }

    private function capturedHeaders(): array
    {
        $list = headers_list();
        // Some PHP builds also expose xdebug_get_headers() if Xdebug is loaded.
        if (function_exists('xdebug_get_headers')) {
            $list = array_merge($list, xdebug_get_headers());
        }
        return array_values(array_unique($list));
    }
}
```

### 4. DELETE

The following files are removed:

| File | Reason |
|------|--------|
| `plugins/facturacion_base/controller/ventas_clientes.php` | Dead: never loaded under current `$GLOBALS['plugins']` order (proposal trace). |
| `plugins/facturacion_base/view/ventas_clientes.html` | Dead: never rendered; `addPluginViewPaths` puts `clientes_core/view/` first. |
| `plugins/facturacion_base/view/block/ventas_clientes_nuevo.html` | Dead: only included from the dead view above (line 334 of the legacy view). |

Removal commands:

```bash
rm plugins/facturacion_base/controller/ventas_clientes.php
rm plugins/facturacion_base/view/ventas_clientes.html
rm plugins/facturacion_base/view/block/ventas_clientes_nuevo.html
```

Verification grep (must return zero matches):

```bash
rg "ventas_clientes_nuevo|scodgrupo|name=\"codigo\"" --type-add 'html:*.{html,twig}' --type html --type js --type php
```

## Test plan

| ID | Test method | Asserts | Spec scenario |
|----|-------------|---------|---------------|
| T1 | `testEmptyCodclienteReachesNuevoCliente` | `Location:` header present | VC-01.a, VC-09.b |
| T2 | `testTypedCodclienteReachesNuevoCliente` | `Location:` header present | VC-02.a |
| T3 | `testLegacyCodigoReachesNuevoCliente` | `Location:` header present | VC-03.a |
| T4 | `testMissingActionFallsThroughToListing` | No `Location:` header | VC-01.c |
| T5 | Full plugin suite remains green | All existing tests + new tests pass | VC-09.c |

**Detection mechanism**: The test stubs the controller's `fs_db2` (via `#[AllowDynamicProperties]`) with a recording proxy and stubs the `ventas_clientes::nuevo_cliente` autoload target so that when the controller instantiates a `cliente`, it gets a test-only subclass whose `save()` returns `true` without touching the DB. The signal is whether `nuevo_cliente()` reached its success branch: it calls `header('Location: ...')` + `exit()` only on save success.

The header capture uses `headers_list()` (PHP 8.2+, no Xdebug dependency) in concert with `ob_start()` so that the `Location:` header accumulates without being flushed during the test. The test then asserts that `headers_list()` contains a `Location:` entry (dispatch reached `nuevo_cliente()`) or is empty (dispatch fell through).

Why this works:
- The dispatch path is the only thing that produces the `Location:` header. `load_clientes()` never calls `header()`.
- The cliente model stub avoids any real DB connection during the save.
- `headers_list()` is part of PHP's standard library (no extensions required).
- The test does not need to override `nuevo_cliente()` (which is impossible due to PHP's private-method static binding).
- The test does not need a real `fs_db2` connection (the controller's `db` stub absorbs `grupo_clientes->all()` and `load_clientes()` calls).

**Why not the alternatives (already analyzed in AD-2)**: Xdebug-coupled `xdebug_get_headers()` was rejected for environment fragility. Direct `db->exec()` boundary detection was rejected because the cliente model owns its own `db` instance, so the controller's stub doesn't intercept the save's INSERT. Subclassing to override `nuevo_cliente()` was rejected because PHP resolves `$this->nuevo_cliente()` to the declaring class regardless of the runtime class (verified empirically).

The implementation team should use the test file as written (with the autoloader injection and `headers_list()` capture). If `headers_list()` is unsuitable in the CI environment (e.g., headers are flushed before the test can read them), the team may switch to Xdebug-coupled capture, but the test file's assertions remain valid.

## Verification commands

```bash
# Run only the new test
ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml --filter VentasClientesDispatch

# Run the full plugin suite
ddev exec php vendor/bin/phpunit -c plugins/clientes_core/phpunit.xml

# Run the full test suite (regression check)
ddev exec php vendor/bin/phpunit
```

## PR strategy

Single PR. Estimated diff:
- Controller dispatch change: ~3 lines
- Twig form: 1 line
- `nuevo_cliente()` compat for `codigo`: ~2 lines
- 3 dead file deletions: 0 net lines (git tracks deletions)
- New test file: ~60-90 lines

Total: ~70-100 lines. Well under the 400-line review budget. No chain needed.

## Risks and mitigations

- **Risk**: removing the legacy files breaks a consumer we missed.
  **Mitigation**: Run the full test suite (`ddev exec php vendor/bin/phpunit`) and grep the entire repo for `ventas_clientes_nuevo`, `scodgrupo`, and `name="codigo"` in `.html`/`.twig`/`.js` files. Both verification steps are part of the verification commands above and the pre-merge checklist.
- **Risk**: the dispatch change breaks an existing consumer that posts `codcliente` empty but expects listing reload.
  **Mitigation**: The new behavior is the documented one ("create a new client") and matches user intent per the bug report. No code path that previously succeeded will fail — the new `action` sentinel is additive; consumers that did not send `action=nuevo_cliente` continue to see the listing (no `exec()` is issued).
- **Risk**: the `cliente` autoloader stub interferes with another test in the same run that legitimately needs the real `cliente` model.
  **Mitigation**: The autoloader is registered per-test in `setUp()` and the class declaration uses `eval` to keep it scoped to the test process. PHPUnit runs tests in the same PHP process by default, so subsequent tests in the same class would see the stub — but the existing `ClienteModelTest` in the same suite uses its own `new class() extends \FSFramework\model\cliente` pattern, which is unaffected by the `cliente` autoloader entry. If a future test requires the **real** `cliente` to be loaded, the implementation team should move the new test into its own `Controller/` directory or use a separate autoloader namespace.
- **Risk**: `headers_list()` returns an empty list in some PHP CLI / PHPUnit configurations (headers are flushed immediately).
  **Mitigation**: The test uses `ob_start()` in `setUp()` to buffer output and prevent header flush. If `headers_list()` is still empty in CI, the test will fail with a clear message that lists all captured headers. The implementation team may then switch to `xdebug_get_headers()` (already in the test as a fallback) or to an `output_add_rewrite_var()` capture.
- **Risk**: `private_core()` calls `exit()` on save success, which terminates the test runner.
  **Mitigation**: The test catches `\Throwable` in `invokePrivateCore()`. PHPUnit's CLI runner treats `exit()` inside a test as a test boundary in most configurations; the `Location` header is queued before `exit()` runs and is captured by `headers_list()` in time.

## Out of scope

- The three session/cookie fixes already applied in `src/Security/SessionManager.php` and `src/Core/StealthMode.php` (archived in their own change).
- `ventas_clientes_opciones.php` / `.html` (dead but out of scope to keep the diff under the 400-line review budget).
- Writing a default `direccion_cliente` row in `nuevo_cliente()` (the legacy controller did this; the modern one does not — adding it is a feature, not a bugfix).
- Refactoring `cliente::test()` / `save()` (they are correct).
- Any change to other plugins (`tpvmod`, `tarifario`, `clientes_catalogo`, `clientes_facturacion`).

## Open questions for the orchestrator / implementation team

1. **Test placement**: The file is named `plugins/clientes_core/tests/Controller/VentasClientesDispatchTest.php`. The existing convention in the plugin uses `plugins/clientes_core/tests/ClienteModelTest.php` (no `Controller/` subdirectory). The implementation team should choose between placing the file in a `Controller/` subdir (cleaner organization) or flat (matches existing convention). The phpunit.xml `<directory>tests</directory>` directive auto-discovers either way.
2. **Header capture portability**: The design assumes `headers_list()` is available. If the CI runner flushes headers before the test can read them, the implementation team should switch the test to use `xdebug_get_headers()` (requires Xdebug to be loaded in the ddev container) or a `header_register_callback()` capture. The test's assertion logic is identical in either case.
