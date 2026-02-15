<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FSFramework\Security;

class SecretManager
{
    private static ?string $secret = null;

    public static function getSecret(): string
    {
        if (self::$secret !== null) {
            return self::$secret;
        }

        if (defined('FS_SECRET_KEY') && !empty(FS_SECRET_KEY)) {
            self::$secret = (string) FS_SECRET_KEY;
            return self::$secret;
        }

        $parts = [];
        foreach (['FS_DB_NAME', 'FS_DB_USER', 'FS_CACHE_PREFIX', 'FS_TMP_NAME', 'FS_FOLDER'] as $const) {
            if (defined($const)) {
                $parts[] = (string) constant($const);
            }
        }

        if (empty($parts)) {
            $parts[] = __FILE__;
            $parts[] = PHP_VERSION;
        }

        self::$secret = hash('sha256', implode('|', $parts));
        return self::$secret;
    }

    public static function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, self::getSecret());
    }
}
