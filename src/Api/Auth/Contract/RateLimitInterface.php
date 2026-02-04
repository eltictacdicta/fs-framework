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
 * Interface para manejar el rate limiting de la API
 *
 * @author FacturaScripts Team
 */
interface RateLimitInterface
{
    /**
     * Busca un registro de rate limit por usuario e IP
     */
    public function getByUsuarioIp(string $nick_usuario, string $ip_address): self|false;

    /**
     * Guarda el registro
     */
    public function save(): bool;

    /**
     * Elimina el registro
     */
    public function delete(): bool;

    /**
     * Obtiene todos los registros de rate limit activos
     * @return static[]
     */
    public function all(): array;

    /**
     * Limpia entradas antiguas de rate limiting
     */
    public function cleanOldEntries(): bool;

    /**
     * Obtiene estadísticas de rate limiting
     * @return array{total_entries: int, blocked_users: int, avg_attempts: float}
     */
    public function getStatistics(): array;
}
