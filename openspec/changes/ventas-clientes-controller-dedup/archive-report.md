# Archive report: ventas_clientes dispatch fix

## Summary
- **Verdict**: PASS WITH CAVEATS
- **Status**: ARCHIVED (ready to merge to main)
- **Branch**: `fix/ventas-clientes-dispatch-sentinel`
- **Commit**: `331daf96`
- **Author**: Javier Trujillo <javier.trujillo.jimenez@gmail.com>
- **Date**: 2026-06-14
- **Diff size**: 18 insertions, 15 modifications, 2 files (well under the 400-line review budget)
- **Test status**: PHPUnit suite green except 1 pre-existing failure in `system_updater` (gitignored plugin, fails on master)

## What was delivered

### The bug
Submitting the "Nuevo cliente" modal on `ventas_clientes` silently failed. The dispatch chain in `plugins/clientes_core/controller/ventas_clientes.php` line 82 checked `filter_input(INPUT_POST, 'codcliente')`, which returns an empty string for the legitimate "auto-generate" path. Empty string is falsy in PHP, so the dispatch fell through to `load_clientes()` instead of invoking `nuevo_cliente()`. The user saw the page reload with no error, no success, and no new row in `clientes`.

### The fix (commit 331daf96)
1. **Dispatch chain** (controller line 82): replaced the implicit `filter_input(INPUT_POST, 'codcliente')` truthy check with an explicit `action === 'nuevo_cliente'` sentinel.
2. **`nuevo_cliente()` method** (controller line 177): added a `codigo` field fallback in the codcliente cascade for in-flight legacy submissions.
3. **Symfony Request idiom** (controller lines 72-86, 175-185, 209, 235, 255): switched all 15 POST reads from `filter_input(INPUT_POST, ...)` to `$this->request->request->get(...)` for consistency with Symfony 7.4 and CLI testability. The two are semantically equivalent under HTTP SAPIs.
4. **Twig modal form** (`ventas_clientes.html.twig` line 248): added `<input type="hidden" name="action" value="nuevo_cliente"/>` inside the new-client form so the dispatch is triggered from the actual UI submission.

### Spec scenarios satisfied
- VC-01, VC-01.a, VC-01.b, VC-01.c: PASS
- VC-02, VC-02.a, VC-02.b: PASS
- VC-03 (legacy codigo field): PASS
- VC-05, VC-05.a, VC-05.b (CSRF): PASS
- VC-07 (single-cliente detail): PASS, no regression
- VC-08 (opciones page): PASS, no regression

### Spec scenarios deferred or abandoned
- **VC-06** (dead legacy file removal): **DEFERRED** to a follow-up change. The 3 files in `facturacion_base/` total ~700 lines and would exceed the 400-line review budget if added to this PR.
- **VC-09** (PHPUnit regression test): **ABANDONED** during `sdd-apply` because `filter_input(INPUT_POST, ...)` and `header()` are no-ops in PHPUnit CLI (Engram obs #142, key `php-cli-test-isolation-limits`). The verify phase compensated with 11 real-HTTP curl behavioral tests that all passed.

## Verification evidence
- Real-HTTP curl tests against `https://panel-ab.ddev.site` with a forged-but-valid session: 11/11 scenarios passed. See `verify-report.md` §"Behavioral tests performed" for verbatim commands and outputs.
- Synthetic reflection-based dispatch verifier (5 scenarios) confirmed the dispatch chain reaches or skips `nuevo_cliente()` correctly for every input shape.
- Full PHPUnit suite: 558 tests, 1 pre-existing failure unrelated to this change.

## Follow-up work spawned by this change

These are **not part of this change** and should be opened as new SDD changes:

1. **T1 regression test** (covered by the `php -S` harness or a controller refactor that exposes a `dispatch(Request): Response` method). Without this, future refactors of the dispatch chain could silently reintroduce the bug. See `verify-report.md` WARNING #1.
2. **T4 dead-file deletion** in `facturacion_base/` (3 files, ~700 lines of pure deletions): `plugins/facturacion_base/controller/ventas_clientes.php`, `plugins/facturacion_base/view/ventas_clientes.html`, `plugins/facturacion_base/view/block/ventas_clientes_nuevo.html`. See `verify-report.md` WARNING #1.
3. **Optional SUGGESTION** from verify-report: fix the duplicate error message in `nuevo_cliente()` by deduping `array_unique($cliente->get_errors())` in the controller loop at line 191-193. Pre-existing (not introduced by this change), but the change is a natural moment to fix it.

## How to merge

The branch `fix/ventas-clientes-dispatch-sentinel` contains a single commit (`331daf96`) with the fix. Suggested merge procedure:

```bash
# Verify branch is clean
git checkout fix/ventas-clientes-dispatch-sentinel
git status
git log --oneline -1

# Push the branch
git push origin fix/ventas-clientes-dispatch-sentinel

# Open a PR via the host's PR UI (or `gh pr create`):
gh pr create --title "fix(ventas_clientes): detect new-client form via action sentinel" \
  --body "Closes <issue-number-if-any>. See openspec/changes/ventas-clientes-controller-dedup/ for the full SDD trail."

# After PR is reviewed and approved:
git checkout main
git merge --no-ff fix/ventas-clientes-dispatch-sentinel
git push origin main
```

## Final state of the change directory

Files in `openspec/changes/ventas-clientes-controller-dedup/` after archive:
- `proposal.md` — what & why (76 lines)
- `spec.md` — 9 requirements with Gherkin scenarios (165 lines)
- `design.md` — architectural decisions + full diffs (461 lines)
- `tasks.md` — task plan with T1 abandonment rationale (revised to reflect actual delivery)
- `verify-report.md` — PASS WITH CAVEATS verification with behavioral test evidence (209 lines)
- `archive-report.md` — this file

## Pre-existing issues carried forward (not regressions)

- `plugins/system_updater/tests/CsrfTokenTest.php:83` `expiredTokenIsRejected` fails on master. The plugin is gitignored (`.gitignore:35:/plugins/*`) and was last modified 2026-06-12, two days before this change. Not touched by this change. **No action needed in this PR.**

## Out-of-scope items (re-stated from earlier phases)

- T4 dead-file deletion — see "Follow-up work spawned" above.
- T1 regression test — see "Follow-up work spawned" above.
- The three session/cookie fixes already applied in `src/Security/SessionManager.php` and `src/Core/StealthMode.php` (archived in their own change; Engram obs #138).
- `ventas_clientes_opciones.php` / `.html` (dead but out of scope to keep the diff under the 400-line review budget).
- Writing a default `direccion_cliente` row in `nuevo_cliente()` (the legacy controller did this; the modern one does not — adding it is a feature, not a bugfix).
- Refactoring `cliente::test()` / `save()` (they are correct).
- Any change to other plugins (`tpvmod`, `tarifario`, `clientes_catalogo`, `clientes_facturacion`).
