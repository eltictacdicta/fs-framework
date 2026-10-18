<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * Session identity of THIS installation: the ONE place that derives the
 * session cookie name and the install ownership signature.
 *
 * It is intentionally dependency-free (plain functions, no class, no
 * autoloader) because it is loaded by bootstraps that run BEFORE the Composer
 * autoloader: base/fs_session_manager.php, base/fs_maintenance_mode.php and
 * base/install_security.php (install.php requires the latter before
 * vendor/autoload.php). It therefore cannot call
 * FSFramework\Security\SessionManager or SecretManager, but it MUST resolve the
 * same seed or the session name would differ mid-request and the user would be
 * bounced to the login page.
 *
 * Secret resolution mirrors SecretManager::resolveConfiguredSecret() exactly
 * (constant, then environment variable rejected below 32 characters, then the
 * secret file). Unlike the canonical path it NEVER auto-generates or persists a
 * secret: when nothing is configured it degrades to the install root and warns
 * once per process.
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */

/**
 * Name of the secret file, relative to the install root. Same file
 * SecretManager reads and writes.
 */
const FS_SESSION_IDENTITY_SECRET_FILE = '.fs_secret_key';

/**
 * Minimum length accepted for a configured secret, mirroring SecretManager.
 */
const FS_SESSION_IDENTITY_SECRET_MIN_LENGTH = 32;

/**
 * Prefix of the application session cookie name.
 */
const FS_SESSION_IDENTITY_APP_PREFIX = 'FSSESS_';

/**
 * Prefix of the installer session cookie name. Deliberately different from the
 * application prefix: the installer must never share a session with the app.
 */
const FS_SESSION_IDENTITY_INSTALLER_PREFIX = 'FSINSTALL_';

/**
 * Normalizes a seed so two hosts that spell the same path differently (Windows
 * backslashes, stray whitespace) agree on the derived identity.
 */
function fs_session_identity_normalize_seed(string $value): string
{
    return str_replace('\\', '/', trim($value));
}

/**
 * Hashes a seed into a cookie-name suffix. This is the ONLY place the session
 * name formula exists; every prefix (application, installer) reuses it.
 *
 * @internal Exposed so callers can reuse the formula instead of copying it.
 */
function fs_session_identity_name_with_prefix(string $prefix, string $seed): string
{
    return $prefix . substr(sha1(fs_session_identity_normalize_seed($seed)), 0, 12);
}

/**
 * Application session name for an arbitrary seed.
 *
 * @internal Exposed for the uniqueness tests.
 */
function fs_session_identity_name_from_seed(string $seed): string
{
    return fs_session_identity_name_with_prefix(FS_SESSION_IDENTITY_APP_PREFIX, $seed);
}

/**
 * Install ownership signature for an arbitrary seed.
 *
 * @internal Exposed for the ownership tests.
 */
function fs_session_identity_signature_from_seed(string $seed): string
{
    return sha1(fs_session_identity_normalize_seed($seed));
}

/**
 * FS_SESSION_NAME override, trimmed, or an empty string when absent.
 *
 * @internal Exposed so every caller parses the override the same way.
 */
function fs_session_identity_override_name(): string
{
    if (!defined('FS_SESSION_NAME')) {
        return '';
    }

    return trim((string) FS_SESSION_NAME);
}

/**
 * Root of THIS installation.
 *
 * Never $_SERVER['DOCUMENT_ROOT']: that is the vhost root and every sibling
 * installation of the same host shares it, so deriving from it would hand them
 * the same session name.
 */
function fs_session_identity_install_root(): string
{
    if (defined('FS_FOLDER') && trim((string) FS_FOLDER) !== '') {
        return (string) FS_FOLDER;
    }

    // This file lives in <install root>/base/.
    return dirname(__DIR__);
}

/**
 * Configured per-install secret, or null when none is resolvable.
 *
 * Precedence mirrors SecretManager::resolveConfiguredSecret(): constant, then
 * environment variable (rejected when shorter than 32 characters), then the
 * secret file. It never creates or persists anything.
 */
function fs_session_identity_secret(): ?string
{
    $constantSecret = fs_session_identity_constant_secret();
    if ($constantSecret !== null) {
        return $constantSecret;
    }

    $envSecret = getenv('FS_SECRET_KEY');
    if ($envSecret !== false && $envSecret !== '') {
        if (strlen($envSecret) < FS_SESSION_IDENTITY_SECRET_MIN_LENGTH) {
            error_log(
                '[FSFramework] fs_session_identity: FS_SECRET_KEY environment variable is too short'
                . ' (minimum ' . FS_SESSION_IDENTITY_SECRET_MIN_LENGTH . ' characters required)'
            );

            return null;
        }

        return $envSecret;
    }

    return fs_session_identity_file_secret();
}

/**
 * FS_SECRET_KEY constant, or null when undefined, empty or too short.
 */
function fs_session_identity_constant_secret(): ?string
{
    if (!defined('FS_SECRET_KEY')) {
        return null;
    }

    $value = constant('FS_SECRET_KEY');
    if (!is_scalar($value)) {
        // SecretManager raises here and its caller degrades to the install
        // root; returning null reaches the same place without an exception.
        return null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (strlen($value) < FS_SESSION_IDENTITY_SECRET_MIN_LENGTH) {
        error_log(
            '[FSFramework] fs_session_identity: FS_SECRET_KEY constant is too short'
            . ' (minimum ' . FS_SESSION_IDENTITY_SECRET_MIN_LENGTH . ' characters required)'
        );

        return null;
    }

    return $value;
}

/**
 * Path of the secret file, derived exactly like SecretManager::getSecretFilePath().
 */
function fs_session_identity_secret_file_path(): string
{
    $baseDir = defined('FS_FOLDER') ? (string) FS_FOLDER : dirname(__DIR__);

    return $baseDir . DIRECTORY_SEPARATOR . FS_SESSION_IDENTITY_SECRET_FILE;
}

/**
 * Last non-comment line of the secret file, or null when missing, unreadable or
 * shorter than 32 characters.
 */
function fs_session_identity_file_secret(): ?string
{
    $path = fs_session_identity_secret_file_path();
    if (!file_exists($path) || !is_readable($path)) {
        return null;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        return null;
    }

    $lines = preg_split('/\R/', $content) ?: [];
    $secret = null;

    foreach ($lines as $line) {
        $candidate = trim($line);
        if ($candidate === '') {
            continue;
        }

        if (str_starts_with($candidate, '#') || str_starts_with($candidate, '//') || str_starts_with($candidate, ';')) {
            continue;
        }

        $secret = $candidate;
    }

    if ($secret === null || strlen($secret) < FS_SESSION_IDENTITY_SECRET_MIN_LENGTH) {
        return null;
    }

    return $secret;
}

/**
 * Logs ONCE per process that no configured secret is resolvable.
 */
function fs_session_identity_warn_missing_secret(): void
{
    static $warned = false;

    if ($warned) {
        return;
    }

    $warned = true;

    error_log(
        '[FSFramework] fs_session_identity: no configured FS_SECRET_KEY is resolvable'
        . ' (constant, environment or ' . FS_SESSION_IDENTITY_SECRET_FILE . ' file);'
        . ' falling back to the install root as the session identity seed.'
        . ' This code never auto-generates a secret.'
    );
}

/**
 * Identity seed of THIS installation.
 *
 * The per-install secret is unique and stable across deploys, so two
 * installations never share a session name and moving the app to another folder
 * does not change it. Without a configured secret it degrades to the install
 * root, exactly like SessionManager::installSeed() does when SecretManager
 * throws.
 */
function fs_session_identity_seed(): string
{
    $secret = fs_session_identity_secret();
    if ($secret !== null) {
        return 'session:' . $secret;
    }

    fs_session_identity_warn_missing_secret();

    return fs_session_identity_install_root();
}

/**
 * Session cookie name of THIS installation: the single formula every caller
 * uses.
 *
 * @internal Exposed so pre-Composer bootstraps resolve the name the same way
 * the canonical SessionManager does.
 */
function fs_session_identity_name(): string
{
    $override = fs_session_identity_override_name();
    if ($override !== '') {
        return $override;
    }

    return fs_session_identity_name_from_seed(fs_session_identity_seed());
}

/**
 * Session cookie name of the INSTALLER: same seed as the application, different
 * prefix, so the installer never shares a cookie (and therefore never a
 * session) with the application.
 *
 * Unlike the application name it does NOT honour FS_SESSION_NAME. Honouring it
 * would return the application's own cookie name and the installer would end up
 * reading and writing the application session. The old copy did honour it, but
 * install.php exits when config.php exists, and config.php is the only
 * configured source of FS_SESSION_NAME, so that branch was unreachable in the
 * installer's real bootstrap.
 */
function fs_session_identity_installer_name(): string
{
    return fs_session_identity_name_with_prefix(FS_SESSION_IDENTITY_INSTALLER_PREFIX, fs_session_identity_seed());
}

/**
 * Install ownership signature of THIS installation.
 *
 * Mirrors SessionManager::installSignature() exactly, which hashes the RAW
 * seed: normalizing here would restamp the ownership signature of installs
 * whose root path needs normalization and log them out on upgrade. The legacy
 * helper below does normalize, because it mirrors the route-based formula.
 */
function fs_session_identity_install_signature(): string
{
    return sha1(fs_session_identity_seed());
}

/**
 * Previous session name (seed = install root), kept to migrate without logging
 * anyone out.
 */
function fs_session_identity_legacy_name(): string
{
    return fs_session_identity_name_from_seed(fs_session_identity_install_root());
}

/**
 * Previous install ownership signature (seed = install root).
 */
function fs_session_identity_legacy_install_signature(): string
{
    return fs_session_identity_signature_from_seed(fs_session_identity_install_root());
}
