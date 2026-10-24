# Verify Report: phpstan-plugin-coverage

## Verdict

**PASS** — the gate is green with no baseline and no suppression.

| Check | Result |
|---|---|
| `ddev exec composer phpstan:plugins` | `[OK] No errors` (18 files analysed) |
| Gate with an optional plugin absent (`factura_pdf1` hidden) | `[OK] No errors` |
| `plugins/clientes_core` | `OK (134 tests, 485 assertions)` |
| `plugins/tpvmod` | `OK (192 tests, 1586 assertions)` |
| `plugins/catalogo_core` | `OK (1378 tests, 5673 assertions)`, 2 warnings + 1 skipped pre-existing |
| Root `composer phpstan` | 38 errors, unchanged (pre-existing, see WARNING-1) |
| Baselines / `@phpstan-ignore` / `ignoreErrors` added | none |

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
- **No `ignoreErrors` at all.** The one entry this change initially carried was
  removed after review: `fs_model` and `fs_extended_model` declare
  `#[\AllowDynamicProperties]` and derive their fields from the schema, so they
  are modelled as `universalObjectCratesClasses` alongside `fs_controller`.
  Modelling the real design beats suppressing every `property.notFound` in a
  file.
- **The profile is generated from what is installed.** `tools/phpstan-plugins.php`
  resolves the analysed paths and the scanned directories against the checkout.
  PHPStan aborts when a `scanDirectories` entry is missing, and plugins are
  optional repositories, so a static list would make the gate unusable on a
  partial install. Verified by hiding `plugins/factura_pdf1`: still zero errors.
- **Explicit candidate lists.** Widening is a per-plugin decision, not an
  automatic scan.

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

## Review de CodeRabbit (post-verify)

`coderabbit review --agent --dir . -t committed --base-commit c9428e7e` →
2 findings `major`, ambos sobre `phpstan-plugins.neon`. Los dos se verificaron
empíricamente antes de aceptarlos y ambos eran correctos:

| Finding | Verificación | Resolución |
|---|---|---|
| Un `scanDirectories` inexistente aborta el análisis | Reproducido: `Scanned directory ... does not exist`, exit 1, sin analizar nada | `tools/phpstan-plugins.php` genera el perfil contra lo instalado. Probado ocultando `plugins/factura_pdf1`: `[OK] No errors` |
| `identifier` + `path` ocultaba todo `property.notFound` del archivo | Confirmado en la semántica de `ignoreErrors` | Se eliminó la exclusión entera: `fs_extended_model` pasa a `universalObjectCratesClasses` (11 errores cubiertos por diseño, no por supresión) |

Resultado neto del review: el perfil quedó **sin ninguna exclusión** y el gate
sigue en cero. Un finding se cerró eliminando supresión, que es el mejor
desenlace posible.
