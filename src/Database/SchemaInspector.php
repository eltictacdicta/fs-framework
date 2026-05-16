<?php
/**
 * This file is part of FSFramework
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

declare(strict_types=1);

namespace FSFramework\Database;

final class SchemaInspector
{
    private const IDENTIFIER_REGEX = '/^[a-z0-9_]+$/i';

    private object $db;

    public function __construct(object $db)
    {
        $this->db = $db;
    }

    public function getColumns(string $tableName): array
    {
        $quoted = $this->quoteIdentifier($tableName);
        $columns = [];
        $aux = $this->db->select('SHOW COLUMNS FROM ' . $quoted . ';');
        if ($aux) {
            foreach ($aux as $a) {
                $columns[] = [
                    'name' => $a['Field'],
                    'type' => $a['Type'],
                    'default' => $a['Default'],
                    'is_nullable' => $a['Null'],
                    'extra' => $a['Extra'],
                ];
            }
        }

        return $columns;
    }

    public function getConstraints(string $tableName): array
    {
        $tableName = $this->requireIdentifier($tableName, 'table');
        $constraints = [];
        $sql = "SELECT CONSTRAINT_NAME as name, CONSTRAINT_TYPE as type FROM information_schema.table_constraints "
            . 'WHERE table_schema = schema() AND table_name = ' . $this->quoteStringLiteral($tableName) . ';';

        $aux = $this->db->select($sql);
        if ($aux) {
            foreach ($aux as $a) {
                $constraints[] = $a;
            }
        }

        return $constraints;
    }

    public function getConstraintsExtended(string $tableName): array
    {
        $tableName = $this->requireIdentifier($tableName, 'table');
        $constraints = [];
        $sql = "SELECT t1.constraint_name as name,
            t1.constraint_type as type,
            t2.column_name,
            t2.ordinal_position,
            t2.position_in_unique_constraint,
            t2.referenced_table_name AS foreign_table_name,
            t2.referenced_column_name AS foreign_column_name,
            t3.update_rule AS on_update,
            t3.delete_rule AS on_delete
         FROM information_schema.table_constraints t1
         LEFT JOIN information_schema.key_column_usage t2
            ON t1.table_schema = t2.table_schema
            AND t1.table_name = t2.table_name
            AND t1.constraint_name = t2.constraint_name
         LEFT JOIN information_schema.referential_constraints t3
            ON t3.constraint_schema = t1.table_schema
            AND t3.constraint_name = t1.constraint_name
            WHERE t1.table_schema = SCHEMA() AND t1.table_name = " . $this->quoteStringLiteral($tableName) . "
            ORDER BY type DESC, name ASC, t2.ordinal_position ASC;";

        $aux = $this->db->select($sql);
        if ($aux) {
            foreach ($aux as $a) {
                $constraints[] = $a;
            }
        }

        return $constraints;
    }

    public function getIndexes(string $tableName): array
    {
        $tableName = $this->requireIdentifier($tableName, 'table');
        $indexes = [];
        $aux = $this->db->select('SHOW INDEXES FROM ' . $this->quoteIdentifier($tableName) . ';');
        if ($aux) {
            foreach ($aux as $a) {
                $indexes[] = ['name' => $a['Key_name']];
            }
        }

        return $indexes;
    }

    public function getFkColumnNames(string $tableName): array
    {
        $tableName = $this->requireIdentifier($tableName, 'table');
        $columns = [];
        $sql = "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE "
            . 'WHERE TABLE_SCHEMA = SCHEMA() AND TABLE_NAME = ' . $this->quoteStringLiteral($tableName) . ' '
            . "AND REFERENCED_TABLE_NAME IS NOT NULL;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $columns[] = $d['COLUMN_NAME'];
            }
        }
        return $columns;
    }

    private function requireIdentifier(string $identifier, string $kind = 'identifier'): string
    {
        if (!preg_match(self::IDENTIFIER_REGEX, $identifier)) {
            throw new \InvalidArgumentException('Invalid SQL ' . $kind . ': ' . $identifier);
        }

        return $identifier;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . $this->requireIdentifier($identifier) . '`';
    }

    private function quoteStringLiteral(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }
}
