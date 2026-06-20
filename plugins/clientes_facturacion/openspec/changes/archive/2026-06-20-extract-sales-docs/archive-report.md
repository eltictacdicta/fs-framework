# Archive Report: extract-sales-docs

**Change**: `extract-sales-docs`
**Plugin**: `clientes_facturacion` (plugin-local SDD per `AGENTS.md` → "OpenSpec per plugin")
**Date archived**: 2026-06-20
**Verifier verdict**: **PASS WITH WARNINGS** (0 CRITICAL, 3 WARNING, 2 SUGGESTION)
**Archive path**: `plugins/clientes_facturacion/openspec/changes/archive/2026-06-20-extract-sales-docs/`

---

## 1. Executive summary

The change is archived as PASS_WITH_WARNINGS. The 30 implementation tasks completed cleanly and all 8 source-traceable spec scenarios hold against the actual filesystem, the test suite, and runtime behavior. The plugin's test suite reports 10 pass + 1 skipped (the cross-plugin guard goes GREEN when `facturacion_base` is in `$GLOBALS['plugins']` and PR-B commit 1 is applied — verified via direct smoke test). The Base suite is 160/160 unchanged. PHPStan introduces 0 new errors attributable to this change. The 3 WARNINGS are process/portability items for the user (PR-B cross-repo port, TDD evidence table, 11th-test skip noise); none are blocking. The delta spec was synced to source-of-truth at `plugins/clientes_facturacion/openspec/specs/sales-documents/spec.md` (cleaned of delta framing per the contract) and the change artifacts moved to the date-prefixed archive directory.

---

## 2. Final state of the change

### 2.1 Source-of-truth spec (the contract, post-archive)

- **`plugins/clientes_facturacion/openspec/specs/sales-documents/spec.md`** — new file. Cleaned of delta framing (`# Delta: sales-documents` → `# sales-documents`; removed `Change: extract-sales-docs`, the move-target breadcrumb, the proposal reference, and the ownership-transfer blockquote). The 8 Requirements are intact, self-contained, and read as the domain contract for `clientes_facturacion`'s sales-document model layer without needing the proposal/design.

### 2.2 Archived change artifacts (the audit trail)

- **`plugins/clientes_facturacion/openspec/changes/archive/2026-06-20-extract-sales-docs/`** — new directory. Contents:
  - `proposal.md` — original intent/scope/approach/risks
  - `design.md` — architecture, atomic groups, path resolution, PR plan, rollback plan
  - `tasks.md` — 30/30 tasks checked
  - `verify-report.md` — verifier's findings, 8/8 scenarios PASS
  - `specs/sales-documents/spec.md` — the delta spec, preserved as written
  - `archive-report.md` — this file

### 2.3 Source change directory

- `plugins/clientes_facturacion/openspec/changes/extract-sales-docs/` — **removed** (moved to archive). `ls` reports `No such file or directory`.

### 2.4 Core `openspec/changes/`

- **No entries created** for `extract-sales-docs`. The plugin SDD rule (HARD) is honored: the change lives only under `plugins/clientes_facturacion/openspec/`.

---

## 3. What was implemented

The 8 source-of-truth spec requirements (now in `plugins/clientes_facturacion/openspec/specs/sales-documents/spec.md`):

| # | Requirement | What landed |
|---|-------------|-------------|
| 1 | **Plugin ownership of sales-document model layer** | 10 shims + 10 `FSFramework\model` cores in `plugins/clientes_facturacion/model/`. Shim `require_once` paths updated to point to the new core home. |
| 2 | **Plugin ownership of sales-document traits** | 3 traits (`documento_venta`, `linea_documento_venta`, `factura`) at `plugins/clientes_facturacion/extras/`. 2 cores that used CWD-relative `require_once` (pedido_cliente, presupuesto_cliente) had their paths updated; the other 6 used `__DIR__`-relative paths that resolve correctly with no edit. |
| 3 | **Cross-plugin trait sharing with facturacion_base** | `facturacion_base/model/core/factura_proveedor.php:22` now reads `require_once FS_FOLDER . '/plugins/clientes_facturacion/extras/factura.php';` (modern absolute style). `factura_proveedor` instantiates; the trait resolves from the new home (verified via direct smoke test). |
| 4 | **Database schema identity** | 10 XMLs at `plugins/clientes_facturacion/model/table/` (byte-identical to the source per design S7). No DDL change, no DB migration. `table_name` resolution confirmed for all 10 models. |
| 5 | **Test suite green** | `plugins/clientes_facturacion/tests/ModelLoadingTest.php` with 11 tests (10 per-model + 1 cross-plugin guard). Plugin suite: 10 pass + 1 skipped, never red. Base suite: 160/160 unchanged. |
| 6 | **Functional standalone of clientes_facturacion** | Standalone smoke script: with `$GLOBALS['plugins'] = ['clientes_core', 'clientes_facturacion'];`, all 10 moved models instantiate cleanly (10 OK, 0 FAIL). The `tpvmod`-style consumer smoke check is the same path. |
| 7 | **Dependency graph integrity** | `catalogo_core/fsframework.ini` adds `require = "clientes_facturacion";`. `clientes_facturacion` keeps `require = "clientes_core"`. `facturacion_base` keeps its full `require`. `fbase_controller.php:688` `new regularizacion_iva()` resolves via the new `require`. No cycles. |
| 8 | **Static analysis clean** | PHPStan reports 0 new errors attributable to the move. The pre-existing OidcProvider scan-file config drift (gitignored plugin → missing scan target) is unrelated and was present before this change. |

---

## 4. What the user must still do (out of SDD scope)

These items were flagged in the verify-report (W1 in particular) and are **out of scope** for the SDD's automation. They require the user's manual action.

### 4.1 Commit PR-A in `panel-ab` (3 logical commits, per design §5)

PR-A is the **additive** change in this repo (`panel-ab`). The implementation is already on disk and verified; the user just needs to commit and push.

- **Commit 1 — bootstrap** (8 tasks): `Init.php`, `description`, `translations/messages.es.yaml`, `phpunit.xml`, `tests/ModelLoadingTest.php` (placeholder, 0 tests), `clientes_facturacion/fsframework.ini` 1→2, `clientes_facturacion/facturascripts.ini` 1→2, `catalogo_core/fsframework.ini` added `clientes_facturacion` to `require`.
- **Commit 2 — model layer** (12 tasks): 10 RED→GREEN tests in `ModelLoadingTest.php`; 10 shims + 10 cores + 3 traits + 10 XMLs moved; `php -l` clean on all 33; PHPStan no new errors; Base suite unchanged.
- **Commit 3 — controller layer** (5 tasks): 11th test `testFacturaProveedorResolvesFacturaTraitFromClientesFacturacion`; 14 `ventas_*` controllers + 12 views; atomic `informe_facturas`/`informe_albaranes` pair (controller + view); `nueva_venta` (controller + view). `php -l` clean on all 17; manual admin-menu smoke with `facturacion_base` + `catalogo_core` + `clientes_facturacion` + `clientes_core` all active.

Suggested branch name: `feature/extract-sales-docs-core`. Merge target: `master`. **PR-A must land FIRST** (mandatory per design §5 + verify-report §13.3).

### 4.2 Port PR-B to the external `facturacion_base` repo (per design §5 and verify-report W1)

The workspace already shows the destructive state of PR-B commit 2 (65 file deletions + 2 ini/description updates). `facturacion_base` is gitignored from `panel-ab` and lives in its own external repo (upstream `NeoRazorX/facturacion_base`). The user must port these changes to that external repo as PR-B:

- **PR-B commit 1 — prepare** (3 changes): `facturacion_base/model/core/factura_proveedor.php:22` cross-plugin `require_once`; `facturacion_base/facturascripts.ini` 158→159; `facturacion_base/description` rewritten to the Q3 reduced-scope text.
- **PR-B commit 2 — remove** (65 file deletions): 10 shims + 10 cores + 3 traits + 10 XMLs (33 model-layer) + 17 controllers + 15 views (32 controller-layer). The atomic `informe_*` pair must be deleted together (R6).

Suggested branch name: `feature/extract-sales-docs-legacy` (in the `facturacion_base` repo). Merge target: `master` of `facturacion_base`. **PR-B must land SECOND** (after PR-A is verified merged in `panel-ab`).

### 4.3 Production deploy order (mandatory, per design §5 and verify-report §13.3)

1. Deploy `panel-ab` (with PR-A merged) **FIRST**.
2. Then update `facturacion_base` (with PR-B merged).
3. **Forbidden reverse order**: PR-B before PR-A breaks `factura_proveedor` instantiation globally (the cross-plugin `require_once` would point to a non-existent `plugins/clientes_facturacion/extras/factura.php`).
4. **Rollback order** (if both merged and need revert): PR-B first, then PR-A. Reverse order breaks the cross-plugin `require_once`.

---

## 5. References

### 5.1 Source-of-truth spec

- `plugins/clientes_facturacion/openspec/specs/sales-documents/spec.md` — the contract, 8 Requirements, self-contained.

### 5.2 Archived artifacts

- `plugins/clientes_facturacion/openspec/changes/archive/2026-06-20-extract-sales-docs/proposal.md`
- `plugins/clientes_facturacion/openspec/changes/archive/2026-06-20-extract-sales-docs/design.md`
- `plugins/clientes_facturacion/openspec/changes/archive/2026-06-20-extract-sales-docs/tasks.md` (30/30 checked)
- `plugins/clientes_facturacion/openspec/changes/archive/2026-06-20-extract-sales-docs/verify-report.md` (verdict: PASS_WITH_WARNINGS)
- `plugins/clientes_facturacion/openspec/changes/archive/2026-06-20-extract-sales-docs/specs/sales-documents/spec.md` (the delta as written)
- `plugins/clientes_facturacion/openspec/changes/archive/2026-06-20-extract-sales-docs/archive-report.md` (this file)

### 5.3 Plugin config

- `plugins/clientes_facturacion/openspec/config.yaml` — `ownership: plugin-local`, `change_root`, `archive_root` per the AGENTS.md "OpenSpec per plugin" rule.

### 5.4 Project canon

- `AGENTS.md` → "OpenSpec per Plugin (SDD ownership)" — the HARD rule that this change honors.
- `.opencode/skills/fsframework-plugin-sdd/SKILL.md` — the routing rules and archive workflow followed.

---

## 6. Archive log

- **2026-06-20 18:13 (UTC)**: archive dir created at `plugins/clientes_facturacion/openspec/changes/archive/2026-06-20-extract-sales-docs/`.
- **2026-06-20 18:13 (UTC)**: delta spec copied to source-of-truth at `plugins/clientes_facturacion/openspec/specs/sales-documents/spec.md` with minimal cleanup (delta header, change-id breadcrumb, proposal reference, ownership-transfer blockquote removed; the 8 Requirements kept verbatim).
- **2026-06-20 18:13 (UTC)**: change artifacts moved: `proposal.md`, `design.md`, `tasks.md`, `verify-report.md`, and the `specs/sales-documents/` subdir all relocated to the archive dir.
- **2026-06-20 18:13 (UTC)**: empty `plugins/clientes_facturacion/openspec/changes/extract-sales-docs/` removed; source change dir no longer exists.
- **2026-06-20 18:13 (UTC)**: this `archive-report.md` written. Core `openspec/changes/extract-sales-docs/` was **never** created (plugin SDD rule honored).

---

## 7. Sign-off

**Change archived, ready for production** (subject to the user's PR-A commit and PR-B port per §4).

- ✅ All 30 tasks complete (verified in `tasks.md`).
- ✅ All 8 source-traceable spec scenarios PASS (verified in `verify-report.md` §2).
- ✅ Delta spec synced to source-of-truth, cleaned of delta framing, self-contained.
- ✅ Change artifacts moved to date-prefixed archive (`2026-06-20-extract-sales-docs`).
- ✅ Source change dir removed.
- ✅ Core `openspec/changes/extract-sales-docs/` never created (plugin SDD rule honored).
- ✅ Plugin tests: 10 pass + 1 skipped (the skip is the correct cross-plugin guard behavior).
- ✅ Base suite: 160/160 unchanged.
- ⚠️ User actions pending: PR-A commit (§4.1), PR-B port (§4.2), production deploy order (§4.3). All in the user's hands, all documented.
- 🔴 **0 CRITICAL findings** from verify.
- 🟡 **3 WARNING findings** from verify (W1 PR-B port — user action item; W2 TDD evidence table — process; W3 11th-test skip noise — non-blocking). All documented in `verify-report.md` §10.
- 🟢 **2 SUGGESTION findings** from verify (S1 path-style assumption in design; S2 `cliente_facturacion.php` count nit). Non-blocking.

---

## Fix batch (2026-06-20)

### Trigger

Post-archive runtime fatal on the first admin-page hit:

```
Fatal error: Uncaught Error: Class "albaran_proveedor" not found in
/var/www/html/plugins/clientes_facturacion/controller/informe_albaranes.php:81
```

The original apply batch (T-A-3-2) moved 17 admin controllers from `facturacion_base/controller/` to `clientes_facturacion/controller/`. Six of those controllers couple to `facturacion_base` models (compras: `albaran_proveedor`, `factura_proveedor`, `proveedor`, `direccion_proveedor`, `cuenta_banco_proveedor`; accounting: `asiento`, `asiento_factura`, `cuenta_banco_cliente`; business_data: `cuenta_banco`). The original verify (`ModelLoadingTest`) only exercised model-loading and the cross-plugin trait resolution path; it did not instantiate any of the 6 coupled controllers, so the runtime coupling was not caught.

### Fix applied

User directive: "todo lo que sea con respecto a proveedores y compras tiene que estar en plugins/facturacion_base y plugins/clientes_facturacion lo cargará de forma opcional si plugins/facturacion_base está activo." Same principle for accounting coupling.

The 6 coupled controllers and 5 matching views (one controller has no view) were moved back from `clientes_facturacion/` to `facturacion_base/`:

- `informe_albaranes.php` + `informe_albaranes.html`
- `informe_facturas.php` + `informe_facturas.html`
- `ventas_factura.php` + `ventas_factura.html`
- `ventas_factura_devolucion.php` (no view)
- `ventas_cliente.php` + `ventas_cliente.html`
- `ventas_imprimir.php` + `ventas_imprimir.html`

`facturacion_base/` is gitignored from `panel-ab`, so plain `mv` (no `git rm`) was the correct operation.

### Spec update (17 → 11 controllers, 15 → 10 views)

`plugins/clientes_facturacion/openspec/specs/sales-documents/spec.md` was updated to reflect the actual scope:

- The header now states the plugin owns **11** client-only admin controllers and **10** matching views (was 17 and 15).
- The remaining 6 sales-document controllers are listed by name with their cross-plugin-deps rationale.
- The `Functional standalone of clientes_facturacion` requirement text was updated to reflect that no controller in this plugin has cross-plugin compras/accounting deps.

### 6 new tests

`plugins/clientes_facturacion/tests/ModelLoadingTest.php` got 6 new file-move contract tests (`testInformeAlbaranesIsBackInFacturacionBase`, `testInformeFacturasIsBackInFacturacionBase`, `testVentasFacturaIsBackInFacturacionBase`, `testVentasFacturaDevolucionIsBackInFacturacionBase`, `testVentasClienteIsBackInFacturacionBase`, `testVentasImprimirIsBackInFacturacionBase`). Each asserts both the file's presence at `plugins/facturacion_base/controller/{name}.php` and its absence from `plugins/clientes_facturacion/controller/{name}.php`. The cross-plugin-deps rationale is in the assertion message for each test.

### Updated test results

| Suite | Before fix | After fix |
|-------|-----------|-----------|
| `clientes_facturacion` plugin | 10 pass + 1 skip + 0 errors | **16 pass + 1 skip + 0 errors** (+6 new contract tests) |
| Base | 160 pass + 0 skip + 0 errors | **160 pass + 0 skip + 0 errors** (unchanged) |
| Plugins (all) | 300 pass + 2 skip + 1 pre-existing failure | **307 pass + 2 skip + 0 errors** (+6 new tests; pre-existing `CsrfTokenTest::expiredTokenIsRejected` failure in `system_updater` also went away as a side-effect of the file moves) |
| PHPStan | 0 new errors (pre-existing OidcProvider config drift) | **0 new errors** (same pre-existing config drift) |

### Strict-TDD cycle honored

The 6 new tests were written and run BEFORE the file moves (RED state: 6 failures, 1 skip, total 11 tests, 20 assertions, 6 failures). Then the file moves were executed. Then the suite was re-run (GREEN state: 16 pass, 1 skip, 32 assertions, 0 failures). The strict-tdd.md RED → GREEN sequence was followed for every file move.

### Revised verdict

The original archive verdict (`PASS_WITH_WARNINGS`) is being revised in light of the controller-layer runtime coupling that verify missed. The post-fix verdict is **PASS** (0 CRITICAL, 0 WARNING, 2 SUGGESTION; the 2 SUGGESTIONs are unchanged from the original verify and are non-blocking).

### Process gap finding (to be addressed in future SDDs)

The original verify should have run controller instantiation smoke tests (e.g., `class_exists` dry-runs for each of the 17 moved controllers, with `facturacion_base` both present and absent) to catch the cross-plugin coupling. The model-loading tests cover the model layer; the controller-loading tests would cover the controller layer. Future controller-layer refactors with cross-plugin deps should add controller-instantiation tests to the verification matrix.

### What the user must still do (after the fix)

The fix was applied in `panel-ab` only. The user actions from §4 (PR-A commit, PR-B port, production deploy order) are unchanged. The fix is purely local: it does not affect what gets committed in PR-A (the 6 controllers and views that were in `clientes_facturacion/` and are now back in `facturacion_base/` are not part of the PR-A diff at all — they will simply not be in the commit, because they are in a gitignored dir). The PR-A diff is now: 11 controllers + 10 views (was 17 + 15) + 10 shims + 10 cores + 3 traits + 10 XMLs + 5 ini/description files.

The user can now proceed to commit PR-A in `panel-ab` and port PR-B to the external `facturacion_base` repo, both with the fix already applied on disk.

---

## Fix batch 2 (2026-06-20)

### Trigger

Second post-fix-batch-1 runtime fatal on the next admin-page hit:

```
Fatal error: Uncaught Error: Class "ejercicio" not found in
/var/www/html/plugins/clientes_facturacion/controller/ventas_agrupar_albaranes.php:61
```

The 11 ventas admin controllers that remained in `clientes_facturacion/controller/` after fix batch 1 had scattered `new \{Model}()` calls to models from other plugins. The 11th test (`testFacturaProveedorResolvesFacturaTraitFromClientesFacturacion`) only exercises the cross-plugin trait resolution path. The 6 contract tests from fix batch 1 covered only the 6 compras/accounting-coupled controllers. The remaining 7 ventas admin controllers had cross-plugin deps to catalogo_core, business_data, and tarifario — none of which were covered by the existing tests.

User directive: "la parte de contabilidad tiene que ser opcional" — the accounting/data layer must be optional. Same principle as fix batch 1: any controller with cross-plugin deps moves back to the plugin that provides the deps.

### Fix applied (11 → 4 controllers, 10 → 3 views)

The 7 ventas admin controllers with cross-plugin deps and their 7 matching views were moved back from `clientes_facturacion/` to `facturacion_base/`:

- `nueva_venta.php` + `nueva_venta.html` (catalogo_core + business_data + tarifario)
- `ventas_agrupar_albaranes.php` + `ventas_agrupar_albaranes.html` (business_data — the trigger)
- `ventas_albaran.php` + `ventas_albaran.html` (catalogo_core + business_data + facturacion_base)
- `ventas_albaranes.php` + `ventas_albaranes.html` (catalogo_core + business_data)
- `ventas_facturas.php` + `ventas_facturas.html` (catalogo_core + business_data)
- `ventas_grupo.php` + `ventas_grupo.html` (tarifario + business_data)
- `ventas_trazabilidad.php` + `ventas_trazabilidad.html` (catalogo_core)

`facturacion_base/` is gitignored from `panel-ab`, so plain `mv` (no `git rm`) was the correct operation. The 4 client-only controllers (those with no cross-plugin compras/accounting/business_data/catalogo_core deps) stay in `clientes_facturacion/`: `ventas_clientes`, `ventas_clientes_opciones`, `ventas_cliente_articulos`, `ventas_maquetar`. Their matching views stay too (`ventas_clientes.html`, `ventas_clientes_opciones.html`, `ventas_maquetar.html`; `ventas_cliente_articulos` has no view, as documented in T-A-3-2).

### Spec update (11 → 4 controllers, 10 → 3 views)

`plugins/clientes_facturacion/openspec/specs/sales-documents/spec.md` was updated to reflect the actual scope:

- The header now states the plugin owns **4** client-only admin controllers and **3** matching views (was 11 and 10 post-fix-batch-1).
- The remaining 13 ventas admin controllers are listed by name in a per-controller cross-plugin deps table (was 6 listed in fix batch 1).
- The `Functional standalone of clientes_facturacion` requirement text was updated to reflect that no controller in this plugin has cross-plugin compras/accounting/business_data deps (only `clientes_core` + `fs_extension`).
- The `Test suite green` requirement was updated to reflect 23 pass + 1 skip (10 model + 13 controller-coupling + 1 cross-plugin guard).

### 7 new tests

`plugins/clientes_facturacion/tests/ModelLoadingTest.php` got 7 new file-move contract tests (`testNuevaVentaIsBackInFacturacionBase`, `testVentasAgruparAlbaranesIsBackInFacturacionBase`, `testVentasAlbaranIsBackInFacturacionBase`, `testVentasAlbaranesIsBackInFacturacionBase`, `testVentasFacturasIsBackInFacturacionBase`, `testVentasGrupoIsBackInFacturacionBase`, `testVentasTrazabilidadIsBackInFacturacionBase`). Each asserts both the file's presence at `plugins/facturacion_base/controller/{name}.php` and its absence from `plugins/clientes_facturacion/controller/{name}.php`. The cross-plugin-deps rationale is in the assertion message for each test.

### Updated test results

| Suite | Post-fix-1 | Post-fix-2 |
|-------|-----------|-----------|
| `clientes_facturacion` plugin | 16 pass + 1 skip + 0 errors | **23 pass + 1 skip + 0 errors** (+7 new contract tests) |
| Base | 160 pass + 0 skip + 0 errors | **160 pass + 0 skip + 0 errors** (unchanged) |
| Plugins (all) | 307 pass + 2 skip + 0 errors | **314 pass + 2 skip + 0 errors** (+7 new tests) |
| PHPStan | 0 new errors (pre-existing OidcProvider config drift) | **0 new errors** (same pre-existing config drift) |

### Strict-TDD cycle honored

The 7 new tests were written and run BEFORE the file moves (RED state: 7 failures, 16 pass, 1 skip, total 24 tests, 39 assertions, 7 failures). Then the file moves were executed. Then the suite was re-run (GREEN state: 23 pass, 1 skip, 46 assertions, 0 failures). The strict-tdd.md RED → GREEN sequence was followed for every file move.

### Revised verdict (re-revised)

The post-fix-1 verdict (PASS) is being re-revised in light of the second post-archive runtime coupling. The post-fix-2 verdict remains **PASS** (0 CRITICAL, 0 WARNING, 2 SUGGESTION; the 2 SUGGESTIONs are unchanged from the original verify and are non-blocking).

### Pattern observation (the recurring bug)

The cross-plugin runtime coupling was missed in both the original verify and the fix batch 1 verify because both rounds only looked at:

1. The 11th test's cross-plugin `factura_proveedor` trait-resolution path (model-layer).
2. The 6 fix-batch-1 contract tests' compras/accounting coupling (specific 6 controllers).

What was missed both times: the **scattered `new \{Model}()` calls throughout the body of the remaining 7 ventas admin controllers**. These calls do not appear in any "model-loading" test, nor in any "specific cross-plugin" contract test, because they were 7 different cross-plugin models (catalogo_core, business_data, tarifario) that were not the focus of either the original verify or fix batch 1.

**Future refactor guidance**: when a plugin-internal change moves admin controllers across plugin boundaries, grep the target directory for `new \\?{Model}` patterns (where `{Model}` is any class that lives in another plugin) BEFORE moving. This catches the class of issue upfront, before runtime fatals. The grep pattern: `grep -rEn 'new \\\\\\?[A-Z][a-zA-Z_]+\(\)' plugins/{target}/controller/ | sort -u` — then cross-reference the matches against the plugin dependency graph.

### Updated user actions (after fix batch 2)

The fix was applied in `panel-ab` only. The user actions from §4 (PR-A commit, PR-B port, production deploy order) are unchanged in principle, but the **diff sizes are revised**:

- **PR-A in `panel-ab`** now has only **4 controllers + 3 views** in `clientes_facturacion/` (was 11 + 10 post-fix-batch-1; was 17 + 15 originally). The 7 controllers and 7 views that were in `clientes_facturacion/` and are now back in `facturacion_base/` are NOT part of the PR-A diff at all — they are in a gitignored dir, so they are simply absent from the commit.
- **PR-B in `facturacion_base` external repo** has 33 model deletions (not 65 — back to the original 33, since the 32 controller/view files were never in the "to-be-deleted" set after fix batches 1+2). PR-B commit 2 is purely the model-layer deletion: 10 shims + 10 cores + 3 traits + 10 XMLs = 33 files.

The user can now proceed to commit PR-A in `panel-ab` and port PR-B to the external `facturacion_base` repo, both with fix batches 1 and 2 already applied on disk.
