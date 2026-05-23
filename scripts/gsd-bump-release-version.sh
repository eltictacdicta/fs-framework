#!/usr/bin/env bash
#
# Bump FSFramework release versions after a GSD milestone completes.
#
# Usage:
#   scripts/gsd-bump-release-version.sh --milestone v0.12.0
#   scripts/gsd-bump-release-version.sh --milestone v0.12.0 --from-tag v0.11.0
#   scripts/gsd-bump-release-version.sh --milestone v0.12.0 --plugins legacy_support,business_data
#   scripts/gsd-bump-release-version.sh --milestone v0.12.0 --dry-run
#
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

MILESTONE=""
FROM_TAG=""
EXPLICIT_PLUGINS=""
DRY_RUN=false
AUTO_PLUGINS=true

usage() {
    sed -n '2,12p' "$0" | sed 's/^# \?//'
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --milestone)
            MILESTONE="${2:-}"
            shift 2
            ;;
        --from-tag)
            FROM_TAG="${2:-}"
            shift 2
            ;;
        --plugins)
            EXPLICIT_PLUGINS="${2:-}"
            AUTO_PLUGINS=false
            shift 2
            ;;
        --no-auto-plugins)
            AUTO_PLUGINS=false
            shift
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        -h|--help)
            usage 0
            ;;
        *)
            echo "Opción desconocida: $1" >&2
            usage 1
            ;;
    esac
done

if [[ -z "$MILESTONE" ]]; then
    echo "ERROR: --milestone es obligatorio (ej. v0.12.0)" >&2
    usage 1
fi

# v0.12.0 -> 0.12.0
CORE_VERSION="${MILESTONE#v}"
CORE_VERSION_FILE="$ROOT_DIR/VERSION"
CONFIG_FILE="$ROOT_DIR/.planning/config.json"

trim() {
    local value="$1"
    value="${value//$'\r'/}"
    value="${value//$'\n'/}"
    echo -n "$value"
}

read_core_version() {
    if [[ ! -f "$CORE_VERSION_FILE" ]]; then
        echo ""
        return
    fi
    trim "$(cat "$CORE_VERSION_FILE")"
}

write_file() {
    local path="$1"
    local content="$2"
    if [[ "$DRY_RUN" == true ]]; then
        echo "[dry-run] escribiría $path"
        return
    fi
    printf '%s\n' "$content" > "$path"
}

increment_plugin_version() {
    local current="$1"
    if [[ "$current" =~ ^([0-9]+)\.([0-9]+)(\.([0-9]+))?$ ]]; then
        local major="${BASH_REMATCH[1]}"
        local minor="${BASH_REMATCH[2]}"
        local patch="${BASH_REMATCH[4]:-0}"
        patch=$((patch + 1))
        echo "${major}.${minor}.${patch}"
        return
    fi
    if [[ "$current" =~ ^[0-9]+$ ]]; then
        echo $((current + 1))
        return
    fi
    echo "$current"
}

bump_plugin_ini() {
    local plugin="$1"
    local ini_path="$ROOT_DIR/plugins/$plugin/fsframework.ini"
    if [[ ! -f "$ini_path" ]]; then
        echo "WARN: no existe $ini_path — omitiendo plugin $plugin" >&2
        return 1
    fi

    local current_version
    current_version="$(grep -E '^version\s*=' "$ini_path" | head -1 | sed -E 's/^version\s*=\s*//; s/[[:space:]]+$//')"
    if [[ -z "$current_version" ]]; then
        echo "WARN: $ini_path no tiene campo version — omitiendo" >&2
        return 1
    fi

    local new_version
    new_version="$(increment_plugin_version "$current_version")"
    if [[ "$new_version" == "$current_version" ]]; then
        echo "WARN: no se pudo incrementar version de $plugin ($current_version)" >&2
        return 1
    fi

    if [[ "$DRY_RUN" == true ]]; then
        echo "[dry-run] $ini_path: version $current_version -> $new_version"
        return 0
    fi

    local tmp
    tmp="$(mktemp)"
    sed -E "s/^version\s*=.*/version = $new_version/" "$ini_path" > "$tmp"
    mv "$tmp" "$ini_path"
    echo "✓ plugins/$plugin/fsframework.ini: $current_version -> $new_version"
}

detect_plugins_from_git() {
    local from_ref="$1"
    local plugins=()

    if ! git rev-parse --verify "$from_ref" >/dev/null 2>&1; then
        echo "WARN: ref git '$from_ref' no encontrada — sin auto-detección de plugins" >&2
        return 0
    fi

    while IFS= read -r file; do
        [[ -z "$file" ]] && continue
        if [[ "$file" =~ ^plugins/([^/]+)/ ]]; then
            local plugin="${BASH_REMATCH[1]}"
            if [[ "$plugin" != *_back ]]; then
                plugins+=("$plugin")
            fi
        fi
    done < <(git diff --name-only "$from_ref"..HEAD -- plugins/ | sort -u)

    if [[ ${#plugins[@]} -eq 0 ]]; then
        return 0
    fi

    printf '%s\n' "${plugins[@]}" | sort -u
}

resolve_from_tag() {
    if [[ -n "$FROM_TAG" ]]; then
        echo "$FROM_TAG"
        return
    fi

    # Buscar el tag de milestone anterior más reciente
    local previous_tag
    previous_tag="$(git tag --list 'v*' --sort=-v:refname | while read -r tag; do
        local tag_version="${tag#v}"
        if [[ "$tag" != "$MILESTONE" && "$tag_version" != "$CORE_VERSION" ]]; then
            echo "$tag"
            break
        fi
    done)"
    if [[ -n "$previous_tag" ]]; then
        echo "$previous_tag"
        return
    fi

    echo "HEAD~50"
}

load_config_plugins() {
    if [[ ! -f "$CONFIG_FILE" ]]; then
        return 0
    fi
    if ! command -v python3 >/dev/null 2>&1; then
        return 0
    fi
    python3 - <<'PY' "$CONFIG_FILE"
import json, sys
path = sys.argv[1]
try:
    with open(path, encoding="utf-8") as fh:
        data = json.load(fh)
except Exception:
    sys.exit(0)
release = data.get("release") or {}
for plugin in release.get("plugins") or []:
    if plugin:
        print(plugin)
PY
}

echo "=== FSFramework release bump ==="
echo "Milestone: $MILESTONE"
echo "Core target: $CORE_VERSION"

current_core="$(read_core_version)"
if [[ "$current_core" == "$CORE_VERSION" ]]; then
    echo "✓ VERSION ya está en $CORE_VERSION"
else
    if [[ -n "$current_core" ]]; then
        echo "→ VERSION: $current_core -> $CORE_VERSION"
    else
        echo "→ VERSION: (nuevo) -> $CORE_VERSION"
    fi
    write_file "$CORE_VERSION_FILE" "$CORE_VERSION"
    if [[ "$DRY_RUN" != true ]]; then
        echo "✓ VERSION actualizado"
    fi
fi

declare -a PLUGINS_TO_BUMP=()

if [[ -n "$EXPLICIT_PLUGINS" ]]; then
    IFS=',' read -ra PLUGINS_TO_BUMP <<< "$EXPLICIT_PLUGINS"
elif [[ "$AUTO_PLUGINS" == true ]]; then
    FROM_REF="$(resolve_from_tag)"
    echo "Rango git para plugins: $FROM_REF..HEAD"
    mapfile -t detected < <(detect_plugins_from_git "$FROM_REF")
    PLUGINS_TO_BUMP=("${detected[@]}")
fi

mapfile -t config_plugins < <(load_config_plugins)
if [[ ${#config_plugins[@]} -gt 0 ]]; then
    PLUGINS_TO_BUMP+=("${config_plugins[@]}")
fi

if [[ ${#PLUGINS_TO_BUMP[@]} -eq 0 ]]; then
    echo "Sin plugins para bump (solo core)."
    exit 0
fi

# Deduplicar
mapfile -t PLUGINS_TO_BUMP < <(printf '%s\n' "${PLUGINS_TO_BUMP[@]}" | awk 'NF && !seen[$0]++')

echo "Plugins a bump:"
for plugin in "${PLUGINS_TO_BUMP[@]}"; do
    bump_plugin_ini "$plugin" || true
done

echo "=== Bump completado ==="
