#!/bin/bash
#
# Install Git hooks for this project.
# Run once after cloning: ./scripts/install-hooks.sh
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
HOOKS_DIR="$SCRIPT_DIR/hooks"
GIT_HOOKS_DIR="$(git -C "$SCRIPT_DIR/.." rev-parse --git-dir)/hooks"

if [[ ! -d "$GIT_HOOKS_DIR" ]]; then
    echo "ERROR: Not inside a Git repository."
    exit 1
fi

for hook in "$HOOKS_DIR"/*; do
    [[ -f "$hook" ]] || continue
    name=$(basename "$hook")
    target="$GIT_HOOKS_DIR/$name"

    if [[ -L "$target" ]]; then
        echo "Hook '$name' already linked."
    elif [[ -f "$target" ]]; then
        echo "WARNING: Hook '$name' exists but is not a symlink. Skipping."
    else
        ln -s "$hook" "$target"
        echo "Installed hook: $name"
    fi
done

echo "Done. Hooks are active."
