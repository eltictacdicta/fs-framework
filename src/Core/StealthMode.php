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

namespace FSFramework\Core;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gestiona el "modo stealth" del panel de administración.
 *
 * Cuando está activo, index.php muestra una homepage pública personalizada
 * y el acceso al backend requiere un parámetro secreto en la URL que
 * habilita una única entrada oculta al login legacy.
 *
 * Usa fs_db2 directamente (sin depender de fs_model/fs_var) para poder
 * ejecutarse en el gate temprano de index.php.
 */
class StealthMode
{
    private const VAR_ENABLED = 'stealth_enabled';
    private const VAR_PARAM_NAME = 'stealth_param_name';
    private const VAR_PARAM_VALUE = 'stealth_param_value';
    private const VAR_HOMEPAGE_HTML = 'stealth_homepage_html';
    private const VAR_CUSTOM_CSS = 'stealth_custom_css';
    private const VAR_PAGE_TITLE = 'stealth_page_title';
    private const SESSION_KEY = 'stealth_unlocked';
    private const DEFAULT_PARAM_NAME = 'adminpanel';
    private const TABLE = 'fs_vars';

    private ?array $settingsCache = null;

    public function __construct(private \fs_db2 $db = new \fs_db2())
    {
        $this->settingsCache = null;
    }

    public function isEnabled(): bool
    {
        return $this->getSetting(self::VAR_ENABLED) === '1';
    }

    /**
     * Comprueba si la petición actual tiene acceso al backend.
     * - Si el usuario ya está logueado (sesión de login existente) -> acceso.
     * - Si la petición es al login oculto y la sesión stealth lo permite -> acceso.
        * - La redirección desde entrada secreta se gestiona en consumeSecretEntryRedirect().
     * - En otro caso -> sin acceso.
     */
    public function hasAccess(): bool
    {
        if ($this->isUserLoggedIn()) {
            return true;
        }

        if ($this->isHiddenLoginAllowed()) {
            return true;
        }

        return false;
    }

    public function consumeSecretEntryRedirect(): ?RedirectResponse
    {
        if (!$this->isValidSecretRequest()) {
            return null;
        }

        if ($this->isHiddenLoginPageRequest() || $this->isHiddenLoginSubmission()) {
            return null;
        }

        $this->grantAccess();

        return $this->redirectToHiddenLogin();
    }

    public function createPublicHomepageResponse(): Response
    {
        $response = new Response($this->buildPublicHomepageHtml());
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $csp = \FSFramework\Security\SecurityHeaders::contentSecurityPolicy();
        if ($csp !== '') {
            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }

    public function buildPublicHomepageHtml(): string
    {
        $bodyContent = trim($this->getHomepageHtml());
        $customCss = trim($this->getCustomCss());

        if (empty($bodyContent)) {
            $bodyContent = $this->getDefaultBodyContent();
        }

        return $this->wrapInDocument($bodyContent, $customCss);
    }

    public function getParamName(): string
    {
        $name = $this->getSetting(self::VAR_PARAM_NAME);
        return !empty($name) ? $name : self::DEFAULT_PARAM_NAME;
    }

    public function getParamValue(): string
    {
        return (string) ($this->getSetting(self::VAR_PARAM_VALUE) ?? '');
    }

    public function getHomepageHtml(): string
    {
        return (string) ($this->getSetting(self::VAR_HOMEPAGE_HTML) ?? '');
    }

    public function getCustomCss(): string
    {
        return (string) ($this->getSetting(self::VAR_CUSTOM_CSS) ?? '');
    }

    public function getPageTitle(): string
    {
        $title = $this->getSetting(self::VAR_PAGE_TITLE);
        return !empty($title) ? $title : 'Bienvenido';
    }

    public function getAccessUrl(): string
    {
        $base = defined('FS_BASE_URL') ? FS_BASE_URL : '';
        $base = rtrim($base, '/');
        $paramValue = $this->getParamValue();
        if ($paramValue === '') {
            return '';
        }

        return $base . '/index.php?' . urlencode($this->getParamName()) . '=' . urlencode($paramValue);
    }

    public function getHiddenLoginUrl(): string
    {
        $base = defined('FS_PATH') ? rtrim((string) FS_PATH, '/') : '';
        $paramName = $this->getParamName();
        $paramValue = $this->getParamValue();

        return $base . '/index.php?page=login&' . urlencode($paramName) . '=' . urlencode($paramValue);
    }

    public function isPublicHomepageRequest(?string $uri = null): bool
    {
        if (Plugins::isEnabled('legacy_support') && class_exists('FSFramework\\Plugins\\legacy_support\\LegacyCompatibility')) {
            \FSFramework\Plugins\legacy_support\LegacyCompatibility::reportDeprecatedComponent(
                'legacy.stealth_mode',
                'isPublicHomepageRequest',
                'Plugins::isPublicPath()'
            );
        }

        $requestUri = $uri ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';
        $path = $path === '/' ? '/' : rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        $base = defined('FS_PATH') ? rtrim((string) FS_PATH, '/') : '';
        $allowedPaths = ['/'];
        if ($base !== '') {
            $allowedPaths[] = $base;
            $allowedPaths[] = $base . '/index.php';
        } else {
            $allowedPaths[] = '/index.php';
        }

        if (!in_array($path, $allowedPaths, true)) {
            return false;
        }

        return empty($_GET['page']);
    }

    public function hasAuthenticatedSession(): bool
    {
        return $this->isUserLoggedIn();
    }

    public function isLegacyLoginRequest(): bool
    {
        return $this->isHiddenLoginPageRequest();
    }

    public function isLegacyLoginSubmission(): bool
    {
        return $this->isHiddenLoginSubmission();
    }

    public function createLegacyLoginRedirectResponse(): RedirectResponse
    {
        $base = defined('FS_PATH') ? rtrim((string) FS_PATH, '/') : '';
        return new RedirectResponse($base . '/index.php?page=login');
    }

    // -- Métodos de gestión (usados por admin_stealth) --

    public function saveEnabled(bool $enabled): bool
    {
        $this->settingsCache = null;
        return $this->saveSetting(self::VAR_ENABLED, $enabled ? '1' : '0');
    }

    public function saveParamName(string $name): bool
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        if (empty($name)) {
            $name = self::DEFAULT_PARAM_NAME;
        }
        $this->settingsCache = null;
        return $this->saveSetting(self::VAR_PARAM_NAME, $name);
    }

    public function regenerateSecret(): string
    {
        $secret = bin2hex(random_bytes(16));
        if (!$this->saveSetting(self::VAR_PARAM_VALUE, $secret)) {
            return '';
        }

        $this->settingsCache = null;
        return $secret;
    }

    public function saveHomepageHtml(string $html): bool
    {
        $html = $this->sanitizeHtml($html);
        $this->settingsCache = null;
        return $this->saveSetting(self::VAR_HOMEPAGE_HTML, $html);
    }

    public function saveCustomCss(string $css): bool
    {
        $sanitizedCss = $this->sanitizeCss($css);
        if ($sanitizedCss === null) {
            return false;
        }

        $saved = $this->saveSetting(self::VAR_CUSTOM_CSS, $sanitizedCss);
        if ($saved) {
            $this->settingsCache = null;
        }

        return $saved;
    }

    public function savePageTitle(string $title): bool
    {
        $title = strip_tags($title);
        $this->settingsCache = null;
        return $this->saveSetting(self::VAR_PAGE_TITLE, $title);
    }

    /**
     * Comprueba si es una ruta OIDC/API que no debe bloquearse.
     */
    public function isExemptRoute(): bool
    {
        if (Plugins::isEnabled('legacy_support') && class_exists('FSFramework\\Plugins\\legacy_support\\LegacyCompatibility')) {
            \FSFramework\Plugins\legacy_support\LegacyCompatibility::reportDeprecatedComponent(
                'legacy.stealth_mode',
                'isExemptRoute',
                'Plugins::isPublicPath()'
            );
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($uri, PHP_URL_PATH) ?? '';
        return Plugins::isPublicPath($path);
    }

    // -- Acceso directo a BD via fs_db2 --

    private function getSetting(string $key): ?string
    {
        if ($this->settingsCache === null) {
            $this->loadAllSettings();
        }

        return $this->settingsCache[$key] ?? null;
    }

    private function loadAllSettings(): void
    {
        $this->settingsCache = [];

        if (!$this->db->connected() && !$this->db->connect()) {
            return;
        }

        $keys = [
            self::VAR_ENABLED,
            self::VAR_PARAM_NAME,
            self::VAR_PARAM_VALUE,
            self::VAR_HOMEPAGE_HTML,
            self::VAR_CUSTOM_CSS,
            self::VAR_PAGE_TITLE,
        ];

        $placeholders = [];
        foreach ($keys as $k) {
            $placeholders[] = $this->db->escape_string($k);
        }
        $inClause = "'" . implode("','", $placeholders) . "'";

        $q = Tools::config('db_type', 'mysql') === 'mysql' ? '`' : '"';
        $sql = "SELECT name, " . $q . "varchar" . $q . " FROM " . self::TABLE . " WHERE name IN (" . $inClause . ")";
        $data = $this->db->select($sql);

        if ($data) {
            foreach ($data as $row) {
                $this->settingsCache[$row['name']] = $row['varchar'];
            }
        }
    }

    private function saveSetting(string $key, string $value): bool
    {
        if (!$this->db->connected() && !$this->db->connect()) {
            return false;
        }

        $escapedKey = $this->db->escape_string($key);
        $escapedValue = $this->db->escape_string($value);

        $q = Tools::config('db_type', 'mysql') === 'mysql' ? '`' : '"';

        $exists = $this->db->select(
            "SELECT name FROM " . self::TABLE . " WHERE name = '" . $escapedKey . "'"
        );

        if ($exists) {
            $sql = "UPDATE " . self::TABLE . " SET " . $q . "varchar" . $q . " = '" . $escapedValue . "'"
                 . " WHERE name = '" . $escapedKey . "'";
        } else {
            $sql = "INSERT INTO " . self::TABLE . " (name, " . $q . "varchar" . $q . ")"
                 . " VALUES ('" . $escapedKey . "', '" . $escapedValue . "')";
        }

        return $this->db->exec($sql);
    }

    // -- Sesión --

    /**
     * Comprueba si hay una sesión de login activa (Symfony Session o cookies legacy validadas).
     */
    private function isUserLoggedIn(): bool
    {
        $this->ensurePhpSessionStarted();

        $sfAttrs = $_SESSION['_sf2_attributes'] ?? [];
        if (!empty($sfAttrs['user_logged_in']) && !empty($sfAttrs['user_nick'])) {
            return true;
        }

        if (!empty($_COOKIE['user']) && !empty($_COOKIE['logkey'])) {
            return $this->validateLegacyCookies(
                (string) $_COOKIE['user'],
                (string) $_COOKIE['logkey'],
                (string) ($_COOKIE['auth_sig'] ?? '')
            );
        }

        return false;
    }

    /**
     * Validates legacy authentication cookies.
     *
     * This method checks:
     * 1. If there's an auth_sig cookie, verify it with CookieSigner (HMAC validation)
     * 2. If no auth_sig, verify the logkey exists in the database for the user
     */
    private function validateLegacyCookies(string $user, string $logkey, string $authSig): bool
    {
        if ($user === '' || $logkey === '') {
            return false;
        }

        if ($authSig !== '' && class_exists('\FSFramework\Security\CookieSigner')) {
            return \FSFramework\Security\CookieSigner::verifyRememberMe($user, $logkey, $authSig);
        }

        if (!$this->db->connected() && !$this->db->connect()) {
            return false;
        }

        try {
            $escapedUser = $this->db->escape_string($user);
            $escapedLogkey = $this->db->escape_string($logkey);

            $result = $this->db->select(
                "SELECT nick FROM fs_users WHERE nick = '" . $escapedUser . "' AND log_key = '" . $escapedLogkey . "' AND enabled = TRUE LIMIT 1"
            );

            return !empty($result);
        } catch (\Throwable $e) {
            error_log('StealthMode::validateLegacyCookies error: ' . $e->getMessage());
            return false;
        }
    }

    private function isHiddenLoginAllowed(): bool
    {
        if (!$this->isValidSecretRequest()) {
            return false;
        }

        return $this->isHiddenLoginPageRequest() || $this->isHiddenLoginSubmission();
    }

    private function isHiddenLoginPageRequest(): bool
    {
        return ($_GET['page'] ?? '') === 'login';
    }

    private function isHiddenLoginSubmission(): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return false;
        }

        if (isset($_GET['nlogin'])) {
            return true;
        }

        return isset($_POST['user']) && (isset($_POST['password']) || isset($_POST['email']));
    }

    private function isValidSecretRequest(): bool
    {
        $paramName = $this->getParamName();
        $paramValue = $this->getParamValue();

        if (empty($paramValue)) {
            error_log(sprintf('StealthMode: access denied because the secret parameter "%s" is empty.', $paramName));
            return false;
        }

        $requestValue = $_GET[$paramName] ?? null;
        return $requestValue !== null && hash_equals($paramValue, (string) $requestValue);
    }

    private function grantAccess(): void
    {
        $this->ensurePhpSessionStarted();

        $_SESSION[self::SESSION_KEY] = true;
    }

    private function ensurePhpSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    private function redirectToHiddenLogin(): RedirectResponse
    {
        return new RedirectResponse($this->getHiddenLoginUrl());
    }

    private function sanitizeHtml(string $html): string
    {
        $sanitizer = new HtmlSanitizer();
        return $sanitizer->sanitizeHtml($html);
    }

    private function sanitizeCss(string $css): ?string
    {
        if ($css === '') {
            return '';
        }
        $sanitizer = new CssSanitizer();
        return $sanitizer->sanitizeCss($css);
    }

    private function wrapInDocument(string $bodyContent, string $customCss = ''): string
    {
        $sanitizer = new HtmlSanitizer();
        return $sanitizer->wrapInDocument($bodyContent, $this->getPageTitle(), $customCss);
    }

    private function getDefaultBodyContent(): string
    {
        $sanitizer = new HtmlSanitizer();
        return $sanitizer->getDefaultBodyContent();
    }
}
