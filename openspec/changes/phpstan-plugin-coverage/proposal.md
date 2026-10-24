# Proposal: PHPStan coverage for plugin code

## Intent

Give the plugin surface a static-analysis gate. `phpstan.neon` analyses only
`src/` and the root `tests/`, so plugin code has never been checked: an entire
gitignored ecosystem is invisible to the quality gate. The archived
`clientes_core` change `cliente-form-compartido-grupos-obligatorios` recorded
this as Definition-of-done item 7 — NOT MET — because the toolchain could not
analyse plugin code at all.

## Evidence that the gap is real

Measured before writing the profile, on the current tree:

| Scope | Errors at level 5 |
|---|---|
| `plugins/tpvmod` lib + controllers + Init | 279 |
| `plugins/clientes_core` (ClienteForm, Init, model) | 278 |

Of those, roughly two thirds were missing-symbol noise (`class.notFound`,
`constant.notFound`) because optional plugins are not indexed. The rest exposed
real defects that no other gate was catching:

- `base/fs_extended_model::get()` declared `@return fs_extended_model|boolean`,
  but the implementation returns only the object or `false`. Fourteen false
  positives across plugins traced back to that single wrong contract.
- `catalogo_core/model/core/articulo::get()` declared the same wrong contract.
- Five `clientes_core` models declared `@var string` on fields that the code —
  correctly, per the "Client group is optional" requirement — assigns `NULL`.
  Nine `is_null()` checks were unreachable as a result, and five `??` guards
  were dead.
- `cliente::__construct()` read `FS_CIFNIF` unconditionally; the constant is
  optional in this deployment, so `new cliente()` could fatal.

## Scope

### In Scope

- `phpstan-plugins.neon`: a core-owned profile that analyses plugin code with
  the core bootstrap, `scanDirectories` for core plus sibling plugins,
  `fs_controller` modelled as a universal object crate, and **no baseline**.
- `composer.json`: a `phpstan:plugins` script.
- `base/fs_extended_model.php`: correct the `get()` return contract to
  `fs_extended_model|false`.
- Contract corrections the gate forced in three plugins (docblock/typing level,
  plus one latent fatal in `cliente`): `clientes_core`, `tpvmod`,
  `catalogo_core`.

### Out of Scope

- The 38 pre-existing errors of the root `phpstan.neon` run (all in four core
  test files). They predate this change and are tracked in the verify report as
  the next gate to close.
- Adding every installed plugin to the profile. The convention and the cost are
  documented; widening the list is a per-plugin decision.
- Any `ignoreErrors` growth or baseline: the profile must stay at zero errors by
  correcting contracts, not by silencing them.

## Approach

1. A single core-owned profile, so plugins do not each need a toolchain. The
   binary and bootstrap belong to the core (`vendor/dev-tools`).
2. `scanDirectories` resolves cross-plugin symbols without reporting on code
   outside the analysed paths.
3. Fix the contract at its source. Every finding was resolved by making the
   declaration match the runtime, never by adding an ignore. One documented
   `ignoreErrors` entry covers the deliberate dynamic-property loop over
   `cliente::COMMERCIAL_FIELDS`, which is not statically resolvable by design.
4. The profile starts with the two plugins this session touched; the list is
   explicit and reviewable.

## Risks

- **Widening the list is not free.** Each added plugin will surface its own
  debt; that is the point, but it must be scheduled, not discovered.
- **Docblock corrections change the declared contract.** They do not change
  runtime behaviour, but downstream consumers that rely on the old (wrong)
  contract will now see `null` in their own analysis. Both affected plugin
  suites stay green.
