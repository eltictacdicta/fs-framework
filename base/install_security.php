<?php

function fs_install_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_name('FSINSTALL');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
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
