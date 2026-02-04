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

use FSFramework\Api\Attribute\ApiResource;
use FSFramework\Api\Attribute\Operation;
use FSFramework\Api\Resource\ModelResourceRegistry;
use FSFramework\Api\Resource\ResourceTransformer;
use FSFramework\Api\Exception\NotFoundException;
use FSFramework\Api\Exception\ForbiddenException;
use FSFramework\Api\Exception\ValidationException;

/**
 * Endpoint genérico que procesa modelos con #[ApiResource]
 *
 * @author FacturaScripts Team
 */
class ModelResourceEndpoint extends ApiEndpoint
{
    protected string $modelClass;
    protected ApiResource|array $config;
    protected ResourceTransformer $transformer;
    protected ModelResourceRegistry $registry;

    /** @var string[] Campos ocultos adicionales */
    protected array $hiddenFields = [];

    public function __construct(string $plugin, string $resource)
    {
        $this->plugin = $plugin;
        $this->resource = $resource;
        $this->transformer = new ResourceTransformer();
        $this->registry = ModelResourceRegistry::getInstance();

        $info = $this->registry->get($plugin, $resource);
        if ($info === null) {
            throw new NotFoundException("Recurso no encontrado: {$plugin}/{$resource}");
        }

        $this->modelClass = $info['class'];
        $this->config = $info['config'];

        if (is_array($this->config)) {
            $this->hiddenFields = $this->config['hidden_fields'] ?? [];
            $this->requiresAuth = $this->config['requiresAuth'] ?? true;
        } else {
            $this->requiresAuth = $this->config->requiresAuth;
        }
    }

    /**
     * Lista recursos (GET /resource)
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        $this->checkOperation(Operation::LIST);

        $model = $this->createModel();
        $pagination = $this->getPagination(
            $this->getConfigValue('perPage', 50),
            $this->getConfigValue('maxPerPage', 100)
        );

        // Búsqueda
        $search = $this->getParam('search');
        $sort = $this->getParam('sort');
        $order = strtoupper($this->getParam('order', 'ASC'));

        // Obtener items
        if (method_exists($model, 'all')) {
            $items = $model->all();
        } else {
            throw new NotFoundException('El modelo no soporta listado');
        }

        // Aplicar filtros si el modelo lo soporta
        if ($search && method_exists($model, 'search')) {
            $items = $model->search($search, $pagination['limit']);
        }

        // Contar total
        $total = method_exists($model, 'count') ? $model->count() : count($items);

        // Paginar manualmente si es necesario
        if (is_array($items)) {
            $items = array_slice($items, $pagination['offset'], $pagination['limit']);
        }

        return [
            'success' => true,
            'items' => $this->transformer->toArrayList($items, $this->hiddenFields),
            'pagination' => [
                'page' => $pagination['page'],
                'limit' => $pagination['limit'],
                'total' => $total,
                'pages' => (int)ceil($total / $pagination['limit'])
            ]
        ];
    }

    /**
     * Obtiene un recurso (GET /resource/{id})
     *
     * @return array<string, mixed>
     */
    public function get(string|int $id): array
    {
        $this->checkOperation(Operation::GET);

        $model = $this->findOrFail($id);

        return [
            'success' => true,
            'item' => $this->transformer->toArray($model, $this->hiddenFields)
        ];
    }

    /**
     * Crea un recurso (POST /resource)
     *
     * @return array<string, mixed>
     */
    public function create(): array
    {
        $this->checkOperation(Operation::CREATE);

        $data = $this->getJsonBody();
        $data = $this->transformer->filterWritableFields($this->modelClass, $data);

        // Validar campos requeridos
        $required = $this->transformer->getRequiredFields($this->modelClass);
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new ValidationException("Campo requerido: {$field}");
            }
        }

        $model = $this->createModel();

        // Asignar datos
        foreach ($data as $key => $value) {
            if (property_exists($model, $key)) {
                $model->$key = $value;
            }
        }

        // Validar si el modelo tiene método test()
        if (method_exists($model, 'test') && !$model->test()) {
            throw new ValidationException('Validación del modelo fallida');
        }

        // Guardar
        if (!$model->save()) {
            throw new ValidationException('Error al guardar el recurso');
        }

        return [
            'success' => true,
            'message' => 'Recurso creado exitosamente',
            'item' => $this->transformer->toArray($model, $this->hiddenFields)
        ];
    }

    /**
     * Actualiza un recurso (PUT/PATCH /resource/{id})
     *
     * @return array<string, mixed>
     */
    public function update(string|int $id): array
    {
        $this->checkOperation(Operation::UPDATE);

        $model = $this->findOrFail($id);
        $data = $this->getJsonBody();
        $data = $this->transformer->filterWritableFields($this->modelClass, $data);

        // Asignar datos
        foreach ($data as $key => $value) {
            if (property_exists($model, $key)) {
                $model->$key = $value;
            }
        }

        // Validar
        if (method_exists($model, 'test') && !$model->test()) {
            throw new ValidationException('Validación del modelo fallida');
        }

        // Guardar
        if (!$model->save()) {
            throw new ValidationException('Error al actualizar el recurso');
        }

        return [
            'success' => true,
            'message' => 'Recurso actualizado exitosamente',
            'item' => $this->transformer->toArray($model, $this->hiddenFields)
        ];
    }

    /**
     * Elimina un recurso (DELETE /resource/{id})
     *
     * @return array<string, mixed>
     */
    public function delete(string|int $id): array
    {
        $this->checkOperation(Operation::DELETE);

        $model = $this->findOrFail($id);

        if (!$model->delete()) {
            throw new ValidationException('Error al eliminar el recurso');
        }

        return [
            'success' => true,
            'message' => 'Recurso eliminado exitosamente'
        ];
    }

    /**
     * Verifica que la operación esté permitida
     */
    protected function checkOperation(Operation $operation): void
    {
        if (!$this->registry->isOperationAllowed($this->plugin, $this->resource, $operation)) {
            throw new ForbiddenException("Operación '{$operation->value}' no permitida para este recurso");
        }
    }

    /**
     * Busca un modelo por ID o lanza excepción
     */
    protected function findOrFail(string|int $id): object
    {
        $model = $this->createModel();

        if (method_exists($model, 'get')) {
            $found = $model->get($id);
            if ($found) {
                return $found;
            }
        }

        throw new NotFoundException("Recurso no encontrado: {$id}");
    }

    /**
     * Crea una instancia del modelo
     */
    protected function createModel(): object
    {
        $model = $this->registry->createModel($this->plugin, $this->resource);
        
        if ($model === null) {
            throw new NotFoundException("No se pudo crear el modelo: {$this->modelClass}");
        }

        return $model;
    }

    /**
     * Obtiene un valor de configuración
     */
    protected function getConfigValue(string $key, mixed $default = null): mixed
    {
        if ($this->config instanceof ApiResource) {
            return $this->config->$key ?? $default;
        }

        return $this->config[$key] ?? $default;
    }
}
