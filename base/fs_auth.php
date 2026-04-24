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

require_once __DIR__ . '/fs_session_manager.php';
require_once __DIR__ . '/fs_core_log.php';

use FSFramework\Security\LegacyAuthBridge;
use FSFramework\Security\LegacyUserService;
use FSFramework\Security\PasswordHasherService;
use FSFramework\Security\SafeRedirect;
use FSFramework\Security\SessionManager;

/**
 * Servicio de autenticación unificado
 *
 * Usa fs_user como modelo único de usuario para mantener compatibilidad
 * con el sistema legacy mientras proporciona una API moderna.
 *
 * Uso:
 *   fs_auth::check();           // Verifica sesión
 *   fs_auth::user();            // Devuelve fs_user
 *   fs_auth::isAdmin();         // Verifica admin
 *   fs_auth::can('ventas_clientes'); // Verifica permiso
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class fs_auth
{
    private const ADMIN_HOME_URL = 'index.php?page=admin_home';
    private const LEGACY_SAMESITE_PATH = '/; SameSite=Lax';

    /**
     * Usuario actual cacheado
     * @var fs_user|null
     */
    private static $currentUser = null;

    /**
     * Verifica si hay un usuario autenticado
     *
     * @return bool
     */
    public static function check()
    {
        return fs_session_manager::isLoggedIn() && fs_session_manager::isValid();
    }

    /**
     * Verifica si hay un usuario autenticado (alias de check)
     *
     * @return bool
     */
    public static function isAuthenticated()
    {
        return self::check();
    }

    /**
     * Obtiene el usuario actual (fs_user)
     *
     * @return fs_user|null
     */
    public static function user()
    {
        if (!self::check()) {
            self::$currentUser = null;
            return null;
        }

        // Usar caché si ya tenemos el usuario
        $nick = fs_session_manager::getCurrentUserNick();
        if (self::$currentUser !== null && self::$currentUser->nick === $nick) {
            return self::$currentUser;
        }

        self::$currentUser = self::getLegacyUserService()->findByNick($nick);
        if (self::$currentUser) {
            self::$currentUser->logged_on = true;
        }

        return self::$currentUser;
    }

    /**
     * Obtiene el nick del usuario actual
     *
     * @return string|null
     */
    public static function nick()
    {
        return fs_session_manager::getCurrentUserNick();
    }

    /**
     * Obtiene el email del usuario actual
     *
     * @return string|null
     */
    public static function email()
    {
        $user = self::user();
        return $user ? $user->email : null;
    }

    /**
     * Obtiene el rol del usuario actual
     *
     * @return string
     */
    public static function role()
    {
        $user = self::user();
        if ($user && $user->admin) {
            return 'admin';
        }
        return fs_session_manager::getCurrentRole();
    }

    /**
     * Verifica si el usuario es administrador
     *
     * @return bool
     */
    public static function isAdmin()
    {
        $user = self::user();
        return $user ? (bool) $user->admin : false;
    }

    /**
     * Intenta autenticar un usuario
     * Usa el sistema de verificación de fs_user
     *
     * @param string $nick Nick del usuario
     * @param string $password Contraseña
     * @return bool
     */
    public static function attempt($nick, $password)
    {
        $authenticated = false;

        $user = self::getLegacyUserService()->findEnabledByNick((string) $nick);
        if ($user && self::isPasswordValid($user, $password)) {
            self::completeLogin($user);
            $authenticated = true;
        }

        return $authenticated;
    }

    /**
     * @param fs_user $user
     */
    private static function isPasswordValid($user, string $password): bool
    {
        $storedHash = (string) $user->password;
        if (self::isLowercasedLegacySha1Bypass($storedHash, $password)) {
            return false;
        }

        $hasher = new PasswordHasherService();
        if (!$hasher->verifyAndMigrate($storedHash, $password)) {
            return false;
        }

        if ($storedHash !== (string) $user->password) {
            // Solo migrar el hash si la contraseña cumple requisitos de longitud
            if (mb_strlen($password) >= 8 && mb_strlen($password) <= 32) {
                if (method_exists($user, 'set_password')) {
                    if ($user->set_password($password) !== false) {
                        $user->save();
                    }
                } else {
                    $user->password = $storedHash;
                    $user->save();
                }
            }
        }

        return true;
    }

    private static function isLowercasedLegacySha1Bypass(string $storedHash, string $password): bool
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $storedHash)) {
            return false;
        }

        $exactSha1 = sha1($password);
        if (hash_equals($storedHash, $exactSha1)) {
            return false;
        }

        return hash_equals($storedHash, sha1(mb_strtolower($password, 'UTF8')));
    }

    /**
     * @param fs_user $user
     */
    private static function completeLogin($user): void
    {
        if (method_exists($user, 'new_logkey')) {
            $user->new_logkey();
            $user->save();
        }

        fs_session_manager::login([
            'nick' => $user->nick,
            'email' => isset($user->email) ? $user->email : null,
            'role' => $user->admin ? 'admin' : 'user',
            'admin' => (bool) $user->admin,
            'logkey' => $user->log_key
        ]);

        self::setLegacyCookies($user);
        self::$currentUser = $user;
    }

    /**
     * Cierra la sesión
     *
     * @return void
     */
    public static function logout()
    {
        self::$currentUser = null;
        fs_session_manager::logout();
        self::clearLegacyCookies();
    }

    /**
     * Verifica si el usuario tiene acceso a una página
     *
     * @param string $pageName Nombre de la página
     * @return bool
     */
    public static function can($pageName)
    {
        $user = self::user();
        if (!$user) {
            return false;
        }

        return self::getLegacyUserService()->canAccess($user, (string) $pageName);
    }

    /**
     * Verifica si el usuario puede eliminar en una página
     *
     * @param string $pageName Nombre de la página
     * @return bool
     */
    public static function canDelete($pageName)
    {
        $user = self::user();
        if (!$user) {
            return false;
        }

        return self::getLegacyUserService()->canDelete($user, (string) $pageName);
    }

    /**
     * Verifica si el usuario tiene alguno de los permisos
     *
     * @param array $pageNames Array de nombres de página
     * @return bool
     */
    public static function canAny($pageNames)
    {
        foreach ($pageNames as $pageName) {
            if (self::can($pageName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica si el usuario tiene todos los permisos
     *
     * @param array $pageNames Array de nombres de página
     * @return bool
     */
    public static function canAll($pageNames)
    {
        foreach ($pageNames as $pageName) {
            if (!self::can($pageName)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Redirige si no está autenticado
     *
     * @param string $redirectTo URL de redirección
     * @return void
     */
    public static function requireAuth($redirectTo = 'index.php?page=login')
    {
        if (!self::check()) {
            $safeUrl = SafeRedirect::validate($redirectTo, 'index.php?page=login');
            header("Location: {$safeUrl}");
            exit;
        }
    }

    /**
     * Redirige si no tiene permiso a una página
     *
     * @param string $pageName Nombre de la página
     * @param string $redirectTo URL de redirección
     * @return void
     */
    public static function requirePermission($pageName, $redirectTo = self::ADMIN_HOME_URL)
    {
        self::requireAuth();

        if (!self::can($pageName)) {
            $safeUrl = SafeRedirect::validate($redirectTo, self::ADMIN_HOME_URL);
            header("Location: {$safeUrl}");
            exit;
        }
    }

    /**
     * Redirige si no es administrador
     *
     * @param string $redirectTo URL de redirección
     * @return void
     */
    public static function requireAdmin($redirectTo = self::ADMIN_HOME_URL)
    {
        self::requireAuth();

        if (!self::isAdmin()) {
            $safeUrl = SafeRedirect::validate($redirectTo, self::ADMIN_HOME_URL);
            header("Location: {$safeUrl}");
            exit;
        }
    }

    /**
     * Obtiene la fecha de inicio de sesión
     *
     * @return int|null Timestamp
     */
    public static function loginTime()
    {
        return fs_session_manager::get('login_time');
    }

    /**
     * Obtiene el tiempo de sesión en segundos
     *
     * @return int
     */
    public static function sessionDuration()
    {
        $loginTime = self::loginTime();
        if (!$loginTime) {
            return 0;
        }
        return time() - $loginTime;
    }

    /**
     * Guarda la URL actual para redirigir después del login
     *
     * @param string $url URL a guardar
     * @return void
     */
    public static function setIntendedUrl($url)
    {
        fs_session_manager::set('_intended_url', $url);
    }

    /**
     * Obtiene la URL guardada para redirigir después del login
     *
     * @param string $default URL por defecto
     * @return string
     */
    public static function getIntendedUrl($default = self::ADMIN_HOME_URL)
    {
        $url = fs_session_manager::get('_intended_url', $default);
        fs_session_manager::remove('_intended_url');
        return $url;
    }

    /**
     * Autentica y redirige
     *
     * @param string $nick Nick del usuario
     * @param string $password Contraseña
     * @param string $redirectTo URL de redirección
     * @return bool False si la autenticación falla
     */
    public static function authenticateAndRedirect($nick, $password, $redirectTo = self::ADMIN_HOME_URL)
    {
        if (self::attempt($nick, $password)) {
            $redirect = self::getIntendedUrl($redirectTo);
            $safeUrl = SafeRedirect::validate($redirect, self::ADMIN_HOME_URL);
            header("Location: {$safeUrl}");
            exit;
        }
        return false;
    }

    /**
     * Obtiene el token CSRF actual
     *
     * @return string
     */
    public static function csrfToken()
    {
        return fs_session_manager::getCsrfToken();
    }

    /**
     * Verifica un token CSRF
     *
     * @param string $token Token a verificar
     * @return bool
     */
    public static function verifyCsrf($token)
    {
        return fs_session_manager::verifyCsrfToken($token);
    }

    /**
     * Verifica el token CSRF de la petición actual
     *
     * @return bool
     */
    public static function verifyCsrfRequest()
    {
        $token = isset($_POST['_token']) ? $_POST['_token'] : '';
        if ($token === '') {
            $token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
        }

        return self::verifyCsrf($token);
    }

    /**
     * Establece cookies legacy para compatibilidad con fs_login
     *
     * @param fs_user $user Usuario
     * @return void
     */
    private static function setLegacyCookies($user)
    {
        $logKey = isset($user->log_key) ? trim((string) $user->log_key) : '';
        if ($logKey === '') {
            self::logLegacyCookieSkipped($user);
            return;
        }

        $legacyAuthBridge = self::getLegacyAuthBridge();
        if ($legacyAuthBridge !== null) {
            $legacyAuthBridge->issueLegacyCookies((string) $user->nick, $logKey);
            return;
        }

        $rememberMe = (bool) fs_session_manager::get('remember_me', true);
        $expire = class_exists('FSFramework\\Security\\SessionPolicy')
            ? \FSFramework\Security\SessionPolicy::cookieExpireFor($rememberMe)
            : time() + (defined('FS_COOKIES_EXPIRE') ? FS_COOKIES_EXPIRE : 31536000);
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        $signature = \FSFramework\Security\CookieSigner::signRememberMe((string) $user->nick, $logKey);
        setcookie('user', $user->nick, $expire, self::LEGACY_SAMESITE_PATH, '', $secure, true);
        setcookie('logkey', $logKey, $expire, self::LEGACY_SAMESITE_PATH, '', $secure, true);
        setcookie('auth_sig', $signature, $expire, self::LEGACY_SAMESITE_PATH, '', $secure, true);
    }

    /**
     * Limpia las cookies legacy
     *
     * @return void
     */
    private static function clearLegacyCookies()
    {
        $legacyAuthBridge = self::getLegacyAuthBridge();
        if ($legacyAuthBridge !== null) {
            $legacyAuthBridge->clearLegacyCookies();
            return;
        }

        $expire = time() - 3600;
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        setcookie('user', '', $expire, self::LEGACY_SAMESITE_PATH, '', $secure, true);
        setcookie('logkey', '', $expire, self::LEGACY_SAMESITE_PATH, '', $secure, true);
        setcookie('auth_sig', '', $expire, self::LEGACY_SAMESITE_PATH, '', $secure, true);
    }

    private static function getLegacyAuthBridge(): ?LegacyAuthBridge
    {
        if (!class_exists(SessionManager::class)) {
            return null;
        }

        return SessionManager::getInstance()->getLegacyAuthBridge();
    }

    private static function getLegacyUserService(): LegacyUserService
    {
        return new LegacyUserService();
    }

    /**
     * @param mixed $user
     */
    private static function logLegacyCookieSkipped($user): void
    {
        $nick = is_object($user) && isset($user->nick) ? (string) $user->nick : '';
        $log = new fs_core_log(__CLASS__);
        $log->alert('No se emitieron cookies legacy porque falta log_key.', ['nick' => $nick]);
    }

    /**
     * Limpia la caché del usuario actual
     *
     * @return void
     */
    public static function clearCache()
    {
        self::$currentUser = null;
    }

    /**
     * Obtiene el ID del agente asociado al usuario (si existe)
     *
     * @return string|null
     */
    public static function agentId()
    {
        $user = self::user();
        return $user && isset($user->codagente) ? $user->codagente : null;
    }

    /**
     * Verifica si el usuario tiene un agente asociado
     *
     * @return bool
     */
    public static function hasAgent()
    {
        return self::agentId() !== null;
    }
}
