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

namespace FSFramework\Core;

/**
 * Public base URL of the installation (FS_BASE_URL).
 *
 * Precedence, so existing installs keep behaving exactly as before:
 *  1. FS_BASE_URL defined in config.php (always wins).
 *  2. The `base_url` row in fs_vars, editable from admin_home » Avanzado.
 *  3. Nothing: PageDispatcher derives it from the request, as it always did.
 *
 * Consumers keep reading the FS_BASE_URL constant; this class only decides
 * whether the database value defines it. system_updater preserves the row of
 * the target server across a database restore, so restoring a backup taken
 * on another host does not import that host's URL.
 */
final class BaseUrl
{
    public const VAR_NAME = 'base_url';

    public const SOURCE_CONFIG = 'config';
    public const SOURCE_DATABASE = 'database';
    public const SOURCE_REQUEST = 'request';

    private static ?string $source = null;

    /**
     * Canonical form (scheme://host[:port][/path], no trailing slash) or null
     * when the value is not a usable absolute http(s) URL.
     */
    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '' || preg_match('/[\s\x00-\x1F\x7F]/', $raw) === 1) {
            return null;
        }

        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        // parse_url() splits but does not validate: "https://[not-ipv6]" would pass.
        if (!self::isValidHost($host)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $scheme . '://' . $host . $port . $path;
    }

    private static function isValidHost(string $host): bool
    {
        if (str_starts_with($host, '[')) {
            return str_ends_with($host, ']')
                && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * Defines FS_BASE_URL from the database when config.php did not. Never
     * throws: any failure leaves the constant undefined (request fallback).
     */
    public static function defineFromDatabase(?\fs_db2 $db = null): void
    {
        if (defined('FS_BASE_URL')) {
            self::$source ??= self::SOURCE_CONFIG;
            return;
        }

        $value = self::normalize(self::readStored($db));
        if ($value !== null) {
            define('FS_BASE_URL', $value);
            self::$source = self::SOURCE_DATABASE;
        }
    }

    /** Where the current FS_BASE_URL came from; null when it is not defined yet. */
    public static function source(): ?string
    {
        if (self::$source !== null) {
            return self::$source;
        }

        return defined('FS_BASE_URL') ? self::SOURCE_REQUEST : null;
    }

    /** Raw stored value, or null when absent or unreadable. */
    public static function readStored(?\fs_db2 $db = null): ?string
    {
        try {
            $db ??= new \fs_db2();
            if (!$db->connect()) {
                return null;
            }

            $rows = $db->select('SELECT * FROM fs_vars WHERE name = ' . $db->var2str(self::VAR_NAME) . ';');
            $value = is_array($rows) && isset($rows[0]['varchar']) ? (string) $rows[0]['varchar'] : '';

            return $value !== '' ? $value : null;
        } catch (\Throwable $e) {
            error_log('BaseUrl: ' . $e->getMessage());
            return null;
        }
    }

    /** Test hook: forget the resolved source. */
    public static function reset(): void
    {
        self::$source = null;
    }
}
