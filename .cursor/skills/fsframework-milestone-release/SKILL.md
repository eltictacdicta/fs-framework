---
name: fsframework-milestone-release
description: >-
  Extiende /gsd-complete-milestone para FSFramework: actualiza VERSION del core y
  fsframework.ini de plugins afectados tras cerrar un milestone. Usar siempre junto
  con gsd-complete-milestone o cuando el usuario pida subir versiones de release.
---

# FSFramework Milestone Release

Extensión de proyecto para el cierre de milestones GSD. Se ejecuta **después** de archivar el milestone y **antes** del git tag.

Al hacer `git push origin vX.Y.Z`, el workflow `.github/workflows/release-on-tag.yml` crea automáticamente un GitHub Release. El panel admin compara la versión local con ese release (no con `master/VERSION`).

## Cuándo usar

- Al ejecutar `/gsd-complete-milestone vX.Y.Z`
- Cuando el usuario pida bump de `VERSION` o versiones de plugins tras un milestone
- Tras `/gsd-audit-milestone` cuando el cierre está aprobado

## Qué actualiza

| Archivo | Cuándo | Valor |
|---------|--------|-------|
| `VERSION` | Siempre | Versión del milestone sin prefijo `v` (ej. `0.12.0`) |
| `plugins/{plugin}/fsframework.ini` | Plugin con cambios en el milestone | Incremento patch del campo `version` |

Plugins versionados en el repo (core): `business_data`, `catalogo_core`, `clientes_core`, `clientes_catalogo`, `clientes_facturacion`, `legacy_support`, `facturascripts_support`.

## Workflow (insertar en complete-milestone)

Ejecutar entre `archive_milestone` y `git_tag`:

```
Release bump:
- [ ] 1. Determinar versión del milestone (ej. v0.12.0)
- [ ] 2. Ejecutar script de bump (dry-run primero si hay duda)
- [ ] 3. Revisar plugins detectados vs cambios reales del milestone
- [ ] 4. Ajustar con --plugins si hace falta override manual
- [ ] 5. Actualizar PROJECT.md → Context → Version (si no quedó alineado)
- [ ] 6. Incluir VERSION y fsframework.ini en el commit de cierre
```

## Comando

```bash
# Detección automática de plugins tocados desde el tag anterior
./scripts/gsd-bump-release-version.sh --milestone v0.12.0

# Especificar rango git explícito
./scripts/gsd-bump-release-version.sh --milestone v0.12.0 --from-tag v0.11.0

# Solo plugins concretos (cuando el cambio principal está en uno)
./scripts/gsd-bump-release-version.sh --milestone v0.12.0 --plugins legacy_support

# Vista previa sin escribir
./scripts/gsd-bump-release-version.sh --milestone v0.12.0 --dry-run
```

## Configuración (`.planning/config.json`)

```json
"release": {
  "core_version_file": "VERSION",
  "auto_detect_plugins": true,
  "plugins": []
}
```

- `auto_detect_plugins`: detecta plugins con cambios en `git diff` del milestone
- `plugins`: lista fija de plugins a bump además de la auto-detección (ej. `["legacy_support"]`)

## Reglas de bump de plugins

1. **Auto-detect**: archivos bajo `plugins/{nombre}/` cambiados entre el tag anterior y `HEAD`
2. **Override manual**: `--plugins plugin1,plugin2` cuando el milestone es principalmente de un plugin
3. **Incremento**: semver patch (`1.0` → `1.0.1`), entero (`1` → `2`)
4. **No bump** si el plugin no tiene `fsframework.ini` o no hubo cambios relevantes

## Ejemplo: milestone centrado en legacy_support

Milestone v0.10.8 delegó SHA1/MD5 a `legacy_support`:

```bash
./scripts/gsd-bump-release-version.sh --milestone v0.10.8 --plugins legacy_support
```

Resultado esperado:
- `VERSION` → `0.10.8`
- `plugins/legacy_support/fsframework.ini` → `version = 1.1` (desde `1.0`)

## Integración con commits GSD

Incluir en el safety commit de complete-milestone o en commit dedicado:

```bash
./scripts/gsd-bump-release-version.sh --milestone v0.12.0
git add VERSION plugins/*/fsframework.ini
# junto con .planning/* en el commit de archive
```

Mensaje sugerido: `chore(release): bump VERSION to X.Y.Z [+ plugins]`

## Verificación

```bash
cat VERSION
grep '^version' plugins/legacy_support/fsframework.ini
grep 'Version:' .planning/PROJECT.md
```
