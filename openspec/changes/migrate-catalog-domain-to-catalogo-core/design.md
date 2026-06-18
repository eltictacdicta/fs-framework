# Design: migrate-catalog-domain-to-catalogo-core

## 1. Technical Approach

Plumbing-first vertical-slice migration. PR1 `git mv`s 13 PHP + 13 XML into `catalogo_core/{model/core,model/table}/` and ships `Init.php` (autoloader for `FSFramework\model\*`) + `compat/class_aliases.php` (4 `class_alias` calls preserving `get_class()` identity per CDM-08). PR1 changes no behavior — `phpunit Plugins` stays green. PR2..N migrate one page per PR (PSR-4 controller + Twig view + thin wrapper) under 600-line/PR budget with strict TDD. References: CDM-01..11, CAM-01..10, CPV-01..11.

## 2. Architecture Decisions

### AD-1: Compat layer mechanism

- **Choice**: `class_alias()` for `articulo`, `familia`, `fabricante`, `impuesto`.
- **Alternatives**: thin wrapper class (rejected — `instanceof` in `tpvmod` breaks); trait (rejected — modifies `model/core/articulo.php`, out of PR1 scope); keep stubs in facturacion_base (rejected by C3).
- **Rationale**: `class_alias` preserves `get_class()` identity (CDM-08), zero runtime cost, `@deprecated` docblock signals migration.

### AD-2: `Init.php` autoload strategy

- **Choice**: `spl_autoload_register` mapping `FSFramework\model\*` → `plugins/catalogo_core/model/core/*`.
- **Alternatives**: PSR-4 composer autoload (rejected — `composer dump-autoload` per hot-reload); per-controller `require_once` (rejected — repeats per consumer); eager `require_once` in `Init.php` (rejected — loads 13 files per request).
- **Rationale**: One registration covers all 13 models (CAM-03, CAM-09); autoloader is lazy.

### AD-3: Cross-repo `git mv` ordering

- **Choice**: PR-A in `facturacion_base` repo FIRST (delete stubs + 13 PHP + 13 XML + 10 controllers + 16 views, release tag); PR1 in panel-ab SECOND (`fs_plugin_manager` fetches, `git mv` confirms, `Init.php` + `compat/` land).
- **Alternatives**: PR1 first (rejected — facturacion_base plugin briefly references missing classes in its own tree); atomic single-step (rejected — independent repos, independent cadence).
- **Rationale**: PR-A must propagate via plugin update BEFORE PR1's autoloader assumes files live under `catalogo_core/`.

### AD-4: Wrapper legacy pattern

- **Choice**: `controller/<lowercase>.php` extends `Controller/<PascalCase>.php` (mirror `admin_almacenes.php` / `AdminAlmacenes.php`).
- **Alternatives**: rewrite from scratch (rejected — loses page-name routing CPV-05); inline logic in wrapper (rejected — 500-line file, defeats the pattern).
- **Rationale**: 5–7 line shim satisfies CPV-03, CPV-05. All logic in PSR-4 class.

### AD-5: Twig template strategy

- **Choice**: full port — `{$fsc->x}` → `{{ fsc.x }}`, `{#loop=...}` → `{% for %}`, `{include="header"}` → `{% include 'header.html.twig' %}`, `{if=...}{else}{/if}` → `{% if %}{% else %}{% endif %}`.
- **Alternatives**: partial port (rejected — Twig parser fails on RainTPL directives); jinja-like dialect (rejected — non-standard).
- **Rationale**: AdminLTE's `header.html.twig`/`footer.html.twig` exist; full port matches `admin_almacenes.html.twig` (auto-escape satisfies CPV-06).

### AD-6: Test placement

- **Choice**: `plugins/catalogo_core/tests/CompatAliasesTest.php` + `InitAutoloadTest.php`; per-entity under `plugins/catalogo_core/tests/<Entity>/` mirroring `ArticuloModelEncodingTest`.
- **Alternatives**: project root `tests/` (rejected — root reserved for open-core per AGENTS.md); monolithic file (rejected — unreviewable past 600 lines).
- **Rationale**: Per-entity directory keeps each PR's test delta small.

### AD-7: Per-entity PR slicing order

- **Choice**: `almacen` (reference) → `pais` → `divisa` → `impuesto` → `fabricante` → `familia` → `articulo` last. Adjacent groups: stocks/transfers together, attributes/combinations together, tariffs/suppliers together.
- **Alternatives**: all-in-one (rejected — exceeds 600 lines); `articulo` first (rejected — 1391-line model + ~450-line Twig; if a compat leak surfaces, we re-merge instead of catching on a smaller entity).
- **Rationale**: Smaller reference entities first, biggest + riskiest last when the compat layer is battle-tested.

## 3. Data Flow

```
HTTP GET index.php?page=ventas_articulo&ref=A001
              │
              ▼
  find_controller('ventas_articulo')
   -> plugins/catalogo_core/controller/ventas_articulo.php   (wrapper)
              │ extends Controller\VentasArticulo
              ▼
  Controller\VentasArticulo::privateCore()  -> PageController parent
              │
              ├─ $articulo = new articulo()                   [legacy global]
              │       │
              │       ▼
              │   spl_autoload_register (Init.php):
              │     'FSFramework\model\articulo'
              │       -> plugins/catalogo_core/model/core/articulo.php
              │       -> class_alias 'articulo' <- 'FSFramework\model\articulo'
              │       (same FQCN instance; CDM-08)
              │
              ├─ twig render View/ventas_articulo.html.twig   [auto-escape]
              │
              ▼
  HTTP 200 + HTML
```

## 4. File Changes

| File | Action | PR | Description |
|------|--------|----|-------------|
| `plugins/facturacion_base/model/{articulo,familia,fabricante,impuesto}.php` | Delete | PR-A (facturacion_base) | Remove 30-line stubs |
| `plugins/facturacion_base/model/core/{13 names}.php` | `git mv` → `plugins/catalogo_core/model/core/` | PR-A → PR1 | 13 adjacent models |
| `plugins/facturacion_base/model/table/{13 names}.xml` | `git mv` → `plugins/catalogo_core/model/table/` | PR-A → PR1 | 13 schemas |
| `plugins/catalogo_core/Init.php` | Create | PR1 | `spl_autoload_register` + require `compat/class_aliases.php` |
| `plugins/catalogo_core/compat/class_aliases.php` | Create | PR1 | 4 `class_alias` with `@deprecated` |
| `plugins/catalogo_core/tests/CompatAliasesTest.php` | Create | PR1 | 4 class-identity assertions |
| `plugins/catalogo_core/tests/InitAutoloadTest.php` | Create | PR1 | 13 autoload resolutions |
| `plugins/catalogo_core/Controller/{VentasAlmacenes,VentasPais,VentasDivisa,...}.php` | Create | PR2..N | 1 modern PSR-4 per PR |
| `plugins/catalogo_core/controller/<lowercase>.php` | Create | PR2..N | 1 thin wrapper per PR |
| `plugins/catalogo_core/View/<lowercase>.html.twig` | Create | PR2..N | 1 Twig port per PR |

## 5. Interfaces / Contracts

### A. `Init.php` autoload registration

```php
namespace FSFramework\Plugins\catalogo_core;

final class Init
{
    public function init(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'FSFramework\\model\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $name = substr($class, strlen($prefix));
            $file = FS_FOLDER . '/plugins/catalogo_core/model/core/' . $name . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }, true, true);

        require_once __DIR__ . '/compat/class_aliases.php';
    }
}
```

### B. `compat/class_aliases.php`

```php
<?php
// Centralized compat layer for legacy global names. Each alias preserves
// get_class() identity with the FSFramework\model\* FQCN (CDM-08).
if (!class_exists('articulo', false))   { class_alias('FSFramework\\model\\articulo',   'articulo');   }
if (!class_exists('familia', false))    { class_alias('FSFramework\\model\\familia',    'familia');    }
if (!class_exists('fabricante', false)) { class_alias('FSFramework\\model\\fabricante', 'fabricante'); }
if (!class_exists('impuesto', false))   { class_alias('FSFramework\\model\\impuesto',   'impuesto');   }
```

## 6. Testing Strategy

| Layer | What to Test | Approach |
|-------|--------------|----------|
| Unit | 7 core entities construct | Extend `ArticuloModelEncodingTest` per entity; `phpunit Plugins` |
| Unit | 13 adjacent models construct | New per-model tests in `plugins/catalogo_core/tests/<Model>/` |
| Unit | `class_aliases.php` identity | `CompatAliasesTest` — `get_class(new articulo()) === 'FSFramework\model\articulo'` for all 4 |
| Unit | `Init.php` autoload | `InitAutoloadTest` — instantiate each of 13 `FSFramework\model\*` names |
| Integration | Plugin suite green | `ddev exec php vendor/bin/phpunit --testsuite Plugins` after each PR |
| Smoke | `tpvmod` URLs resolve | `index.php?page=ventas_articulo&ref=<existing>` returns 200 |
| E2E | none | `openspec/config.yaml` sets `e2e: false` |

## 7. Migration / Rollout

1. **PR-A in facturacion_base**: delete 4 stubs + 13 PHP + 13 XML + 10 controllers + 16 views. Land and tag a release.
2. **PR1 in panel-ab**: `fs_plugin_manager` update; `git mv` confirms new locations. Create `Init.php` + `compat/class_aliases.php` + 2 test files. **No** model content changes. `phpunit Plugins` green.
3. **PR2..N in panel-ab**: one entity per PR (vertical: modern controller + Twig + wrapper + tests), gated on PR1.
4. **Rollback**: `git revert` PR1 + re-apply facturacion_base PR-A. The `class_alias` shim is the rollback anchor — even reverted, restored legacy stubs re-resolve the class names.

## 8. Open Questions

None — all product and technical ambiguities resolved by preflight decisions A2/B1/C3/D1/E3 and the locked 600-line budget.
