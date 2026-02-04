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
 * Interface para manejar los eventos de seguridad de la API
 *
 * @author FacturaScripts Team
 */
interface SecurityEventInterface
{
    // Constantes de tipos de eventos
    public const EVENT_LOGIN_SUCCESS = 'LOGIN_SUCCESS';
    public const EVENT_LOGIN_FAILED = 'LOGIN_FAILED';
    public const EVENT_RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';
    public const EVENT_TOKEN_EXPIRED = 'TOKEN_EXPIRED';
    public const EVENT_INVALID_TOKEN = 'INVALID_TOKEN';
    public const EVENT_LOGOUT = 'LOGOUT';
    public const EVENT_REFRESH_TOKEN = 'REFRESH_TOKEN';
    public const EVENT_TOKENS_REVOKED = 'TOKENS_REVOKED';
    public const EVENT_SECURITY_VIOLATION = 'SECURITY_VIOLATION';

    /**
     * Guarda el evento
     */
    public function save(): bool;

    /**
     * Obtiene todos los eventos
     * @return static[]
     */
    public function all(): array;

    /**
     * Obtiene eventos por tipo
     * @return static[]
     */
    public function getByType(string $event_type): array;

    /**
     * Obtiene eventos por usuario
     * @return static[]
     */
    public function getByUsuario(string $nick_usuario): array;

    /**
     * Obtiene eventos recientes
     * @return static[]
     */
    public function getRecentEvents(int $hours = 24): array;

    /**
     * Limpia eventos antiguos
     */
    public function cleanOldEvents(int $days = 30): bool;
}
