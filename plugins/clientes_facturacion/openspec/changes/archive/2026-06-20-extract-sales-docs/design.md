# Design: Extract sales-document domain to `clientes_facturacion`

**Change**: `extract-sales-docs` (plugin SDD, lives entirely in `plugins/clientes_facturacion/openspec/`)
**Author**: sdd-design (delegated executor)
**Date**: 2026-06-20

---

## 1. Architecture summary

The post-move plugin graph is a strict DAG. `clientes_facturacion` becomes the **home of the client-side sales domain** (10 models, 3 traits, 10 XMLs, 17 controllers, 15 views) and is the only plugin in the graph that owns those files. `facturacion_base` shrinks to the **optional add-on** (suppliers, accounting, TPV-specific). `catalogo_core` keeps its `fbase_controller` parent class but gains one new dep because it instantiates a moved model at runtime.

```
            ┌───────────────────────┐
            │     business_data     │  (no deps — used by facturacion_base)
            └───────────┬───────────┘
                        │
            ┌───────────▼───────────┐
            │     clientes_core     │  (no deps)
            └───────────┬───────────┘
                        │ require
            ┌───────────▼───────────┐         require
            │ clientes_facturacion  │ ◀────────────────────┐
            │ (sales docs; 10 mdls, │                      │
            │  3 traits, 10 XMLs)   │                      │
            └─────┬───────────┬─────┘                      │
                  │ require   │ require                   │
                  ▼           ▼                            │
        ┌──────────────┐  ┌────────────────────┐    ┌──────┴──────────┐
        │ catalogo_core│  │   facturacion_base │    │  (future tpvmod │
        │  + reg.iva   │  │  (suppliers, acct, │    │   consumer)     │
        │  runtime use │  │   TPV-specific)    │    └─────────────────┘
        └──────────────┘  └────────────────────┘
```

- `clientes_facturacion` keeps `require = "clientes_core"`. The **model layer is functionally standalone** (spec §"Functional standalone"). Controllers have a runtime coupling to `fbase_controller` (in `catalogo_core`) and to `asiento`/`partida`/`asiento_factura`/`cuenta_banco_cliente` (in `facturacion_base`); this coupling is acknowledged as R3 and is a future SDD, not this one.
- `catalogo_core` adds `clientes_facturacion` to its `require` because `fbase_controller.php:688` instantiates `new regularizacion_iva()` at runtime. The autoloader (`fs_model_autoloader`) resolves it transparently once the new home exists.
- `facturacion_base` keeps its full `require` (it already includes `clientes_facturacion`). Its `factura_proveedor` core `require_once`s the moved `factura` trait from `clientes_facturacion/extras/` — the new cross-plugin path.

No cycles. The framework does **not** perform topological sort; it appends on enable (`fs_plugin_manager.php:475`). The user must enable `clientes_facturacion` before `facturacion_base`; the `require` field guarantees they cannot enable `facturacion_base` without `clientes_facturacion`.

---

## 2. Move strategy — atomic groups

Files are grouped so that **after each group the working state is consistent**: no broken `require_once`, no missing class, no admin page that half-resolves. Each group is one PR slice in §5.

### Group A — bootstrap of `clientes_facturacion` (no file moves)

Creates the plugin skeleton without moving any sales file. After this lands, `clientes_facturacion` is a valid empty-but-proper plugin that can be enabled and tested.

| Action | File |
|---|---|
| Create | `plugins/clientes_facturacion/Init.php` (empty `init()` body; placeholder for future event listeners) |
| Create | `plugins/clientes_facturacion/description` (one-line summary) |
| Create | `plugins/clientes_facturacion/translations/messages.es.yaml` (empty scaffold) |
| Create | `plugins/clientes_facturacion/phpunit.xml` (mirrors `plugins/clientes_core/phpunit.xml`, suite name `clientes_facturacion`) |
| Create | `plugins/clientes_facturacion/tests/ModelLoadingTest.php` (10 tests, all initially asserting a sentinel; see §6 — these become real after group B) |
| Modify | `plugins/clientes_facturacion/facturascripts.ini` — bump `version = 1 → 2` |
| Modify | `plugins/clientes_facturacion/fsframework.ini` — bump `version = 1 → 2` |
| Modify | `plugins/catalogo_core/fsframework.ini` — add `clientes_facturacion` to `require` (R4: runtime `new regularizacion_iva()` in `fbase_controller.php:688`) |

**Why atomic**: enables the new plugin without touching any business logic. Tests can be wired and CI can run. No risk of breaking `facturacion_base` (it doesn't depend on the new plugin in any code path that Group A creates).

**Verification**:
```bash
ddev exec php vendor/bin/phpunit --testsuite Plugins       # 0 new tests, no regression
ddev exec php -l plugins/clientes_facturacion/Init.php
ddev exec php -l plugins/clientes_facturacion/tests/ModelLoadingTest.php
```

### Group B — model + trait + XML move (the heart of the change)

Moves 10 shims, 10 cores, 10 XMLs, and 3 traits in one slice. This is **the** slice that makes the model layer standalone.

| Source | Target | Action | Path edit in moved file |
|---|---|---|---|
| `facturacion_base/model/factura_cliente.php` | `clientes_facturacion/model/factura_cliente.php` | Move | `require_once 'plugins/facturacion_base/model/core/factura_cliente.php';` → `require_once 'plugins/clientes_facturacion/model/core/factura_cliente.php';` |
| `facturacion_base/model/core/factura_cliente.php` | `clientes_facturacion/model/core/factura_cliente.php` | Move | **No change** — `__DIR__ . '/../../extras/documento_venta.php'` and `__DIR__ . '/../../extras/factura.php'` now resolve to `clientes_facturacion/extras/...` correctly (the `extras/` siblings moved with the trait). |
| `facturacion_base/model/{albaran_cliente,pedido_cliente,presupuesto_cliente,linea_factura_cliente,linea_albaran_cliente,linea_pedido_cliente,linea_presupuesto_cliente,linea_iva_factura_cliente,regularizacion_iva}.php` | `clientes_facturacion/model/{same}.php` | Move × 9 | Same path edit pattern: shim's `require_once` points to `plugins/clientes_facturacion/model/core/{name}.php`. |
| `facturacion_base/model/core/{same 9 names}.php` | `clientes_facturacion/model/core/{same 9 names}.php` | Move × 9 | For cores that need traits: update `require_once __DIR__ . '/../../extras/{trait}.php';` to `'../../../clientes_facturacion/extras/{trait}.php';` (see §3 for the chosen style). For cores with no trait require (e.g., `linea_pedido_cliente.php` core), no edit. |
| `facturacion_base/extras/documento_venta.php` | `clientes_facturacion/extras/documento_venta.php` | Move | No edit (no internal require). |
| `facturacion_base/extras/linea_documento_venta.php` | `clientes_facturacion/extras/linea_documento_venta.php` | Move | No edit. |
| `facturacion_base/extras/factura.php` | `clientes_facturacion/extras/factura.php` | Move | No edit. |
| `facturacion_base/model/table/{facturascli,albaranescli,pedidoscli,presupuestoscli,lineasfacturascli,lineasalbaranescli,lineaspedidoscli,lineaspresupuestoscli,lineasivafactcli,co_regiva}.xml` | `clientes_facturacion/model/table/{same}.xml` | Move × 10 | Byte-identical (no edit). |
| **Cross-plugin edit** | `facturacion_base/model/core/factura_proveedor.php:22` | Modify | `require_once __DIR__ . '/../../extras/factura.php';` → cross-plugin path to `plugins/clientes_facturacion/extras/factura.php`. See §3. |
| **Tests come alive** | `plugins/clientes_facturacion/tests/ModelLoadingTest.php` | Modify | The 10 tests in Group A were placeholders; here they become real `test{Name}LoadsFromClientesFacturacion` tests asserting `class_exists('FSFramework\model\{name}', false)` and `$model instanceof fs_model`. |

**Why atomic**: a model that `require_once`s a sibling core in the same plugin cannot move alone. A trait cannot move alone if a core in the destination uses it. The shim/core pair is a single atomic unit because the shim's only purpose is to forward to the core. The XML is atomic with the core because the model's `parent::__construct('table_name')` looks up the XML by table name (`base/fs_model.php:456-457`), and the autoloader iterates `$GLOBALS['plugins']` in order — if the XML is in a different plugin from the model, schema lookup is still correct, but co-location makes the move easier to reason about.

**Note on the `factura_cliente` core path-edit**: after moving to `clientes_facturacion/model/core/factura_cliente.php`, the `__DIR__ . '/../../extras/factura.php'` line resolves to `clientes_facturacion/extras/factura.php`. **No edit is required** on that line. The proposal and user's "2 cross-plugin updates" actually collapse to 1 (only `factura_proveedor.php:22` needs a cross-plugin path; see §3 and Open Question 2).

**Verification**:
```bash
ddev exec php vendor/bin/phpunit --testsuite Plugins       # 10/10 new tests in ModelLoadingTest
ddev exec php vendor/bin/phpunit --testsuite Base          # 160/160 unchanged
ddev exec php -l plugins/clientes_facturacion/model/{name}.php   # for each of 10
ddev exec composer phpstan                                   # no new errors
```

### Group C — controller + view move + `facturacion_base` ini bump

Moves the 17 controllers and 15 views, then updates `facturacion_base/facturascripts.ini` and its `description`.

| Source | Target | Action |
|---|---|---|
| `facturacion_base/controller/{ventas_agrupar_albaranes,ventas_albaranes,ventas_albaran,ventas_cliente,ventas_clientes,ventas_clientes_opciones,ventas_cliente_articulos,ventas_factura,ventas_factura_devolucion,ventas_facturas,ventas_grupo,ventas_imprimir,ventas_maquetar,ventas_trazabilidad,informe_facturas,informe_albaranes,nueva_venta}.php` (17) | `clientes_facturacion/controller/{same}.php` | Move × 17 |
| `facturacion_base/view/{ventas_agrupar_albaranes,ventas_albaranes,ventas_albaran,ventas_cliente,ventas_clientes,ventas_clientes_opciones,ventas_factura,ventas_facturas,ventas_grupo,ventas_imprimir,ventas_maquetar,ventas_trazabilidad,informe_albaranes,informe_facturas,nueva_venta}.html` (15) | `clientes_facturacion/view/{same}.html` | Move × 15 |
| **Atomic pair**: `informe_facturas` extends `informe_albaranes` (verified at `facturacion_base/controller/informe_facturas.php:25`). Both must move in the same commit or PHP fatal-errors on `class not found`. | — | — |
| `facturacion_base/facturascripts.ini` | — | Modify `version = 158 → 159` |
| `facturacion_base/description` | — | Modify to reflect reduced scope (suppliers, accounting, TPV-specific only) |

**Why atomic**: the `informe_facturas extends informe_albaranes` chain is the binding constraint (R6). The 2 view files that don't exist (`ventas_cliente_articulos.html`, `ventas_factura_devolucion.html`) are not blockers — the corresponding controllers render via tabs/inline blocks, not a dedicated view file.

**Path edits in moved controllers**: zero. The controllers extend `fbase_controller` (in `catalogo_core`) and use the FSFramework's autoloader; they do not contain `require_once` to model files (controllers receive models through `$this->get_model()` or similar helpers).

**Verification**:
```bash
ddev exec php vendor/bin/phpunit --testsuite Plugins       # 10/10 still green
ddev exec php -l plugins/clientes_facturacion/controller/{each}.php
# Manual smoke: admin menu renders ventas_* and informe_facturas/informe_albaranes
# with facturacion_base active.
ddev exec composer phpstan                                   # no new errors
```

### Files NOT moved (staying in `facturacion_base`)

The cross-plugin trait sharing is **one-way**: `facturacion_base → clientes_facturacion`. Files staying put:
- All 20+ purchase/accounting models (`albaran_proveedor`, `factura_proveedor`, `asiento`, `partida`, `subcuenta*`, `cuenta*`, `caja`, `terminal_caja`, etc.).
- All `compras_*` and `contabilidad_*` controllers and views.
- `extras/{documento_compra,linea_documento_compra,fs_pdf,ezpdf,xlsxwriter,libromayor,inventarios_balances,fbase_asiento_factura,fbase_controller_legacy}.php` — `documento_compra` and `linea_documento_compra` are used by `factura_proveedor`/`albaran_proveedor`; the rest stay as-is.
- `cron.php`, `functions.php`, `COPYING`, `README.md`.

---

## 3. Path resolution for cross-plugin `require_once`

### Decision

For the **single** cross-plugin update needed (`facturacion_base/model/core/factura_proveedor.php:22`), the recommended syntax is the **modern absolute style**:

```php
require_once FS_FOLDER . '/plugins/clientes_facturacion/extras/factura.php';
```

### Alternatives considered

| Style | Example | Pros | Cons | Verdict |
|---|---|---|---|---|
| Modern absolute | `require_once FS_FOLDER . '/plugins/clientes_facturacion/extras/factura.php';` | CWD-independent; matches `plugins/catalogo_core/Controller/*.php` pattern (10+ precedents in the codebase); robust to test bootstrap | Slightly longer | **Recommended** |
| Legacy shim | `require_once 'plugins/clientes_facturacion/extras/factura.php';` | Matches the existing shim pattern in `facturacion_base/model/*.php` (10+ precedents); shorter | Relies on CWD == FS_FOLDER; fragile if called from a chdir'd test or a CLI script in a subdirectory | Acceptable, matches the surrounding style in `facturacion_base/model/`. **Both styles are valid**; pick one and apply consistently in the file. |
| `__DIR__`-relative | `require_once __DIR__ . '/../../../clientes_facturacion/extras/factura.php';` | Works regardless of CWD | Brittle (depends on directory depth from `facturacion_base/model/core/` to `clientes_facturacion/extras/`, which is 3 hops — easy to break if either plugin is restructured); no precedent in the codebase | **Rejected** |

### Investigation summary

- **3 styles coexist** in the codebase (saved to Engram as `fsframework/plugin-require-once-and-load-order`). The shim pattern in `facturacion_base/model/{name}.php` uses `require_once 'plugins/...'` without `FS_FOLDER` (10 occurrences). The modern PSR-4 Controllers in `plugins/catalogo_core/Controller/*.php` use `FS_FOLDER . '/plugins/...'` (10+ occurrences). The legacy cores use `__DIR__ . '/...'` for in-plugin relative paths.
- The modern style is preferred for **new** cross-plugin `require_once` because `FS_FOLDER` is always defined by the time the model loads (defined in `config.php` or `tests/bootstrap.php` before any plugin loads).

### Clarification: "1 not 2" cross-plugin updates

The user instruction mentioned 2 cross-plugin updates (`factura_proveedor.php:22` and `factura_cliente.php:23`), reasoning from the **pre-move** state. After the move:
- `factura_cliente.php` (the file) **moves** to `clientes_facturacion/model/core/factura_cliente.php`. At the new home, its line `require_once __DIR__ . '/../../extras/factura.php';` resolves to `clientes_facturacion/extras/factura.php` — exactly the new home. **No edit required** to the line content; the line moves with the file.
- `factura_proveedor.php` **stays** at `facturacion_base/model/core/factura_proveedor.php`. Its `__DIR__ . '/../../extras/factura.php'` would now resolve to `facturacion_base/extras/factura.php` (doesn't exist post-move). **1 cross-plugin update** is required.

This will be confirmed with the user in §9 (Open Question 1).

---

## 4. Plugin load order and activation order

### Activation order (the user-visible part)

`fs_plugin_manager::enable()` (line 440-501) validates dependencies and appends to `$GLOBALS['plugins']`:

```php
// fs_plugin_manager.php:451-463
foreach ($pitem['require'] as $req) {
    if (in_array($req, $GLOBALS['plugins'])) {
        continue;
    }
    $install = FALSE;
    $txt = 'Dependencias incumplidas: <b>' . $req . '</b>';
    ...
    $this->core_log->new_error($txt);
}
```

With the change, `facturacion_base/facturascripts.ini` already requires `clientes_facturacion` (unchanged). The user CANNOT enable `facturacion_base` until `clientes_facturacion` is in the enabled list. This is the safety mechanism that makes the cross-plugin `require_once` safe: when `factura_proveedor` is loaded, `clientes_facturacion` is guaranteed to be on disk and enabled.

There is **no topological sort**. Plugins must be enabled in dependency order (parent first, child second). For a fresh install: enable `clientes_core` → `clientes_facturacion` → `catalogo_core` (or `business_data`) → `facturacion_base`.

### Class-load order (the runtime part)

`fs_model_autoloader::buildModelDirs()` (line 140-170) iterates `$GLOBALS['plugins']` **in array order**, building the search path for both `model/` and `model/core/`. Models are loaded **lazily** — only when first instantiated. The autoloader doesn't know or care about the `require` field; it only knows the file order.

For this change, the only case where load order matters is **the cross-plugin `require_once` in `factura_proveedor.php:22`**. That call resolves at file-load time, not at plugin-load time. Since the file `plugins/clientes_facturacion/extras/factura.php` exists on disk the moment the move lands (regardless of plugin activation state), the require resolves fine. The `require` field is what guarantees that `clientes_facturacion` is **activated**, which is what `fs_model_autoloader` checks via `$GLOBALS['plugins']` to decide whether to scan that plugin's model dirs (line 144-156).

In other words: the `require` field ensures the directory is **scanned**; the cross-plugin `require_once` ensures the specific file is **loaded** when its parent class is loaded. Both must hold, and the design guarantees both.

### Inheritance coupling for moved controllers

`ventas_factura` extends `fbase_controller` (in `catalogo_core/extras/`). If `clientes_facturacion` is enabled without `catalogo_core`, the controller class will fatal at instantiate. **R3 acknowledges this**: controller-level coupling is a future SDD. The `ModelLoadingTest` does not exercise controllers, so the test surface is independent of this coupling. We do not add `catalogo_core` to `clientes_facturacion`'s `require` in this change (would contradict the "model layer is standalone" acceptance criterion in spec §"Functional standalone").

---

## 5. PR/commit plan (2 PRs across 2 repos)

**Recommendation: 2 coordinated PRs in 2 different repos, with strict merge order.** This supersedes the original 3-slice chained-PR proposal after the user clarified the repo structure: `clientes_facturacion` is tracked in the `panel-ab` core repo, while `facturacion_base` is gitignored and lives in its own external repo (upstream `NeoRazorX/facturacion_base`).

### Repo structure (verified)

| Plugin | Repo | PR target |
|---|---|---|
| `clientes_facturacion`, `clientes_core`, `catalogo_core`, `business_data` | `panel-ab` core | `panel-ab` repo |
| `facturacion_base` | external (gitignored from `panel-ab`; `update_url = 'https://github.com/NeoRazorX/facturacion_base/...'`) | `facturacion_base` repo |
| `tpvmod` | external (gitignored) | `tpvmod` repo (future SDD, out of scope here) |

### PR-A — `panel-ab` core repo (additive, lands FIRST)

**Repo**: `panel-ab` (this repo, the core). **Branch**: `feature/extract-sales-docs-core`. **Merge target**: `master`. **Merge order**: **FIRST**.

| Field | Value |
|---|---|
| **Scope** | All new files in `clientes_facturacion/`: 10 shims + 10 cores + 3 traits + 10 XMLs + 17 controllers + 15 views. Bootstrap: `Init.php`, `description`, `translations/messages.es.yaml`, `phpunit.xml`, `tests/ModelLoadingTest.php` (10 + 1 = 11 tests). Bump `clientes_facturacion/fsframework.ini` 1→2 and `facturascripts.ini` 1→2. Add `clientes_facturacion` to `catalogo_core/fsframework.ini` `require`. |
| **Files** | ~60 new + 3 modified = ~63 files |
| **Line estimate** | ~3000-3500 lines (exceeds the 400-line review budget D1; mitigated by logical-commit structure below) |
| **Commit structure** | 3 logical commits in the same PR (each independently reviewable): (1) **bootstrap** — `Init.php`, `description`, `translations/`, `phpunit.xml`, `ModelLoadingTest.php` (placeholder, 0 tests), inis bump, `catalogo_core` ini change; (2) **model layer** — 10 shims + 10 cores + 3 traits + 10 XMLs, `ModelLoadingTest.php` activated with 10 tests; (3) **controller layer** — 17 controllers + 15 views, `ModelLoadingTest.php` gains the 11th cross-plugin test. |
| **Standalone mergeable** | ⚠️ Lands safely without PR-B. The 33 new files in `clientes_facturacion/` use `__DIR__`-relative `require_once` paths that resolve within `clientes_facturacion/` itself. Old files in `facturacion_base/` are unaffected. Brief duplicate class definitions exist (autoloader resolves to `clientes_facturacion` because it loads first as a dep of `facturacion_base`). |
| **Verification** | `ddev exec php vendor/bin/phpunit --testsuite Plugins` (10/10 + 1 green after commit 3). `ddev exec php vendor/bin/phpunit --testsuite Base` (160/160 unchanged). `ddev exec composer phpstan` (no new errors). |
| **Rollback** | `git revert <PR-A-merge-commit>` removes all new files in `clientes_facturacion/` and reverts the 3 modified inis. `facturacion_base` is unaffected. State 0 is restored. |
| **Risk** | LOW (PR-A alone). The class-collision window is transient and the autoloader handles it. |

### PR-B — `facturacion_base` external repo (destructive, lands AFTER PR-A)

**Repo**: `facturacion_base` (external, gitignored from `panel-ab`). **Branch**: `feature/extract-sales-docs-legacy` (in the `facturacion_base` repo). **Merge target**: `master` of `facturacion_base`. **Merge order**: **SECOND — must land AFTER PR-A is merged in `panel-ab`**.

| Field | Value |
|---|---|
| **Scope** | (1) Update `facturacion_base/model/core/factura_proveedor.php:22` to `require_once FS_FOLDER . '/plugins/clientes_facturacion/extras/factura.php';`. (2) Delete 33 files: 10 shims, 10 cores, 3 traits, 10 XMLs. (3) Delete 32 files: 17 controllers, 15 views. (4) Bump `facturascripts.ini` 158→159. (5) Update `description` to reflect reduced scope. |
| **Files** | 1 modified (`factura_proveedor.php`) + 65 deleted + 2 modified (`facturascripts.ini`, `description`) = ~68 files (net destructive) |
| **Line estimate** | Net ~-3000 lines (deletions dominate) |
| **Commit structure** | 2 logical commits in the same PR: (1) **prepare** — the cross-plugin `require_once` update + `facturascripts.ini` bump + `description` update (non-destructive, prepares the path); (2) **remove** — the 65 file deletions. The non-destructive commit CAN land first if a review concern arises; the deletion commit is a pure removal. |
| **Standalone mergeable** | ✅ Yes — but ONLY after PR-A is merged. If PR-B lands first, the cross-plugin `require_once` points to a non-existent file and `factura_proveedor` instantiation breaks globally. |
| **Verification** | `ddev exec php vendor/bin/phpunit` (Plugins + Base suites, 10/10 + 1 green, 160/160 Base). Manual smoke: a fresh install of `facturacion_base` WITHOUT `clientes_facturacion` active should NOT work for sales documents — that's the new contract (`facturacion_base` is optional, not standalone). |
| **Rollback** | `git revert <PR-B-merge-commit>` in the `facturacion_base` repo restores all deleted files and reverts the `require_once` update + ini bump. State 0 is restored. |
| **Risk** | MEDIUM. The 1 cross-plugin `require_once` line is the single riskiest change. Mitigated by the 11th test in `ModelLoadingTest` (PR-A commit 3) which asserts `new factura_proveedor()` resolves the trait from the new home. |

### Coordination requirement between PR-A and PR-B

The 2 PRs are **not** a chained-PR (where each slice's merge enables the next). They are **coordinated PRs** that must land in a specific order:

1. **PR-A merges first** in `panel-ab`. New files exist in `clientes_facturacion/`; old files in `facturacion_base` are still present. State is **transient** (duplicate class definitions tolerated by autoloader).
2. **PR-B merges second** in `facturacion_base`. Old files are removed; the `require_once` path is updated. State is **final** (clean, no duplicates).

**Operational rule for production deployment**: deploy `panel-ab` (with PR-A) BEFORE updating `facturacion_base` (with PR-B). The reverse order is forbidden and breaks `factura_proveedor` instantiation.

### Why not the original 3-slice chained-PR plan?

The original plan assumed all the changes live in a single repo. After the user clarified the repo structure (verified via `.gitignore` and `git ls-files plugins/<name>/`), the slices span 2 repos and the chained-PR convention no longer applies. The 2-PR structure with a clear coordination rule is the correct adaptation.

### Why not single PR with `size:exception`?

A single PR combining PR-A and PR-B would require either:
- Submitting the `facturacion_base` changes as part of `panel-ab`'s PR — **impossible** because `facturacion_base` is gitignored from `panel-ab`.
- Holding `panel-ab`'s PR until `facturacion_base`'s PR is ready, then merging both at once — operationally fragile across repos.

The 2-PR structure with a clear coordination rule is simpler and more reviewable.

---

## 6. Test strategy

### File location

`plugins/clientes_facturacion/tests/ModelLoadingTest.php` — mirrors the pattern in `plugins/catalogo_core/tests/ModelLoadingTest.php`.

### Class structure

- Namespace: `Tests\ClientesFacturacion` (consistent with `Tests\CatalogoCore` precedent)
- Extends: `PHPUnit\Framework\TestCase`
- `setUp()`: empty `$GLOBALS['plugins'] = [];`, define `FS_VENTAS_SIN_STOCK = false` if not defined, `require_once FS_FOLDER . '/base/fs_model.php';`
- Each test does `require_once FS_FOLDER . '/plugins/clientes_facturacion/model/core/{name}.php';` and asserts `class_exists('FSFramework\model\{name}', false)` + `new \FSFramework\model\{name}() instanceof fs_model`.

### 10 test methods

1. `testFacturaClienteLoadsFromClientesFacturacion` — `factura_cliente` (use traits `documento_venta`, `factura`)
2. `testAlbaranClienteLoadsFromClientesFacturacion` — `albaran_cliente`
3. `testPedidoClienteLoadsFromClientesFacturacion` — `pedido_cliente`
4. `testPresupuestoClienteLoadsFromClientesFacturacion` — `presupuesto_cliente`
5. `testLineaFacturaClienteLoadsFromClientesFacturacion` — `linea_factura_cliente` (uses `linea_documento_venta` trait)
6. `testLineaAlbaranClienteLoadsFromClientesFacturacion` — `linea_albaran_cliente` (uses `linea_documento_venta` trait)
7. `testLineaPedidoClienteLoadsFromClientesFacturacion` — `linea_pedido_cliente` (uses `linea_documento_venta` trait)
8. `testLineaPresupuestoClienteLoadsFromClientesFacturacion` — `linea_presupuesto_cliente` (uses `linea_documento_venta` trait)
9. `testLineaIvaFacturaClienteLoadsFromClientesFacturacion` — `linea_iva_factura_cliente`
10. `testRegularizacionIvaLoadsFromClientesFacturacion` — `regularizacion_iva` (verifies the cross-plugin coupling point in `fbase_controller.php:688`)

### When to add the test

**Slice 1** (Group A): the file is created with 10 placeholder tests that just `assertTrue(true)`. This validates the test infrastructure (phpunit.xml, suite name, autoloader, namespace).
**Slice 2** (Group B): the 10 tests become real and assert actual class loading + instantiation. This is the proof that the model move is correct.

### Standalone verification (acceptance criterion)

A **dedicated** test in the same file:
```php
public function testStandaloneInstantiationWithoutFacturacionBase(): void
{
    // GIVEN facturacion_base NOT in active plugins
    $GLOBALS['plugins'] = ['clientes_core', 'clientes_facturacion'];
    // WHEN a moved model is instantiated
    $model = new \FSFramework\model\factura_cliente();
    // THEN it instantiates without fatal
    $this->assertInstanceOf('fs_model', $model);
}
```

This is the spec scenario "tpvmod-style consumer smoke check". It proves the model layer is functionally standalone.

### Standalone is per model layer, NOT per controller layer

R3 explicitly defers controller-level coupling. The test does NOT exercise `ventas_factura`, `informe_facturas`, etc. A future SDD will address controller-level decoupling (likely by injecting service locators instead of `new \asiento()`).

---

## 7. Rollback plan per PR

### PR-A rollback (`panel-ab` core repo)

```bash
git revert <PR-A-merge-commit>
ddev exec php vendor/bin/phpunit --testsuite Plugins    # 0 tests from this change
ddev exec php vendor/bin/phpunit --testsuite Base       # 160/160 unchanged
ddev exec composer phpstan                              # no new errors
```

Reverts: ~60 new files deleted from `clientes_facturacion/` (shims, cores, traits, XMLs, controllers, views, bootstrap artifacts). The 3 modified inis revert (`clientes_facturacion/fsframework.ini` 2→1, `clientes_facturacion/facturascripts.ini` 2→1, `catalogo_core/fsframework.ini` `require` reverts). `facturacion_base` is unaffected (it has its own repo). State 0 is restored.

**Important**: PR-A must be rolled back BEFORE PR-B is merged. If PR-A is reverted after PR-B is in, PR-B's `factura_proveedor.php:22` cross-plugin `require_once` points to a non-existent `clientes_facturacion/extras/factura.php` and `factura_proveedor` instantiation breaks. **Rollback order**: PR-B first, then PR-A.

### PR-B rollback (`facturacion_base` external repo)

```bash
git revert <PR-B-merge-commit>   # in the facturacion_base repo
ddev exec php vendor/bin/phpunit --testsuite Plugins    # 0 tests from this change
ddev exec php vendor/bin/phpunit --testsuite Base       # 160/160 unchanged
```

Reverts: 65 deleted files restored in `facturacion_base/`, the 1 cross-plugin `require_once` in `factura_proveedor.php:22` reverts to the in-plugin `__DIR__` path, `facturascripts.ini` reverts to `version = 158`, `description` reverts. The 10 XMLs are byte-identical to the original, so **the DB is untouched** — `facturascli`, `albaranescli`, etc. continue to be read with the same schema.

**Caveat**: if `facturacion_base` was in production with `version = 159` for a while, rolling back to `version = 158` may trigger the system_updater to think an upgrade is needed. Acceptable — the rollback is a deliberate "go back to known good state" signal.

### Rollback order summary

| Scenario | Rollback order | Reason |
|---|---|---|
| Both PR-A and PR-B are merged, want to undo the whole change | **PR-B first, then PR-A** | PR-A removal before PR-B revert would break the cross-plugin `require_once` in `factura_proveedor.php:22` (would point to a deleted `clientes_facturacion/extras/factura.php`). |
| Only PR-A is merged (PR-B pending), want to undo PR-A | **PR-A only** (safe) | PR-B hasn't landed yet; the cross-plugin `require_once` doesn't exist yet; reverting PR-A restores State 0 cleanly. |
| Both merged, only want to fix a PR-B issue | **PR-B only** (safe) | PR-A stays; the cross-plugin `require_once` in PR-B still points to a valid `clientes_facturacion/extras/factura.php`. |

---

## 8. Risks

| ID | Risk | Severity | Mitigation |
|---|---|---|---|
| **R1** | 10 shims + 10 cores + 3 traits must move as 3 atomic units. The `factura` trait is shared with `factura_proveedor` (staying). | CRITICAL | Move all 33 in Slice 2. Update 1 cross-plugin `require_once` (`factura_proveedor.php:22`). Verified via `ModelLoadingTest` (10 tests) and PHPStan. |
| **R2** | S3 says 18 controllers; actual is 17 (14 `ventas_*` + 2 `informe_*` + 1 `nueva_venta`). | WARNING | Adopt 17 in Slice 3. |
| **R3** | Cross-domain runtime couplings: `factura_cliente` core uses `\asiento`, `regularizacion_iva` core uses `\partida` and `\asiento`, `ventas_factura`/`ventas_factura_devolucion` use `asiento_factura`, `ventas_cliente`/`ventas_imprimir` use `cuenta_banco_cliente`, `ventas_clientes`/`nueva_venta` use `get_subcuenta()` (body references `\subcuenta`). | CRITICAL (architectural, accepted) | **Split by location, not full independence.** Goal met for model layer. Controller coupling is R3-accepted and is a future SDD. `tpvmod` does not trigger any of these paths (verified by absence of `asiento`/`partida`/`subcuenta` refs in `plugins/tpvmod/`). |
| **R4** | `catalogo_core` runtime use of `regularizacion_iva` at `fbase_controller.php:688` (private method `validateFacturaEjercicio`). Verified RUNTIME-only. | CRITICAL (mitigated) | Add `clientes_facturacion` to `catalogo_core/fsframework.ini` `require` in Slice 1. Autoloader resolves `new regularizacion_iva()` via the new home. |
| **R5** | Size: ~57–62 files / ~2000–3500 lines. Exceeds 400-line review budget (D1). | SUGGESTION | 3-slice chained-PR (this design §5). Each slice ≤ 3000 lines but with focused scope; Slice 2 may need a temporary shim to be standalone-mergeable, OR Slice 2+3 form one PR with 2 commits (deferred to user choice). |
| **R6** | `informe_facturas extends informe_albaranes`; both must move atomically. | WARNING | Atomic move of the pair in Slice 3. |
| **R7** *(new)* | **Cross-plugin `require_once` is a latent fragility**: if the user ever moves `factura.php` trait to a different plugin, `factura_proveedor.php:22` breaks silently (no compile-time check). | SUGGESTION | Add a test in Slice 2 that asserts `factura_proveedor::class has constant 'TRAIT_FACTURA_PATH' or similar` (or at minimum that the trait file exists and is reachable from `factura_proveedor`). Optional — a smoke test of `new factura_proveedor()` covers this indirectly. |
| **R8** *(new)* | **"2 cross-plugin updates" misconception**: the proposal and user's adjustments state 2 cross-plugin `require_once` updates. After the move, only 1 is cross-plugin (`factura_proveedor.php:22`); the other (`factura_cliente.php:23`) keeps its `__DIR__`-relative path and the line just moves with the file. | SUGGESTION | Design §3 documents the clarification. **Resolved**: user confirmed Q1 in §9. Scope collapses from 2 to 1 cross-plugin update. |
| **R9** *(new)* | **Cycle if `clientes_facturacion` requires `catalogo_core`**: R4 already requires the inverse (`catalogo_core → clientes_facturacion` for `regularizacion_iva` runtime). Adding `catalogo_core` to `clientes_facturacion`'s `require` would create a mutual `require` cycle that the plugin manager cannot resolve. | CRITICAL (resolved) | **Resolved**: user chose Option A in §9 (keep `clientes_facturacion` with `require = "clientes_core"` only). Controller-layer coupling to `catalogo_core` (because `ventas_*` controllers extend `fbase_controller`) is documented in `clientes_facturacion/description` as a consumer note. |
| **R10** *(new)* | **2-repo coordination**: PR-A (in `panel-ab`) and PR-B (in `facturacion_base`) must merge in a specific order. The window between PR-A merged and PR-B not-yet-merged is a transient state with duplicate class definitions. The reverse order (PR-B before PR-A) is forbidden — it would break `factura_proveedor` instantiation globally. | WARNING | Design §5 documents the coordination rule. The merge order is enforced by the PR review/merge process (PR-B cannot merge until PR-A is verified merged). Production deploy order: `panel-ab` first, then `facturacion_base`. |
| **R9** *(new)* | **`clientes_facturacion` `require` field doesn't include `catalogo_core`**: moved controllers extend `fbase_controller` (in `catalogo_core`). A consumer enabling only `clientes_facturacion` + `clientes_core` will have broken controllers. | WARNING (accepted by spec) | The spec's "Functional standalone" requirement explicitly covers the **model layer only**; controller-level coupling is R3. The plugin description will document that controllers require `catalogo_core` to be active. A future SDD will address this. |

---

## 9. Open questions for the user (RESOLVED)

All 6 open questions were resolved by the user in the design review.

1. **1 vs 2 cross-plugin updates** — **RESOLVED**: confirmed as 1 (R8). Only `factura_proveedor.php:22` is cross-plugin; `factura_cliente.php:23` moves with its file.
2. **Path syntax for the cross-plugin update** — **RESOLVED**: `require_once FS_FOLDER . '/plugins/clientes_facturacion/extras/factura.php';` (modern absolute, robust against CWD changes).
3. **3 slices vs single PR vs 4 slices** — **RESOLVED** (superseded): the 3-slice plan is replaced by **2 coordinated PRs in 2 repos** after the user clarified that `facturacion_base` is an independent external repo. PR-A lands first (additive in `panel-ab`); PR-B lands second (destructive in `facturacion_base`).
4. **`clientes_facturacion/fsframework.ini` adding `catalogo_core` to `require`** — **RESOLVED**: **NO** (Option A). User initially proposed adding it (R9), but the cycle `catalogo_core ↔ clientes_facturacion` made it impossible. Option A: keep `require = "clientes_core"`; document the controller-layer coupling to `catalogo_core` in the plugin description. Standalone property is at the model layer only.
5. **11th test for `new factura_proveedor()`** — **RESOLVED**: **YES** (R7 mitigation). `testFacturaProveedorResolvesFacturaTraitFromClientesFacturacion` is added in PR-A commit 3.
6. **`description` file in Slice 1 or Slice 3** — **RESOLVED**: **Slice 1** (now PR-A commit 1). The plugin is fully bootable from the moment it's enabled.

---

## Summary

This is a file-move refactor deliverable as **2 coordinated PRs in 2 repos**: PR-A in `panel-ab` core (additive, ~60 new files, ~3000-3500 lines, 3 logical commits) and PR-B in `facturacion_base` (destructive, ~65 deletions + 1 cross-plugin `require_once` + 2 ini updates, 2 logical commits). The model layer becomes functionally standalone in `clientes_facturacion`; the controller layer remains runtime-coupled to `catalogo_core` (acknowledged as R9, documented in the plugin description, deferred to a future SDD). The cross-plugin `require_once` is limited to **1 line** in `facturacion_base/model/core/factura_proveedor.php:22` (R8 confirmed). The `ModelLoadingTest` (10 + 1 tests) is the test surface; it lives in `plugins/clientes_facturacion/tests/` and is auto-discovered by the root **Plugins** suite. No core code (`base/`, `src/`, `controller/` root, `model/` root) is touched. The `openspec/` directory is touched **only** under `plugins/clientes_facturacion/openspec/changes/extract-sales-docs/`.

**Merge order (mandatory)**: PR-A → PR-B. Reverse order breaks `factura_proveedor` instantiation. Production deploy order: `panel-ab` (with PR-A) BEFORE `facturacion_base` (with PR-B).

**Next step**: hand off to `sdd-tasks` for the task breakdown, then `sdd-apply` (with TDD enforcement per `strict_tdd: true` in the plugin's `config.yaml`).
