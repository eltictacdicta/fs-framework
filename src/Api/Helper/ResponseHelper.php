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
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Crea un JsonResponse de Symfony (no termina la ejecución)
     */
    public static function createJsonResponse(array $data, int $statusCode = 200): JsonResponse
    {
        return new JsonResponse($data, $statusCode, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Auth-Token'
        ]);
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
