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

use FSFramework\Security\SessionManager;

/**
 * Gestiona sesiones de usuario de forma segura
 * 
 * Este es el único punto de gestión de sesiones PHP.
 * Compatible con el sistema legacy (cookies user/logkey).
 * 
 * NOTA: Esta clase delega a FSFramework\Security\SessionManager (Symfony)
 * cuando está disponible, manteniendo compatibilidad con código legacy.
 * 
 * Características:
 * - CSRF token automático
 * - Regeneración periódica de ID de sesión
 * - Cookies seguras (SameSite, httponly)
 * - Compatibilidad transparente con cookies legacy
 * - Mensajes flash
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class fs_session_manager
{
    /**
     * @var bool
     */
    private static $initialized = false;
    
    /**
     * @var string|null
     */
    private static $csrfToken = null;

    /**
     * @var bool Si usar el SessionManager moderno (Symfony)
     */
    private static $useModern = null;

    /**
     * Verifica si el SessionManager moderno está disponible
     * 
     * @return bool
     */
    private static function canUseModern(): bool
    {
        if (self::$useModern === null) {
            self::$useModern = class_exists('FSFramework\\Security\\SessionManager');
        }
        return self::$useModern;
    }

    /**
     * Obtiene la instancia del SessionManager moderno
     * 
     * @return SessionManager|null
     */
    private static function getModern(): ?SessionManager
    {
        if (self::canUseModern()) {
            return SessionManager::getInstance();
        }
        return null;
    }

    /**
     * Inicializa el sistema de sesiones
     * 
     * @return void
     */
    public static function initialize()
    {
        // Si el moderno está disponible, usarlo
        if (self::canUseModern()) {
            $modern = self::getModern();
            self::$csrfToken = $modern->getCsrfToken();
            self::$initialized = true;
            return;
        }

        // Fallback a implementación legacy
        if (self::$initialized || session_status() === PHP_SESSION_ACTIVE) {
            self::$initialized = true;
            self::$csrfToken = isset($_SESSION['_csrf_token']) ? $_SESSION['_csrf_token'] : null;
            return;
        }

        if (headers_sent()) {
            self::$initialized = true;
            return;
        }

        // Configuración de cookies segura
        $lifetime = defined('FS_SESSION_LIFETIME') ? FS_SESSION_LIFETIME : 7200;
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        
        // PHP 7.3+ soporta SameSite en session_set_cookie_params con array
        if (PHP_VERSION_ID >= 70300) {
            $cookieParams = [
                'lifetime' => $lifetime,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ];
            session_set_cookie_params($cookieParams);
        } else {
            // PHP < 7.3 - configuración tradicional
            session_set_cookie_params($lifetime, '/; SameSite=Lax', '', $secure, true);
        }
        
        $sessionName = defined('FS_SESSION_NAME') ? FS_SESSION_NAME : 'FSSESSION';
        session_name($sessionName);

        if (!session_start()) {
            trigger_error('fs_session_manager: No se pudo iniciar la sesión', E_USER_WARNING);
            self::$initialized = true;
            return;
        }

        // Regenerar ID periódicamente (cada 30 minutos)
        if (!isset($_SESSION['_last_regeneration'])) {
            $_SESSION['_last_regeneration'] = time();
        } elseif (time() - $_SESSION['_last_regeneration'] > 1800) {
            self::regenerateId();
        }

        // Inicializar CSRF token
        if (!isset($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = self::generateToken();
        }
        self::$csrfToken = $_SESSION['_csrf_token'];

        self::$initialized = true;
    }

    /**
     * Genera un token seguro
     * 
     * @param int $length Longitud en bytes
     * @return string
     */
    private static function generateToken($length = 32)
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($length));
        }
        // Fallback para PHP < 7.0
        return bin2hex(openssl_random_pseudo_bytes($length));
    }

    /**
     * Verifica si hay una sesión activa
     * Compatible con sistema legacy (cookies user/logkey)
     * 
     * @return bool
     */
    public static function isLoggedIn()
    {
        self::initialize();
        
        // Usar moderno si está disponible
        if (self::canUseModern()) {
            return self::getModern()->isLoggedIn();
        }
        
        // Verificar sesión PHP
        if (isset($_SESSION['user_nick']) && !empty($_SESSION['user_nick'])) {
            return true;
        }
        
        // Verificar cookies legacy y sincronizar si es válido
        $cookieUser = isset($_COOKIE['user']) ? filter_var($_COOKIE['user'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) : null;
        $cookieLogkey = isset($_COOKIE['logkey']) ? filter_var($_COOKIE['logkey'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) : null;
        
        if ($cookieUser && $cookieLogkey) {
            return self::syncFromLegacyCookies($cookieUser, $cookieLogkey);
        }
        
        return false;
    }

    /**
     * Sincroniza sesión desde cookies legacy
     * 
     * @param string $nick
     * @param string $logkey
     * @return bool
     */
    private static function syncFromLegacyCookies($nick, $logkey)
    {
        $cookieSig = isset($_COOKIE['auth_sig']) ? (string) $_COOKIE['auth_sig'] : '';

        // Si ya está sincronizado, retornar true
        if (isset($_SESSION['user_nick']) && $_SESSION['user_nick'] === $nick) {
            return true;
        }

        // Cargar fs_user y validar
        self::loadUserDependencies();
        
        if (!class_exists('fs_user')) {
            return false;
        }

        try {
            $userModel = new fs_user();
            $user = $userModel->get($nick);
            
            if ($user && $user->enabled && $user->log_key === $logkey) {
                if (!empty($cookieSig) && !\FSFramework\Security\CookieSigner::verifyRememberMe((string) $nick, (string) $logkey, $cookieSig)) {
                    return false;
                }

                // Sincronizar sesión
                $_SESSION['user_nick'] = $user->nick;
                $_SESSION['user_email'] = isset($user->email) ? $user->email : null;
                $_SESSION['user_role'] = $user->admin ? 'admin' : 'user';
                $_SESSION['user_admin'] = (bool) $user->admin;
                $_SESSION['login_time'] = time();
                $_SESSION['user_logkey'] = $logkey;
                $_SESSION['user_logged_in'] = true;
                
                return true;
            }
        } catch (Exception $e) {
            error_log('fs_session_manager: Error sincronizando sesión legacy: ' . $e->getMessage());
        }
        
        return false;
    }

    /**
     * Carga dependencias para fs_user
     * 
     * @return void
     */
    private static function loadUserDependencies()
    {
        $folder = defined('FS_FOLDER') ? FS_FOLDER : '.';
        
        if (class_exists('fs_user')) {
            return;
        }

        $deps = [
            'fs_cache' => '/base/fs_cache.php',
            'fs_core_log' => '/base/fs_core_log.php',
            'fs_db2' => '/base/fs_db2.php',
            'fs_model' => '/base/fs_model.php',
            'fs_page' => '/model/core/fs_page.php',
            'fs_access' => '/model/core/fs_access.php',
            'fs_user' => '/model/core/fs_user.php',
        ];

        foreach ($deps as $class => $path) {
            if (!class_exists($class)) {
                $fullPath = $folder . $path;
                if (file_exists($fullPath)) {
                    require_once $fullPath;
                }
            }
        }
    }

    /**
     * Obtiene el nick del usuario actual
     * 
     * @return string|null
     */
    public static function getCurrentUserNick()
    {
        self::initialize();
        return isset($_SESSION['user_nick']) ? $_SESSION['user_nick'] : null;
    }

    /**
     * Obtiene el rol del usuario actual
     * 
     * @return string
     */
    public static function getCurrentRole()
    {
        self::initialize();
        return isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'guest';
    }

    /**
     * Verifica si el usuario es administrador
     * 
     * @return bool
     */
    public static function isAdmin()
    {
        self::initialize();
        return isset($_SESSION['user_admin']) && $_SESSION['user_admin'] === true;
    }

    /**
     * Verifica si la sesión es válida (no expirada)
     * 
     * @return bool
     */
    public static function isValid()
    {
        self::initialize();
        
        if (!isset($_SESSION['user_nick']) || empty($_SESSION['user_nick'])) {
            return false;
        }
        
        // Verificar tiempo de sesión
        $maxLifetime = defined('FS_SESSION_LIFETIME') ? FS_SESSION_LIFETIME : 7200;
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $maxLifetime) {
            return false;
        }
        
        return true;
    }

    /**
     * Inicia sesión para un usuario
     * 
     * @param array $userData Array con nick, email, role, admin, logkey
     * @return void
     */
    public static function login($userData)
    {
        self::initialize();
        
        // Usar moderno si está disponible
        if (self::canUseModern()) {
            self::getModern()->login($userData);
            return;
        }
        
        self::regenerateId();
        
        $_SESSION['user_nick'] = $userData['nick'];
        $_SESSION['user_email'] = isset($userData['email']) ? $userData['email'] : null;
        $_SESSION['user_role'] = isset($userData['role']) ? $userData['role'] : 'user';
        $_SESSION['user_admin'] = isset($userData['admin']) ? (bool) $userData['admin'] : false;
        $_SESSION['login_time'] = time();
        $_SESSION['user_logkey'] = isset($userData['logkey']) ? $userData['logkey'] : null;
        $_SESSION['user_logged_in'] = true;
    }

    /**
     * Cierra la sesión
     * 
     * @return void
     */
    public static function logout()
    {
        self::initialize();
        
        // Usar moderno si está disponible
        if (self::canUseModern()) {
            self::getModern()->logout();
            self::$initialized = false;
            self::$csrfToken = null;
            return;
        }
        
        $_SESSION = [];
        
        $sessionName = session_name();
        if (isset($_COOKIE[$sessionName])) {
            $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            
            if (PHP_VERSION_ID >= 70300) {
                setcookie($sessionName, '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            } else {
                setcookie($sessionName, '', time() - 3600, '/; SameSite=Lax', '', $secure, true);
            }
        }
        
        session_destroy();
        self::$initialized = false;
        self::$csrfToken = null;
    }

    /**
     * Obtiene el token CSRF
     * 
     * @return string
     */
    public static function getCsrfToken()
    {
        self::initialize();
        return self::$csrfToken !== null ? self::$csrfToken : '';
    }

    /**
     * Verifica un token CSRF
     * 
     * @param string $token Token a verificar
     * @return bool
     */
    public static function verifyCsrfToken($token)
    {
        self::initialize();
        if (self::$csrfToken === null || $token === '') {
            return false;
        }
        return hash_equals(self::$csrfToken, $token);
    }

    /**
     * Genera un campo CSRF para formularios HTML
     * 
     * @return string HTML del campo hidden
     */
    public static function csrfField()
    {
        $token = self::getCsrfToken();
        return '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Genera meta tag CSRF para AJAX
     * 
     * @return string HTML del meta tag
     */
    public static function csrfMeta()
    {
        $token = self::getCsrfToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Almacena un mensaje flash
     * 
     * @param string $key Clave del mensaje
     * @param mixed $value Valor del mensaje
     * @return void
     */
    public static function flash($key, $value)
    {
        self::initialize();
        if (!isset($_SESSION['_flash'])) {
            $_SESSION['_flash'] = [];
        }
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Obtiene y elimina un mensaje flash
     * 
     * @param string $key Clave del mensaje
     * @param mixed $default Valor por defecto
     * @return mixed
     */
    public static function getFlash($key, $default = null)
    {
        self::initialize();
        $value = isset($_SESSION['_flash'][$key]) ? $_SESSION['_flash'][$key] : $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    /**
     * Verifica si existe un mensaje flash
     * 
     * @param string $key Clave del mensaje
     * @return bool
     */
    public static function hasFlash($key)
    {
        self::initialize();
        return isset($_SESSION['_flash'][$key]);
    }

    /**
     * Regenera el ID de sesión
     * 
     * @return void
     */
    public static function regenerateId()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_last_regeneration'] = time();
        }
    }

    /**
     * Obtiene todos los datos de sesión
     * 
     * @return array
     */
    public static function all()
    {
        self::initialize();
        return $_SESSION;
    }

    /**
     * Establece un valor en la sesión
     * 
     * @param string $key Clave
     * @param mixed $value Valor
     * @return void
     */
    public static function set($key, $value)
    {
        self::initialize();
        $_SESSION[$key] = $value;
    }

    /**
     * Obtiene un valor de la sesión
     * 
     * @param string $key Clave
     * @param mixed $default Valor por defecto
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        self::initialize();
        return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;
    }

    /**
     * Elimina un valor de la sesión
     * 
     * @param string $key Clave a eliminar
     * @return void
     */
    public static function remove($key)
    {
        self::initialize();
        unset($_SESSION[$key]);
    }

    /**
     * Verifica si existe una clave en la sesión
     * 
     * @param string $key Clave a verificar
     * @return bool
     */
    public static function has($key)
    {
        self::initialize();
        return isset($_SESSION[$key]);
    }
}
