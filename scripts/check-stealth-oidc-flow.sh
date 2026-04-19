#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SITE_URL="${1:-https://oidcprovider.ddev.site}"

cd "$ROOT_DIR"

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

pass() {
    printf 'PASS: %s\n' "$1"
}

header_location() {
    grep -i '^location:' "$1" | sed 's/^[Ll]ocation: //' | tr -d '\r' | head -1
}

normalize_location() {
    local value="$1"
    if [[ "$value" =~ ^https?://[^/]+(/.*)$ ]]; then
        printf '%s' "${BASH_REMATCH[1]}"
        return
    fi

    printf '%s' "$value"
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "missing required command: $1"
}

require_command ddev
require_command curl
require_command grep
require_command sed
require_command awk

query_output="$(ddev exec mysql --batch --skip-column-names -e "SELECT name, \`varchar\` AS value FROM fs_vars WHERE name IN ('stealth_enabled','stealth_param_name','stealth_param_value');")"

stealth_enabled=""
param_name=""
param_value=""

while IFS=$'\t' read -r name value; do
    case "$name" in
        stealth_enabled) stealth_enabled="$value" ;;
        stealth_param_name) param_name="$value" ;;
        stealth_param_value) param_value="$value" ;;
    esac
done <<< "$query_output"

[[ "$stealth_enabled" == "1" ]] || fail "stealth mode is not enabled in fs_vars"
[[ -n "$param_name" ]] || fail "stealth_param_name is empty"
[[ -n "$param_value" ]] || fail "stealth_param_value is empty"

secret_url="$SITE_URL/index.php?$param_name=$param_value"
hidden_login_url="$SITE_URL/index.php?page=login&$param_name=$param_value"
blocked_reset_url="$SITE_URL/index.php?page=password_reset&$param_name=$param_value"

tmp_cookie="$(mktemp)"
tmp_headers="$(mktemp)"
tmp_body="$(mktemp)"
trap 'rm -f "$tmp_cookie" "$tmp_headers" "$tmp_body"' EXIT

curl -sk -b "$tmp_cookie" -c "$tmp_cookie" "$SITE_URL/" -o "$tmp_body"
grep -q '<!DOCTYPE html>' "$tmp_body" || fail "root does not return HTML"
if grep -q 'Inicia sesión para acceder al sistema' "$tmp_body"; then
    fail "root exposed the legacy login before using the stealth URL"
fi
pass "root stays on the public stealth homepage"

curl -sk -b "$tmp_cookie" -c "$tmp_cookie" -D "$tmp_headers" -o /dev/null "$secret_url"
expected_location="/index.php?page=login&$param_name=$param_value"
actual_location="$(header_location "$tmp_headers")"
normalized_actual_location="$(normalize_location "$actual_location")"
[[ "$normalized_actual_location" == "$expected_location" ]] || fail "secret entry redirected to '$actual_location' (normalized: '$normalized_actual_location') instead of '$expected_location'"
pass "secret entry redirects to the hidden login"

curl -sk -b "$tmp_cookie" -c "$tmp_cookie" "$hidden_login_url" -o "$tmp_body"
grep -q 'Inicia sesión para acceder al sistema' "$tmp_body" || fail "hidden login page did not render the legacy login"
grep -q 'name="user"' "$tmp_body" || fail "hidden login page is missing the user field"
if grep -q 'index.php?page=password_reset' "$tmp_body"; then
    fail "hidden login still exposes the password reset link"
fi
pass "hidden login keeps the login form and hides password reset"

curl -sk -b "$tmp_cookie" -c "$tmp_cookie" "$SITE_URL/" -o "$tmp_body"
if grep -q 'Inicia sesión para acceder al sistema' "$tmp_body"; then
    fail "root exposed the legacy login after using the stealth URL"
fi
pass "root remains closed after the stealth URL was used"

curl -sk -b "$tmp_cookie" -c "$tmp_cookie" -D "$tmp_headers" -o /dev/null "$blocked_reset_url"
actual_location="$(header_location "$tmp_headers")"
normalized_actual_location="$(normalize_location "$actual_location")"
[[ "$normalized_actual_location" == "$expected_location" ]] || fail "password_reset redirected to '$actual_location' (normalized: '$normalized_actual_location') instead of '$expected_location'"
pass "legacy password reset stays blocked and redirects back to the hidden login"

curl -sk "$SITE_URL/oauth/login" -o "$tmp_body"
grep -q '<html lang="es">' "$tmp_body" || fail "OIDC public login did not render"
pass "OIDC public login remains reachable"

printf 'Stealth and OIDC flow checks completed successfully.\n'