---
name: fsframework-core-release
description: >-
  Sube la versión del core FSFramework (OidcProvider), crea tag vX.Y.Z y
  actualiza el repositorio remoto. Usar cuando el usuario pida release, bump de
  VERSION, nuevo tag del núcleo, actualizar core tras cambios, o desplegar vía
  system_updater tras modificar src/, base/ o tests/ del core.
---

# FSFramework Core Release

Repositorio: `/home/javier/proyectos/one-login/OidcProvider` (git remoto: `facturascripts/fs-framework-modern`).

El panel **system_updater** compara la versión local con el **GitHub Release** del tag `vX.Y.Z`. Sin tag con prefijo `v`, no hay release automático.

## Archivo de versión

| Archivo | Formato | Ejemplo |
|---------|---------|---------|
| `VERSION` | Semver sin prefijo `v`, una línea | `0.13.5` |

## Qué va en el core (este repo)

- `src/`, `base/`, `model/`, `index.php`, `tests/`, `VERSION`
- Plugins bajo `plugins/*` están en **gitignore** (repos separados); no forman parte del tag del core salvo plugins core embebidos (`clientes_core`, `legacy_support`, etc.)

## Workflow estándar (patch release)

Ejecutar desde `OidcProvider/`:

```bash
# 1. Revisar cambios pendientes del core
git status
git diff --stat

# 2. Decidir versión (patch por defecto: 0.13.4 → 0.13.5)
#    minor si hay features breaking-compat, major solo con acuerdo explícito

# 3. Escribir VERSION (sin prefijo v)
echo '0.13.5' > VERSION

# 4. Commit de release
git add VERSION <archivos-del-core>
git commit -m "$(cat <<'EOF'
chore(release): bump core VERSION to 0.13.5

EOF
)"

# 5. Tag anotado (SIEMPRE con prefijo v — dispara release-on-tag.yml)
git tag -a v0.13.5 -m "Release v0.13.5"

# 6. Publicar
git push origin master
git push origin v0.13.5
```

## Reglas

1. **Tag con `v`**: el workflow `.github/workflows/release-on-tag.yml` solo escucha `v*`. No usar tags sin `v` (ej. `0.13.4` no crea release).
2. **VERSION y tag alineados**: tag `v0.13.5` ↔ contenido de `VERSION` = `0.13.5`.
3. **No commitear plugins ignorados**: `plugins/OidcProvider/` tiene repo propio; su release es independiente.
4. **Tras push del tag**: GitHub Actions crea el Release; `system_updater` podrá detectar la nueva versión.
5. **Solo commitear cuando el usuario lo pida** o esté en un flujo explícito de release como este.

## Bump automático (milestones GSD)

Para cierre de milestone con varios plugins core, usar también:

```bash
./scripts/gsd-bump-release-version.sh --milestone v0.13.5
```

Ver skill hermana: `fsframework-milestone-release`.

## Verificación

```bash
cat VERSION
git tag -l 'v0.13.*' | tail -3
git log -1 --oneline
gh release list --limit 3   # tras push del tag
```

## Cuándo proponer release al usuario

Tras cambios en `src/`, `base/` o tests del core que vayan a producción vía `system_updater`, recordar:

> ¿Subimos VERSION y creamos tag `vX.Y.Z` para que el updater lo detecte?
