---
name: fsframework-plugin-release
description: >-
  Sube la versión de un plugin FSFramework en fsframework.ini, crea el tag
  vX.Y.Z y publica su repositorio para que el system_updater lo detecte. Usar
  cuando se actualice o versione un plugin, se cree un tag, se mergee una
  feature branch de un plugin, o haya que dejarlo listo para actualizaciones.
---

# FSFramework Plugin Release

Cada plugin vive en su **propio repositorio** (`https://github.com/eltictacdicta/<plugin>.git`, gitignored desde el core) y se versiona de forma independiente.

## Cómo detecta el updater una actualización de plugin

El **system_updater** NO compara tags: compara la **`version` del `fsframework.ini` remoto** con la local.

1. El catálogo remoto `eltictacdicta/fs-cusmtom-plugins@main/custom_plugins.json` lista cada plugin (`nombre`, `link`, `zip_link`; opcional `version`).
2. `plugin_downloader` lee el `fsframework.ini` remoto del repo y toma `version`.
3. `plugin_compatibility_checker` compara esa versión con la del `fsframework.ini` instalado.

Por eso, para habilitar una actualización alcanza con **subir la `version` en `fsframework.ini` y pushear** al branch que el catálogo referencia (normalmente `main`). El tag `vX.Y.Z` es trazabilidad/GitHub: el updater no lo lee.

Opcional: un `releases.json` en la raíz del repo (historial de versiones) lo lee el downloader de forma best-effort.

## Reglas de versión

- Semver **sin** prefijo `v` en `fsframework.ini` (ej. `1.2.0`).
- **Patch** para fixes internos (`1.1.0` → `1.1.1`).
- **Minor** para features nuevas (`1.1.0` → `1.2.0`).
- Consistencia: tag `vX.Y.Z` ↔ `version = X.Y.Z`.

## Workflow

```bash
cd plugins/<PluginName>

# 1. Árbol limpio y en el branch que el catálogo referencia (main)
git status
git branch --show-current

# 2. Bump de versión en fsframework.ini (semver sin v)
#    Editar: version = X.Y.Z

# 3. Commit (código + versión)
git add -A
git commit -m "fix(<área>): <qué> (vX.Y.Z)"   # o feat(...) para minor

# 4. Tag anotado CON prefijo v
git tag -a vX.Y.Z -m "Release vX.Y.Z"

# 5. Publicar branch + tag
git push origin HEAD
git push origin vX.Y.Z
```

## Reglas

1. **Repo propio**: nunca commitear cambios de un plugin desde el repo del core (los plugins están gitignored).
2. **Branch**: pushear al branch que el catálogo referencia (`main`). Si el trabajo está en una feature branch, **mergear a `main`** (fast-forward si es posible) ANTES de taggear; un tag en una feature branch sin mergear no es un release válido.
3. **`vendor/` se commitea**: los plugins versionan su `vendor/` junto con `composer.json`/`composer.lock` (ver AGENTS.md).
4. **`version` y tag alineados** y acordes a la magnitud (patch vs minor).
5. **Catálogo**: si el plugin es nuevo o cambió de repo/`zip_link`, actualizar `custom_plugins.json` en `eltictacdicta/fs-cusmtom-plugins` (repo separado).

## Verificación

```bash
cd plugins/<PluginName>
grep '^version' fsframework.ini
git tag -l 'v*' | tail -3
git log --oneline -1
git status --short   # debe estar limpio
```

## Casos comunes

- **Feature branch sin mergear** → release inválido si se taggea ahí:
  ```bash
  git checkout main
  git merge --ff-only <feature>     # o merge normal + resolver
  git push origin main
  # recién ahora: bump de versión + tag vX.Y.Z
  ```
- **Tag prematuro ya pusheado** → borrar y recrear tras mergear:
  ```bash
  git tag -d vX.Y.Z
  git push origin :refs/tags/vX.Y.Z
  ```
