#!/usr/bin/env bash
#
# Descarga via git los plugins publicos del catalogo FSFramework que aun no
# estan presentes en plugins/.
#
# La fuente de verdad es el catalogo publico en
# plugins/system_updater/data/custom_plugins.json (campos: nombre, link, branch).
# Cada plugin se clona como repositorio git independiente en plugins/{nombre},
# listo para trabajar y subir cambios a su propio remoto.
#
# Uso:
#   scripts/git-clone-plugins.sh
#   scripts/git-clone-plugins.sh --dry-run
#   scripts/git-clone-plugins.sh --plugin catalogo_core --plugin tpvmod
#   scripts/git-clone-plugins.sh --force   (reemplaza directorios existentes sin .git)
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CUSTOM_PLUGINS_JSON="${ROOT}/plugins/system_updater/data/custom_plugins.json"

DRY_RUN=false
FORCE=false
SELECTED_PLUGINS=()

usage() {
    sed -n '2,17p' "$0" | sed 's/^# \?//'
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --force)
            FORCE=true
            shift
            ;;
        --plugin)
            SELECTED_PLUGINS+=("${2:-}")
            shift 2
            ;;
        -h|--help)
            usage 0
            ;;
        *)
            echo "Opcion desconocida: $1" >&2
            usage 1
            ;;
    esac
done

log() {
    printf '%s\n' "$*"
}

warn() {
    printf 'AVISO: %s\n' "$*" >&2
}

fail_plugin() {
    printf 'ERROR [%s]: %s\n' "$1" "$2" >&2
}

is_selected() {
    local name="$1"
    local selected
    for selected in "${SELECTED_PLUGINS[@]}"; do
        if [[ "${selected}" == "${name}" ]]; then
            return 0
        fi
    done
    return 1
}

# Emite lineas "nombre|url|branch" del catalogo.
# Solo plugins de tipo "gratis" con link a repositorio git.
load_catalog_entries() {
    if [[ ! -f "${CUSTOM_PLUGINS_JSON}" ]]; then
        fail_plugin "catalogo" "no existe ${CUSTOM_PLUGINS_JSON}; instala/activa system_updater."
        return 1
    fi

    if ! command -v python3 >/dev/null 2>&1; then
        fail_plugin "catalogo" "python3 no disponible; es necesario para leer el catalogo."
        return 1
    fi

    python3 - "${CUSTOM_PLUGINS_JSON}" <<'PY'
import json
import sys

path = sys.argv[1]
with open(path, encoding="utf-8") as fh:
    data = json.load(fh)

for item in data:
    name = item.get("nombre")
    link = item.get("link", "")
    branch = item.get("branch") or "main"
    if name and link:
        print(f"{name}|{link}|{branch}")
PY
}

clone_plugin() {
    local name="$1" url="$2" branch="$3"
    local target_dir="${ROOT}/plugins/${name}"

    log ""
    log "==> ${name}"
    log "    repo: ${url}"
    log "    rama: ${branch}"

    if [[ -d "${target_dir}/.git" ]]; then
        log "    ya existe como repositorio git; se omite (usa git-update-all.sh para actualizar)."
        return 2
    fi

    if [[ -d "${target_dir}" ]]; then
        if [[ "${FORCE}" != true ]]; then
            warn "${name}: el directorio existe y no es un repo git. Usa --force para reemplazarlo."
            return 2
        fi
        log "    --force: eliminando directorio existente..."
        if [[ "${DRY_RUN}" == true ]]; then
            log "    [dry-run] rm -rf ${target_dir}"
        else
            rm -rf "${target_dir}"
        fi
    fi

    if [[ "${DRY_RUN}" == true ]]; then
        log "    [dry-run] git clone --branch ${branch} ${url} ${target_dir}"
        log "    OK (simulado)"
        return 0
    fi

    if ! git clone --branch "${branch}" "${url}" "${target_dir}"; then
        fail_plugin "${name}" "clone fallido"
        return 1
    fi

    # Sin config local sobrante: el repo queda limpio para commit/push directo.
    local head
    head="$(git -C "${target_dir}" rev-parse --short HEAD 2>/dev/null || echo "?")"
    log "    OK -> ${head} (${branch})"
    return 0
}

main() {
    cd "${ROOT}"

    if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        echo "Error: ${ROOT} no es un repositorio git." >&2
        exit 1
    fi

    log "Descarga de plugins publicos FSFramework"
    log "Raiz: ${ROOT}"
    log "Catalogo: ${CUSTOM_PLUGINS_JSON}"
    [[ "${DRY_RUN}" == true ]] && log "Modo: dry-run"

    local ok=0 skipped=0 failed=0
    local entry name url branch rc=0

    while IFS= read -r entry; do
        [[ -n "${entry}" ]] || continue
        name="${entry%%|*}"
        entry="${entry#*|}"
        url="${entry%%|*}"
        branch="${entry#*|}"

        if [[ ${#SELECTED_PLUGINS[@]} -gt 0 ]] && ! is_selected "${name}"; then
            continue
        fi

        rc=0
        clone_plugin "${name}" "${url}" "${branch}" || rc=$?
        case "${rc}" in
            0) ok=$((ok + 1)) ;;
            2) skipped=$((skipped + 1)) ;;
            *) failed=$((failed + 1)) ;;
        esac
    done < <(load_catalog_entries | sort)

    if [[ ${#SELECTED_PLUGINS[@]} -gt 0 ]]; then
        local selected
        for selected in "${SELECTED_PLUGINS[@]}"; do
            if ! load_catalog_entries | grep -q "^${selected}|"; then
                warn "${selected}: no figura en el catalogo publico."
                failed=$((failed + 1))
            fi
        done
    fi

    log ""
    log "Resumen: ${ok} descargados, ${skipped} omitidos, ${failed} fallidos"

    if [[ "${failed}" -gt 0 ]]; then
        exit 1
    fi
}

main "$@"
