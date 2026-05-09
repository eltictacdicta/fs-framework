<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FSFramework\Security;

use FSFramework\Cache\CacheManager;

/**
 * Protección anti fuerza bruta para intentos de login.
 *
 * Cuenta los intentos fallidos por IP + nombre de usuario y bloquea
 * temporalmente cuando se supera el umbral configurado.
 *
 * Inspirado en FacturaScripts 2025 Core/Controller/Login.php
 *
 * Uso:
 *   if (LoginThrottle::isThrottled($nick)) {
 *       // mostrar error genérico, no revelar si el usuario existe
 *   }
 *   // ... intentar login ...
 *   if (!$success) {
 *       LoginThrottle::recordFailure($nick);
 *   } else {
 *       LoginThrottle::clear($nick);
 *   }
 */
class LoginThrottle
{
    const CACHE_PREFIX = 'login_throttle_';
    const MAX_ATTEMPTS = 6;
    const THROTTLE_WINDOW = 600; // 10 minutos
    const GENERIC_ERROR = 'Usuario o contraseña incorrectos.';

    /**
     * Verifica si un usuario+IP está bloqueado por exceso de intentos.
     */
    public static function isThrottled(string $nick): bool
    {
        $ip = self::getClientIp();
        $key = self::cacheKey($nick, $ip);
        $attempts = self::getAttempts($key);

        return $attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Registra un intento fallido de login.
     */
    public static function recordFailure(string $nick): void
    {
        $ip = self::getClientIp();
        $key = self::cacheKey($nick, $ip);
        $attempts = self::getAttempts($key);
        $attempts++;

        try {
            $cache = CacheManager::getInstance();
            $cache->set($key, $attempts, self::THROTTLE_WINDOW);
        } catch (\Throwable) {
            // Si la caché no está disponible, no bloqueamos
        }
    }

    /**
     * Limpia el contador de intentos tras un login exitoso.
     */
    public static function clear(string $nick): void
    {
        $ip = self::getClientIp();
        $key = self::cacheKey($nick, $ip);

        try {
            $cache = CacheManager::getInstance();
            $cache->delete($key);
        } catch (\Throwable) {
        }
    }

    /**
     * Obtiene el número de intentos actuales.
     */
    public static function getAttemptCount(string $nick): int
    {
        $ip = self::getClientIp();
        $key = self::cacheKey($nick, $ip);

        return self::getAttempts($key);
    }

    /**
     * Devuelve un hash bcrypt fijo para usar como dummy en protección
     * contra enumeración de usuarios (timing attack).
     *
     * Se usa cuando el usuario no existe, para que el tiempo de respuesta
     * sea similar al de un intento real con password_verify().
     */
    public static function getDummyHash(): string
    {
        return '$2y$10$dummyHashForTimingAttackProtection1234567890abcdefABCDEF';
    }

    private static function getAttempts(string $key): int
    {
        try {
            $cache = CacheManager::getInstance();
            $value = $cache->getItem($key, 0);

            return is_numeric($value) ? (int) $value : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function cacheKey(string $nick, string $ip): string
    {
        return self::CACHE_PREFIX . md5(strtolower($nick) . '|' . $ip);
    }

    private static function getClientIp(): string
    {
        if (function_exists('fs_get_ip')) {
            return fs_get_ip();
        }

        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $header) {
            $ip = $_SERVER[$header] ?? '';
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '127.0.0.1';
    }
}
