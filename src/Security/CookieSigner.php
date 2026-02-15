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

class CookieSigner
{
    public const SIGNATURE_COOKIE = 'auth_sig';

    public static function signRememberMe(string $nick, string $logKey): string
    {
        return SecretManager::hmac(self::payload($nick, $logKey));
    }

    public static function verifyRememberMe(string $nick, string $logKey, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $expected = self::signRememberMe($nick, $logKey);
        return hash_equals($expected, $signature);
    }

    private static function payload(string $nick, string $logKey): string
    {
        return $nick . '|' . $logKey;
    }
}
