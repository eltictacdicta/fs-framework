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

namespace FSFramework\Api\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para manejo de CORS
 *
 * @author FacturaScripts Team
 */
class CorsMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $allowedOrigins;
    
    /** @var string[] */
    private array $allowedMethods;
    
    /** @var string[] */
    private array $allowedHeaders;
    
    private bool $allowCredentials;
    private int $maxAge;

    /**
     * @param array{origins?: string[], methods?: string[], headers?: string[], credentials?: bool, max_age?: int} $options
     */
    public function __construct(array $options = [])
    {
        $this->allowedOrigins = $options['origins'] ?? $this->getConfiguredOrigins();
        $this->allowedMethods = $options['methods'] ?? ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];
        $this->allowedHeaders = $options['headers'] ?? ['Content-Type', 'Authorization', 'X-Auth-Token', 'X-Requested-With'];
        $this->allowCredentials = $options['credentials'] ?? false;
        $this->maxAge = $options['max_age'] ?? 86400;
    }

    /**
     * @return string[]
     */
    private function getConfiguredOrigins(): array
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

    private function resolveAllowedOrigin(?string $origin): ?string
    {
        if (!is_string($origin) || $origin === '') {
            return null;
        }

        if (in_array('*', $this->allowedOrigins, true)) {
            // Security check: reject insecure wildcard+credentials combination
            if ($this->allowCredentials) {
                if (class_exists('fs_core_log')) {
                    $log = new \fs_core_log();
                    $log->new_error('CORS security error: wildcard origin "*" combined with credentials=true is insecure and denied.');
                }
                return null;
            }
            return $origin;
        }

        return in_array($origin, $this->allowedOrigins, true) ? $origin : null;
    }

    /**
     * Procesa el request añadiendo headers CORS
     */
    public function handle(Request $request, callable $next): Response
    {
        // Manejar preflight OPTIONS
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response('', 204);
            $this->addCorsHeaders($response, $request);
            return $response;
        }

        /** @var Response $response */
        $response = $next($request);
        $this->addCorsHeaders($response, $request);
        
        return $response;
    }

    /**
     * Añade headers CORS a la respuesta
     */
    public function addCorsHeaders(Response $response, ?Request $request = null): void
    {
        $origin = $request?->headers->get('Origin');
        $allowedOrigin = $this->resolveAllowedOrigin($origin);

        if ($allowedOrigin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
            $response->headers->set('Vary', 'Origin');
        }
        
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));
        $response->headers->set('Access-Control-Max-Age', (string)$this->maxAge);
        
        if ($this->allowCredentials) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }
    }

    /**
     * Establece headers CORS directamente (sin Symfony Response)
     */
    public function setHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $allowedOrigin = $this->resolveAllowedOrigin(is_string($origin) ? $origin : null);

        if ($allowedOrigin !== null) {
            header('Access-Control-Allow-Origin: ' . $allowedOrigin);
            header('Vary: Origin');
        }
        
        header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
        header('Access-Control-Max-Age: ' . $this->maxAge);
        
        if ($this->allowCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }
    }

    /**
     * Maneja preflight OPTIONS (legacy)
     */
    public function handlePreflight(): never
    {
        $this->setHeaders();
        http_response_code(204);
        exit;
    }

    /**
     * Añade un origen permitido
     */
    public function allowOrigin(string $origin): self
    {
        if (!in_array($origin, $this->allowedOrigins, true)) {
            $this->allowedOrigins[] = $origin;
        }
        return $this;
    }

    /**
     * Añade un método permitido
     */
    public function allowMethod(string $method): self
    {
        $method = strtoupper($method);
        if (!in_array($method, $this->allowedMethods, true)) {
            $this->allowedMethods[] = $method;
        }
        return $this;
    }

    /**
     * Añade un header permitido
     */
    public function allowHeader(string $header): self
    {
        if (!in_array($header, $this->allowedHeaders, true)) {
            $this->allowedHeaders[] = $header;
        }
        return $this;
    }
}
