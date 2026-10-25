<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com> (lead developer of Facturascript)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FSFramework\Core;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

// The canonical requested page is defined once, in the dependency-free
// base/fs_auth_request.php, so the gate and index.php's dispatcher cannot
// disagree about which page a request names (AAG-02, AAG-05).
require_once dirname(__DIR__, 2) . '/base/fs_auth_request.php';

class PublicAccessGate
{
    public function __construct(private readonly StealthMode $stealth)
    {
    }

    public function intercept(Request $request): ?Response
    {
        $path = $request->getPathInfo();

        if (Plugins::isPublicPath($path)) {
            // A framework exact public path (/favicon.ico and friends) is exempt
            // only for a request that names no controller page: index.php
            // dispatches on `page=`, so presenting an allowlisted path while
            // naming a private page must not skip the gate (AAG-05). A path
            // registered as a plugin public prefix is opt-in by the plugin that
            // owns the route and stays exempt unconditionally.
            if (!Plugins::isFrameworkExactPublicPath($path) || \fs_auth_request::requestedPage($_GET) === '') {
                return null;
            }
        }

        if ($this->stealth->hasAuthenticatedSession()) {
            return null;
        }

        if ($this->stealth->isEnabled()) {
            $secretEntryRedirect = $this->stealth->consumeSecretEntryRedirect();
            if ($secretEntryRedirect !== null) {
                return $secretEntryRedirect;
            }

            if ($this->stealth->hasAccess()) {
                return null;
            }

            return $this->createStealthResponse();
        }

        // The exemption is the requested page alone. Consulting the request
        // shape (isLegacyLoginSubmission) here let a credential POST to any page
        // — e.g. page=password_reset&nlogin=1 — skip the gate and run the
        // controller's anonymous path (VULN-0002, AAG-01).
        if ($this->stealth->isLegacyLoginRequest()) {
            return $this->resolvePublicLoginRedirect();
        }

        return $this->createAnonymousLoginRedirectResponse();
    }

    /**
     * Create the response for stealth mode: either redirect to a plugin-registered
     * override URL, or show the default public homepage.
     */
    private function createStealthResponse(): Response
    {
        $overrideUrl = Plugins::getStealthHomeOverride();

        if ($overrideUrl !== null && $this->isValidLocalPath($overrideUrl)) {
            $base = defined('FS_PATH') ? rtrim((string) FS_PATH, '/') : '';
            $path = '/' . ltrim($overrideUrl, '/');
            return new RedirectResponse($base . $path);
        }

        return $this->stealth->createPublicHomepageResponse();
    }

    private function resolvePublicLoginRedirect(): ?RedirectResponse
    {
        $overrideUrl = Plugins::getPublicLoginRedirect();

        if ($overrideUrl === null || !$this->isValidLocalPath($overrideUrl)) {
            return null;
        }

        $base = defined('FS_PATH') ? rtrim((string) FS_PATH, '/') : '';
        $path = '/' . ltrim($overrideUrl, '/');

        return new RedirectResponse($base . $path);
    }

    private function createAnonymousLoginRedirectResponse(): RedirectResponse
    {
        return $this->resolvePublicLoginRedirect()
            ?? $this->stealth->createLegacyLoginRedirectResponse();
    }

    private function isValidLocalPath(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '//')) {
            return false;
        }

        $parsedScheme = parse_url($url, PHP_URL_SCHEME);
        $parsedHost = parse_url($url, PHP_URL_HOST);

        if ($parsedScheme !== null || $parsedHost !== null) {
            return false;
        }

        if (!str_starts_with($url, '/')) {
            return false;
        }

        return true;
    }
}
