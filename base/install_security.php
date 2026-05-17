<?php

function fs_install_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_name(fs_install_resolve_session_name());
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => fs_install_resolve_cookie_path(),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function fs_install_get_csrf_token(): string
{
    fs_install_start_session();

    if (empty($_SESSION['fs_install_csrf_token']) || !is_string($_SESSION['fs_install_csrf_token'])) {
        $_SESSION['fs_install_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['fs_install_csrf_token'];
}

function fs_install_validate_csrf_token(?string $token): bool
{
    fs_install_start_session();

    $sessionToken = $_SESSION['fs_install_csrf_token'] ?? '';

    return is_string($token) && $token !== '' && is_string($sessionToken) && $sessionToken !== ''
        && hash_equals($sessionToken, $token);
}

function fs_install_is_valid_database_name(?string $name): bool
{
    return is_string($name) && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
}

function fs_install_quote_mysql_identifier(string $name): string
{
    return '`' . $name . '`';
}

function fs_install_quote_pg_identifier(string $name): string
{
    return '"' . $name . '"';
}

function fs_install_resolve_session_name(): string
{
    if (defined('FS_SESSION_NAME') && trim((string) FS_SESSION_NAME) !== '') {
        return trim((string) FS_SESSION_NAME);
    }

    $seed = defined('FS_FOLDER') ? (string) FS_FOLDER : dirname(__DIR__);
    $seed = str_replace('\\', '/', $seed);

    return 'FSINSTALL_' . substr(sha1($seed), 0, 12);
}

function fs_install_resolve_cookie_path(): string
{
    $preferredPath = defined('FS_PATH') ? (string) FS_PATH : null;
    if ($preferredPath !== null && trim($preferredPath) === '' && empty($_SERVER['REQUEST_URI'])) {
        return '/';
    }

    return fs_install_normalize_cookie_path($preferredPath, $_SERVER);
}

/**
 * @param array<string, mixed> $server
 */
function fs_install_normalize_cookie_path(?string $preferredPath, array $server): string
{
    $candidate = trim((string) $preferredPath);
    if ($candidate !== '') {
        return fs_install_normalize_cookie_path_value($candidate);
    }

    $scriptName = trim((string) ($server['SCRIPT_NAME'] ?? ''));
    if ($scriptName !== '') {
        return fs_install_normalize_cookie_path_value((string) dirname($scriptName));
    }

    $requestUri = filter_var((string) ($server['REQUEST_URI'] ?? '/'), FILTER_SANITIZE_URL);
    $parsedPath = parse_url($requestUri, PHP_URL_PATH);
    if (is_string($parsedPath) && $parsedPath !== '') {
        if (str_ends_with($parsedPath, '/install.php')) {
            $parsedPath = substr($parsedPath, 0, -12);
        } else {
            $parsedPath = (string) dirname($parsedPath);
        }

        return fs_install_normalize_cookie_path_value($parsedPath);
    }

    return '/';
}

function fs_install_normalize_cookie_path_value(string $path): string
{
    $normalized = trim(str_replace('\\', '/', $path));
    if ($normalized === '' || $normalized === '.' || $normalized === '/') {
        return '/';
    }

    $normalized = '/' . ltrim($normalized, '/');

    return str_ends_with($normalized, '/') ? $normalized : $normalized . '/';
}
