# Phase 12: Plugin Composer Setup - Context

**Gathered:** 2026-05-23
**Status:** Ready for planning

<domain>
## Phase Boundary

`api_base` obtiene autonomía de dependencias Composer: manifest propio, `vendor/` aislado, autoload cargado al registrar servicios, e instrucciones de instalación documentadas. El core **no** elimina paquetes en esta fase (Phase 13); aquí solo se prepara y valida el plugin.

**Requirements in scope:** DEPS-01, DEPS-02, DEPS-04

</domain>

<decisions>
## Implementation Decisions

### Vendor strategy
- **D-01:** Vendor aislado en `plugins/api_base/vendor/` — `composer install` se ejecuta dentro del plugin (repo git separado).
- **D-02:** Convivencia de autoload: solo `require_once` del autoload del plugin; el vendor del core no se modifica ni se hace prepend.
- **D-03:** Versionar `composer.lock` en el repo de `api_base`; `vendor/` sigue ignorado (`.gitignore` actual).
- **D-04:** Fail-fast si falta vendor: `error_log` claro; rutas OpenAPI/docs degradan sin stack trace en producción (alineado con patrón existente de `SwaggerGenerator::swaggerPhpAvailable`).

### Package scope
- **D-05:** `require` del plugin: **solo** `zircote/swagger-php` (^6.0, alineado con major del core hasta Phase 13).
- **D-06:** El paquete se elimina del `composer.json` del core en **Phase 13**, no en Phase 12. El plugin debe seguir siendo compatible con contratos del core (`src/Api/Attribute/*`, excepciones).
- **D-07:** PSR-4 explícito en el composer del plugin: `FSFramework\Plugins\api_base\` → `Api/` (autonomía del repo; el mapeo raíz `FSFramework\Plugins\` puede coexistir).
- **D-08:** `firebase/php-jwt` **no** entra en `api_base` — limpieza del core en Phase 13; tokens API siguen opacos (SHA-256 en BD).

### Autoload hook
- **D-09:** Cargar autoload en `config/services.php` (closure de registro DI), **no** en `Init.php`.
- **D-10:** Implementación: guard `file_exists` / `is_file` antes de `require_once`; si falta, `error_log` fail-fast.
- **D-11:** Orden en `services.php`: (1) cargar vendor autoload → (2) `api_base_load_legacy_models()` → resto de registros.
- **D-12:** Documentación de instalación **solo** en `plugins/api_base/AGENTS.md`:
  ```bash
  ddev exec composer install --working-dir=plugins/api_base
  ```

### Claude's Discretion
- Versión patch exacta de `swagger-php` al generar el lock inicial (mantener ^6.0 en require).
- Si conviene classmap adicional para clases legacy sin namespace (`model/`, `controller/`) además del PSR-4 de `Api/`.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Milestone & requirements
- `.planning/ROADMAP.md` — Phase 12 goal, success criteria, requirement mapping
- `.planning/REQUIREMENTS.md` — DEPS-01, DEPS-02, DEPS-04 (Phase 12 scope)
- `.planning/PROJECT.md` — Core stays thin; plugin extends

### Plugin architecture
- `plugins/api_base/AGENTS.md` — Runtime layout, URL map, test command (update install section)
- `plugins/api_base/config/services.php` — DI registration hook for autoload + legacy models
- `plugins/api_base/Init.php` — Plugin init (settings seeding only; **not** autoload hook)
- `plugins/api_base/model/swagger/SwaggerGenerator.php` — `class_exists('\\OpenApi\\Generator')` graceful check
- `plugins/api_base/.gitignore` — `/vendor/`, `composer.lock` (lock will be committed per D-03 — update gitignore)
- `plugins/api_base/.planning/codebase/STACK.md` — Current stack analysis (2026-05-23)

### Core contracts (do not break)
- `src/Api/Attribute/ApiResource.php`, `ApiField.php`, `Operation.php` — Consumer plugin annotations
- `src/Api/Exception/*.php` — Shared exception types used by plugin runtime
- `api.php` — Thin bootstrap; must keep working when plugin active + vendor installed

### Parent composer (reference only — removal in Phase 13)
- `composer.json` (root) — Currently lists `zircote/swagger-php`; remove in Phase 13 after plugin vendor verified

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `SwaggerGenerator`: already detects OpenAPI availability via `class_exists`; fail-fast logging complements existing degrade path.
- `config/services.php`: closure already calls `api_base_ensure_tables()` and `api_base_load_legacy_models()` — autoload slots before legacy models per D-11.
- Root PSR-4 `"FSFramework\\Plugins\\": "plugins/"` — plugin code autoloaded today without plugin composer; plugin PSR-4 adds explicit repo autonomy.

### Established Patterns
- Plugin as separate git repo with own `.gitignore` for vendor.
- Symfony DI via `config/services.php` per plugin (`api.runtime` service).
- DDEV for all Composer commands (`ddev exec composer ...`).

### Integration Points
- `api.php` → `Container::get('api.runtime')` — vendor must load when services.php runs during container build, before first API request.
- OpenAPI route in `ApiRuntime` → `SwaggerGenerator` needs `OpenApi\Generator` from plugin vendor after migration.

</code_context>

<specifics>
## Specific Ideas

- Usuario confirmó: swagger-php solo en plugin; en core "se puede eliminar" tras mantener compatibilidad con elementos del core (Phase 13).
- Instalación documentada únicamente en AGENTS.md del plugin, sin Makefile ni nota en AGENTS.md raíz en esta fase.

</specifics>

<deferred>
## Deferred Ideas

- **Eliminar `zircote/swagger-php` del core** — Phase 13 (DEPS-03)
- **`firebase/php-jwt` removal from core** — Phase 13; not added to api_base
- **Composer merge-plugin at root** — v2 API-04 in REQUIREMENTS.md
- **Makefile target `api-base-install`** — user declined for Phase 12
- **Documentación en AGENTS.md raíz** — user chose plugin AGENTS.md only

</deferred>

---
*Phase: 12-Plugin Composer Setup*
*Context gathered: 2026-05-23*
