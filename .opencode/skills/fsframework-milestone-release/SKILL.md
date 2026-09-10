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
| `plugins/{plugin}/releases.json` | Plugin ya adaptado al historial de versiones (ver abajo) | Append de la entrada del release con `version` + `min_version`/`max_version` congelados |

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
3. **Incremento**: depende del formato actual de la versión — si es semver `MAJOR.MINOR.PATCH` se aplica patch (`1.0.0` → `1.0.1`), si es `MAJOR.MINOR` se aplica minor (`1.0` → `1.1`), y si es entero se incrementa (`1` → `2`)
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

## Metadata de compatibilidad por release (historial de versiones)

> Mecanismo especificado en el change SDD `plugin-compatible-update-resolver`
> (vive en `plugins/system_updater/openspec/`). El `fsframework.ini` de cada tag
> sigue siendo la fuente de verdad de `version`, `min_version` y `max_version`.

Aplica **solo a plugins ya adaptados** al historial. Al publicar un release de un
plugin adaptado, además del bump de `version` en `fsframework.ini`, agregar
(append) una entrada al historial del plugin:

- `releases.json` del plugin, o alternativamente `versions[]` en la entrada del
  catálogo.
- Cada entrada: `version`, `min_version`, `max_version` (congelados del
  `fsframework.ini` de ese mismo tag) y una referencia de descarga (`zip_url` o
  id de catálogo).

Reglas:

- **Append-only e inmutable**: una entrada publicada no se reescribe; los límites
  por release no cambian retroactivamente.
- Habilita que el actualizador resuelva "la última versión compatible" cuando la
  punta de rama ya no es compatible con el core en ejecución.

### Retrocompatibilidad (obligatoria)

- La adaptación es **por plugin y opcional**. Un plugin sin `releases.json` sigue
  usando el mecanismo antiguo (punta de rama + `fsframework.ini` de la rama),
  indefinidamente.
- El historial es **aditivo**: los `system_updater` viejos lo ignoran y siguen
  operando con la punta de rama. Publicarlo **no rompe nada**.
- **No eliminar** el camino de punta de rama / ini único mientras existan plugins
  sin adaptar.
- El resolver **nunca** propone una versión igual o inferior a la instalada (sin
  auto-downgrade).

## Verificación

```bash
cat VERSION
grep '^version' plugins/legacy_support/fsframework.ini
grep 'Version:' .planning/PROJECT.md
```
