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

namespace FSFramework\Api\Router;

use FSFramework\Api\Endpoint\ApiEndpoint;
use FSFramework\Api\Middleware\AuthMiddleware;
use FSFramework\Api\Middleware\CorsMiddleware;
use FSFramework\Api\Exception\ApiException;
use FSFramework\Api\Exception\NotFoundException;
use FSFramework\Api\Exception\UnauthorizedException;
use FSFramework\Api\Helper\ResponseHelper;

/**
 * Router principal de la API
 *
 * @author FacturaScripts Team
 */
class ApiRouter
{
    private EndpointRegistry $registry;
    private AuthMiddleware $authMiddleware;
    private CorsMiddleware $corsMiddleware;

    /** @var array<string, mixed>|null Usuario autenticado */
    private ?array $user = null;

    public function __construct(
        ?EndpointRegistry $registry = null,
        ?AuthMiddleware $authMiddleware = null,
        ?CorsMiddleware $corsMiddleware = null
    ) {
        $this->registry = $registry ?? EndpointRegistry::getInstance();
        $this->authMiddleware = $authMiddleware ?? new AuthMiddleware();
        $this->corsMiddleware = $corsMiddleware ?? new CorsMiddleware();
    }

    /**
     * Maneja un request de la API
     *
     * @param string $path Path del request (ej: /v1/infrico/contacts/123)
     * @param string $method Método HTTP
     * @return array<string, mixed> Respuesta
     */
    public function handle(string $path, string $method = 'GET'): array
    {
        try {
            // Manejar CORS preflight
            if ($method === 'OPTIONS') {
                $this->corsMiddleware->setHeaders();
                return ['success' => true, 'preflight' => true];
            }

            // Parsear path: /v{version}/{plugin}/{resource}/{id?}/{action?}
            $parts = $this->parsePath($path);
            
            if ($parts === null) {
                throw new NotFoundException('Ruta no válida: ' . $path);
            }

            $version = $parts['version'];
            $plugin = $parts['plugin'];
            $resource = $parts['resource'];
            $id = $parts['id'];
            $action = $parts['action'];

            // Cargar endpoint
            $endpoint = $this->registry->loadEndpoint($plugin, $resource);
            
            if ($endpoint === null) {
                throw new NotFoundException("Endpoint no encontrado: {$plugin}/{$resource}");
            }

            // Autenticación si es requerida
            if ($endpoint->requiresAuthentication() && !$this->registry->isPublicRoute($path)) {
                $this->user = $this->authMiddleware->authenticate();
                $endpoint->setUser($this->user);
            }

            // Ejecutar endpoint según método
            return $this->dispatch($endpoint, $method, $id, $action);

        } catch (ApiException $e) {
            return $e->toArray(defined('FS_DEBUG') && FS_DEBUG);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'errorType' => 'Exception'
            ];
        }
    }

    /**
     * Parsea el path en componentes
     *
     * @return array{version: string, plugin: string, resource: string, id: ?string, action: ?string}|null
     */
    private function parsePath(string $path): ?array
    {
        // Limpiar path
        $path = trim($path, '/');
        $parts = explode('/', $path);

        // Mínimo: version/plugin/resource
        if (count($parts) < 3) {
            return null;
        }

        // Verificar formato de versión
        $version = $parts[0];
        if (!preg_match('/^v\d+$/', $version)) {
            return null;
        }

        return [
            'version' => $version,
            'plugin' => $parts[1],
            'resource' => $parts[2],
            'id' => $parts[3] ?? null,
            'action' => $parts[4] ?? null
        ];
    }

    /**
     * Despacha la llamada al endpoint
     *
     * @return array<string, mixed>
     */
    private function dispatch(ApiEndpoint $endpoint, string $method, ?string $id, ?string $action): array
    {
        return match ($method) {
            'GET' => $id !== null ? $endpoint->get($id) : $endpoint->list(),
            'POST' => $action !== null ? $endpoint->action($action, $id) : $endpoint->create(),
            'PUT', 'PATCH' => $endpoint->update($id ?? ''),
            'DELETE' => $endpoint->delete($id ?? ''),
            default => throw new ApiException("Método no soportado: {$method}", 405)
        };
    }

    /**
     * Obtiene el usuario autenticado
     *
     * @return array<string, mixed>|null
     */
    public function getUser(): ?array
    {
        return $this->user;
    }

    /**
     * Establece headers CORS
     */
    public function setCorsHeaders(): void
    {
        $this->corsMiddleware->setHeaders();
    }
}
