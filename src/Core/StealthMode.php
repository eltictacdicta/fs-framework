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

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Sabberworm\CSS\CSSList\AtRuleBlockList;
use Sabberworm\CSS\CSSList\CSSList;
use Sabberworm\CSS\CSSList\KeyFrame;
use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser as CssParser;
use Sabberworm\CSS\Parsing\SourceException;
use Sabberworm\CSS\Parsing\UnexpectedEOFException;
use Sabberworm\CSS\Parsing\UnexpectedTokenException;
use Sabberworm\CSS\Property\Declaration;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use Sabberworm\CSS\Value\CSSFunction;
use Sabberworm\CSS\Value\CSSString;
use Sabberworm\CSS\Value\URL;
use Sabberworm\CSS\Value\Value;
use Sabberworm\CSS\Value\ValueList;
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

    private const ALLOWED_SCRIPT_HOSTS = [
        'ajax.googleapis.com',
        'cdn.jsdelivr.net',
        'cdnjs.cloudflare.com',
        'code.jquery.com',
        'unpkg.com',
    ];

    private const ALLOWED_SCRIPT_ATTRIBUTES = [
        'async',
        'crossorigin',
        'defer',
        'integrity',
        'referrerpolicy',
        'src',
    ];

    private const BLOCKED_HTML_TAGS = [
        'base',
        'embed',
        'form',
        'iframe',
        'input',
        'link',
        'meta',
        'object',
        'textarea',
    ];

    private const URL_HTML_ATTRIBUTES = [
        'action',
        'formaction',
        'href',
        'poster',
        'src',
    ];

    private const ALLOWED_CSS_BLOCK_AT_RULES = [
        'media',
        'supports',
    ];

    private const ALLOWED_CSS_KEYFRAME_AT_RULES = [
        '-webkit-keyframes',
        'keyframes',
    ];

    private const ALLOWED_CSS_URL_PROPERTIES = [
        'background',
        'background-image',
        'cursor',
        'list-style',
        'list-style-image',
    ];

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
     * Comprueba si hay una sesión de login activa (Symfony Session o cookies legacy).
     */
    private function isUserLoggedIn(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $sfAttrs = $_SESSION['_sf2_attributes'] ?? [];
        if (!empty($sfAttrs['user_logged_in']) && !empty($sfAttrs['user_nick'])) {
            return true;
        }

        if (!empty($_COOKIE['user']) && !empty($_COOKIE['logkey'])) {
            return true;
        }

        return false;
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
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION[self::SESSION_KEY] = true;
    }

    private function redirectToHiddenLogin(): RedirectResponse
    {
        return new RedirectResponse($this->getHiddenLoginUrl());
    }

    // -- Sanitización --

    /**
     * Sanitiza HTML eliminando scripts inline y event handlers.
     * Permite scripts de CDN conocidos (Bootstrap, jQuery, etc.)
     */
    private function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $wrappedHtml = '<!DOCTYPE html><html><body><div id="stealth-root">' . $html . '</div></body></html>';

        $previousUseErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        $xpath = new DOMXPath($document);
        $rootNode = $xpath->query('//*[@id="stealth-root"]')->item(0);
        if (!$rootNode instanceof DOMElement) {
            return '';
        }

        foreach ($this->collectElements($rootNode) as $element) {
            $tagName = strtolower($element->tagName);

            if ($tagName === 'script') {
                if (!$this->sanitizeScriptElement($element)) {
                    $element->parentNode?->removeChild($element);
                }
                continue;
            }

            if (in_array($tagName, self::BLOCKED_HTML_TAGS, true)) {
                $element->parentNode?->removeChild($element);
                continue;
            }

            $this->sanitizeHtmlAttributes($element);
        }

        return $this->getInnerHtml($rootNode);
    }

    private function sanitizeCss(string $css): ?string
    {
        $css = trim($css);
        if ($css === '') {
            return '';
        }

        try {
            $document = (new CssParser($css))->parse();
        } catch (SourceException | UnexpectedEOFException | UnexpectedTokenException $exception) {
            return null;
        }

        if (!$this->sanitizeCssList($document)) {
            return null;
        }

        return trim($document->render(OutputFormat::createCompact()));
    }

    private function sanitizeCssList(CSSList $list): bool
    {
        foreach ($list->getContents() as $item) {
            if ($item instanceof DeclarationBlock) {
                if (!$this->sanitizeCssDeclarationBlock($item)) {
                    return false;
                }

                if ($item->getDeclarations() === [] || $item->getSelectors() === []) {
                    $list->remove($item);
                }

                continue;
            }

            if ($item instanceof AtRuleBlockList) {
                if (!in_array(strtolower($item->atRuleName()), self::ALLOWED_CSS_BLOCK_AT_RULES, true)) {
                    return false;
                }

                if (!$this->sanitizeCssList($item)) {
                    return false;
                }

                if ($item->getContents() === []) {
                    $list->remove($item);
                }

                continue;
            }

            if ($item instanceof KeyFrame) {
                if (!in_array(strtolower($item->atRuleName()), self::ALLOWED_CSS_KEYFRAME_AT_RULES, true)) {
                    return false;
                }

                if (!$this->sanitizeCssList($item)) {
                    return false;
                }

                if ($item->getContents() === []) {
                    $list->remove($item);
                }

                continue;
            }

            return false;
        }

        return true;
    }

    private function sanitizeCssDeclarationBlock(DeclarationBlock $block): bool
    {
        foreach ($block->getDeclarations() as $declaration) {
            if (!$this->isAllowedCssDeclaration($declaration)) {
                return false;
            }
        }

        return true;
    }

    private function isAllowedCssDeclaration(Declaration $declaration): bool
    {
        $property = strtolower($declaration->getPropertyName());
        if (!$this->isAllowedCssProperty($property)) {
            return false;
        }

        $value = $declaration->getValue();
        if ($this->containsCssUrlValue($value) && !in_array($property, self::ALLOWED_CSS_URL_PROPERTIES, true)) {
            return false;
        }

        return $this->isSafeCssValue($value);
    }

    private function isAllowedCssProperty(string $property): bool
    {
        return preg_match(
            '/^(--[a-z0-9_-]+|align-(content|items|self)|animation(-[a-z]+)?|background(-[a-z]+)?|border(-[a-z]+)?|bottom|box-shadow|color|column-gap|content|cursor|display|flex(-[a-z]+)?|font(-[a-z]+)?|gap|grid(-[a-z]+)?|height|justify-content|left|letter-spacing|line-height|list-style(-[a-z]+)?|margin(-[a-z]+)?|max-(height|width)|min-(height|width)|object-(fit|position)|opacity|outline(-[a-z]+)?|overflow(-[xy])?|padding(-[a-z]+)?|pointer-events|position|right|row-gap|text(-[a-z]+)?|top|transform|transition(-[a-z]+)?|visibility|white-space|width|word-spacing|z-index)$/',
            $property
        ) === 1;
    }

    private function containsCssUrlValue(mixed $value): bool
    {
        if ($value instanceof URL) {
            return true;
        }

        if ($value instanceof ValueList) {
            foreach ($value->getListComponents() as $component) {
                if ($this->containsCssUrlValue($component)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isSafeCssValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (is_string($value)) {
            return !$this->containsUnsafeCssToken($value);
        }

        if ($value instanceof URL) {
            return $this->isSafeCssUrl($value->getURL()->getString());
        }

        if ($value instanceof CSSString) {
            return !$this->containsUnsafeCssToken($value->getString());
        }

        if ($value instanceof CSSFunction && strtolower($value->getName()) === 'expression') {
            return false;
        }

        if ($value instanceof ValueList) {
            foreach ($value->getListComponents() as $component) {
                if (!$this->isSafeCssValue($component)) {
                    return false;
                }
            }

            return true;
        }

        if ($value instanceof Value) {
            return !$this->containsUnsafeCssToken($value->render(OutputFormat::createCompact()));
        }

        return true;
    }

    private function containsUnsafeCssToken(string $value): bool
    {
        $normalized = strtolower(rawurldecode($value));

        return str_contains($normalized, '@import')
            || str_contains($normalized, 'expression(')
            || str_contains($normalized, 'javascript:')
            || str_contains($normalized, 'vbscript:')
            || str_contains($normalized, 'data:');
    }

    private function isSafeCssUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $decodedUrl = strtolower(rawurldecode($url));
        if (str_starts_with($decodedUrl, 'javascript:')
            || str_starts_with($decodedUrl, 'data:')
            || str_starts_with($decodedUrl, 'vbscript:')) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if ($scheme === null) {
            return $host === null;
        }

        return in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /**
     * @return list<DOMElement>
     */
    private function collectElements(DOMNode $root): array
    {
        $elements = [];
        foreach ($root->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $elements[] = $child;
            foreach ($this->collectElements($child) as $nested) {
                $elements[] = $nested;
            }
        }

        return $elements;
    }

    private function sanitizeScriptElement(DOMElement $element): bool
    {
        $src = trim($element->getAttribute('src'));
        if ($src === '' || trim($element->textContent) !== '') {
            return false;
        }

        if (!$this->isAllowedScriptSource($src)) {
            return false;
        }

        $attributesToRemove = [];
        foreach ($element->attributes as $attribute) {
            $attributeName = strtolower($attribute->nodeName);
            if (!in_array($attributeName, self::ALLOWED_SCRIPT_ATTRIBUTES, true)) {
                $attributesToRemove[] = $attribute->nodeName;
            }
        }

        foreach ($attributesToRemove as $attributeName) {
            $element->removeAttribute($attributeName);
        }

        return true;
    }

    private function isAllowedScriptSource(string $src): bool
    {
        $scheme = strtolower((string) parse_url($src, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($src, PHP_URL_HOST));

        return $scheme === 'https' && in_array($host, self::ALLOWED_SCRIPT_HOSTS, true);
    }

    private function sanitizeHtmlAttributes(DOMElement $element): void
    {
        $attributesToRemove = [];

        foreach ($element->attributes as $attribute) {
            $attributeName = strtolower($attribute->nodeName);
            $attributeValue = $attribute->nodeValue ?? '';

            if (str_starts_with($attributeName, 'on') || $attributeName === 'style' || $attributeName === 'srcdoc') {
                $attributesToRemove[] = $attribute->nodeName;
                continue;
            }

            if (in_array($attributeName, self::URL_HTML_ATTRIBUTES, true) && !$this->isSafeHtmlUrl($attributeValue)) {
                $attributesToRemove[] = $attribute->nodeName;
            }
        }

        foreach ($attributesToRemove as $attributeName) {
            $element->removeAttribute($attributeName);
        }
    }

    private function isSafeHtmlUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#')) {
            return true;
        }

        $decodedUrl = strtolower(rawurldecode($url));
        if (str_starts_with($decodedUrl, 'javascript:')
            || str_starts_with($decodedUrl, 'data:')
            || str_starts_with($decodedUrl, 'vbscript:')) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if ($scheme === null) {
            return $host === null;
        }

        return in_array(strtolower($scheme), ['http', 'https', 'mailto', 'tel'], true);
    }

    private function getInnerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $childNode) {
            $html .= $node->ownerDocument?->saveHTML($childNode) ?? '';
        }

        return $html;
    }

    /**
     * Envuelve el contenido del body en un documento HTML5 completo con Bootstrap 5.
     */
    private function wrapInDocument(string $bodyContent, string $customCss = ''): string
    {
        $title = htmlspecialchars($this->getPageTitle(), ENT_QUOTES, 'UTF-8');
        $cssBlock = '';
        if (!empty($customCss)) {
            $cssBlock = "\n    <style>\n" . $customCss . "\n    </style>";
        }

        return '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . '</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-primary: #2DBCB6;
            --brand-dark: #1a2332;
            --brand-darker: #111927;
            --brand-accent: #24d4ad;
            --brand-light: #f0fdfb;
        }
        body { font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; color: #333; }
        .st-navbar { background: var(--brand-dark); padding: .8rem 0; }
        .st-navbar .navbar-brand { color: var(--brand-primary); font-weight: 700; font-size: 1.4rem; }
        .st-navbar .navbar-brand:hover { color: var(--brand-accent); }
        .st-hero {
            background: linear-gradient(135deg, var(--brand-dark) 0%, #1e3a4f 50%, #1a4a45 100%);
            color: #fff; padding: 100px 20px 80px; text-align: center; position: relative; overflow: hidden;
        }
        .st-hero::before {
            content: ""; position: absolute; top: -50%; right: -20%; width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(45,188,182,.15) 0%, transparent 70%); border-radius: 50%;
        }
        .st-hero::after {
            content: ""; position: absolute; bottom: -30%; left: -10%; width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(36,212,173,.1) 0%, transparent 70%); border-radius: 50%;
        }
        .st-hero .container { position: relative; z-index: 2; }
        .st-hero .brand-logo { width: 80px; height: 80px; margin: 0 auto 1.5rem; }
        .st-hero .brand-logo img { max-width: 100%; height: auto; }
        .st-hero h1 { font-size: 2.8rem; font-weight: 700; margin-bottom: .75rem; }
        .st-hero p.lead { font-size: 1.2rem; opacity: .85; max-width: 550px; margin: 0 auto 2rem; font-weight: 300; }
        .st-btn-primary {
            background: var(--brand-primary); border: none; color: #fff; padding: .7rem 2rem;
            border-radius: 8px; font-weight: 600; font-size: 1rem; transition: all .2s;
        }
        .st-btn-primary:hover { background: var(--brand-accent); color: #fff; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(45,188,182,.3); }
        .st-btn-outline {
            background: transparent; border: 2px solid rgba(255,255,255,.3); color: #fff; padding: .65rem 2rem;
            border-radius: 8px; font-weight: 500; font-size: 1rem; transition: all .2s;
        }
        .st-btn-outline:hover { border-color: var(--brand-primary); color: var(--brand-primary); }
        .st-section { padding: 80px 0; }
        .st-section-title { font-weight: 700; font-size: 2rem; margin-bottom: .5rem; color: var(--brand-dark); }
        .st-section-subtitle { color: #6c757d; font-size: 1.1rem; margin-bottom: 3rem; }
        .st-card {
            background: #fff; border: 1px solid #e8f4f3; border-radius: 16px; padding: 2rem;
            height: 100%; transition: all .25s;
        }
        .st-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(45,188,182,.1); border-color: var(--brand-primary); }
        .st-card-icon {
            width: 56px; height: 56px; border-radius: 12px; background: var(--brand-light);
            display: inline-flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 1.2rem;
            color: var(--brand-primary);
        }
        .st-card h4 { font-weight: 600; font-size: 1.15rem; margin-bottom: .5rem; color: var(--brand-dark); }
        .st-card p { color: #6c757d; font-size: .95rem; line-height: 1.6; margin: 0; }
        .st-cta {
            background: linear-gradient(135deg, var(--brand-dark) 0%, #1e3a4f 100%);
            color: #fff; padding: 60px 0; text-align: center;
        }
        .st-cta h3 { font-weight: 700; font-size: 1.8rem; margin-bottom: .5rem; }
        .st-cta p { opacity: .8; margin-bottom: 1.5rem; font-size: 1.05rem; }
        .st-footer { background: var(--brand-darker); color: rgba(255,255,255,.5); padding: 2rem 0; font-size: .85rem; }
        .st-footer a { color: var(--brand-primary); text-decoration: none; }
        .st-footer a:hover { color: var(--brand-accent); }
    </style>' . $cssBlock . '
</head>
<body>
' . $bodyContent . '
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
    }

    private function getDefaultBodyContent(): string
    {
        $year = date('Y');
        return '
    <nav class="navbar navbar-expand-lg st-navbar">
        <div class="container">
            <a class="navbar-brand" href="#">
                <!-- Reemplaza con tu logo: <img src="tu-logo.png" height="36" alt="Logo"> -->
                Tu Empresa
            </a>
        </div>
    </nav>

    <section class="st-hero">
        <div class="container">
            <div class="brand-logo">
                <!-- Reemplaza con tu logo: <img src="tu-logo-blanco.png" alt="Logo"> -->
            </div>
            <h1>Bienvenido a nuestra plataforma</h1>
            <p class="lead">Soluciones integrales para la gesti&oacute;n empresarial moderna, r&aacute;pida y segura.</p>
            <a href="#servicios" class="btn st-btn-primary me-2">Descubrir m&aacute;s</a>
            <a href="#contacto" class="btn st-btn-outline">Contacto</a>
        </div>
    </section>

    <section id="servicios" class="st-section">
        <div class="container">
            <div class="text-center">
                <h2 class="st-section-title">Nuestros Servicios</h2>
                <p class="st-section-subtitle">Todo lo que necesitas en un solo lugar</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="st-card">
                        <div class="st-card-icon">&#9889;</div>
                        <h4>R&aacute;pido</h4>
                        <p>Rendimiento optimizado para que tu negocio opere sin esperas ni interrupciones.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="st-card">
                        <div class="st-card-icon">&#128274;</div>
                        <h4>Seguro</h4>
                        <p>Tus datos protegidos con los m&aacute;s altos est&aacute;ndares de encriptaci&oacute;n y privacidad.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="st-card">
                        <div class="st-card-icon">&#10024;</div>
                        <h4>Intuitivo</h4>
                        <p>Interfaz sencilla y moderna dise&ntilde;ada para que empieces a trabajar desde el primer d&iacute;a.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="st-cta" id="contacto">
        <div class="container">
            <h3>&iquest;Listo para empezar?</h3>
            <p>Cont&aacute;ctanos y descubre c&oacute;mo podemos ayudarte</p>
            <a href="mailto:info@tuempresa.com" class="btn st-btn-primary">Contactar</a>
        </div>
    </section>

    <footer class="st-footer">
        <div class="container text-center">
            <p class="mb-0">&copy; ' . $year . ' Tu Empresa &mdash; Todos los derechos reservados.</p>
        </div>
    </footer>';
    }
}
