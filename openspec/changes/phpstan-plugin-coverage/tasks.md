# Tasks: phpstan-plugin-coverage

## T1 — Profile and entry point

- [x] **T1.1** Add `phpstan-plugins.neon`: level 5, core `phpstan-bootstrap.php`,
      `scanDirectories` for core + sibling plugins, `fs_controller` as a
      universal object crate, no baseline.
- [x] **T1.2** Add the `phpstan:plugins` script to `composer.json`.
- [x] **T1.3** Document the one intentional `ignoreErrors` entry (dynamic
      property loop over `cliente::COMMERCIAL_FIELDS`).

## T2 — Contract fixes the gate forced

- [x] **T2.1** `base/fs_extended_model.php`: `get()` returns
      `fs_extended_model|false`, never `true`.
- [x] **T2.2** `plugins/catalogo_core/model/core/articulo.php`: `get()` returns
      `\FSFramework\model\articulo|false` (FQCN, because the global name only
      exists through a runtime `class_alias`).
- [x] **T2.3** `plugins/clientes_core`: make the nullable fields truthful
      (`codcliente`, `codserie`, `codagente`, `codgrupo`, `fechabaja`,
      `fechaalta`, `diaspago`, `codproveedor`, `codtarifa`, `direccion_cliente::$id`,
      `$codcliente`, `grupo_clientes::$codgrupo`, `$codtarifa`,
      `grupo_descuentos::$codgrupo_descuento`, `cuenta_banco_cliente::$codcuenta`,
      `$codcliente`, `$fmandato`).
- [x] **T2.4** `plugins/clientes_core`: keep `nombre` non-nullable and fix the
      assignment instead (`''` in both group models; drop the dead `??` in
      `cliente::validateFields()`).
- [x] **T2.5** `plugins/clientes_core`: `get_by_cifnif()` declares
      `string|false` for `$razon`, matching its real usage.
- [x] **T2.6** `plugins/clientes_core`: `FS_CIFNIF` is optional in this
      deployment; guard with `defined()` and fall back to the XML default `NIF`.
- [x] **T2.7** `plugins/tpvmod`: widen the `imprimir_url()` parameter to
      `string` so the `default => null` arm stays reachable; delete the
      impossible `if (!$imp)` guard and the dead `array_values()` / `??` in the
      opcionales helpers.

## T3 — Verification

- [x] **T3.1** `ddev exec composer phpstan:plugins` → `[OK] No errors`.
- [x] **T3.2** `plugins/clientes_core` suite green (134 tests / 477 assertions).
- [x] **T3.3** `plugins/tpvmod` suite green (192 tests / 1586 assertions).
- [x] **T3.4** `plugins/catalogo_core` suite green (1378 tests / 5673 assertions,
      2 warnings + 1 skipped pre-existing).
- [x] **T3.5** Root `composer phpstan` not worsened (38 pre-existing errors,
      unchanged in count and scope).

## Definition of done

1. `composer phpstan:plugins` exits 0 with no baseline and a single documented
   ignore.
2. Plugin suites still green.
3. No `@phpstan-ignore` or new baseline in any touched repo.
4. The widened-scope convention is written down for the next plugin.
