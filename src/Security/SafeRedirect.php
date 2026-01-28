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
     * Valida una URL de redirección y retorna una URL segura.
     * 
     * @param string|null $url URL a validar (puede ser de $_REQUEST, $_GET, etc.)
     * @param string $fallbackUrl URL por defecto si la validación falla
     * @return string URL segura para redirección
     */
    public static function validate(?string $url, string $fallbackUrl = 'index.php'): string
    {
        // Si no hay URL, usar fallback
        if (empty($url)) {
            return $fallbackUrl;
        }

        // Sanitizar la URL
        $url = trim($url);
        
        // Bloquear URLs que empiecen con protocolos peligrosos
        if (self::hasDangerousProtocol($url)) {
            return $fallbackUrl;
        }

        // Si es una ruta relativa simple (empieza con / o es un path)
        if (self::isRelativeUrl($url)) {
            return self::sanitizeRelativeUrl($url);
        }

        // Si es una URL absoluta, validar el host
        if (self::isAbsoluteUrl($url)) {
            if (self::isAllowedHost($url)) {
                return $url;
            }
            // Host no permitido, usar fallback
            return $fallbackUrl;
        }

        // URL con formato desconocido o sospechoso, usar fallback
        return $fallbackUrl;
    }

    /**
     * Valida y ejecuta una redirección segura.
     * Convenience method que hace validate() + header() + exit().
     * 
     * @param string|null $url URL a validar
     * @param string $fallbackUrl URL por defecto si la validación falla
     * @param int $httpCode Código HTTP de redirección (302 por defecto)
     * @return never
     */
    public static function redirect(?string $url, string $fallbackUrl = 'index.php', int $httpCode = 302): never
    {
        $safeUrl = self::validate($url, $fallbackUrl);
        header('Location: ' . $safeUrl, true, $httpCode);
        exit();
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
     * Útil para entornos con múltiples subdominios o dominios relacionados.
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
        $normalized = preg_replace('/[\s\x00-\x1f]+/', '', strtolower($url));
        
        $dangerousProtocols = [
            'javascript:',
            'vbscript:',
            'data:',
            'file:',
            'blob:',
            'about:',
        ];
        
        foreach ($dangerousProtocols as $protocol) {
            if (str_starts_with($normalized, $protocol)) {
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
        if (!str_contains($url, '://') && !str_starts_with($url, '//')) {
            // Verificar que no tenga esquema
            if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $url)) {
                return true;
            }
        }
        
        return false;
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
        
        if (!isset($urlParts['host'])) {
            return false;
        }
        
        $targetHost = strtolower($urlParts['host']);
        
        // Obtener el host actual de la petición
        $currentHost = self::getCurrentHost();
        
        // Permitir el mismo host
        if ($targetHost === $currentHost) {
            return true;
        }
        
        // Verificar hosts adicionales permitidos
        if (in_array($targetHost, self::$allowedHosts, true)) {
            return true;
        }
        
        // Verificar si es un subdominio del host actual
        if ($currentHost && str_ends_with($targetHost, '.' . $currentHost)) {
            return true;
        }
        
        return false;
    }

    /**
     * Obtiene el host actual de la petición.
     */
    private static function getCurrentHost(): ?string
    {
        // Usar Symfony Request si está disponible
        if (class_exists(Request::class)) {
            $request = Request::createFromGlobals();
            $host = $request->getHost();
            if (!empty($host)) {
                return strtolower($host);
            }
        }
        
        // Fallback a $_SERVER
        if (isset($_SERVER['HTTP_HOST'])) {
            // Remover puerto si está presente
            $host = $_SERVER['HTTP_HOST'];
            $host = preg_replace('/:\d+$/', '', $host);
            return strtolower($host);
        }
        
        if (isset($_SERVER['SERVER_NAME'])) {
            return strtolower($_SERVER['SERVER_NAME']);
        }
        
        return null;
    }
}
