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

use FSFramework\Api\Auth\Contract\ApiAuthInterface;
use FSFramework\Api\Auth\Contract\AllowedUserInterface;
use FSFramework\Api\Exception\UnauthorizedException;
use FSFramework\Api\Helper\RequestHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de autenticación para la API
 *
 * @author FacturaScripts Team
 */
class AuthMiddleware implements MiddlewareInterface
{
    private ?ApiAuthInterface $authModel = null;
    private ?AllowedUserInterface $allowedUserModel = null;

    public function __construct(?ApiAuthInterface $authModel = null, ?AllowedUserInterface $allowedUserModel = null)
    {
        $this->authModel = $authModel;
        $this->allowedUserModel = $allowedUserModel;
    }

    /**
     * Verifica si los modelos de autenticación están configurados
     */
    public function isConfigured(): bool
    {
        return $this->authModel !== null && $this->allowedUserModel !== null;
    }

    /**
     * Procesa el request verificando autenticación
     */
    public function handle(Request $request, callable $next): Response
    {
        $this->authenticate();
        return $next($request);
    }

    /**
     * Autentica el usuario actual
     *
     * @return array<string, mixed> Información del usuario autenticado
     * @throws UnauthorizedException Si no está autenticado
     */
    public function authenticate(): array
    {
        if (!$this->isConfigured()) {
            throw new UnauthorizedException('Sistema de autenticación no configurado. El plugin api_base debe estar activado.');
        }

        $token = RequestHelper::getAuthToken();
        
        if (empty($token)) {
            throw new UnauthorizedException('Token de autenticación requerido');
        }
        
        $result = $this->authModel->validateToken($token);
        
        if (!$result['success']) {
            throw new UnauthorizedException($result['error'] ?? 'Token inválido o expirado');
        }

        $user = $result['user'];
        
        // Verificar que el usuario esté en la lista de permitidos
        if (!$this->allowedUserModel->isUserAllowed($user['nick'])) {
            throw new UnauthorizedException('Usuario no autorizado para acceder a esta API');
        }
        
        // Actualizar último acceso
        $this->allowedUserModel->updateLastAccess($user['nick']);

        return $user;
    }

}
