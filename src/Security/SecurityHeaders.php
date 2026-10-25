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

final class SecurityHeaders
{
    private static ?string $nonce = null;

    /**
     * `max-age` por defecto de Strict-Transport-Security: un año. Overridable
     * con FS_HSTS_MAX_AGE; el valor 0 desactiva la emisión.
     */
    private const DEFAULT_HSTS_MAX_AGE = 31536000;

    /**
     * The current UI still ships many inline scripts/styles and inline event handlers across
     * legacy and plugin Twig templates, so removing unsafe-inline here would break rendering.
     * Keep this explicit until nonce/hash propagation is implemented end-to-end.
     */
    private const SCRIPT_SRC_DIRECTIVE = "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://cdn.tailwindcss.com";
    private const STYLE_SRC_DIRECTIVE = "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com";

    public static function contentSecurityPolicy(array $additionalFormActionSources = []): string
    {
        if (defined('FS_DISABLE_CSP') && FS_DISABLE_CSP) {
            return '';
        }

        if (defined('FS_CSP_POLICY')) {
            $configuredPolicy = trim((string) FS_CSP_POLICY);
            if ($configuredPolicy !== '') {
                return $configuredPolicy;
            }
        }

        $formAction = 'form-action ' . self::buildFormActionSources($additionalFormActionSources);

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            $formAction,
            self::SCRIPT_SRC_DIRECTIVE,
            self::STYLE_SRC_DIRECTIVE,
            "img-src 'self' data: https:",
            "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com",
            "connect-src 'self' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net",
            "frame-src 'self'",
            "manifest-src 'self'",
            "media-src 'self' data:",
            "worker-src 'self' blob:",
        ]);
    }

    private static function buildFormActionSources(array $additionalSources = []): string
    {
        if (defined('FS_CSP_FORM_ACTION') && is_string(FS_CSP_FORM_ACTION) && trim(FS_CSP_FORM_ACTION) !== '') {
            return trim(FS_CSP_FORM_ACTION);
        }

        $sources = ["'self'"];

        foreach ($additionalSources as $source) {
            if (!is_string($source)) {
                continue;
            }

            $source = trim($source);
            if ($source === '') {
                continue;
            }

            $sources[] = $source;
        }

        return implode(' ', array_values(array_unique($sources)));
    }

    /**
     * Generate a stable per-request nonce so shared templates can start stamping script/style tags
     * before the policy is switched to strict nonce enforcement.
     */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(18));
        }

        return self::$nonce;
    }

    public static function nonceAttribute(): string
    {
        return sprintf('nonce="%s"', htmlspecialchars(self::nonce(), ENT_QUOTES, 'UTF-8'));
    }

    public static function applyDefaultHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        self::applyHstsHeader();

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        $policy = self::contentSecurityPolicy();
        if ($policy !== '' && !self::shouldDeferContentSecurityPolicy()) {
            header('Content-Security-Policy: ' . $policy);
        }
    }

    /**
     * Emite SÓLO la cabecera de transporte (HSTS).
     *
     * Para los entry points que no deben recibir el juego completo de cabeceras
     * de navegador: api.php y cron.php. Llamar a applyDefaultHeaders() ahí
     * agregaría CSP, nosniff y X-Frame-Options a la superficie de la API, que es
     * un cambio de comportamiento ajeno a VULN-0007 (design D6).
     */
    public static function applyTransportHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        self::applyHstsHeader();
    }

    /**
     * Valor de `Strict-Transport-Security` para este request, o '' si no
     * corresponde emitirlo.
     *
     * `max-age` sale de FS_HSTS_MAX_AGE (31536000 por defecto) y el valor 0 lo
     * desactiva por completo: es la vía de escape documentada, porque un
     * `max-age` ya servido a un navegador NO se deshace revirtiendo el código y
     * puede fijar un host de desarrollo a HTTPS durante un año.
     *
     * `includeSubDomains` es opt-in (FS_HSTS_INCLUDE_SUBDOMAINS): fija HTTPS para
     * toda la zona padre (p. ej. *.ddev.site) y no es barato de revertir.
     *
     * @internal Expuesto para las pruebas: el CLI SAPI no puede observar header().
     */
    public static function hstsHeaderValue(): string
    {
        if (!SecureRequestDetector::isSecure()) {
            return '';
        }

        $maxAge = self::hstsMaxAge();
        if ($maxAge <= 0) {
            return '';
        }

        $value = 'max-age=' . $maxAge;

        return self::hstsIncludeSubDomains() ? $value . '; includeSubDomains' : $value;
    }

    private static function applyHstsHeader(): void
    {
        $value = self::hstsHeaderValue();
        if ($value !== '') {
            header('Strict-Transport-Security: ' . $value);
        }
    }

    private static function hstsMaxAge(): int
    {
        if (defined('FS_HSTS_MAX_AGE')) {
            return (int) FS_HSTS_MAX_AGE;
        }

        $configured = getenv('FS_HSTS_MAX_AGE');
        if ($configured !== false && trim($configured) !== '') {
            return (int) trim($configured);
        }

        return self::DEFAULT_HSTS_MAX_AGE;
    }

    private static function hstsIncludeSubDomains(): bool
    {
        if (defined('FS_HSTS_INCLUDE_SUBDOMAINS')) {
            return (bool) FS_HSTS_INCLUDE_SUBDOMAINS;
        }

        $configured = getenv('FS_HSTS_INCLUDE_SUBDOMAINS');
        if ($configured === false) {
            return false;
        }

        return in_array(strtolower(trim($configured)), ['1', 'true', 'on', 'yes'], true);
    }

    private static function shouldDeferContentSecurityPolicy(): bool
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? null;
        if (!is_string($requestUri) || $requestUri === '') {
            return false;
        }

        $path = parse_url($requestUri, PHP_URL_PATH);
        return is_string($path) && $path === '/oauth/login';
    }
}
