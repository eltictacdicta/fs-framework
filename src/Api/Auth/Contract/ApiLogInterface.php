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
 * Interface para el registro de peticiones a la API
 *
 * @author FacturaScripts Team
 */
interface ApiLogInterface
{
    /**
     * Guarda el log
     */
    public function save(): bool;

    /**
     * Obtiene logs recientes
     * @return static[]
     */
    public function getRecent(int $limit = 100, int $offset = 0): array;

    /**
     * Obtiene logs por usuario
     * @return static[]
     */
    public function getByUser(string $userId, int $limit = 50): array;

    /**
     * Obtiene logs con errores
     * @return static[]
     */
    public function getErrors(int $limit = 100): array;

    /**
     * Obtiene estadísticas de uso de la API
     * @return array|null
     */
    public function getStatistics(int $days = 7, ?string $userId = null): ?array;

    /**
     * Obtiene estadísticas por endpoint
     * @return array|null
     */
    public function getEndpointStats(int $days = 7): ?array;

    /**
     * Limpia logs antiguos
     */
    public function cleanOldLogs(int $days = 30): bool;

    /**
     * Cuenta el total de logs
     */
    public function countAll(): int;
}
