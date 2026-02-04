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

namespace FSFramework\Api\Resource;

use FSFramework\Api\Attribute\ApiResource;
use FSFramework\Api\Attribute\Operation;
use ReflectionClass;

/**
 * Registro de modelos expuestos a la API mediante atributos o configuración
 *
 * @author FacturaScripts Team
 */
class ModelResourceRegistry
{
    private static ?self $instance = null;

    /** @var array<string, array{class: string, config: ApiResource|array}> */
    private array $resources = [];

    /** @var bool */
    private bool $scanned = false;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Escanea modelos en busca del atributo #[ApiResource]
     */
    public function scan(?string $modelDir = null): void
    {
        if ($this->scanned) {
            return;
        }

        $fsFolder = defined('FS_FOLDER') ? FS_FOLDER : '.';

        // Escanear modelo principal
        $this->scanDirectory($modelDir ?? $fsFolder . '/model');

        // Escanear plugins
        $pluginsDir = $fsFolder . '/plugins';
        if (is_dir($pluginsDir)) {
            $plugins = $GLOBALS['plugins'] ?? [];
            foreach ($plugins as $plugin) {
                $pluginModelDir = $pluginsDir . '/' . $plugin . '/model';
                if (is_dir($pluginModelDir)) {
                    $this->scanDirectory($pluginModelDir, $plugin);
                }

                // Cargar api_models.php si existe
                $apiModelsFile = $pluginsDir . '/' . $plugin . '/api_models.php';
                if (file_exists($apiModelsFile)) {
                    $this->loadApiModelsConfig($apiModelsFile, $plugin);
                }
            }
        }

        $this->scanned = true;
    }

    /**
     * Escanea un directorio de modelos
     */
    private function scanDirectory(string $dir, ?string $plugin = null): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*.php');
        foreach ($files as $file) {
            $this->scanFile($file, $plugin);
        }
    }

    /**
     * Escanea un archivo PHP en busca de ApiResource
     */
    private function scanFile(string $file, ?string $plugin = null): void
    {
        $className = pathinfo($file, PATHINFO_FILENAME);

        // Cargar el archivo si no está cargado
        if (!class_exists($className, false)) {
            require_once $file;
        }

        if (!class_exists($className)) {
            return;
        }

        $reflection = new ReflectionClass($className);
        $attributes = $reflection->getAttributes(ApiResource::class);

        if (empty($attributes)) {
            return;
        }

        /** @var ApiResource $apiResource */
        $apiResource = $attributes[0]->newInstance();

        // Determinar nombre del recurso
        $resourceName = $apiResource->resource ?? strtolower($className);
        $pluginName = $apiResource->plugin ?? $plugin ?? 'core';

        $key = $pluginName . '/' . $resourceName;
        $this->resources[$key] = [
            'class' => $className,
            'config' => $apiResource,
            'file' => $file
        ];
    }

    /**
     * Carga configuración desde api_models.php
     */
    private function loadApiModelsConfig(string $file, string $plugin): void
    {
        $config = require $file;

        if (!is_array($config)) {
            return;
        }

        foreach ($config as $modelName => $modelConfig) {
            // Convertir array a ApiResource-like config
            $resourceName = $modelConfig['resource'] ?? strtolower($modelName);
            $key = $plugin . '/' . $resourceName;

            // Convertir operaciones string a enum si es necesario
            $operations = [];
            foreach ($modelConfig['operations'] ?? [] as $op) {
                if ($op instanceof Operation) {
                    $operations[] = $op;
                } elseif (is_string($op)) {
                    $operations[] = Operation::from($op);
                }
            }

            $this->resources[$key] = [
                'class' => $modelName,
                'config' => [
                    'operations' => $operations,
                    'searchable' => $modelConfig['searchable'] ?? [],
                    'sortable' => $modelConfig['sortable'] ?? [],
                    'filterable' => $modelConfig['filterable'] ?? [],
                    'hidden_fields' => $modelConfig['hidden_fields'] ?? [],
                    'resource_class' => $modelConfig['resource_class'] ?? null,
                    'requiresAuth' => $modelConfig['requiresAuth'] ?? true,
                    'perPage' => $modelConfig['perPage'] ?? 50
                ],
                'file' => null // Se cargará dinámicamente
            ];
        }
    }

    /**
     * Registra un modelo manualmente
     */
    public function register(string $plugin, string $resourceName, string $modelClass, ApiResource|array $config): void
    {
        $key = $plugin . '/' . $resourceName;
        $this->resources[$key] = [
            'class' => $modelClass,
            'config' => $config,
            'file' => null
        ];
    }

    /**
     * Obtiene información de un recurso
     *
     * @return array{class: string, config: ApiResource|array, file: ?string}|null
     */
    public function get(string $plugin, string $resource): ?array
    {
        $this->scan();
        $key = $plugin . '/' . $resource;
        return $this->resources[$key] ?? null;
    }

    /**
     * Obtiene todos los recursos registrados
     *
     * @return array<string, array{class: string, config: ApiResource|array}>
     */
    public function getAll(): array
    {
        $this->scan();
        return $this->resources;
    }

    /**
     * Verifica si una operación está permitida para un recurso
     */
    public function isOperationAllowed(string $plugin, string $resource, Operation $operation): bool
    {
        $info = $this->get($plugin, $resource);
        
        if ($info === null) {
            return false;
        }

        $config = $info['config'];

        if ($config instanceof ApiResource) {
            return $config->allowsOperation($operation);
        }

        // Array config
        $operations = $config['operations'] ?? [];
        return in_array($operation, $operations, true);
    }

    /**
     * Instancia un modelo
     */
    public function createModel(string $plugin, string $resource): ?object
    {
        $info = $this->get($plugin, $resource);
        
        if ($info === null) {
            return null;
        }

        $className = $info['class'];

        // Cargar archivo si es necesario
        if (!class_exists($className) && $info['file'] !== null) {
            require_once $info['file'];
        }

        if (class_exists($className)) {
            return new $className();
        }

        return null;
    }

    /**
     * Resetea el registro
     */
    public function reset(): void
    {
        $this->resources = [];
        $this->scanned = false;
    }
}
