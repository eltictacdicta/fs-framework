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

use FSFramework\Api\Exception\ApiException;
use FSFramework\Api\Helper\ResponseHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Middleware para rate limiting
 *
 * @author FacturaScripts Team
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /** Límite de requests por ventana */
    private int $limit;
    
    /** Ventana de tiempo en segundos */
    private int $window;
    
    /** Identificador del usuario/IP actual */
    private ?string $identifier = null;

    /** @var array<string, array{count: int, reset: int}> Cache en memoria */
    private static array $cache = [];

    /**
     * @param int $limit Número máximo de requests permitidos
     * @param int $window Ventana de tiempo en segundos
     */
    public function __construct(int $limit = 100, int $window = 60)
    {
        $this->limit = $limit;
        $this->window = $window;
    }

    /**
     * Procesa el request verificando rate limit
     */
    public function handle(Request $request, callable $next): Response
    {
        $this->identifier = $this->getIdentifier($request);
        
        if (!$this->checkLimit()) {
            $resetTime = $this->getResetTime();
            $retryAfter = max(1, $resetTime - time());
            
            return new JsonResponse([
                'success' => false,
                'error' => 'Demasiadas solicitudes. Por favor, espere.',
                'retry_after' => $retryAfter
            ], 429, [
                'Retry-After' => (string)$retryAfter,
                'X-RateLimit-Limit' => (string)$this->limit,
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string)$resetTime
            ]);
        }

        /** @var Response $response */
        $response = $next($request);
        
        // Añadir headers de rate limit
        $response->headers->set('X-RateLimit-Limit', (string)$this->limit);
        $response->headers->set('X-RateLimit-Remaining', (string)$this->getRemainingRequests());
        $response->headers->set('X-RateLimit-Reset', (string)$this->getResetTime());
        
        return $response;
    }

    /**
     * Obtiene el identificador único para el rate limiting
     */
    private function getIdentifier(Request $request): string
    {
        // Usar nick del usuario si está autenticado, sino IP
        $authHeader = $request->headers->get('Authorization', '');
        if (!empty($authHeader)) {
            return 'user:' . md5($authHeader);
        }
        
        return 'ip:' . $request->getClientIp();
    }

    /**
     * Verifica si el request está dentro del límite
     */
    public function checkLimit(): bool
    {
        if ($this->identifier === null) {
            $this->identifier = 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        }

        $now = time();
        $key = $this->identifier;

        // Verificar si existe en cache
        if (!isset(self::$cache[$key])) {
            self::$cache[$key] = [
                'count' => 0,
                'reset' => $now + $this->window
            ];
        }

        // Si la ventana expiró, resetear
        if (self::$cache[$key]['reset'] <= $now) {
            self::$cache[$key] = [
                'count' => 0,
                'reset' => $now + $this->window
            ];
        }

        // Incrementar contador
        self::$cache[$key]['count']++;

        return self::$cache[$key]['count'] <= $this->limit;
    }

    /**
     * Obtiene los requests restantes
     */
    public function getRemainingRequests(): int
    {
        if ($this->identifier === null || !isset(self::$cache[$this->identifier])) {
            return $this->limit;
        }

        return max(0, $this->limit - self::$cache[$this->identifier]['count']);
    }

    /**
     * Obtiene el tiempo de reset
     */
    public function getResetTime(): int
    {
        if ($this->identifier === null || !isset(self::$cache[$this->identifier])) {
            return time() + $this->window;
        }

        return self::$cache[$this->identifier]['reset'];
    }

    /**
     * Establece el límite
     */
    public function setLimit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Establece la ventana de tiempo
     */
    public function setWindow(int $window): self
    {
        $this->window = $window;
        return $this;
    }

    /**
     * Limpia el cache (útil para tests)
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
