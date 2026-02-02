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

namespace FSFramework\Api\Endpoint;

use FSFramework\Api\Helper\RequestHelper;
use FSFramework\Api\Helper\ResponseHelper;
use FSFramework\Api\Exception\NotFoundException;
use FSFramework\Api\Exception\ValidationException;

/**
 * Clase base abstracta para endpoints de la API
 *
 * @author FacturaScripts Team
 */
abstract class ApiEndpoint
{
    /** Nombre del plugin */
    protected string $plugin = '';
    
    /** Versión de la API */
    protected string $version = 'v1';
    
    /** Nombre del recurso (se deduce del nombre de clase si no se especifica) */
    protected string $resource = '';

    /** Usuario autenticado actual */
    protected ?array $user = null;

    /** Requiere autenticación por defecto */
    protected bool $requiresAuth = true;

    /**
     * Establece el usuario autenticado
     *
     * @param array<string, mixed> $user
     */
    public function setUser(array $user): void
    {
        $this->user = $user;
    }

    /**
     * Obtiene el nombre del plugin
     */
    public function getPlugin(): string
    {
        return $this->plugin;
    }

    /**
     * Obtiene la versión
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Obtiene el nombre del recurso
     */
    public function getResource(): string
    {
        if (empty($this->resource)) {
            // Deducir del nombre de clase: ContactsEndpoint -> contacts
            $className = (new \ReflectionClass($this))->getShortName();
            $resource = str_replace('Endpoint', '', $className);
            return strtolower($resource);
        }
        return $this->resource;
    }

    /**
     * Indica si requiere autenticación
     */
    public function requiresAuthentication(): bool
    {
        return $this->requiresAuth;
    }

    /**
     * Lista recursos (GET /resource)
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        throw new NotFoundException('Método list() no implementado');
    }

    /**
     * Obtiene un recurso (GET /resource/{id})
     *
     * @return array<string, mixed>
     */
    public function get(string|int $id): array
    {
        throw new NotFoundException('Método get() no implementado');
    }

    /**
     * Crea un recurso (POST /resource)
     *
     * @return array<string, mixed>
     */
    public function create(): array
    {
        throw new NotFoundException('Método create() no implementado');
    }

    /**
     * Actualiza un recurso (PUT/PATCH /resource/{id})
     *
     * @return array<string, mixed>
     */
    public function update(string|int $id): array
    {
        throw new NotFoundException('Método update() no implementado');
    }

    /**
     * Elimina un recurso (DELETE /resource/{id})
     *
     * @return array<string, mixed>
     */
    public function delete(string|int $id): array
    {
        throw new NotFoundException('Método delete() no implementado');
    }

    /**
     * Ejecuta una acción personalizada (POST /resource/{id}/{action})
     *
     * @return array<string, mixed>
     */
    public function action(string $action, ?string $id = null): array
    {
        $methodName = 'action' . ucfirst($action);
        
        if (method_exists($this, $methodName)) {
            return $this->$methodName($id);
        }
        
        throw new NotFoundException("Acción '{$action}' no encontrada");
    }

    // ========================================
    // Helpers para uso en endpoints
    // ========================================

    /**
     * Obtiene parámetros de query string
     */
    protected function getParam(string $name, mixed $default = null): mixed
    {
        return RequestHelper::getParam($name, $default);
    }

    /**
     * Obtiene el body JSON del request
     *
     * @return array<string, mixed>
     */
    protected function getJsonBody(): array
    {
        return RequestHelper::getJsonBody();
    }

    /**
     * Valida que los campos requeridos estén presentes
     *
     * @param array<string, mixed> $data
     * @param string[] $fields
     * @throws ValidationException
     */
    protected function requireFields(array $data, array $fields): void
    {
        RequestHelper::requireFields($data, $fields);
    }

    /**
     * Envía respuesta de éxito
     *
     * @param array<string, mixed> $data
     */
    protected function success(array $data = [], string $message = null): never
    {
        ResponseHelper::sendSuccess($data, 200, $message);
    }

    /**
     * Envía respuesta de creación exitosa
     *
     * @param array<string, mixed> $data
     */
    protected function created(array $data = [], string $message = 'Recurso creado'): never
    {
        ResponseHelper::sendCreated($data, $message);
    }

    /**
     * Envía respuesta de error
     */
    protected function error(string $message, int $code = 400): never
    {
        ResponseHelper::sendError($message, $code);
    }

    /**
     * Verifica si el usuario actual es administrador
     */
    protected function isAdmin(): bool
    {
        return ($this->user['admin'] ?? false) === true;
    }

    /**
     * Obtiene el nick del usuario actual
     */
    protected function getCurrentUserNick(): ?string
    {
        return $this->user['nick'] ?? null;
    }

    /**
     * Obtiene paginación del request
     *
     * @return array{page: int, limit: int, offset: int}
     */
    protected function getPagination(int $defaultLimit = 50, int $maxLimit = 100): array
    {
        $page = max(1, (int)$this->getParam('page', 1));
        $limit = min($maxLimit, max(1, (int)$this->getParam('limit', $defaultLimit)));
        $offset = ($page - 1) * $limit;

        return [
            'page' => $page,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    /**
     * Construye respuesta paginada
     *
     * @param array<mixed> $items
     * @return array{items: array, pagination: array{page: int, limit: int, total: int, pages: int}}
     */
    protected function paginatedResponse(array $items, int $total, int $page, int $limit): array
    {
        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit)
            ]
        ];
    }
}
