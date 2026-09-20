# Verify Report: Remove FS_DEMO

**Date**: 2026-09-19
**Verifier**: Agent (automated)
**Status**: **PASS**

## Summary

`FS_DEMO` has been completely eliminated from the FSFramework codebase. The
constant, all conditional branches, the `log_in_demo()` method, 7 plugin
controller guards, 5 Twig template conditionals, and the config/bootstrap
definitions have all been removed. Two regression test suites guard the removal.

## Verification Matrix

| Criterion | Result | Evidence |
|-----------|--------|----------|
| No `FS_DEMO` in PHP source | **PASS** | `grep -rn FS_DEMO --include='*.php'` returns only test assertions that assert its *absence* |
| No `FS_DEMO` in Twig templates | **PASS** | `grep -rn FS_DEMO --include='*.twig'` returns zero results |
| No `FS_DEMO` in config | **PASS** | Removed from `config.php`, `tests/bootstrap.php`, `phpstan.neon` |
| Full test suite green | **PASS** | 2364 tests, 7877 assertions, 0 failures, 24 skipped (pre-existing) |
| Plugin suite green | **PASS** | `catalogo_core` tests pass including inverted assertions |
| Regression guards in place | **PASS** | `FsUserComposeMenuTest::userAuthorityNeverReadsFsDemo()` greps source for `FS_DEMO` in authority methods; `VentasArticuloArticleEditAbsorptionTest` and `VentasArticulosListAbsorptionTest` assert `FS_DEMO` is absent from delete methods |
| Documentation updated | **PASS** | `AGENTS.md`, skill files, PA-09 spec all updated |

## Files Modified

### Core (14 files)

| File | Change |
|------|--------|
| `base/fs_login.php` | Removed `log_in_demo()` (~40 lines) + `FS_DEMO` branch in `login()` |
| `model/core/fs_user.php` | Removed `FS_DEMO` guard in `rotate_logkey()` + comment blocks |
| `controller/admin_home.php` | Removed demo advice block |
| `controller/admin_users.php` | Removed demo guard in `delete_user()` |
| `controller/admin_user.php` | Removed demo guard in `modificar_user()` |
| `controller/admin_agentes.php` | Removed demo guard in agente deletion |
| `themes/AdminLTE/view/login/default.html.twig` | Removed 5 demo conditionals |
| `themes/AdminLTE/view/admin_users.html.twig` | Removed masking + tab guard |
| `themes/AdminLTE/view/admin_user.html.twig` | Removed conditional email input |
| `themes/AdminLTE/view/password_reset.html.twig` | Removed demo title |
| `themes/AdminLTE/view/feedback.html.twig` | Removed demo guard |
| `tests/bootstrap.php` | Removed `define('FS_DEMO', false)` |
| `phpstan.neon` | Removed from bootstrap constants |
| `config.php` | Removed `define('FS_DEMO', FALSE)` |

### Plugin catalogo_core (9 files)

| File | Change |
|------|--------|
| `Controller/VentasFamilia.php` | Removed demo delete guard |
| `Controller/VentasFabricante.php` | Removed demo delete guard |
| `Controller/VentasFabricantes.php` | Removed demo delete guard |
| `Controller/VentasArticulos.php` | Removed demo delete guard |
| `Controller/VentasOpcional.php` | Removed demo delete guard |
| `Controller/VentasArticulo.php` | Removed demo delete guard |
| `Controller/AdminPaises.php` | Removed demo delete guard |
| `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | Inverted assertion |
| `tests/Controller/VentasArticulosListAbsorptionTest.php` | Inverted assertion |

### Documentation (5 files)

| File | Change |
|------|--------|
| `AGENTS.md` | Removed "FS_DEMO never widens authority" subsection |
| `.cursor/skills/fsframework-security-review/SKILL.md` | Updated to past tense |
| `.opencode/skills/fsframework-security-review/SKILL.md` | Updated to past tense |
| `openspec/specs/page-authorization/spec.md` | Updated PA-09 |
| `tests/Base/FsUserComposeMenuTest.php` | Updated docblocks |

## Residual References (all acceptable)

All remaining `FS_DEMO` strings are in:
1. **Regression test assertions** that verify `FS_DEMO` is NOT in the source code
2. **Documentation** using past tense ("was removed", "was fully removed")
3. **Archived SDD documents** (historical, not touched)

## Open Issues

None.
