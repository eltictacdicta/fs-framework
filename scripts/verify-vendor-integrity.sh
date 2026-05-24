#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

REQUIRED_FILES=(
    "vendor/twig/twig/src/Node/CoercesChildrenToStringInterface.php"
    "vendor/symfony/yaml/ParserState.php"
)

if ! command -v ddev >/dev/null 2>&1; then
    echo "ddev no esta disponible en el sistema." >&2
    exit 1
fi

cd "$ROOT_DIR"

missing=()
for file in "${REQUIRED_FILES[@]}"; do
    if [[ ! -f "$file" ]]; then
        missing+=("$file")
    fi
done

if ((${#missing[@]} == 0)); then
    echo "Vendor integrity OK."
    exit 0
fi

echo "Vendor incompleto: faltan ${#missing[@]} archivo(s) requerido(s):" >&2
for file in "${missing[@]}"; do
    echo "  - $file" >&2
done

echo "Reinstalando twig/twig y symfony/yaml..." >&2
ddev exec composer reinstall twig/twig symfony/yaml --no-interaction

for file in "${REQUIRED_FILES[@]}"; do
    if [[ ! -f "$file" ]]; then
        echo "ERROR: sigue faltando $file tras reinstalar." >&2
        exit 1
    fi
done

echo "Vendor reparado correctamente."
