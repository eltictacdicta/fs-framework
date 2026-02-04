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
 * Interface para manejar los tokens activos de usuarios de la API
 *
 * @author FacturaScripts Team
 */
interface UserTokenInterface
{
    /**
     * Busca un token activo por su hash
     */
    public function getByTokenHash(string $token_hash): self|false;

    /**
     * Busca un refresh token activo por su hash
     */
    public function getByRefreshTokenHash(string $refresh_token_hash): self|false;

    /**
     * Guarda el token
     */
    public function save(): bool;

    /**
     * Elimina el token
     */
    public function delete(): bool;

    /**
     * Obtiene todos los tokens de un usuario
     * @return static[]
     */
    public function getByUsuario(string $nick_usuario): array;

    /**
     * Limpia tokens expirados
     */
    public function cleanExpiredTokens(): bool;

    /**
     * Marca como inactivo todos los tokens de un usuario
     */
    public function invalidateUserTokens(string $nick_usuario): bool;

    /**
     * Marca como inactivo un token específico
     */
    public function invalidateToken(string $token_hash): bool;

    /**
     * Marca como inactivo un refresh token específico por su hash
     */
    public function invalidateRefreshToken(string $refresh_token_hash): bool;

    /**
     * Actualiza la fecha de último uso de un token
     */
    public function updateLastUsed(string $token_hash): bool;

    /**
     * Obtiene estadísticas de tokens activos
     * @return array{total_active_tokens: int, expiring_soon: int, tokens_by_user: array}
     */
    public function getStatistics(): array;

    /**
     * Obtiene lista de usuarios con tokens activos y su conteo
     * @return array<array{nick: string, token_count: int, last_used: ?string, last_created: ?string}>
     */
    public function getUsersWithActiveTokens(): array;

    /**
     * Revoca (invalida) todos los tokens activos de un usuario
     */
    public function revokeAllUserTokens(string $nick_usuario): bool;
}
