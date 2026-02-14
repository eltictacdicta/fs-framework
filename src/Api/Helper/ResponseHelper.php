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

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Helper para manejo de respuestas HTTP
 *
 * @author FacturaScripts Team
 */
class ResponseHelper
{
    /**
     * @return string[]
     */
    private static function getAllowedOrigins(): array
    {
        if (!defined('FS_API_CORS_ORIGINS')) {
            return [];
        }

        $origins = FS_API_CORS_ORIGINS;
        if (is_string($origins)) {
            $origins = array_map('trim', explode(',', $origins));
        }

        if (!is_array($origins)) {
            return [];
        }

        return array_values(array_filter($origins, static function ($origin): bool {
            return is_string($origin) && $origin !== '';
        }));
    }

    private static function resolveCorsOrigin(): ?string
    {
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (!is_string($requestOrigin) || $requestOrigin === '') {
            return null;
        }

        $allowedOrigins = self::getAllowedOrigins();
        if (empty($allowedOrigins)) {
            return null;
        }

        if (in_array('*', $allowedOrigins, true)) {
            return $requestOrigin;
        }

        return in_array($requestOrigin, $allowedOrigins, true) ? $requestOrigin : null;
    }

    /**
     * @return array<string, string>
     */
    private static function getCorsHeaders(): array
    {
        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Auth-Token'
        ];

        $allowedOrigins = self::getAllowedOrigins();
        if (!empty($allowedOrigins)) {
            $headers['Vary'] = 'Origin';
            
            $origin = self::resolveCorsOrigin();
            if ($origin !== null) {
                $headers['Access-Control-Allow-Origin'] = $origin;
            }
        }

        return $headers;
    }

    private static function sendCorsHeaders(): void
    {
        foreach (self::getCorsHeaders() as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /**
     * Envía una respuesta de éxito
     */
    public static function sendSuccess(array $data = [], int $statusCode = 200, ?string $message = null): never
    {
        $response = ['success' => true];
        
        if ($message !== null) {
            $response['message'] = $message;
        }
        
        if (!empty($data)) {
            $response = array_merge($response, $data);
        }
        
        self::sendJson($response, $statusCode);
    }
    
    /**
     * Envía una respuesta de error
     */
    public static function sendError(string $error, int $statusCode = 400, array $data = []): never
    {
        $response = [
            'success' => false,
            'error' => $error
        ];
        
        if (!empty($data)) {
            $response['data'] = $data;
        }
        
        self::sendJson($response, $statusCode);
    }
    
    /**
     * Envía una respuesta JSON
     */
    public static function sendJson(array $data, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        
        header('Content-Type: application/json; charset=utf-8');
        self::sendCorsHeaders();
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Crea un JsonResponse de Symfony (no termina la ejecución)
     */
    public static function createJsonResponse(array $data, int $statusCode = 200): JsonResponse
    {
        return new JsonResponse($data, $statusCode, self::getCorsHeaders());
    }
    
    public static function sendCreated(array $data = [], string $message = 'Recurso creado exitosamente'): never
    {
        self::sendSuccess($data, 201, $message);
    }
    
    public static function sendUpdated(array $data = [], string $message = 'Recurso actualizado exitosamente'): never
    {
        self::sendSuccess($data, 200, $message);
    }
    
    public static function sendDeleted(string $message = 'Recurso eliminado exitosamente'): never
    {
        self::sendSuccess([], 200, $message);
    }
    
    public static function sendUnauthorized(string $message = 'No autorizado'): never
    {
        self::sendError($message, 401);
    }
    
    public static function sendForbidden(string $message = 'Acceso prohibido'): never
    {
        self::sendError($message, 403);
    }
    
    public static function sendNotFound(string $message = 'Recurso no encontrado'): never
    {
        self::sendError($message, 404);
    }
    
    public static function sendConflict(string $message = 'Conflicto de recursos', array $data = []): never
    {
        self::sendError($message, 409, $data);
    }
    
    public static function sendValidationError(string|array $errors): never
    {
        $message = is_array($errors) ? 'Error de validación' : $errors;
        $data = is_array($errors) ? ['errors' => $errors] : [];
        
        self::sendError($message, 400, $data);
    }
    
    public static function sendServerError(string $message = 'Error interno del servidor'): never
    {
        self::sendError($message, 500);
    }

    public static function sendTooManyRequests(string $message = 'Demasiadas solicitudes', ?int $retryAfter = null): never
    {
        if ($retryAfter !== null) {
            header('Retry-After: ' . $retryAfter);
        }
        self::sendError($message, 429);
    }
}
