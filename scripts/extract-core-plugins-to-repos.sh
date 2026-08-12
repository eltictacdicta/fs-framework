#!/usr/bin/env bash
# Extrae los plugins core embebidos a repositorios independientes (patrón api_base).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ORG="${GITHUB_ORG:-eltictacdicta}"
PLUGINS=(
  business_data
  catalogo_core
  legacy_support
  facturascripts_support
  clientes_core
  clientes_catalogo
  clientes_facturacion
)

PLUGIN_GITIGNORE='# vendor/ se versiona a propósito (política FSFramework): cada plugin/el núcleo
# commitea su vendor para despliegues/actualizaciones sin composer install.

# Scratch de planificación interna del desarrollo — no se publica.
/.planning/
'

ensure_plugin_gitignore() {
  local plugin_dir="$1"
  local gitignore="${plugin_dir}/.gitignore"
  if [[ ! -f "${gitignore}" ]]; then
    printf '%s\n' "${PLUGIN_GITIGNORE}" > "${gitignore}"
    return
  fi
  if ! grep -q '/.planning/' "${gitignore}"; then
    printf '\n# Scratch de planificación interna del desarrollo — no se publica.\n/.planning/\n' >> "${gitignore}"
  fi
}

init_plugin_repo() {
  local name="$1"
  local plugin_dir="${ROOT}/plugins/${name}"
  local split_branch="split/${name}"

  echo "==> Extrayendo ${name} con historial (subtree split)..."

  if ! git -C "${ROOT}" rev-parse --verify "${split_branch}" >/dev/null 2>&1; then
    git -C "${ROOT}" subtree split --prefix="plugins/${name}" -b "${split_branch}"
  else
    echo "    Rama ${split_branch} ya existe, reutilizando."
  fi

  if [[ -d "${plugin_dir}/.git" ]]; then
    echo "    Repo git ya inicializado en plugins/${name}"
    return
  fi

  local tmp_dir
  tmp_dir="$(mktemp -d)"
  trap 'rm -rf "${tmp_dir}"' RETURN

  git clone --branch "${split_branch}" "${ROOT}" "${tmp_dir}/${name}"

  ensure_plugin_gitignore "${tmp_dir}/${name}"

  if [[ -n "$(git -C "${tmp_dir}/${name}" status --porcelain)" ]]; then
    git -C "${tmp_dir}/${name}" add .gitignore
    git -C "${tmp_dir}/${name}" commit -m "chore: add plugin .gitignore"
  fi

  mv "${tmp_dir}/${name}/.git" "${plugin_dir}/.git"
  rm -rf "${tmp_dir}"
  trap - RETURN

  git -C "${plugin_dir}" remote remove origin 2>/dev/null || true
  git -C "${plugin_dir}" branch -M main 2>/dev/null || git -C "${plugin_dir}" checkout -b main

  echo "    Repo local listo en plugins/${name}"
}

create_github_repo_and_push() {
  local name="$1"
  local plugin_dir="${ROOT}/plugins/${name}"
  local remote="https://github.com/${ORG}/${name}.git"

  if git -C "${plugin_dir}" remote get-url origin >/dev/null 2>&1; then
    echo "==> ${name}: remote origin ya configurado"
  else
    echo "==> Creando repo público ${ORG}/${name}..."
    gh repo create "${ORG}/${name}" \
      --public \
      --source "${plugin_dir}" \
      --remote origin \
      --push \
      --description "FSFramework plugin: ${name}"
    return
  fi

  echo "==> Publicando ${name}..."
  git -C "${plugin_dir}" push -u origin main
}

main() {
  cd "${ROOT}"

  if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "Error: ejecutar desde el repositorio fs-framework." >&2
    exit 1
  fi

  local phase="${1:-all}"

  case "${phase}" in
    init)
      for name in "${PLUGINS[@]}"; do
        init_plugin_repo "${name}"
      done
      ;;
    publish)
      if ! gh auth status >/dev/null 2>&1; then
        echo "Error: gh no autenticado. Ejecuta: gh auth login" >&2
        exit 1
      fi
      for name in "${PLUGINS[@]}"; do
        create_github_repo_and_push "${name}"
      done
      ;;
    all)
      for name in "${PLUGINS[@]}"; do
        init_plugin_repo "${name}"
      done
      if gh auth status >/dev/null 2>&1; then
        for name in "${PLUGINS[@]}"; do
          create_github_repo_and_push "${name}"
        done
      else
        echo ""
        echo "Repos locales creados. Para publicar en GitHub:"
        echo "  gh auth login"
        echo "  $0 publish"
      fi
      ;;
    *)
      echo "Uso: $0 [init|publish|all]" >&2
      exit 1
      ;;
  esac
}

main "$@"
