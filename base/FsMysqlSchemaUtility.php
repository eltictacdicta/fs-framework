<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com> (lead developer of Facturascript)
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

/**
 * Pure utility functions for MySQL schema operations.
 * Extracted from fs_mysql to avoid requiring a database connection.
 */
class FsMysqlSchemaUtility
{
    /**
     * Normaliza una lista de identificadores separados por comas,
     * devolviendo un array con cada identificador normalizado.
     *
     * @param string $list
     * @return array
     */
    public static function normalizeIdentifierList(string $list): array
    {
        $items = array_map('trim', explode(',', $list));
        $items = array_filter($items, static function ($item): bool {
            return $item !== '';
        });

        return array_map(function ($item): string {
            return self::normalizeIdentifier($item);
        }, $items);
    }

    /**
     * Normaliza un identificador (nombre de columna, tabla, etc.)
     * eliminando backticks, comillas y prefijos de esquema/tabla.
     *
     * @param string $identifier
     * @return string
     */
    public static function normalizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        $identifier = trim($identifier, "`\" ");

        if (strpos($identifier, '.') !== false) {
            $parts = explode('.', $identifier);
            $identifier = end($parts);
            $identifier = trim((string) $identifier, "`\" ");
        }

        return strtolower($identifier);
    }

    /**
     * Indica si el tipo de columna admite collation en MySQL.
     *
     * @param string $columnType
     * @return bool
     */
    public static function isCollatableColumnType($columnType): bool
    {
        $type = strtolower(trim((string) $columnType));
        return strpos($type, 'char') !== false
            || strpos($type, 'text') !== false
            || strpos($type, 'enum(') === 0
            || strpos($type, 'set(') === 0;
    }
}
