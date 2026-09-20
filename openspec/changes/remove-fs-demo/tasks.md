# Tasks: Remove FS_DEMO

## Phase 1 — PHP: Remove dead demo branches

- [x] 1.1 **`base/fs_login.php`** — Deleted `log_in_demo()` method (~40 lines). Removed `if (FS_DEMO)` branch in `login()` so the flow falls through to `log_in_user()` unconditionally.
- [x] 1.2 **`model/core/fs_user.php`** — In `rotate_logkey()`, removed the `!FS_DEMO` guard: always regenerates `log_key`. Removed comment blocks referencing FS_DEMO.
- [x] 1.3 **`controller/admin_home.php`** — Deleted the `if (FS_DEMO)` advice block.
- [x] 1.4 **`controller/admin_users.php`** — In `delete_user()`, removed `if (FS_DEMO)` branch. Kept `!$this->user->admin` check.
- [x] 1.5 **`controller/admin_user.php`** — In `modificar_user()`, removed `if (FS_DEMO && ...)` branch. Kept `!$this->allow_modify` check.
- [x] 1.6 **`controller/admin_agentes.php`** — Deleted `if (defined('FS_DEMO') && FS_DEMO)` block.

## Phase 2 — Twig: Remove demo conditionals

- [x] 2.1 **`themes/AdminLTE/view/login/default.html.twig`** — Removed all 5 `FS_DEMO` conditionals: title, autofocus, hidden password, remember-me, password-reset link.
- [x] 2.2 **`themes/AdminLTE/view/admin_users.html.twig`** — Removed email/IP masking and permission tab guard.
- [x] 2.3 **`themes/AdminLTE/view/admin_user.html.twig`** — Removed conditional email input.
- [x] 2.4 **`themes/AdminLTE/view/password_reset.html.twig`** — Removed demo title branch.
- [x] 2.5 **`themes/AdminLTE/view/feedback.html.twig`** — Removed demo guard on background tasks.

## Phase 3 — Config and tooling

- [x] 3.1 **`tests/bootstrap.php`** — Removed `define('FS_DEMO', false);`.
- [x] 3.2 **`phpstan.neon`** — Removed `- FS_DEMO` from bootstrap constants.
- [x] 3.3 **`config.php`** — Removed `define('FS_DEMO', FALSE);` (found during audit).

## Phase 4 — Tests

- [x] 4.1 **`tests/Base/FsUserComposeMenuTest.php`** — Updated docblock comments to past tense. Kept regression assertions as guards.
- [x] 4.2 **Full test suite** — `ddev exec php vendor/bin/phpunit` → 2364 tests, 7877 assertions, 0 failures.

## Phase 5 — Documentation

- [x] 5.1 **`AGENTS.md`** — Removed the "FS_DEMO never widens authority" subsection.
- [x] 5.2 **`.cursor/rules/fs-framework-general.mdc`** — No `FS_DEMO` reference found.
- [x] 5.3 **`.cursor/rules/fs-framework-security.mdc`** — No `FS_DEMO` reference found.
- [x] 5.4 **Skill files** — Updated both `.cursor/skills/` and `.opencode/skills/` security review skills.
- [x] 5.5 **`openspec/specs/page-authorization/spec.md`** — Updated PA-09 to reflect full removal.

## Phase 6 — Plugin: catalogo_core

- [x] 6.1 **7 controllers** — Removed `FS_DEMO` delete guards from `VentasFamilia`, `VentasFabricante`, `VentasFabricantes`, `VentasArticulos`, `VentasOpcional`, `VentasArticulo`, `AdminPaises`.
- [x] 6.2 **2 absorption tests** — Inverted assertions from `assertStringContainsString('FS_DEMO')` to `assertStringNotContainsString('FS_DEMO')`.
- [x] 6.3 **Full test suite re-run** — 2364 tests, 0 failures after plugin changes.

## Phase 7 — Final verification

- [x] 7.1 **Grep audit** — Zero `FS_DEMO` references in executable code. Only past-tense documentation and regression test guards remain.
