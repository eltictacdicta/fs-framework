---
name: fsframework-plugin-sdd
description: >-
  Trigger: plugin SDD, plugin OpenSpec, plugin change, plugin archive, plugin
  proposal, plugin verify. Manage the SDD lifecycle (proposal/spec/tasks/apply/
  verify/archive) for plugin-internal changes. Plugin SDDs live entirely in
  plugins/{name}/openspec/ and never in core openspec/.
license: Apache-2.0
metadata:
  author: "javier-trujillo"
  version: "1.0"
---

# FSFramework Plugin SDD

> **Hard rule**: el código del core no debe contener restos de los plugins.
> Cada plugin es dueño de su propio ciclo de vida SDD. Documentado en
> `AGENTS.md → "OpenSpec per Plugin (SDD ownership)"`.

## Routing Decision: Core vs Plugin

Antes de crear cualquier artefacto SDD, clasificá el change:

| Si el change... | Ubicación del SDD | Ejemplos |
|---|---|---|
| Solo toca `plugins/{name}/` | `plugins/{name}/openspec/` (este skill) | Nuevo modelo, fix de import, refactor interno |
| Toca `base/`, `src/`, `controller/`, `model/` raíz, o convenciones que cruzan plugins | `openspec/` (core; usar SDDs genéricos) | Migración entre plugins core, fix de CSRF del framework, refactor del container |
| Híbrido: plugin + core | **Default al plugin** si el plugin es el beneficiario principal. Si el toque a core es la pieza grande, abrir en core y referenciar al plugin. | Plugin nuevo que registra un servicio en el container |

**Regla rápida**: si el change **NO** modifica archivos fuera de `plugins/{name}/`, todo el SDD vive en el plugin. El core ni se entera.

**Anti-patrón**: ~~crear `openspec/changes/{name}/` en el core para trackear un change que solo toca el plugin~~. Borralo, es un resto.

## Canonical Paths (Plugin SDD)

```
plugins/{name}/openspec/
├── config.yaml                # ownership: plugin-local; change_root; archive_root
├── specs/                     # source of truth de specs del plugin
│   └── {domain}/spec.md
└── changes/
    ├── {name}/                # cambios activos
    │   ├── specs/{domain}/spec.md   (delta)
    │   ├── tasks.md
    │   └── verify-report.md
    └── archive/               # cambios cerrados
        └── YYYY-MM-DD-{name}/
            ├── specs/...
            ├── tasks.md
            ├── verify-report.md
            └── archive-report.md
```

**Date prefix en archive**: ISO `YYYY-MM-DD` (ej. `2026-06-19-unificar-...`).

## Config por Plugin

Cada plugin con SDD propio declara en `plugins/{name}/openspec/config.yaml`:

```yaml
ownership: plugin-local
change_root: plugins/{name}/openspec/changes/{name}/
archive_root: plugins/{name}/openspec/changes/archive/{YYYY-MM-DD}-{name}/
```

Ver `plugins/tarifario/openspec/config.yaml` como referencia canónica.

## Dispatcher Limitation

`gentle-ai sdd-status` actualmente **solo conoce el `openspec/` raíz**.
Para plugin SDDs:
- `gentle-ai sdd-status {name} --cwd <repo>` reportará falsos "blocked" porque
  no encuentra los artefactos en el parent.
- **No** intentes crear entradas en el parent para "engañar" al dispatcher.
  Es el anti-patrón explícito del proyecto.
- Pasale al `sdd-archive` sub-agent el path completo del plugin como contexto
  (no puede descubrir automáticamente `plugins/{name}/openspec/`).

## Archive Workflow (Plugin SDD)

```
Archive Plugin SDD:
- [ ] 1. Verify all artifacts present (specs/, tasks.md, verify-report.md)
- [ ] 2. Verify verify-report has no CRITICAL issues
- [ ] 3. Verify no unchecked implementation tasks in tasks.md
- [ ] 4. Run round-trip / smoke test if applicable
- [ ] 4a. If the change adds a Composer dependency: verify `plugins/{name}/vendor/` is committed (see "Dependency Commits in Plugin SDDs" below)
- [ ] 5. Create archive dir: plugins/{name}/openspec/changes/archive/
- [ ] 6. Move change dir to: plugins/{name}/openspec/changes/archive/YYYY-MM-DD-{name}/
- [ ] 7. Create archive-report.md (mirror verify-report format + closing summary)
- [ ] 8. Update spec source of truth if delta added/removed/renamed requirements
- [ ] 9. Verify NO entries in core openspec/ for this change name
```

## Dependency Commits in Plugin SDDs

FSFramework plugins **MUST** version their `vendor/` directory together
with `composer.json` and `composer.lock` — the plugin loader does NOT run
`composer install` at boot, so a fresh clone without `vendor/` will not
boot. See `AGENTS.md` → "Plugin Composer Dependencies" for the full rule.

**When a plugin SDD adds or upgrades a Composer dependency**, the
`tasks.md` MUST include the step to commit `vendor/`. Before archiving
the SDD, verify:

- `git ls-files plugins/{name}/vendor/` returns a non-empty list.
- The `composer.lock` version of the new/updated package matches the
  version actually present in `plugins/{name}/vendor/{vendor}/{pkg}/`.
- `/vendor/` is **not** in `plugins/{name}/.gitignore` (the only
  Composer-related line that stays ignored is `/composer.phar`).

**Anti-patrón**: ~~dejar `vendor/` gitignored y delegar al operador que
corra `composer install` en producción~~. El plugin se entrega completo.

**Sync de delta specs**: si el delta spec tiene `ADDED Requirements`,
`MODIFIED Requirements` o `REMOVED Requirements`, mergearlos en
`plugins/{name}/openspec/specs/{domain}/spec.md` ANTES de mover a archive.
Si la main spec ya está actualizada (caso normal: el spec canónico se
escribió completo desde el principio), no hay merge que hacer.

## Output Contract

Después de cerrar un plugin SDD, el agente reporta:
- Path del archive (ej. `plugins/{name}/openspec/changes/archive/2026-06-19-{name}/`)
- Confirmación de que el core `openspec/changes/{name}/` NO tiene entry
- Lista de artefactos archivados
- Si hubo post-verify fixes, mencionarlos en archive-report.md

## References

- `AGENTS.md` → "OpenSpec per Plugin (SDD ownership)" — regla canónica del proyecto.
- `plugins/tarifario/openspec/config.yaml` — ejemplo de config completo.
- `plugins/tarifario/openspec/changes/archive/2026-06-19-unificar-exportador-importador-excel-sap/` — ejemplo de plugin SDD archivado correctamente.
