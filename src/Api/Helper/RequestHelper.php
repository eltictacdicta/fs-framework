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
use FSFramework\Core\Plugins;
use Symfony\Component\HttpFoundation\Request;

/**
 * Helper para manejo de requests HTTP
 *
 * @author FacturaScripts Team
 */
/**
 * @deprecated Las APIs helper legacy se retirarán en v3.0.
 *             Migración: usar Request de Symfony inyectado y métodos modernos de este helper.
 */
class RequestHelper
{
    private static ?Request $request = null;

    /**
     * Establece el request de Symfony para usar
     */
    public static function setRequest(?Request $request): void
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
     * Obtiene un parámetro (GET o POST)
     * @deprecated Será retirado en v3.0. Usar getRequest()->get().
     */
    public static function getParam(string $name, mixed $default = null): mixed
    {
        self::legacyDeprecation('getParam', 'getRequest()->get');
        return self::getRequestValue($name, $default);
    }

    /**
     * Obtiene un parámetro entero
     */
    public static function getInt(string $name, int $default = 0): int
    {
        $value = self::getRequestValue($name);
        return $value !== null ? intval($value) : $default;
    }

    /**
     * Obtiene un parámetro booleano
     */
    public static function getBool(string $name, bool $default = false): bool
    {
        $value = self::getRequestValue($name);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on']);
    }
    
    /**
     * Obtiene el token de autenticación del request.
     * Busca solo en cabeceras seguras: Authorization y X-Auth-Token.
     */
    public static function getAuthToken(): ?string
    {
        $token = null;
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        } elseif (isset($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            $token = $_SERVER['HTTP_X_AUTH_TOKEN'];
        }

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if ($token === null && isset($headers['Authorization']) && str_starts_with($headers['Authorization'], 'Bearer ')) {
            $token = substr($headers['Authorization'], 7);
        } elseif ($token === null && isset($headers['X-Auth-Token'])) {
            $token = $headers['X-Auth-Token'];
        }

        return $token;
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
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
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
     * Verifica si es una petición AJAX
     */
    public static function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    private static function getRequestValue(string $name, mixed $default = null): mixed
    {
        $request = self::getRequest();
        $value = $default;

        if ($request->attributes->has($name)) {
            $value = $request->attributes->get($name);
        } elseif ($request->query->has($name)) {
            $value = $request->query->get($name, $default);
        } elseif ($request->request->has($name)) {
            $value = $request->request->get($name, $default);
        }

        return $value;
    }

    // Legacy method aliases
    /**
     * @deprecated Será retirado en v3.0. Usar getInt() + validación explícita.
     */
    public static function getIntParam(string $name, int $default = 0, ?int $min = null, ?int $max = null): int
    {
        self::legacyDeprecation('getIntParam', 'getInt');
        $value = self::getInt($name, $default);
        if ($min !== null && $value < $min) {
            return $min;
        }
        if ($max !== null && $value > $max) {
            return $max;
        }
        return $value;
    }
    /**
     * @deprecated Será retirado en v3.0. Usar getBool().
     */
    public static function getBoolParam(string $name, bool $default = false): bool
    {
        self::legacyDeprecation('getBoolParam', 'getBool');
        return self::getBool($name, $default);
    }

    private static function legacyDeprecation(string $method, string $replacement): void
    {
        if (Plugins::isEnabled('legacy_support') && class_exists('FSFramework\\Plugins\\legacy_support\\LegacyCompatibility')) {
            \FSFramework\Plugins\legacy_support\LegacyCompatibility::reportDeprecatedComponent(
                'api.request_helper',
                $method,
                $replacement . '()'
            );

            return;
        }

        @trigger_error(
            sprintf('%s() está deprecado y será retirado en v3.0. Migración recomendada: %s().', $method, $replacement),
            E_USER_DEPRECATED
        );
    }
}

