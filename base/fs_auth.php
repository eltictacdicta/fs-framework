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

        // Cargar usuario desde BD
        self::loadDependencies();
        
        if (class_exists('fs_user')) {
            $userModel = new fs_user();
            $user = $userModel->get($nick);
            self::$currentUser = $user ? $user : null;
            
            if (self::$currentUser) {
                self::$currentUser->logged_on = true;
            }
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
        self::loadDependencies();
        
        if (!class_exists('fs_user')) {
            return false;
        }

        $userModel = new fs_user();
        $user = $userModel->get($nick);

        if (!$user || !$user->enabled) {
            return false;
        }

        // Verificar contraseña (soporta Argon2ID, bcrypt y SHA1 legacy)
        $passwordValid = false;
        
        // Primero intentar con password_verify (Argon2ID, bcrypt)
        if (function_exists('password_verify') && password_verify($password, $user->password)) {
            $passwordValid = true;
        } 
        // Fallback a SHA1 legacy
        elseif ($user->password === sha1($password) || $user->password === sha1(mb_strtolower($password, 'UTF8'))) {
            $passwordValid = true;
            // Actualizar a hash seguro si el método existe
            if (method_exists($user, 'set_password')) {
                $user->set_password($password);
                $user->save();
            }
        }

        if (!$passwordValid) {
            return false;
        }

        // Generar nueva clave de sesión
        if (method_exists($user, 'new_logkey')) {
            $user->new_logkey();
            $user->save();
        }

        // Guardar en sesión
        fs_session_manager::login([
            'nick' => $user->nick,
            'email' => isset($user->email) ? $user->email : null,
            'role' => $user->admin ? 'admin' : 'user',
            'admin' => (bool) $user->admin,
            'logkey' => $user->log_key
        ]);

        // Guardar cookies legacy para compatibilidad
        self::setLegacyCookies($user);

        self::$currentUser = $user;
        return true;
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

        // Los administradores tienen todos los permisos
        if ($user->admin) {
            return true;
        }

        // Usar el método have_access_to de fs_user
        if (method_exists($user, 'have_access_to')) {
            return $user->have_access_to($pageName);
        }

        return false;
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

        if (method_exists($user, 'allow_delete_on')) {
            return $user->allow_delete_on($pageName);
        }

        return $user->admin;
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
            header("Location: {$redirectTo}");
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
            header("Location: {$redirectTo}");
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
            header("Location: {$redirectTo}");
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
            header("Location: {$redirect}");
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
        $token = isset($_POST['_token']) ? $_POST['_token'] : 
                 (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
        return self::verifyCsrf($token);
    }

    /**
     * Carga las dependencias necesarias para fs_user
     * 
     * @return void
     */
    private static function loadDependencies()
    {
        $folder = defined('FS_FOLDER') ? FS_FOLDER : '.';

        // Cargar dependencias en orden
        $dependencies = [
            'fs_cache' => $folder . '/base/fs_cache.php',
            'fs_core_log' => $folder . '/base/fs_core_log.php',
            'fs_db2' => $folder . '/base/fs_db2.php',
            'fs_model' => $folder . '/base/fs_model.php',
            'fs_page' => $folder . '/model/core/fs_page.php',
            'fs_access' => $folder . '/model/core/fs_access.php',
            'fs_user' => $folder . '/model/core/fs_user.php',
        ];

        foreach ($dependencies as $class => $path) {
            if (!class_exists($class) && file_exists($path)) {
                require_once $path;
            }
        }
    }

    /**
     * Establece cookies legacy para compatibilidad con fs_login
     * 
     * @param fs_user $user Usuario
     * @return void
     */
    private static function setLegacyCookies($user)
    {
        $expire = time() + (defined('FS_COOKIES_EXPIRE') ? FS_COOKIES_EXPIRE : 31536000);
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        
        if (PHP_VERSION_ID >= 70300) {
            setcookie('user', $user->nick, [
                'expires' => $expire,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            setcookie('logkey', $user->log_key, [
                'expires' => $expire,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        } else {
            setcookie('user', $user->nick, $expire, self::LEGACY_SAMESITE_PATH, '', $secure, true);
            setcookie('logkey', $user->log_key, $expire, self::LEGACY_SAMESITE_PATH, '', $secure, true);
        }
    }

    /**
     * Limpia las cookies legacy
     * 
     * @return void
     */
    private static function clearLegacyCookies()
    {
        $expire = time() - 3600;
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        
        if (PHP_VERSION_ID >= 70300) {
            setcookie('user', '', [
                'expires' => $expire,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            setcookie('logkey', '', [
                'expires' => $expire,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        } else {
            setcookie('user', '', $expire, self::LEGACY_SAMESITE_PATH, '', $secure, true);
            setcookie('logkey', '', $expire, self::LEGACY_SAMESITE_PATH, '', $secure, true);
        }
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
