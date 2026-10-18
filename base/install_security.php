<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

// The installer runs before the Composer autoloader (install.php requires this
// file first), so it resolves the session identity through the dependency-free
// resolver. Its own FSINSTALL_ prefix is applied on top of the SAME seed used
// by the application session name, which keeps the installer session separate
// from the application session without inventing a second seed.
require_once __DIR__ . '/fs_session_identity.php';

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

function fs_install_resolve_session_name(): string
{
    // Same seed as the application session name, different prefix: the
    // installer must never share a cookie (and therefore a session) with the
    // application, but it must not re-implement the seed either.
    return fs_session_identity_installer_name();
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

function fs_install_normalize_admin_nick(?string $nick): string
{
    $nick = trim((string) $nick);

    return $nick === '' ? 'admin' : $nick;
}

function fs_install_normalize_admin_password(?string $password): string
{
    $password = trim((string) $password);

    return $password === '' ? 'admin' : $password;
}

function fs_install_is_valid_admin_nick(string $nick): bool
{
    return preg_match('/^[A-Za-z0-9_+\.-]{3,12}$/', $nick) === 1;
}

