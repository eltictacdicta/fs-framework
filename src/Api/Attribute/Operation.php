<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
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

namespace FSFramework\Api\Attribute;

/**
 * Enum de operaciones CRUD para la API
 *
 * @author FacturaScripts Team
 */
enum Operation: string
{
    case LIST = 'list';
    case GET = 'get';
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';

    /**
     * Obtiene todas las operaciones
     *
     * @return Operation[]
     */
    public static function all(): array
    {
        return [
            self::LIST,
            self::GET,
            self::CREATE,
            self::UPDATE,
            self::DELETE
        ];
    }

    /**
     * Operaciones de solo lectura
     *
     * @return Operation[]
     */
    public static function readOnly(): array
    {
        return [
            self::LIST,
            self::GET
        ];
    }

    /**
     * Operaciones de escritura
     *
     * @return Operation[]
     */
    public static function write(): array
    {
        return [
            self::CREATE,
            self::UPDATE,
            self::DELETE
        ];
    }

    /**
     * Mapea método HTTP a operación
     */
    public static function fromHttpMethod(string $method, bool $hasId): ?self
    {
        return match ($method) {
            'GET' => $hasId ? self::GET : self::LIST,
            'POST' => self::CREATE,
            'PUT', 'PATCH' => self::UPDATE,
            'DELETE' => self::DELETE,
            default => null
        };
    }
}
