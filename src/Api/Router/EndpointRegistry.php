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

/**
 * Registro singleton de endpoints de la API
 *
 * @author FacturaScripts Team
 */
class EndpointRegistry
{
    private static ?self $instance = null;

    /** @var array<string, array{class: class-string<ApiEndpoint>, file: string, config: array}> */
    private array $endpoints = [];

    /** @var array<string, array{plugin_name: string, version: string, auth_class?: string, auth_file?: string, endpoints: string[], public_routes?: string[], config?: array}> */
    private array $plugins = [];

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
     * Escanea plugins en busca de api_endpoints.php
     */
    public function scan(?string $pluginsDir = null): void
    {
        if ($this->scanned) {
            return;
        }

        $pluginsDir = $pluginsDir ?? (defined('FS_FOLDER') ? FS_FOLDER . '/plugins' : 'plugins');
        
        if (!is_dir($pluginsDir)) {
            return;
        }

        $dirs = scandir($pluginsDir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $configFile = $pluginsDir . '/' . $dir . '/api_endpoints.php';
            if (file_exists($configFile)) {
                $config = require $configFile;
                if (is_array($config)) {
                    $this->registerPlugin($dir, $config, $pluginsDir);
                }
            }
        }

        $this->scanned = true;
    }

    /**
     * Registra un plugin con sus endpoints
     *
     * @param array{plugin_name: string, version?: string, auth_class?: string, auth_file?: string, endpoints?: string[], public_routes?: string[], config?: array} $config
     */
    public function registerPlugin(string $pluginFolder, array $config, string $pluginsDir): void
    {
        $pluginName = $config['plugin_name'] ?? $pluginFolder;
        $version = $config['version'] ?? 'v1';
        
        $this->plugins[$pluginName] = $config;

        // Registrar cada endpoint
        $endpoints = $config['endpoints'] ?? [];
        foreach ($endpoints as $endpointClass) {
            $this->registerEndpoint($pluginName, $endpointClass, $pluginsDir . '/' . $pluginFolder, $config);
        }
    }

    /**
     * Registra un endpoint individual
     *
     * @param array<string, mixed> $pluginConfig
     */
    private function registerEndpoint(string $plugin, string $endpointClass, string $pluginPath, array $pluginConfig): void
    {
        // Buscar archivo del endpoint
        $possiblePaths = [
            $pluginPath . '/model/endpoints/' . $endpointClass . '.php',
            $pluginPath . '/Endpoint/' . $endpointClass . '.php',
            $pluginPath . '/src/Endpoint/' . $endpointClass . '.php',
        ];

        $endpointFile = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $endpointFile = $path;
                break;
            }
        }

        if ($endpointFile === null) {
            error_log("API: Endpoint file not found for {$endpointClass} in {$plugin}");
            return;
        }

        // Deducir nombre del recurso
        $resource = strtolower(str_replace('Endpoint', '', $endpointClass));
        
        $key = $plugin . '/' . $resource;
        $this->endpoints[$key] = [
            'class' => $endpointClass,
            'file' => $endpointFile,
            'config' => $pluginConfig
        ];
    }

    /**
     * Encuentra un endpoint por plugin y recurso
     *
     * @return array{class: string, file: string, config: array}|null
     */
    public function findEndpoint(string $plugin, string $resource): ?array
    {
        $this->scan();
        
        $key = $plugin . '/' . $resource;
        return $this->endpoints[$key] ?? null;
    }

    /**
     * Obtiene todos los endpoints registrados
     *
     * @return array<string, array{class: string, file: string, config: array}>
     */
    public function getEndpoints(): array
    {
        $this->scan();
        return $this->endpoints;
    }

    /**
     * Obtiene todos los plugins registrados
     *
     * @return array<string, array>
     */
    public function getPlugins(): array
    {
        $this->scan();
        return $this->plugins;
    }

    /**
     * Obtiene configuración de un plugin
     *
     * @return array<string, mixed>|null
     */
    public function getPluginConfig(string $plugin): ?array
    {
        $this->scan();
        return $this->plugins[$plugin] ?? null;
    }

    /**
     * Verifica si una ruta es pública (no requiere auth)
     */
    public function isPublicRoute(string $path): bool
    {
        $this->scan();
        
        foreach ($this->plugins as $config) {
            $publicRoutes = $config['public_routes'] ?? [];
            foreach ($publicRoutes as $route) {
                if ($this->matchRoute($route, $path)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Compara una ruta con un patrón
     */
    private function matchRoute(string $pattern, string $path): bool
    {
        // Convertir patrón a regex
        $regex = str_replace(['/', '*'], ['\/', '.*'], $pattern);
        return (bool)preg_match('/^' . $regex . '$/', $path);
    }

    /**
     * Carga e instancia un endpoint
     */
    public function loadEndpoint(string $plugin, string $resource): ?ApiEndpoint
    {
        $info = $this->findEndpoint($plugin, $resource);
        
        if ($info === null) {
            return null;
        }

        // Cargar archivo
        require_once $info['file'];

        $className = $info['class'];
        
        // Intentar con namespace del plugin
        $namespacedClass = "\\FacturaScripts\\Plugins\\" . ucfirst($plugin) . "\\Endpoint\\" . $className;
        if (class_exists($namespacedClass)) {
            return new $namespacedClass();
        }

        // Intentar sin namespace
        if (class_exists($className)) {
            return new $className();
        }

        return null;
    }

    /**
     * Resetea el registro (útil para tests)
     */
    public function reset(): void
    {
        $this->endpoints = [];
        $this->plugins = [];
        $this->scanned = false;
    }
}
