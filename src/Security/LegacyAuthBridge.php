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

use fs_user;
use Throwable;
use fs_core_log;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Bridge mínimo para compatibilidad de autenticación heredada.
 */
final readonly class LegacyAuthBridge
{
    public function __construct(private Session $session)
    {
    }

    /**
     * @return array{nick: string, email: mixed, admin: bool, logkey: string}|null
     */
    public function getLegacyUserDataFromCookies(): ?array
    {
        $cookieUser = $_COOKIE['user'] ?? null;
        $cookieLogkey = $_COOKIE['logkey'] ?? null;
        $cookieSig = $_COOKIE['auth_sig'] ?? null;
        $legacyUserData = null;

        if ($cookieUser && $cookieLogkey) {
            $this->loadUserDependencies();
            if (class_exists('fs_user')) {
                try {
                    $userModel = new fs_user();
                    $user = $userModel->get($cookieUser);
                    if ($this->isLegacyUserAuthenticated($user, (string) $cookieLogkey)
                        && $this->isRememberMeSignatureValid((string) $cookieUser, (string) $cookieLogkey, (string) $cookieSig)) {
                        $legacyUserData = [
                            'nick' => $user->nick,
                            'email' => $user->email ?? null,
                            'admin' => (bool) $user->admin,
                            'logkey' => (string) $cookieLogkey,
                        ];
                    }
                } catch (Throwable) {
                    $legacyUserData = null;
                }
            }
        }

        return $legacyUserData;
    }

    public function clearLegacyCookies(): void
    {
        $expire = time() - 3600;
        $this->writeLegacyCookies('', '', '', $expire);
    }

    public function issueLegacyCookies(string $nick, string $logkey): void
    {
        if ($logkey === '') {
            $this->logMissingLogKey($nick);
            return;
        }

        $expire = time() + (defined('FS_COOKIES_EXPIRE') ? FS_COOKIES_EXPIRE : 31536000);
        $signature = CookieSigner::signRememberMe($nick, $logkey);

        $this->writeLegacyCookies($nick, $logkey, $signature, $expire);
    }

    private function writeLegacyCookies(string $nick, string $logkey, string $signature, int $expire): void
    {
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        $cookieOptions = [
            'expires' => $expire,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie('user', $nick, $cookieOptions);
        setcookie('logkey', $logkey, $cookieOptions);
        setcookie('auth_sig', $signature, $cookieOptions);
    }

    /**
     * @param mixed $user
     */
    private function isLegacyUserAuthenticated($user, string $logkey): bool
    {
        return $user
            && $user->enabled
            && $user->log_key === $logkey
            && $this->session->get('user_nick') !== $user->nick;
    }

    private function isRememberMeSignatureValid(string $nick, string $logkey, string $cookieSig): bool
    {
        return $cookieSig !== '' && CookieSigner::verifyRememberMe($nick, $logkey, $cookieSig);
    }

    private function logMissingLogKey(string $nick): void
    {
        if (!class_exists('fs_core_log')) {
            return;
        }

        $log = new fs_core_log(self::class);
        $log->alert('No se emitieron cookies legacy porque falta log_key.', ['nick' => $nick]);
    }

    private function loadUserDependencies(): void
    {
        $folder = defined('FS_FOLDER') ? FS_FOLDER : '.';
        if (class_exists('fs_user')) {
            return;
        }

        $dependencies = [
            'fs_cache' => '/base/fs_cache.php',
            'fs_core_log' => '/base/fs_core_log.php',
            'fs_db2' => '/base/fs_db2.php',
            'fs_model' => '/base/fs_model.php',
            'fs_page' => '/model/fs_page.php',
            'fs_access' => '/model/fs_access.php',
            'fs_user' => '/model/core/fs_user.php',
        ];

        foreach ($dependencies as $class => $path) {
            if (class_exists($class)) {
                continue;
            }

            $fullPath = $folder . $path;
            if (file_exists($fullPath)) {
                require_once $fullPath;
            }
        }
    }
}
