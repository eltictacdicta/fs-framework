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

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;

/**
 * Gestor de sesiones moderno usando Symfony HttpFoundation
 *
 * Proporciona una API moderna sobre Symfony Session mientras mantiene
 * compatibilidad con el sistema legacy (cookies user/logkey).
 *
 * Características:
 * - Usa Symfony Session internamente
 * - CSRF token automático
 * - Regeneración periódica de ID
 * - Cookies seguras (SameSite, httponly)
 * - Mensajes flash nativos de Symfony
 * - Compatibilidad con cookies legacy
 *
 * Uso:
 *   $session = SessionManager::getInstance();
 *   $session->set('key', 'value');
 *   $value = $session->get('key');
 *   $session->flash('success', 'Operación completada');
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class SessionManager
{
    private static ?SessionManager $instance = null;
    private Session $session;
    private bool $initialized = false;
    private LegacyAuthBridge $legacyAuthBridge;

    /**
     * Constructor privado (singleton)
     */
    private function __construct()
    {
        $this->initialize();
        $this->legacyAuthBridge = new LegacyAuthBridge($this->session);
    }

    /**
     * Obtiene la instancia singleton
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa la sesión con Symfony
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Si ya hay una sesión PHP activa, usarla
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->session = new Session();
            $this->initialized = true;
            return;
        }

        if (headers_sent()) {
            $this->session = new Session();
            $this->initialized = true;
            return;
        }

        // Configurar storage con opciones seguras
        $lifetime = defined('FS_SESSION_LIFETIME') ? FS_SESSION_LIFETIME : 7200;
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        $sessionName = defined('FS_SESSION_NAME') ? FS_SESSION_NAME : 'FSSESSION';

        $options = [
            'name' => $sessionName,
            'cookie_lifetime' => $lifetime,
            'cookie_path' => '/',
            'cookie_secure' => $secure,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'gc_maxlifetime' => $lifetime,
            'use_strict_mode' => true,
        ];

        // Crear storage con handler nativo
        $handler = null;
        if (defined('FS_SESSION_SAVE_PATH') && FS_SESSION_SAVE_PATH) {
            $handler = new NativeFileSessionHandler(FS_SESSION_SAVE_PATH);
        }

        $storage = new NativeSessionStorage($options, $handler);

        $this->session = new Session($storage, new AttributeBag(), new FlashBag());
        $this->session->start();

        // Regenerar ID periódicamente
        $this->maybeRegenerateId();

        $this->initialized = true;
    }

    /**
     * Regenera el ID de sesión si ha pasado suficiente tiempo
     */
    private function maybeRegenerateId(): void
    {
        $lastRegen = $this->session->get('_last_regeneration', 0);

        if (time() - $lastRegen > 1800) { // 30 minutos
            $this->regenerateId();
        }
    }

    /**
     * Regenera el ID de sesión
     */
    public function regenerateId(): void
    {
        $this->session->migrate(true);
        $this->session->set('_last_regeneration', time());
    }

    /**
     * Verifica si hay una sesión de usuario activa
     */
    public function isLoggedIn(): bool
    {
        if ($this->session->has('user_nick') && $this->session->get('user_nick')) {
            return true;
        }

        $legacyUserData = $this->legacyAuthBridge->getLegacyUserDataFromCookies();
        if ($legacyUserData === null) {
            return false;
        }

        $this->login($legacyUserData);
        return true;
    }

    /**
     * Inicia sesión para un usuario
     */
    public function login(array $userData): void
    {
        $this->regenerateId();

        $this->session->set('user_nick', $userData['nick']);
        $this->session->set('user_email', $userData['email'] ?? null);
        $this->session->set('user_role', ($userData['admin'] ?? false) ? 'admin' : 'user');
        $this->session->set('user_admin', (bool) ($userData['admin'] ?? false));
        $this->session->set('login_time', time());
        $this->session->set('user_logkey', $userData['logkey'] ?? null);
        $this->session->set('user_logged_in', true);
    }

    /**
     * Cierra la sesión
     */
    public function logout(): void
    {
        $this->session->invalidate();

        // Limpiar cookies legacy
        $this->legacyAuthBridge->clearLegacyCookies();
    }

    // =========================================================================
    // API de sesión
    // =========================================================================

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

    public function has(string $key): bool
    {
        return $this->session->has($key);
    }

    public function remove(string $key): void
    {
        $this->session->remove($key);
    }

    public function all(): array
    {
        return $this->session->all();
    }

    // =========================================================================
    // Flash messages (usando FlashBag de Symfony)
    // =========================================================================

    public function flash(string $type, string $message): void
    {
        $this->session->getFlashBag()->add($type, $message);
    }

    public function getFlashes(string $type = null): array
    {
        if ($type) {
            return $this->session->getFlashBag()->get($type, []);
        }
        return $this->session->getFlashBag()->all();
    }

    public function hasFlashes(string $type = null): bool
    {
        if ($type) {
            return $this->session->getFlashBag()->has($type);
        }
        return count($this->session->getFlashBag()->peekAll()) > 0;
    }

    // =========================================================================
    // CSRF
    // =========================================================================

    public function getCsrfToken(): string
    {
        return CsrfManager::generateToken();
    }

    public function verifyCsrfToken(string $token): bool
    {
        return $token !== '' && CsrfManager::isValid($token);
    }

    public function csrfField(): string
    {
        $safeToken = htmlspecialchars($this->getCsrfToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf_token" value="' . $safeToken . '">'
             . '<input type="hidden" name="_token" value="' . $safeToken . '">';
    }

    public function csrfMeta(): string
    {
        return CsrfManager::metaTag();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function getCurrentUserNick(): ?string
    {
        return $this->session->get('user_nick');
    }

    public function getCurrentRole(): string
    {
        return $this->session->get('user_role', 'guest');
    }

    public function isAdmin(): bool
    {
        return $this->session->get('user_admin', false) === true;
    }

    public function isValid(): bool
    {
        if (!$this->session->get('user_nick')) {
            return false;
        }

        $maxLifetime = defined('FS_SESSION_LIFETIME') ? FS_SESSION_LIFETIME : 7200;
        $loginTime = $this->session->get('login_time', 0);

        return (time() - $loginTime) <= $maxLifetime;
    }

    /**
     * Obtiene la sesión Symfony subyacente
     */
    public function getSymfonySession(): Session
    {
        return $this->session;
    }

    public function getLegacyAuthBridge(): LegacyAuthBridge
    {
        return $this->legacyAuthBridge;
    }

    /**
     * Previene clonación
     */
    private function __clone() {}
}
