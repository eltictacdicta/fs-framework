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

/**
 * Centralised session timeout policy.
 *
 * Both the framework SessionManager and the OidcProvider plugin
 * delegate to this class so that idle / absolute timeouts and
 * remember-me cookie lifetimes are consistent everywhere.
 */
final class SessionPolicy
{
    private const DEFAULT_IDLE_TIMEOUT = 7200;      // 2 hours
    private const DEFAULT_ABSOLUTE_TIMEOUT = 28800;  // 8 hours
    private const DEFAULT_REMEMBER_COOKIE = 604800;  // 7 days
    private const SESSION_COOKIE_EXPIRE = 0;         // browser session

    public static function getIdleTimeout(): int
    {
        if (defined('FS_SESSION_IDLE_TIMEOUT')) {
            return (int) FS_SESSION_IDLE_TIMEOUT;
        }

        if (defined('FS_SESSION_LIFETIME')) {
            return (int) FS_SESSION_LIFETIME;
        }

        return self::DEFAULT_IDLE_TIMEOUT;
    }

    public static function getAbsoluteTimeout(): int
    {
        if (defined('FS_SESSION_ABSOLUTE_TIMEOUT')) {
            return (int) FS_SESSION_ABSOLUTE_TIMEOUT;
        }

        return self::DEFAULT_ABSOLUTE_TIMEOUT;
    }

    public static function getRememberMeCookieLifetime(): int
    {
        if (defined('FS_COOKIES_EXPIRE')) {
            return (int) FS_COOKIES_EXPIRE;
        }

        return self::DEFAULT_REMEMBER_COOKIE;
    }

    /**
     * @return int cookie `expires` value — 0 means browser-session cookie
     */
    public static function cookieExpireFor(bool $rememberMe): int
    {
        return $rememberMe
            ? time() + self::getRememberMeCookieLifetime()
            : self::SESSION_COOKIE_EXPIRE;
    }

    /**
     * Pure logic: is the session expired given the two timestamps?
     *
     * Non-positive timestamps are treated as invalid and therefore expired.
     *
     * A session is expired when EITHER:
     *  - idle timeout exceeded  (now − lastActivity > idle)
     *  - absolute timeout exceeded (now − loginTime  > absolute)
     */
    public static function isExpired(int $loginTime, int $lastActivity): bool
    {
        $now = time();

        return $loginTime <= 0
            || $lastActivity <= 0
            || ($now - $lastActivity) > self::getIdleTimeout()
            || ($now - $loginTime) > self::getAbsoluteTimeout();
    }
}
