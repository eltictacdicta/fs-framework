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

namespace FSFramework\Api;

use FSFramework\Api\Router\ApiRouter;
use FSFramework\Api\Router\EndpointRegistry;
use FSFramework\Api\Auth\Contract\ApiLogInterface;
use FSFramework\Api\Middleware\CorsMiddleware;
use FSFramework\Api\Helper\ResponseHelper;
use FSFramework\Api\Exception\ApiException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Kernel principal de la API
 *
 * @author FacturaScripts Team
 */
class ApiKernel
{
    private static ?self $instance = null;

    private ApiRouter $router;
    private EndpointRegistry $registry;
    private CorsMiddleware $cors;
    private bool $debug = false;
    private float $startTime;

    /** @var ApiLogInterface|null Logger inyectable desde plugins */
    private ?ApiLogInterface $apiLogger = null;

    /** @var callable|null Función de logging inyectable desde plugins */
    private $logCallback = null;

    private function __construct()
    {
        $this->startTime = microtime(true);
        $this->debug = defined('FS_DEBUG') && FS_DEBUG;
        $this->registry = EndpointRegistry::getInstance();
        $this->router = new ApiRouter($this->registry);
        $this->cors = new CorsMiddleware();
    }

    /**
     * Establece el logger de la API (permite inyección desde plugins)
     */
    public function setApiLogger(ApiLogInterface $logger): void
    {
        $this->apiLogger = $logger;
    }

    /**
     * Establece una función de logging personalizada
     *
     * @param callable $callback function(?string $userId, string $path, string $method, int $statusCode, float $duration, ?string $errorMessage): bool
     */
    public function setLogCallback(callable $callback): void
    {
        $this->logCallback = $callback;
    }

    /**
     * Obtiene la instancia singleton
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Maneja un request HTTP (punto de entrada principal)
     */
    public static function handle(): never
    {
        $kernel = self::getInstance();
        $kernel->processRequest();
    }

    /**
     * Procesa el request actual
     */
    public function processRequest(): never
    {
        try {
            // Establecer headers CORS
            $this->cors->setHeaders();

            // Obtener path y método
            $path = $this->getPath();
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

            // Manejar rutas especiales
            if ($this->handleSpecialRoutes($path)) {
                exit;
            }

            // Manejar OPTIONS (preflight)
            if ($method === 'OPTIONS') {
                http_response_code(204);
                exit;
            }

            // Ejecutar router
            $result = $this->router->handle($path, $method);

            // Logging
            $this->logRequest($path, $method, $result);

            // Enviar respuesta
            $statusCode = ($result['success'] ?? false) ? 200 : ($result['statusCode'] ?? 400);
            ResponseHelper::sendJson($result, $statusCode);

        } catch (ApiException $e) {
            $this->logRequest($path ?? '', $method ?? 'GET', ['success' => false, 'error' => $e->getMessage()]);
            ResponseHelper::sendJson($e->toArray($this->debug), $e->getHttpStatusCode());
        } catch (\Exception $e) {
            $this->logRequest($path ?? '', $method ?? 'GET', ['success' => false, 'error' => $e->getMessage()]);
            ResponseHelper::sendServerError($this->debug ? $e->getMessage() : 'Error interno del servidor');
        }
    }

    /**
     * Maneja un request Symfony (para integración con Router de Symfony)
     */
    public function handleRequest(Request $request): JsonResponse
    {
        try {
            $path = $request->getPathInfo();
            $method = $request->getMethod();

            // Limpiar /api del inicio si existe
            if (str_starts_with($path, '/api')) {
                $path = substr($path, 4);
            }

            $result = $this->router->handle($path, $method);
            $statusCode = ($result['success'] ?? false) ? 200 : 400;

            return new JsonResponse($result, $statusCode);

        } catch (ApiException $e) {
            return new JsonResponse($e->toArray($this->debug), $e->getHttpStatusCode());
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $this->debug ? $e->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtiene el path del request
     */
    private function getPath(): string
    {
        // Intentar PATH_INFO primero
        if (!empty($_SERVER['PATH_INFO'])) {
            return $_SERVER['PATH_INFO'];
        }

        // Intentar desde REQUEST_URI
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

        // Remover script name del path
        if (str_starts_with($uri, $scriptName)) {
            $uri = substr($uri, strlen($scriptName));
        }

        // Remover query string
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }

        // Intentar parámetro GET
        if (empty($uri) || $uri === '/') {
            $uri = $_GET['api_path'] ?? '/';
        }

        return $uri;
    }

    /**
     * Maneja rutas especiales (docs, health, etc.)
     */
    private function handleSpecialRoutes(string $path): bool
    {
        $path = trim($path, '/');

        // Health check
        if ($path === 'health' || $path === '') {
            ResponseHelper::sendSuccess([
                'status' => 'ok',
                'version' => '1.0.0',
                'timestamp' => date('c')
            ]);
        }

        // Documentación
        if ($path === 'docs') {
            $this->showDocumentation();
            return true;
        }

        // OpenAPI spec
        if ($path === 'openapi.json') {
            $this->showOpenApiSpec();
            return true;
        }

        return false;
    }

    /**
     * Muestra página de documentación
     */
    private function showDocumentation(): never
    {
        $plugins = $this->registry->getPlugins();
        $endpoints = $this->registry->getEndpoints();

        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html><head><title>API Documentation</title></head><body>";
        echo "<h1>API Documentation</h1>";
        echo "<h2>Plugins registrados: " . count($plugins) . "</h2>";
        echo "<h2>Endpoints: " . count($endpoints) . "</h2>";
        echo "<ul>";
        foreach ($endpoints as $key => $info) {
            echo "<li><strong>{$key}</strong> - {$info['class']}</li>";
        }
        echo "</ul>";
        echo "</body></html>";
        exit;
    }

    /**
     * Genera y envía especificación OpenAPI
     */
    private function showOpenApiSpec(): never
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'FSFramework API',
                'version' => '1.0.0',
                'description' => 'API REST del framework FSFramework'
            ],
            'servers' => [
                ['url' => '/api', 'description' => 'API Server']
            ],
            'paths' => []
        ];

        // Generar paths desde endpoints
        foreach ($this->registry->getEndpoints() as $key => $info) {
            [$plugin, $resource] = explode('/', $key);
            $basePath = "/v1/{$plugin}/{$resource}";

            $spec['paths'][$basePath] = [
                'get' => ['summary' => "List {$resource}", 'tags' => [$plugin]],
                'post' => ['summary' => "Create {$resource}", 'tags' => [$plugin]]
            ];
            $spec['paths'][$basePath . '/{id}'] = [
                'get' => ['summary' => "Get {$resource}", 'tags' => [$plugin]],
                'put' => ['summary' => "Update {$resource}", 'tags' => [$plugin]],
                'delete' => ['summary' => "Delete {$resource}", 'tags' => [$plugin]]
            ];
        }

        ResponseHelper::sendJson($spec);
    }

    /**
     * Registra la petición en logs
     *
     * @param array<string, mixed> $result
     */
    private function logRequest(string $path, string $method, array $result): void
    {
        $duration = (microtime(true) - $this->startTime) * 1000; // ms
        $statusCode = ($result['success'] ?? false) ? 200 : 400;
        $userId = $this->router->getUser()['nick'] ?? null;
        $errorMessage = $result['error'] ?? null;

        try {
            // Usar callback si está definido
            if ($this->logCallback !== null) {
                ($this->logCallback)($userId, $path, $method, $statusCode, $duration, $errorMessage);
                return;
            }

            // Usar logger si está inyectado
            if ($this->apiLogger !== null) {
                $this->apiLogger->save();
                return;
            }

            // Si no hay logger configurado, solo registrar en error_log si debug está activo
            if ($this->debug) {
                $safePath = str_replace(["\r", "\n"], '', $path);
                $safeMethod = str_replace(["\r", "\n"], '', $method);
                $safeUser = str_replace(["\r", "\n"], '', $userId ?? 'anonymous');
                error_log(sprintf(
                    "API Request: %s %s - Status: %d - Duration: %.2fms - User: %s",
                    $safeMethod,
                    $safePath,
                    $statusCode,
                    $duration,
                    $safeUser
                ));
            }
        } catch (\Exception $e) {
            // Silenciar errores de logging
            if ($this->debug) {
                error_log("API Log error: " . $e->getMessage());
            }
        }
    }

    /**
     * Obtiene el router
     */
    public function getRouter(): ApiRouter
    {
        return $this->router;
    }

    /**
     * Obtiene el registry
     */
    public function getRegistry(): EndpointRegistry
    {
        return $this->registry;
    }
}
