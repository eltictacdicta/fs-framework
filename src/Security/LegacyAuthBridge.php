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
 *
 * Los plugins pueden registrar {@see self::registerSkipLegacyCookieRestoreCheck()} para impedir que las cookies
 * remember-me restauren fs_user cuando la sesión pertenece a otro ámbito de login (p. ej. portal federado).
 */
final class LegacyAuthBridge
{
    /**
     * Devuelve true para NO aplicar cookies legacy en esta petición.
     *
     * @var null|callable(array<string,mixed>):bool
     */
    private static mixed $skipLegacyCookieRestoreCheck = null;

    public function __construct(private readonly Session $session)
    {
    }

    /**
     * Registra una comprobación opcional (p. ej. desde un plugin). Recibe atributos de sesión ya aplanados
     * (ver {@see self::flattenSessionAttributesForPlugins()}).
     *
     * @param ?callable(array<string,mixed>):bool $callback
     */
    public static function registerSkipLegacyCookieRestoreCheck(?callable $callback): void
    {
        self::$skipLegacyCookieRestoreCheck = $callback;
    }

    /**
     * Para tests: limpia el callback registrado.
     */
    public static function resetSkipLegacyCookieRestoreCheck(): void
    {
        self::$skipLegacyCookieRestoreCheck = null;
    }

    public function shouldSkipLegacyCookieRestore(): bool
    {
        if (self::$skipLegacyCookieRestoreCheck === null) {
            return false;
        }

        $flat = self::flattenSessionAttributesForPlugins($this->session->all());

        return (bool) (self::$skipLegacyCookieRestoreCheck)($flat);
    }

    /**
     * Misma lógica que {@see shouldSkipLegacyCookieRestore()} para rutas que solo tienen `$_SESSION` (fallback legacy).
     *
     * @param array<string,mixed> $rawSession
     */
    public static function shouldSkipLegacyCookieRestoreForRawSession(array $rawSession): bool
    {
        if (self::$skipLegacyCookieRestoreCheck === null) {
            return false;
        }

        $flat = self::flattenSessionAttributesForPlugins($rawSession);

        return (bool) (self::$skipLegacyCookieRestoreCheck)($flat);
    }

    /**
     * Une `_sf2_attributes` con el resto para que los plugins encuentren claves como en Session::all().
     *
     * @param array<string,mixed> $rawSession
     *
     * @return array<string,mixed>
     */
    public static function flattenSessionAttributesForPlugins(array $rawSession): array
    {
        $flat = $rawSession;
        unset($flat['_sf2_attributes']);
        if (isset($rawSession['_sf2_attributes']) && is_array($rawSession['_sf2_attributes'])) {
            foreach ($rawSession['_sf2_attributes'] as $key => $value) {
                if (!array_key_exists($key, $flat)) {
                    $flat[$key] = $value;
                }
            }
        }

        return $flat;
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
                    if ($this->isLegacyUserEligibleForCookieRestore($user, (string) $cookieLogkey)
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
        $this->clearAuxiliaryLegacyCookies($expire);
        $this->syncLegacyCookieGlobals('', '', '', $expire);
    }

    public function revokeCurrentLegacyLogin(): void
    {
        $nick = $this->resolveLegacyLogoutNick();
        if ($nick === null) {
            return;
        }

        $this->loadUserDependencies();
        if (!class_exists('fs_user')) {
            return;
        }

        try {
            $userModel = new fs_user();
            $user = $userModel->get($nick);
            if (!$user || !method_exists($user, 'rotate_logkey') || !method_exists($user, 'save')) {
                return;
            }

            $user->rotate_logkey();
            $user->save();
        } catch (Throwable $e) {
            $this->logException('revokeCurrentLegacyLogin', $e, ['nick' => $nick]);
        }
    }

    public function issueLegacyCookies(string $nick, string $logkey): void
    {
        if ($logkey === '') {
            $this->logMissingLogKey($nick);
            return;
        }

        $rememberMe = (bool) ($this->session->get('remember_me') ?? false);
        $expire = SessionPolicy::cookieExpireFor($rememberMe);
        $signature = CookieSigner::signRememberMe($nick, $logkey);

        $this->writeLegacyCookies($nick, $logkey, $signature, $expire);
    }

    private function writeLegacyCookies(string $nick, string $logkey, string $signature, int $expire): void
    {
        foreach ($this->resolveLegacyCookiePaths($expire) as $cookieOptions) {
            setcookie('user', $nick, $cookieOptions);
            setcookie('logkey', $logkey, $cookieOptions);
            setcookie('auth_sig', $signature, $cookieOptions);
        }

        $this->syncLegacyCookieGlobals($nick, $logkey, $signature, $expire);
    }

    private function clearAuxiliaryLegacyCookies(int $expire): void
    {
        foreach ($this->resolveLegacyCookiePaths($expire) as $cookieOptions) {
            setcookie('fsNick', '', $cookieOptions);
        }
    }

    /**
     * @return array<int, array{expires: int, path: string, secure: bool, httponly: bool, samesite: string}>
     */
    private function resolveLegacyCookiePaths(int $expire): array
    {
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        $paths = [$this->resolveLegacyCookiePath()];

        if ($expire < time() && $paths[0] !== '/') {
            $paths[] = '/';
        }

        return array_map(static function (string $path) use ($expire, $secure): array {
            return [
                'expires' => $expire,
                'path' => $path,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ];
        }, array_values(array_unique($paths)));
    }

    private function resolveLegacyCookiePath(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url(str_replace('/index.php', '', $requestUri), PHP_URL_PATH) ?: '/';

        if ($path === '') {
            return '/';
        }

        return str_ends_with($path, '/') ? $path : $path . '/';
    }

    private function resolveLegacyLogoutNick(): ?string
    {
        $sessionNick = $this->session->get('user_nick');
        if (is_string($sessionNick) && $sessionNick !== '') {
            return $sessionNick;
        }

        $cookieUser = $_COOKIE['user'] ?? null;
        if (is_string($cookieUser) && $cookieUser !== '') {
            return $cookieUser;
        }

        return null;
    }

    private function syncLegacyCookieGlobals(string $nick, string $logkey, string $signature, int $expire): void
    {
        if ($expire < time()) {
            unset($_COOKIE['user'], $_COOKIE['logkey'], $_COOKIE['auth_sig'], $_COOKIE['fsNick']);
            return;
        }

        $_COOKIE['user'] = $nick;
        $_COOKIE['logkey'] = $logkey;
        $_COOKIE['auth_sig'] = $signature;
    }

    /**
     * Restaura sesión fs_user desde cookies solo cuando no hay conflicto con otro nick ya cargado en sesión.
     * Antes se exigía session[user_nick] !== usuario cookie (idea equivocada de “refresco”), lo que permitía
     * sustituir una sesión (p. ej. sujeto OIDC portal o usuario caducado) por otro usuario con cookies válidas
     * — típicamente escalando a administrador por cookies remember-me antiguas.
     *
     * @param mixed $user
     */
    private function isLegacyUserEligibleForCookieRestore($user, string $logkey): bool
    {
        if (!$user || !$user->enabled || $user->log_key !== $logkey) {
            return false;
        }

        $sessionNick = $this->session->get('user_nick');
        if ($sessionNick === null || $sessionNick === '') {
            return true;
        }

        return (string) $sessionNick === (string) $user->nick;
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

    /**
     * @param array<string, mixed> $context
     */
    private function logException(string $method, Throwable $e, array $context = []): void
    {
        if (!class_exists('fs_core_log')) {
            return;
        }

        $log = new fs_core_log(self::class);
        $log->error('LegacyAuthBridge exception in ' . $method . ': ' . $e->getMessage(), array_merge($context, [
            'component' => self::class,
            'trace' => $e->getTraceAsString(),
        ]));
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
