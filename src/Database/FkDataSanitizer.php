<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
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

/**
 * Normalises '' to NULL on columns that are about to receive a constraint, so
 * a FOREIGN KEY or UNIQUE index can be created without an empty string being
 * interpreted as a real reference.
 *
 * MySQL rejects `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY` with errno
 * 1452 when a row holds '' in the referencing column and no key '' exists in
 * the referenced table. The same data is what breaks the schema-sync path
 * before the model path ever runs.
 *
 * This is the single implementation shared by:
 *  - `fs_model::pre_migrate_data()` (legacy model path, instance based), and
 *  - `fs_schema::syncConstraints()` (XML schema-sync path, static).
 *
 * Guards (never falsify a real problem):
 *  - only nullable columns are touched (DB metadata OR the XML says NULL),
 *  - a '' FK is preserved when the referenced table itself holds '' as a key,
 *  - every identifier is validated before it reaches the SQL.
 */
final class FkDataSanitizer
{
    private const IDENTIFIER_REGEX = '/^[a-z0-9_]+$/i';

    /**
     * @param object $db Database engine exposing get_columns(), select() and exec().
     * @param string $tableName Table whose columns are normalised.
     * @param array<int, array{nombre?: string, consulta?: string}> $constraints XML constraints.
     * @param array<int, array{nombre?: string, nulo?: string}> $xmlColumns XML columns, nullability source.
     *
     * @return array{
     *     statements: list<string>,
     *     failures: list<array{sql: string, error: string}>
     * }
     */
    public static function normalizeEmptyStringsForConstraints(
        $db,
        string $tableName,
        array $constraints,
        array $xmlColumns = []
    ): array {
        $result = ['statements' => [], 'failures' => []];

        if (!self::isValidIdentifier($tableName)) {
            return $result;
        }

        $quote = self::identifierQuote();
        $dbColumns = null;

        foreach ($constraints as $constraint) {
            $target = self::columnToNormalize((string) ($constraint['consulta'] ?? ''));
            if ($target === null) {
                continue;
            }

            $columnName = $target['column'];
            if (!self::isValidIdentifier($columnName)) {
                continue;
            }

            if ($dbColumns === null) {
                $dbColumns = $db->get_columns($tableName);
            }

            if (!self::isNullableColumn($dbColumns, $xmlColumns, $columnName)) {
                continue;
            }

            if ($target['reference_table'] !== null && self::referencedKeyAcceptsEmptyString($db, $target)) {
                continue;
            }

            $sql = 'UPDATE ' . $quote . $tableName . $quote
                . ' SET ' . $quote . $columnName . $quote . ' = NULL'
                . ' WHERE ' . $quote . $columnName . $quote . " = '';";

            $result['statements'][] = $sql;

            try {
                if ($db->exec($sql) === false) {
                    $result['failures'][] = ['sql' => $sql, 'error' => self::lastError($db)];
                }
            } catch (\Throwable $e) {
                // A column that the XML will soften later can reject NULL until
                // compare_columns() runs; do not abort the whole check, but let
                // the caller decide whether to report the failure.
                $message = $e->getMessage() !== '' ? $e->getMessage() : self::lastError($db);
                $result['failures'][] = ['sql' => $sql, 'error' => $message];
            }
        }

        return $result;
    }

    /**
     * Describes the column to normalise when the constraint is a one-column
     * UNIQUE or FOREIGN KEY; NULL for any other constraint.
     *
     * @return array{column: string, reference_table: ?string, reference_column: ?string}|null
     */
    private static function columnToNormalize(string $consulta): ?array
    {
        if (preg_match('/^UNIQUE\s*\((\w+)\)/i', $consulta, $matches)) {
            return ['column' => $matches[1], 'reference_table' => null, 'reference_column' => null];
        }

        if (preg_match('/^FOREIGN\s+KEY\s*\((\w+)\)\s+REFERENCES\s+(\w+)\s*\((\w+)\)/i', $consulta, $matches)) {
            return [
                'column' => $matches[1],
                'reference_table' => $matches[2],
                'reference_column' => $matches[3],
            ];
        }

        return null;
    }

    /**
     * TRUE when the column admits NULL according to the database metadata or
     * according to the XML (compare_columns() may soften it later).
     *
     * @param array<int, array{name?: string, is_nullable?: string}> $dbColumns
     * @param array<int, array{nombre?: string, nulo?: string}> $xmlColumns
     */
    private static function isNullableColumn(array $dbColumns, array $xmlColumns, string $columnName): bool
    {
        foreach ($dbColumns as $column) {
            if (
                ($column['name'] ?? '') === $columnName
                && strtoupper((string) ($column['is_nullable'] ?? 'NO')) === 'YES'
            ) {
                return true;
            }
        }

        foreach ($xmlColumns as $column) {
            if (
                ($column['nombre'] ?? '') === $columnName
                && strtoupper((string) ($column['nulo'] ?? 'YES')) !== 'NO'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * TRUE when the referenced table stores '' as a key, which makes the '' in
     * the FK column a legitimate reference that must not be nulled.
     *
     * Any failure (missing table, engine error) is read as "no empty key":
     * normalising stays safe and keeps the production fix intact.
     *
     * @param array{column: string, reference_table: ?string, reference_column: ?string} $target
     */
    private static function referencedKeyAcceptsEmptyString($db, array $target): bool
    {
        $referenceTable = (string) $target['reference_table'];
        $referenceColumn = (string) $target['reference_column'];

        if (!self::isValidIdentifier($referenceTable) || !self::isValidIdentifier($referenceColumn)) {
            return false;
        }

        $quote = self::identifierQuote();

        try {
            $rows = $db->select(
                'SELECT 1 FROM ' . $quote . $referenceTable . $quote
                . ' WHERE ' . $quote . $referenceColumn . $quote . " = '' LIMIT 1;"
            );
        } catch (\Throwable $e) {
            return false;
        }

        return !empty($rows);
    }

    private static function isValidIdentifier(string $identifier): bool
    {
        return preg_match(self::IDENTIFIER_REGEX, $identifier) === 1;
    }

    private static function identifierQuote(): string
    {
        return strtolower((string) FS_DB_TYPE) === 'mysql' ? '`' : '"';
    }

    private static function lastError($db): string
    {
        if (is_object($db) && method_exists($db, 'get_error_msg')) {
            return (string) $db->get_error_msg();
        }

        return '';
    }
}
