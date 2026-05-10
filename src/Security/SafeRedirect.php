<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
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

namespace FSFramework\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Gestiona redirecciones seguras para prevenir ataques Open Redirect.
 *
 * Los ataques Open Redirect ocurren cuando una aplicación acepta URLs de
 * redirección controladas por el usuario sin validación, permitiendo a
 * atacantes redirigir a usuarios a sitios maliciosos.
 *
 * Esta clase valida que las URLs de redirección sean:
 * - Relativas (rutas internas)
 * - O absolutas hacia el mismo host de la aplicación
 *
 * Uso:
 *   // En lugar de: header('Location: ' . $_REQUEST['redir']);
 *   // Usar:
 *   $safeUrl = SafeRedirect::validate($_REQUEST['redir'], $defaultUrl);
 *   header('Location: ' . $safeUrl);
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class SafeRedirect
{
    /**
     * Lista de hosts permitidos además del host actual.
     * Puede extenderse mediante configuración.
     *
     * @var array<string>
     */
    private static array $allowedHosts = [];

    /**
     * @var bool Evita inicializaciones repetidas del ``bootstrapAllowedHosts()``.
     */
    private static bool $hostsBootstrapped = false;

    /**
     * Valida una URL de redirección y retorna una URL segura.
     *
     * @param string|null $url URL a validar (puede ser de $_REQUEST, $_GET, etc.)
     * @param string $fallbackUrl URL por defecto si la validación falla
     * @return string URL segura para redirección
     */
    public static function validate(?string $url, string $fallbackUrl = 'index.php'): string
    {
        $safeUrl = $fallbackUrl;

        if (!empty($url)) {
            $url = trim($url);

            if (!self::hasDangerousProtocol($url)) {
                if (self::isRelativeUrl($url)) {
                    $safeUrl = self::sanitizeRelativeUrl($url);
                } elseif (self::isAbsoluteUrl($url) && self::isAllowedHost($url)) {
                    $safeUrl = $url;
                }
            }
        }

        return $safeUrl;
    }

    /**
     * Obtiene una URL de redirección segura desde la petición actual.
     * Busca en parámetros comunes como 'redir', 'redirect', 'return_url'.
     *
     * @param string $fallbackUrl URL por defecto
     * @param array<string> $paramNames Nombres de parámetros a buscar
     * @return string URL segura
     */
    public static function getFromRequest(
        string $fallbackUrl = 'index.php',
        array $paramNames = ['redir', 'redirect', 'return_url', 'returnUrl']
    ): string {
        foreach ($paramNames as $param) {
            if (isset($_REQUEST[$param]) && !empty($_REQUEST[$param])) {
                return self::validate($_REQUEST[$param], $fallbackUrl);
            }
        }

        return $fallbackUrl;
    }

    /**
     * Agrega hosts adicionales permitidos para redirección.
     *
     * @param string|array<string> $hosts Host(s) a agregar
     */
    public static function addAllowedHosts(string|array $hosts): void
    {
        if (is_string($hosts)) {
            $hosts = [$hosts];
        }

        foreach ($hosts as $host) {
            $host = strtolower(trim($host));
            if (!empty($host) && !in_array($host, self::$allowedHosts, true)) {
                self::$allowedHosts[] = $host;
            }
        }
    }

    /**
     * Obtiene la lista de hosts permitidos.
        *
     * @return array<string>
     */
    public static function getAllowedHosts(): array
    {
        return self::$allowedHosts;
    }

    /**
     * Limpia la lista de hosts permitidos adicionales.
     */
    public static function clearAllowedHosts(): void
    {
        self::$allowedHosts = [];
    }

    /**
     * Verifica si una URL tiene un protocolo peligroso.
     * Protege contra javascript:, data:, vbscript:, etc.
     */
    private static function hasDangerousProtocol(string $url): bool
    {
        // Normalizar para detectar ofuscación (espacios, tabs, newlines)
        $normalized = preg_replace('/[\x00-\x20]+/', '', strtolower($url));

        $dangerousProtocols = [
            'javascript:',
            'vbscript:',
            'data:',
            'file:',
            'blob:',
            'about:',
        ];

        foreach ($dangerousProtocols as $protocol) {
            if (str_starts_with((string) $normalized, $protocol)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica si es una URL relativa (sin esquema).
     */
    private static function isRelativeUrl(string $url): bool
    {
        // Comienza con / pero no con // (que sería protocol-relative)
        if (preg_match('#^/[^/]#', $url) || $url === '/') {
            return true;
        }
        // Es un path simple como "index.php?page=home"
        // Verificar que no tenga esquema
        return !str_contains($url, '://') && !str_starts_with($url, '//') && !preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $url);
    }

    /**
     * Sanitiza una URL relativa.
     */
    private static function sanitizeRelativeUrl(string $url): string
    {
        // Remover cualquier intento de path traversal
        $url = str_replace(['../', '..\\'], '', $url);

        // Decodificar y re-codificar para normalizar
        $parts = parse_url($url);

        if ($parts === false) {
            return 'index.php';
        }

        $result = '';

        if (isset($parts['path'])) {
            $result .= $parts['path'];
        }

        if (isset($parts['query'])) {
            $result .= '?' . $parts['query'];
        }

        if (isset($parts['fragment'])) {
            $result .= '#' . $parts['fragment'];
        }

        return $result ?: 'index.php';
    }

    /**
     * Verifica si es una URL absoluta (con esquema http/https).
     */
    private static function isAbsoluteUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', $url);
    }

    /**
     * Verifica si el host de una URL está permitido.
     */
    private static function isAllowedHost(string $url): bool
    {
        $urlParts = parse_url($url);
        $currentHost = self::getCurrentHost();

        if (!isset($urlParts['host'])) {
            return false;
        }

        $targetHost = strtolower($urlParts['host']);
        $isCurrentHost = $targetHost === $currentHost;
        $isAllowedAdditionalHost = in_array($targetHost, self::$allowedHosts, true);
        $isAllowedSubdomain = $currentHost !== null && str_ends_with($targetHost, '.' . $currentHost);

        if ($isCurrentHost || $isAllowedAdditionalHost || $isAllowedSubdomain) {
            if ($isAllowedAdditionalHost) {
                return true;
            }

            return self::matchesConfiguredBaseHost($targetHost);
        }

        return false;
    }

    /**
     * When FS_BASE_URL is configured, the target host must also belong to that
     * domain to prevent host-header injection attacks.
     *
     * Without FS_BASE_URL (e.g. test / dev environments), this check is skipped.
     */
    private static function matchesConfiguredBaseHost(string $targetHost): bool
    {
        if (!defined('FS_BASE_URL')) {
            return true;
        }

        $baseUrl = trim((string) FS_BASE_URL);
        if ($baseUrl === '') {
            return true;
        }

        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($baseHost) || $baseHost === '') {
            return true;
        }

        $baseHost = strtolower($baseHost);

        return $targetHost === $baseHost || str_ends_with($targetHost, '.' . $baseHost);
    }

    /**
     * Obtiene el host actual de la petición.
     */
    private static function getCurrentHost(): ?string
    {
        self::bootstrapAllowedHosts();

        $host = null;

        if (class_exists(Request::class)) {
            $request = Request::createFromGlobals();
            $requestHost = $request->getHost();
            if (!empty($requestHost)) {
                $host = $requestHost;
            }
        }

        if ($host === null && isset($_SERVER['HTTP_HOST'])) {
            $host = preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']);
        }

        if ($host === null && isset($_SERVER['SERVER_NAME'])) {
            $host = (string) $_SERVER['SERVER_NAME'];
        }

        return !empty($host) ? strtolower((string) $host) : null;
    }

    /**
     * Populates the allowed hosts list from FS_BASE_URL so that
     * ``getCurrentHost()`` never trusts an unconfigured Host header.
     */
    private static function bootstrapAllowedHosts(): void
    {
        if (self::$hostsBootstrapped) {
            return;
        }

        self::$hostsBootstrapped = true;

        if (!defined('FS_BASE_URL')) {
            return;
        }

        $baseUrl = trim((string) FS_BASE_URL);
        if ($baseUrl === '') {
            return;
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $host = strtolower($host);
            if (!in_array($host, self::$allowedHosts, true)) {
                self::$allowedHosts[] = $host;
            }
        }
    }

}
