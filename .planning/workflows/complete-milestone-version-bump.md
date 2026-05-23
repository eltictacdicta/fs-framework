# Extensión FSFramework — bump de versión al cerrar milestone

> **Proyecto:** panel-ab / FSFramework  
> **Skill:** `.cursor/skills/fsframework-milestone-release/SKILL.md`  
> **Script:** `scripts/gsd-bump-release-version.sh`

Este documento extiende el workflow global `complete-milestone.md` con pasos específicos de versionado del framework.

## Punto de inserción

Ejecutar **después** de `archive_milestone` / `reorganize_roadmap_and_delete_originals` y **antes** de `git_tag`.

## Paso: bump_release_versions

### 1. Resolver versión

Usar la versión del milestone que se está cerrando (ej. `v0.12.0`).

### 2. Dry-run (recomendado en modo interactive)

```bash
./scripts/gsd-bump-release-version.sh --milestone v[X.Y.Z] --dry-run
```

Revisar:
- `VERSION` target correcto
- Plugins detectados coinciden con el alcance del milestone

### 3. Aplicar bump

```bash
./scripts/gsd-bump-release-version.sh --milestone v[X.Y.Z]
```

Si el milestone fue **principalmente un plugin** (ej. extracción a `legacy_support`):

```bash
./scripts/gsd-bump-release-version.sh --milestone v[X.Y.Z] --plugins legacy_support
```

### 4. Sincronizar PROJECT.md

Actualizar la línea de contexto:

```markdown
- **Version:** X.Y.Z (see `VERSION`)
```

### 5. Incluir en commit de cierre

Añadir al safety commit o commit de release:

```bash
git add VERSION plugins/*/fsframework.ini .planning/PROJECT.md
```

## Criterios de éxito

- [ ] `VERSION` coincide con la versión del milestone (sin `v`)
- [ ] Plugins con cambios principales tienen `fsframework.ini` incrementado
- [ ] `PROJECT.md` Context refleja la nueva versión
- [ ] Archivos incluidos en el commit de milestone/tag

## Configuración

Ver `.planning/config.json` → sección `release`.
