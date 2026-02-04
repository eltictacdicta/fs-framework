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

namespace FSFramework\Api\Auth\Contract;

/**
 * Interface principal para manejar la autenticación API REST
 *
 * @author FacturaScripts Team
 */
interface ApiAuthInterface
{
    /**
     * Autentica un usuario con nick y contraseña
     * @return array{success: bool, token?: string, refresh_token?: string, user?: array, expires_in?: int, error?: string}
     */
    public function authenticate(string $nick, string $password): array;

    /**
     * Autentica un usuario sin contraseña (para OTL - One Touch Login)
     * @return array{success: bool, token?: string, refresh_token?: string, user?: array, expires_in?: int, error?: string}
     */
    public function authenticateWithoutPassword(\fs_user $user): array;

    /**
     * Cierra la sesión de un usuario
     * @return array{success: bool, message?: string, error?: string}
     */
    public function logout(string $token): array;

    /**
     * Valida un token de acceso
     * @return array{success: bool, user?: array, error?: string}
     */
    public function validateToken(string $token): array;

    /**
     * Valida un refresh token
     * @return array{success: bool, user?: \fs_user, error?: string}
     */
    public function validateRefreshToken(string $refreshToken): array;

    /**
     * Genera nuevos tokens usando un refresh token válido
     * @return array{success: bool, token?: string, refresh_token?: string, user?: array, expires_in?: int, error?: string}
     */
    public function refreshTokens(string $refreshToken): array;

    /**
     * Verifica si el usuario actual es administrador
     */
    public function isAdmin(): bool;

    /**
     * Verifica si el usuario actual tiene acceso a una página
     */
    public function hasAccessTo(string $pageName): bool;

    /**
     * Obtiene el usuario autenticado actual
     */
    public function getCurrentUser(): ?\fs_user;

    /**
     * Obtiene el token actual
     */
    public function getCurrentToken(): ?string;

    /**
     * Limpia el rate limit de un usuario
     */
    public function clearUserRateLimit(string $nick, ?string $ip = null): bool;

    /**
     * Obtiene usuarios con tokens activos
     * @return array<array{nick: string, token_count: int, last_used: ?string, last_created: ?string}>
     */
    public function getUsersWithActiveTokens(): array;

    /**
     * Revoca todos los tokens de un usuario
     */
    public function revokeUserTokens(string $nick): bool;

    /**
     * Obtiene estadísticas de rate limiting
     * @return array<array{nick: string, ip: string, attempts: int, is_blocked: bool, last_attempt: ?string, blocked_until: ?string}>
     */
    public function getRateLimitStatistics(): array;
}
