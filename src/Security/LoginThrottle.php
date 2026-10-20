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
 * Cuenta los intentos fallidos por IP + nombre de usuario y, además, por cuenta
 * sola: la clave nick+IP sola permite fuerza bruta rotando IP contra un mismo
 * usuario. Bloquea temporalmente al superar cualquiera de los dos umbrales.
 *
 * Si la caché no está disponible el throttle no bloquea y lo registra en el
 * log (fail-open a propósito: una caché caída no debe dejar a todos afuera).
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
    private static ?string $dummyHash = null;

    private const LOG_PREFIX = '[LoginThrottle] ';

    const CACHE_PREFIX = 'login_throttle_';
    const MAX_ATTEMPTS = 6;
    const THROTTLE_WINDOW = 600; // 10 minutos

    /**
     * Umbral por cuenta, independiente de la IP.
     *
     * El contador nick+IP solo permite fuerza bruta rotando IP contra una misma
     * cuenta. Este segundo contador acota ese caso. Es más alto y más largo que
     * el de nick+IP para no bloquear cuentas por errores legítimos.
     */
    const ACCOUNT_MAX_ATTEMPTS = 20;
    const ACCOUNT_WINDOW = 900; // 15 minutos

    const GENERIC_ERROR = 'Usuario o contraseña incorrectos.';

    /**
     * Verifica si un usuario está bloqueado, por IP+usuario o por cuenta.
     */
    public static function isThrottled(string $nick): bool
    {
        $ip = self::getClientIp();

        if (self::getAttempts(self::cacheKey($nick, $ip)) >= self::MAX_ATTEMPTS) {
            return true;
        }

        return self::getAttempts(self::accountCacheKey($nick)) >= self::ACCOUNT_MAX_ATTEMPTS;
    }

    /**
     * Registra un intento fallido de login.
     */
    public static function recordFailure(string $nick): void
    {
        $ip = self::getClientIp();

        self::increment(self::cacheKey($nick, $ip), self::THROTTLE_WINDOW);
        self::increment(self::accountCacheKey($nick), self::ACCOUNT_WINDOW);
    }

    /**
     * Limpia el contador de intentos tras un login exitoso.
     */
    public static function clear(string $nick): void
    {
        $ip = self::getClientIp();

        self::forget(self::cacheKey($nick, $ip));
        self::forget(self::accountCacheKey($nick));
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
        if (self::$dummyHash === null) {
            self::$dummyHash = password_hash('dummy-timing-protection', PASSWORD_BCRYPT);
        }

        return self::$dummyHash;
    }

    private static function getAttempts(string $key): int
    {
        try {
            $cache = CacheManager::getInstance();
            $value = $cache->getItem($key, 0);

            return is_numeric($value) ? (int) $value : 0;
        } catch (\Throwable $e) {
            error_log(self::LOG_PREFIX . $e->getMessage());
            return 0;
        }
    }

    private static function increment(string $key, int $ttl): void
    {
        $attempts = self::getAttempts($key) + 1;

        try {
            CacheManager::getInstance()->set($key, $attempts, $ttl);
        } catch (\Throwable $e) {
            error_log(self::LOG_PREFIX . $e->getMessage());
            // Si la caché no está disponible, no bloqueamos
        }
    }

    private static function forget(string $key): void
    {
        try {
            CacheManager::getInstance()->delete($key);
        } catch (\Throwable $e) {
            error_log(self::LOG_PREFIX . $e->getMessage());
        }
    }

    private static function cacheKey(string $nick, string $ip): string
    {
        return self::CACHE_PREFIX . md5(strtolower($nick) . '|' . $ip);
    }

    private static function accountCacheKey(string $nick): string
    {
        return self::CACHE_PREFIX . 'account_' . md5(strtolower($nick));
    }

    /**
     * Intentos fallidos acumulados por cuenta, sin importar la IP.
     */
    public static function getAccountAttemptCount(string $nick): int
    {
        return self::getAttempts(self::accountCacheKey($nick));
    }

    private static function getClientIp(): string
    {
        // Delegar a Symfony Request que respeta FS_TRUSTED_PROXIES
        // (configurado en TrustedProxyConfigurator al boot del Kernel).
        // Fallback a $_SERVER['REMOTE_ADDR'] si Symfony no está disponible.
        if (class_exists(\Symfony\Component\HttpFoundation\Request::class)) {
            try {
                $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
                $ip = $request->getClientIp();
                if ($ip !== null && $ip !== '') {
                    return $ip;
                }
            } catch (\Throwable $e) {
                error_log(self::LOG_PREFIX . $e->getMessage());
                // Fallar silenciosamente al fallback legacy
            }
        }

        // Fallback de seguridad: solo REMOTE_ADDR (no headers forwarded)
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if (filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }

        return '127.0.0.1';
    }
}
