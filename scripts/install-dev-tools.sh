#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

if ! command -v ddev >/dev/null 2>&1; then
    echo "ddev no esta disponible en el sistema." >&2
    exit 1
fi

cd "$ROOT_DIR"
ddev exec composer install --working-dir=dev-tools --no-interaction

echo "Herramientas de desarrollo instaladas en vendor/dev-tools"