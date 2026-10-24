# Verify Report: phpstan-plugin-coverage

## Verdict

**PASS** — the gate is green with no baseline and one documented ignore.

| Check | Result |
|---|---|
| `ddev exec composer phpstan:plugins` | `[OK] No errors` (from 47 after the profile was scoped, 279/278 before scoping) |
| `plugins/clientes_core` | `OK (134 tests, 477 assertions)` |
| `plugins/tpvmod` | `OK (192 tests, 1586 assertions)` |
| `plugins/catalogo_core` | `OK (1378 tests, 5673 assertions)`, 2 warnings + 1 skipped pre-existing |
| Root `composer phpstan` | 38 errors, unchanged (pre-existing, see WARNING-1) |
| Baselines / `@phpstan-ignore` added | none |

## What the gate proved

The profile is not cosmetic: closing it required correcting six wrong contracts
and one latent fatal.

| Finding | Resolution |
|---|---|
| `fs_extended_model::get()` declared `\|boolean` | `\|false` — it never returns `true` (14 false positives across plugins) |
| `catalogo_core articulo::get()` same defect | `\FSFramework\model\articulo\|false` |
| 18 `clientes_core` model fields declared `string`/`int` but assigned `NULL` | truthful `\|null` contracts |
| 9 `is_null()` checks unreachable because of the wrong contracts | became reachable again, no code change needed |
| 5 `??` guards on non-nullable fields | dead code removed |
| `get_by_cifnif($razon = FALSE)` incompatible default | `string\|false` |
| `cliente::__construct()` read `FS_CIFNIF` unconditionally | guarded with `defined()`, falls back to the XML default `NIF`; `new cliente()` no longer fatals when the constant is absent |
| `tpvmod` `match` arm unreachable because of an over-narrow parameter type | parameter widened to `string`, `default => null` preserved (behaviour kept) |

## Deliberate design choices

- **No baseline.** The profile must stay at zero by fixing contracts. A baseline
  would hide exactly the class of defect this gate exists to catch.
- **One `ignoreErrors` entry**, scoped to `cliente.php`, for the
  `$model->{$field}` loop over `cliente::COMMERCIAL_FIELDS`. The field list is a
  class constant, but the access is by variable name; resolving it statically
  would duplicate the mapping. The pattern is covered by tests.
- **`universalObjectCratesClasses: [fs_controller]`** models the framework's
  deliberate contract: controllers publish properties for their views.
- **Explicit plugin list.** The profile names the analysed paths; widening is a
  per-plugin decision, not an automatic scan.

## WARNING-1 — The root `phpstan.neon` run is still red

`composer phpstan` (core scope) reports 38 errors, all in four core test files:
22 `function.notFound` (legacy global functions not indexed for those tests),
10 `argument.type`, 5 `property.notFound`, 1 `constant.notFound`:

- `tests/Base/OperatorAuthorizationTest.php` (13)
- `tests/Core/UpdaterManifestTest.php` (10)
- `tests/Base/SelectLimitCastingTest.php` (10)
- `tests/Security/RememberMeSignatureRequiredTest.php` (5)

This predates the change (the archived `clientes_core` report recorded one of
these as the reason its Definition-of-done item 7 could not be met) and is out of
scope here. The fix path is the same discipline: index the legacy functions in
`scanFiles` and correct the argument types, with no baseline.

## WARNING-2 — Scope is two plugins, not the ecosystem

The profile analyses `clientes_core` and `tpvmod`. Every other installed plugin
will surface its own debt when added. The convention for doing so is: append its
paths and its sibling `scanDirectories` entries, then fix the contracts; if a
plugin needs a suppression, that is a design discussion, not a default.

## Not covered by this gate

- Twig templates (no analyser).
- Runtime behaviour: the suites cover it, not PHPStan.
- `plugins/*/vendor/` (third-party code).

## Commits

- Core: this change (profile, script, `fs_extended_model` contract).
- `plugins/clientes_core`: `b1871f2` — make model property contracts match
  runtime state.
- `plugins/tpvmod`: `6b91bd5` — remove dead defensive code from the tpv helpers.
- `plugins/catalogo_core`: `8f856f03` — correct the `articulo::get()` return
  contract.
