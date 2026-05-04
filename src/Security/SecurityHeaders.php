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
     * The current UI still ships many inline scripts/styles and inline event handlers across
     * legacy and plugin Twig templates, so removing unsafe-inline here would break rendering.
     * Keep this explicit until nonce/hash propagation is implemented end-to-end.
     */
    private const SCRIPT_SRC_DIRECTIVE = "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://cdn.tailwindcss.com";
    private const STYLE_SRC_DIRECTIVE = "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com";

    public static function contentSecurityPolicy(): string
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

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self' https://github.com",
            self::SCRIPT_SRC_DIRECTIVE,
            self::STYLE_SRC_DIRECTIVE,
            "img-src 'self' data: https:",
            "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com",
            "connect-src 'self'",
            "frame-src 'self'",
            "manifest-src 'self'",
            "media-src 'self' data:",
            "worker-src 'self' blob:",
        ]);
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

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        $policy = self::contentSecurityPolicy();
        if ($policy !== '') {
            header('Content-Security-Policy: ' . $policy);
        }
    }
}
