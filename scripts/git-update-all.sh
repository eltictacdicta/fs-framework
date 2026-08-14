#!/usr/bin/env bash
#
# Actualiza via git el nucleo FSFramework y los plugins instalados con repositorio git.
#
# La logica de actualizacion descubre repositorios locales en plugins/*/.git.
# El catalogo publico en plugins/system_updater/data/custom_plugins.json se usa
# solo como referencia documental (nombres y ramas habituales), no como fuente
# de verdad para decidir que actualizar.
#
# Uso:
#   scripts/git-update-all.sh
#   scripts/git-update-all.sh --dry-run
#   scripts/git-update-all.sh --core-only
#   scripts/git-update-all.sh --plugins-only
#   scripts/git-update-all.sh --plugin catalogo_core --plugin tpvmod
#   scripts/git-update-all.sh --stash
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CUSTOM_PLUGINS_JSON="${ROOT}/plugins/system_updater/data/custom_plugins.json"

DRY_RUN=false
UPDATE_CORE=true
UPDATE_PLUGINS=true
AUTO_STASH=false
FORCE=false
SELECTED_PLUGINS=()

declare -a PUBLIC_PLUGIN_NAMES=()

usage() {
    sed -n '2,18p' "$0" | sed 's/^# \?//'
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --core-only)
            UPDATE_PLUGINS=false
            shift
            ;;
        --plugins-only)
            UPDATE_CORE=false
            shift
            ;;
        --plugin)
            SELECTED_PLUGINS+=("${2:-}")
            shift 2
            ;;
        --stash)
            AUTO_STASH=true
            shift
            ;;
        --force)
            FORCE=true
            shift
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

if [[ ${#SELECTED_PLUGINS[@]} -gt 0 ]]; then
    UPDATE_CORE=false
fi

log() {
    printf '%s\n' "$*"
}

warn() {
    printf 'AVISO: %s\n' "$*" >&2
}

fail_repo() {
    printf 'ERROR [%s]: %s\n' "$1" "$2" >&2
}

is_public_plugin() {
    local name="$1"
    local plugin
    for plugin in "${PUBLIC_PLUGIN_NAMES[@]}"; do
        if [[ "${plugin}" == "${name}" ]]; then
            return 0
        fi
    done
    return 1
}

load_public_plugin_names() {
    PUBLIC_PLUGIN_NAMES=()

    if [[ ! -f "${CUSTOM_PLUGINS_JSON}" ]]; then
        return
    fi

    if ! command -v python3 >/dev/null 2>&1; then
        warn "python3 no disponible; se omitira la etiqueta de plugins publicos del catalogo."
        return
    fi

    local names
    names="$(python3 - "${CUSTOM_PLUGINS_JSON}" <<'PY'
import json
import sys

path = sys.argv[1]
with open(path, encoding="utf-8") as fh:
    data = json.load(fh)

for item in data:
    name = item.get("nombre")
    if name:
        print(name)
PY
)" || return

    while IFS= read -r line; do
        [[ -n "${line}" ]] && PUBLIC_PLUGIN_NAMES+=("${line}")
    done <<< "${names}"
}

discover_plugin_repos() {
    local plugin_dir name

    for plugin_dir in "${ROOT}/plugins"/*; do
        [[ -d "${plugin_dir}" ]] || continue
        name="$(basename "${plugin_dir}")"

        [[ "${name}" == *_back ]] && continue
        [[ -d "${plugin_dir}/.git" ]] || continue

        if [[ ${#SELECTED_PLUGINS[@]} -gt 0 ]]; then
            local selected match=false
            for selected in "${SELECTED_PLUGINS[@]}"; do
                if [[ "${selected}" == "${name}" ]]; then
                    match=true
                    break
                fi
            done
            [[ "${match}" == true ]] || continue
        fi

        printf '%s\n' "${name}"
    done
}

repo_label() {
    local name="$1"
    if is_public_plugin "${name}"; then
        printf '%s [publico]' "${name}"
    else
        printf '%s [local]' "${name}"
    fi
}

git_run() {
    local dir="$1"
    shift

    if [[ "${DRY_RUN}" == true ]]; then
        printf '    [dry-run] (cd %s && %s)\n' "${dir}" "$*"
        return 0
    fi

    git -C "${dir}" "$@"
}

update_repo() {
    local scope="$1"
    local dir="$2"
    local label="$3"

    log ""
    log "==> ${label}"

    if ! git -C "${dir}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        fail_repo "${scope}" "no es un repositorio git"
        return 1
    fi

    local branch remote upstream dirty stash_created=false

    branch="$(git -C "${dir}" rev-parse --abbrev-ref HEAD 2>/dev/null || echo "HEAD")"
    remote="$(git -C "${dir}" remote get-url origin 2>/dev/null || echo "")"
    upstream="$(git -C "${dir}" rev-parse --abbrev-ref '@{u}' 2>/dev/null || echo "")"
    dirty="$(git -C "${dir}" status --porcelain | wc -l | tr -d ' ')"

    log "    rama: ${branch}"
    [[ -n "${remote}" ]] && log "    remote: ${remote}"
    [[ -n "${upstream}" ]] && log "    upstream: ${upstream}"

    if [[ -z "${remote}" ]]; then
        warn "${scope}: sin remote origin; se omite."
        return 2
    fi

    if [[ "${dirty}" != "0" && "${FORCE}" != true && "${AUTO_STASH}" != true ]]; then
        warn "${scope}: working tree sucio (${dirty} cambios). Usa --stash o --force."
        return 2
    fi

    if [[ "${dirty}" != "0" && "${AUTO_STASH}" == true ]]; then
        git_run "${dir}" stash push -u -m "git-update-all.sh auto-stash $(date -u +%Y-%m-%dT%H:%M:%SZ)"
        stash_created=true
    fi

    if ! git_run "${dir}" fetch --prune origin; then
        [[ "${stash_created}" == true ]] && git -C "${dir}" stash pop >/dev/null 2>&1 || true
        fail_repo "${scope}" "fetch fallido"
        return 1
    fi

    local pull_ref="${upstream#origin/}"
    if [[ -z "${pull_ref}" || "${pull_ref}" == "@{u}" ]]; then
        pull_ref="${branch}"
    fi

    if ! git_run "${dir}" pull --ff-only origin "${pull_ref}"; then
        if [[ "${stash_created}" == true ]]; then
            git -C "${dir}" stash pop >/dev/null 2>&1 || warn "${scope}: no se pudo restaurar el stash automatico."
        fi
        fail_repo "${scope}" "pull --ff-only fallido (posible divergencia o cambios locales)"
        return 1
    fi

    if [[ "${stash_created}" == true ]]; then
        if ! git_run "${dir}" stash pop; then
            warn "${scope}: pull completado pero hubo conflictos al restaurar el stash."
            return 1
        fi
    fi

    if [[ "${DRY_RUN}" == true ]]; then
        log "    OK (simulado)"
    else
        local after
        after="$(git -C "${dir}" rev-parse --short HEAD 2>/dev/null || echo "?")"
        log "    OK -> ${after}"
    fi
    return 0
}

main() {
    cd "${ROOT}"

    if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        echo "Error: ${ROOT} no es un repositorio git." >&2
        exit 1
    fi

    load_public_plugin_names

    local ok=0 skipped=0 failed=0
    local name plugin_dir

    log "Actualizacion git FSFramework"
    log "Raiz: ${ROOT}"
    if [[ -f "${CUSTOM_PLUGINS_JSON}" ]]; then
        log "Catalogo publico de referencia: ${CUSTOM_PLUGINS_JSON}"
    fi
    [[ "${DRY_RUN}" == true ]] && log "Modo: dry-run"

    record_result() {
        case "$1" in
            0) ok=$((ok + 1)) ;;
            1) failed=$((failed + 1)) ;;
            2) skipped=$((skipped + 1)) ;;
        esac
    }

    if [[ "${UPDATE_CORE}" == true ]]; then
        local rc
        update_repo "core" "${ROOT}" "core (fs-framework)" || rc=$?
        record_result "${rc:-0}"
    fi

    if [[ "${UPDATE_PLUGINS}" == true ]]; then
        while IFS= read -r name; do
            [[ -n "${name}" ]] || continue
            plugin_dir="${ROOT}/plugins/${name}"
            local rc=0
            update_repo "plugin:${name}" "${plugin_dir}" "$(repo_label "${name}")" || rc=$?
            record_result "${rc}"
        done < <(discover_plugin_repos | sort)
    fi

    log ""
    log "Resumen: ${ok} actualizados, ${skipped} omitidos, ${failed} fallidos"

    if [[ "${failed}" -gt 0 ]]; then
        exit 1
    fi
}

main "$@"
