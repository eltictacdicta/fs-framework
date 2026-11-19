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

namespace FSFramework\Security;

use Throwable;

/**
 * Punto único para los tokens de sesión por equipo («Recordar sesión»).
 *
 * Cada login con contraseña crea un token para ese equipo; la cookie `logkey`
 * y la sesión (`user_logkey`) lo llevan. Un token deja de valer al cerrar
 * sesión en ese equipo, al cerrar sesión en todos los equipos, al guardar una
 * contraseña nueva o desactivar al usuario, tras
 * {@see SessionPolicy::getRememberMeCookieLifetime()} sin uso o al cumplir
 * {@see self::maxAge()} desde que se entró con contraseña. Las sesiones
 * abiertas con un token que deja de valer caen en su siguiente comprobación.
 *
 * Antes la credencial era fs_users.log_key, una por cuenta: entrar en un
 * equipo dejaba fuera a los demás. log_key ya no interviene aquí (el plugin
 * api_auth lo reescribe en cada login de la app).
 *
 * Falla cerrado y sin ruido: un error del almacén cuenta como token no válido
 * (se pide la contraseña), nunca como un error que impida entrar.
 */
final class RememberMeTokens
{
    /** Máximo desde el login con contraseña, aunque el token se use a diario. */
    private const DEFAULT_MAX_AGE = 2592000; // 30 días

    /** Cada cuánto vuelve a comprobarse el token de una sesión abierta. */
    public const SESSION_RECHECK = 60;

    private static ?RememberMeTokenStore $store = null;

    /** Para tests: sustituye el almacén (null vuelve al de base de datos). */
    public static function setStore(?RememberMeTokenStore $store): void
    {
        self::$store = $store;
    }

    public static function maxAge(): int
    {
        return defined('FS_REMEMBER_MAX_AGE') ? (int) FS_REMEMBER_MAX_AGE : self::DEFAULT_MAX_AGE;
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Huella de la contraseña vigente: cambiarla anula todos los tokens. Un
     * usuario sin contraseña local (entra por OIDC) tiene huella fija; sus
     * tokens se anulan cerrando sesión en todos los equipos.
     */
    public static function keyFor(object $user): string
    {
        return hash('sha256', 'remember-me|' . (string) ($user->password ?? ''));
    }

    public static function issue(object $user): string
    {
        $nick = (string) ($user->nick ?? '');
        if ($nick === '') {
            return '';
        }

        $userAgent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $ip = function_exists('fs_get_ip') ? (string) fs_get_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return self::call(fn(RememberMeTokenStore $s) => $s->issue($nick, self::keyFor($user), $userAgent, mb_substr($ip, 0, 50)), '');
    }

    public static function isValid(object $user, string $token): bool
    {
        $nick = (string) ($user->nick ?? '');
        if ($nick === '' || $token === '') {
            return false;
        }

        return self::call(fn(RememberMeTokenStore $s) => $s->isValid($nick, self::keyFor($user), $token), false);
    }

    /**
     * ¿Puede seguir la sesión abierta con este token? Sin token (otro camino de
     * login, o no se pudo emitir: se prefiere no bloquear un login válido), sí.
     * Con uno revocado, caducado, de un usuario desactivado o de antes de
     * cambiar la contraseña, no.
     */
    public static function sessionAllows(string $nick, ?string $sessionToken): bool
    {
        $sessionToken = (string) $sessionToken;
        if ($sessionToken === '') {
            return true;
        }

        return $nick !== '' && self::call(fn(RememberMeTokenStore $s) => $s->isLive($nick, $sessionToken), false);
    }

    public static function revoke(string $nick, string $token): bool
    {
        return $nick !== '' && $token !== '' && self::revokeByHash($nick, self::hashOf($token));
    }

    public static function revokeByHash(string $nick, string $tokenHash): bool
    {
        return $nick !== '' && $tokenHash !== ''
            && self::call(fn(RememberMeTokenStore $s) => $s->revoke($nick, $tokenHash), false);
    }

    /** «Cerrar sesión en todos los equipos»: las sesiones abiertas caen en su siguiente comprobación. */
    public static function revokeAll(string $nick): bool
    {
        return $nick !== '' && self::call(fn(RememberMeTokenStore $s) => $s->revokeAll($nick), false);
    }

    /**
     * @return list<array{token_hash: string, nick: string, user_agent: string, ip: string, created_at: int, last_used: int}>
     */
    public static function active(?string $nick = null): array
    {
        return self::call(fn(RememberMeTokenStore $s) => $s->active($nick), []);
    }

    /**
     * Da un token nuevo a la sesión actual, p. ej. tras cambiar la propia
     * contraseña: sin esto, el propio equipo quedaría fuera al instante.
     */
    public static function renewCurrentSession(object $user): void
    {
        $manager = SessionManager::getInstance();
        $token = self::issue($user);
        $manager->getSymfonySession()->set('user_logkey', $token !== '' ? $token : null);
        $manager->getSymfonySession()->set('_rm_checked_at', time());
        if ($token !== '') {
            $manager->getLegacyAuthBridge()->issueLegacyCookies((string) $user->nick, $token);
        }
    }

    /**
     * @template T
     * @param callable(RememberMeTokenStore): T $fn
     * @param T $fallback
     * @return T
     */
    private static function call(callable $fn, mixed $fallback): mixed
    {
        try {
            $store = self::store();

            return $store === null ? $fallback : $fn($store);
        } catch (Throwable $e) {
            error_log('RememberMeTokens: ' . $e->getMessage());

            return $fallback;
        }
    }

    private static function store(): ?RememberMeTokenStore
    {
        if (self::$store !== null) {
            return self::$store;
        }

        if (!class_exists('fs_user_token')) {
            $file = (defined('FS_FOLDER') ? FS_FOLDER : '.') . '/model/fs_user_token.php';
            if (!is_file($file)) {
                return null;
            }
            require_once $file;
        }

        return self::$store = new \fs_user_token();
    }
}
