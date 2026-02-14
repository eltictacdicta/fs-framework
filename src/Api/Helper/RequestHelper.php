<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2013-2025 Carlos Garcia Gomez <neorazorx@gmail.com>
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

namespace FSFramework\Api\Helper;

use FSFramework\Api\Exception\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Helper para manejo de requests HTTP
 *
 * @author FacturaScripts Team
 */
class RequestHelper
{
    private static ?Request $request = null;

    /**
     * Establece el request de Symfony para usar
     */
    public static function setRequest(Request $request): void
    {
        self::$request = $request;
    }

    /**
     * Obtiene el request actual
     */
    public static function getRequest(): Request
    {
        if (self::$request === null) {
            self::$request = Request::createFromGlobals();
        }
        return self::$request;
    }

    /**
     * Obtiene y decodifica el input JSON del request
     * @return array<string, mixed>
     * @throws ValidationException Si el JSON es inválido
     */
    public static function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        
        if (empty($input)) {
            return [];
        }
        
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ValidationException('JSON inválido: ' . json_last_error_msg());
        }
        
        return $data ?: [];
    }

    /**
     * Obtiene el body JSON usando Symfony Request
     * @return array<string, mixed>
     */
    public static function getJsonBody(): array
    {
        $request = self::getRequest();
        $content = $request->getContent();
        
        if (empty($content)) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Obtiene un parámetro de query string
     */
    public static function getQueryParam(string $name, mixed $default = null): mixed
    {
        return $_GET[$name] ?? $default;
    }

    /**
     * Obtiene un parámetro (GET o POST)
     */
    public static function getParam(string $name, mixed $default = null): mixed
    {
        return $_REQUEST[$name] ?? $default;
    }

    /**
     * Obtiene un parámetro string
     */
    public static function getString(string $name, string $default = ''): string
    {
        $value = self::getParam($name);
        return $value !== null ? (string)$value : $default;
    }

    /**
     * Obtiene un parámetro entero
     */
    public static function getInt(string $name, int $default = 0): int
    {
        $value = self::getParam($name);
        return $value !== null ? intval($value) : $default;
    }

    /**
     * Obtiene un parámetro booleano
     */
    public static function getBool(string $name, bool $default = false): bool
    {
        $value = self::getParam($name);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on']);
    }
    
    /**
     * Obtiene un parámetro requerido
     * @throws ValidationException Si el parámetro no existe
     */
    public static function getRequiredParam(string $name): string
    {
        $value = self::getQueryParam($name);
        
        if ($value === null || $value === '') {
            throw new ValidationException("Parámetro requerido: {$name}");
        }
        
        return $value;
    }
    
    /**
     * Obtiene múltiples parámetros requeridos
     * @param string[] $names
     * @return array<string, string>
     * @throws ValidationException Si algún parámetro no existe
     */
    public static function getRequiredParams(array $names): array
    {
        $params = [];
        $missing = [];
        
        foreach ($names as $name) {
            $value = self::getQueryParam($name);
            if ($value === null || $value === '') {
                $missing[] = $name;
            } else {
                $params[$name] = $value;
            }
        }
        
        if (!empty($missing)) {
            throw new ValidationException(
                'Parámetros requeridos faltantes: ' . implode(', ', $missing)
            );
        }
        
        return $params;
    }
    
    /**
     * Obtiene el token de autenticación del request
     * Busca en: Authorization header, X-Auth-Token header, query param, POST param
     */
    public static function getAuthToken(): ?string
    {
        // Authorization: Bearer TOKEN
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        // X-Auth-Token header
        if (isset($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            return $_SERVER['HTTP_X_AUTH_TOKEN'];
        }

        // getallheaders() fallback
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (isset($headers['Authorization'])) {
            return str_replace('Bearer ', '', $headers['Authorization']);
        }
        if (isset($headers['X-Auth-Token'])) {
            return $headers['X-Auth-Token'];
        }
        
        // Query param
        if (isset($_GET['token'])) {
            return $_GET['token'];
        }
        
        // POST param
        if (isset($_POST['token'])) {
            return $_POST['token'];
        }
        
        return null;
    }
    
    /**
     * Valida que un campo exista en los datos
     * @param array<string, mixed> $data
     * @throws ValidationException Si el campo no existe
     */
    public static function requireField(array $data, string $field, ?string $message = null): void
    {
        if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
            throw new ValidationException($message ?? "Campo requerido: {$field}");
        }
    }
    
    /**
     * Valida que múltiples campos existan
     * @param array<string, mixed> $data
     * @param string[] $fields
     * @throws ValidationException Si algún campo no existe
     */
    public static function requireFields(array $data, array $fields): void
    {
        $missing = [];
        
        foreach ($fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            throw new ValidationException(
                'Campos requeridos faltantes: ' . implode(', ', $missing)
            );
        }
    }
    
    /**
     * Sanitiza una cadena de texto
     */
    public static function sanitizeString(string $value): string
    {
        return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Obtiene el método HTTP del request
     */
    public static function getMethod(): string
    {
        // Soporte para _method override (útil para formularios HTML)
        if (isset($_POST['_method'])) {
            return strtoupper($_POST['_method']);
        }
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * Obtiene la IP del cliente
     */
    public static function getClientIp(): string
    {
        return fs_get_ip();
    }

    /**
     * Obtiene el User-Agent
     */
    public static function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Verifica si es una petición HTTPS
     */
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 0) == 443
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    /**
     * Verifica si es una petición AJAX
     */
    public static function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    /**
     * Valida un email
     */
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Valida una URL
     */
    public static function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    // Legacy method aliases
    public static function getJsonInput_legacy(): array { return self::getJsonInput(); }
    public static function getIntParam(string $name, int $default = 0, ?int $min = null, ?int $max = null): int 
    { 
        $value = self::getInt($name, $default);
        if ($min !== null && $value < $min) {
            return $min;
        }
        if ($max !== null && $value > $max) {
            return $max;
        }
        return $value;
    }
    public static function getBoolParam(string $name, bool $default = false): bool { return self::getBool($name, $default); }
}
