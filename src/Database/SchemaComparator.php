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

final class SchemaComparator
{
    private const IDENTIFIER_REGEX = '/^[a-z0-9_]+$/i';
    private const SQL_MODIFY_COL = ' MODIFY `';

    private object $db;
    private ?SchemaInspector $inspector;

    public function __construct(object $db, ?SchemaInspector $inspector = null)
    {
        $this->db = $db;
        $this->inspector = $inspector ?? new SchemaInspector($db);
    }

    public function compareColumns(string $tableName, array $xmlCols, array $dbCols): string
    {
        $rawTableName = $this->requireIdentifier($tableName, 'table');
        $quotedTable = $this->quoteIdentifier($rawTableName);
        $sql = '';
        $fkColumns = $this->inspector->getFkColumnNames($rawTableName);

        foreach ($xmlCols as $xmlCol) {
            $xmlCol['tipo'] = TypeNormalizer::convertPostgresType($xmlCol['tipo']);
            if (strtolower($xmlCol['tipo']) == 'integer') {
                $xmlCol['tipo'] = FS_DB_INTEGER;
            }
            $xmlType = $xmlCol['tipo'];
            $xmlDefault = TypeNormalizer::normalizeDefault($xmlCol['defecto'] ?? null, $xmlType);

            $dbCol = $this->searchInArray($dbCols, 'name', $xmlCol['nombre']);
            if (empty($dbCol)) {
                $sql .= $this->buildAddColumnSql($quotedTable, $xmlCol, $xmlType, $xmlDefault);
                continue;
            }

            $sql .= $this->buildTypeChangeSql($quotedTable, $xmlCol, $xmlType, $dbCol, $fkColumns);
            $sql .= $this->buildNullableChangeSql($quotedTable, $xmlCol, $xmlType, $dbCol, $fkColumns);
            $sql .= $this->buildDefaultChangeSql($quotedTable, $xmlCol, $xmlType, $xmlDefault, $dbCol);
        }

        return $this->fixPostgresql($sql);
    }

    public function compareConstraints(string $tableName, array $xmlCons, array $dbCons, bool $deleteOnly = false): string
    {
        $rawTableName = $this->requireIdentifier($tableName, 'table');
        $quotedTable = $this->quoteIdentifier($rawTableName);
        $sql = '';

        $dbSignatures = $this->buildDbConstraintSignatures($rawTableName);
        $xmlSignatures = $this->buildXmlConstraintSignatures($xmlCons);

        if (!$deleteOnly) {
            foreach ($xmlCons as $c) {
                if ($this->xmlConstraintHasEquivalentDbDefinition($c, $dbSignatures, $xmlSignatures)) {
                    continue;
                }

                $sql .= 'ALTER TABLE ' . $quotedTable . ' ADD ' . $c['consulta'] . ';';
            }
        }

        foreach ($dbCons as $c) {
            if ($this->constraintHasEquivalentXmlDefinition($c, $dbSignatures, $xmlSignatures)) {
                continue;
            }

            if ($c['type'] === 'PRIMARY KEY') {
                $sql .= 'ALTER TABLE ' . $quotedTable . ' DROP PRIMARY KEY;';
            } elseif ($c['type'] === 'UNIQUE') {
                $sql .= 'ALTER TABLE ' . $quotedTable . ' DROP INDEX `' . $c['name'] . '`;';
            } elseif ($c['type'] === 'FOREIGN KEY') {
                $sql .= 'ALTER TABLE ' . $quotedTable . ' DROP FOREIGN KEY `' . $c['name'] . '`;';
            }
        }

        return $this->fixPostgresql($sql);
    }

    public function generateTable(string $tableName, array $xmlCols, array $xmlCons): string
    {
        $tableName = $this->quoteIdentifier($tableName);

        $sql = "CREATE TABLE IF NOT EXISTS " . $tableName . " ( ";
        $i = false;
        foreach ($xmlCols as $col) {
            if ($i) {
                $sql .= ", ";
            } else {
                $i = true;
            }

            $col['tipo'] = TypeNormalizer::convertPostgresType($col['tipo']);

            if ($col['tipo'] == 'serial') {
                $sql .= '`' . $col['nombre'] . '` ' . FS_DB_INTEGER . ' NOT NULL AUTO_INCREMENT';
            } else {
                if (strtolower($col['tipo']) == 'integer') {
                    $col['tipo'] = FS_DB_INTEGER;
                }

                $sql .= '`' . $col['nombre'] . '` ' . $col['tipo'];

                if ($col['nulo'] == 'NO') {
                    $sql .= " NOT NULL";
                } else {
                    $sql .= " NULL";
                }

                if ($col['defecto'] !== null) {
                    $sql .= " DEFAULT " . TypeNormalizer::normalizeDefault($col['defecto'], $col['tipo']);
                }
            }
        }

        $validatedCons = $this->validateFkConstraints($xmlCons);
        $sql .= ' ' . $this->generateTableConstraints($validatedCons) . ' )';
        $sql .= ' ' . $this->tableCharsetCollationSql() . ';';

        return $this->fixPostgresql($sql);
    }

    private function buildAddColumnSql(string $quotedTable, array $xmlCol, string $xmlType, string $xmlDefault): string
    {
        $sql = 'ALTER TABLE ' . $quotedTable . ' ADD `' . $xmlCol['nombre'] . '` ';

        if ($xmlCol['tipo'] == 'serial') {
            return $sql . FS_DB_INTEGER . ' NOT NULL AUTO_INCREMENT;';
        }

        $sql .= $xmlType;
        $sql .= ($xmlCol['nulo'] == 'NO') ? " NOT NULL" : " NULL";

        if ($xmlDefault !== 'NULL') {
            return $sql . " DEFAULT " . $xmlDefault . ";";
        }

        return $sql . (($xmlCol['nulo'] == 'YES') ? " DEFAULT NULL;" : ";");
    }

    private function buildTypeChangeSql(string $quotedTable, array $xmlCol, string $xmlType, array $dbCol, array $fkColumns): string
    {
        if (TypeNormalizer::compareDataTypes($dbCol['type'], $xmlType)) {
            return '';
        }

        if (in_array($xmlCol['nombre'], $fkColumns) && !$this->columnTypeReallyDiffers($dbCol['type'], $xmlType)) {
            return '';
        }

        return 'ALTER TABLE ' . $quotedTable . self::SQL_MODIFY_COL . $xmlCol['nombre'] . '` ' . $xmlType . ';';
    }

    private function buildNullableChangeSql(string $quotedTable, array $xmlCol, string $xmlType, array $dbCol, array $fkColumns): string
    {
        if ($dbCol['is_nullable'] == $xmlCol['nulo']) {
            return '';
        }

        if (in_array($xmlCol['nombre'], $fkColumns) && !$this->columnTypeReallyDiffers($dbCol['type'], $xmlType)) {
            return '';
        }

        $nullable = ($xmlCol['nulo'] == 'YES') ? ' NULL;' : ' NOT NULL;';
        return 'ALTER TABLE ' . $quotedTable . self::SQL_MODIFY_COL . $xmlCol['nombre'] . '` ' . $xmlType . $nullable;
    }

    private function buildDefaultChangeSql(string $quotedTable, array $xmlCol, string $xmlType, string $xmlDefault, array $dbCol): string
    {
        if ($this->compareDefaults($dbCol['default'] ?? '', $xmlDefault)) {
            return '';
        }

        if ($xmlDefault === 'NULL') {
            return 'ALTER TABLE ' . $quotedTable . ' ALTER `' . $xmlCol['nombre'] . '` DROP DEFAULT;';
        }

        if (strtolower(substr($xmlDefault, 0, 9)) == "nextval('") {
            if (($dbCol['extra'] ?? '') == 'auto_increment') {
                return '';
            }
            $nullable = ($xmlCol['nulo'] == 'YES') ? ' NULL AUTO_INCREMENT;' : ' NOT NULL AUTO_INCREMENT;';
            return 'ALTER TABLE ' . $quotedTable . self::SQL_MODIFY_COL . $xmlCol['nombre'] . '` ' . $xmlType . $nullable;
        }

        return 'ALTER TABLE ' . $quotedTable . ' ALTER `' . $xmlCol['nombre'] . '` SET DEFAULT ' . $xmlDefault . ";";
    }

    private function columnTypeReallyDiffers(string $dbType, string $xmlType): bool
    {
        $dbType = strtolower($dbType);
        $xmlType = strtolower($xmlType);

        if ($dbType == $xmlType) {
            return false;
        }

        if (strpos($dbType, 'int') === 0 && $xmlType == 'integer') {
            return false;
        }

        if (strpos($dbType, 'varchar') === 0 && strpos($xmlType, 'varchar') === 0) {
            return false;
        }

        return true;
    }

    private function compareDefaults(string $dbDefault, string $xmlDefault): bool
    {
        if ($dbDefault == $xmlDefault) {
            return true;
        }

        if (in_array($dbDefault, ['0', 'false', 'FALSE'])) {
            return in_array($xmlDefault, ['0', 'false', 'FALSE']);
        }

        if (in_array($dbDefault, ['1', 'true', 'TRUE'])) {
            return in_array($xmlDefault, ['1', 'true', 'TRUE']);
        }

        if (substr($xmlDefault, 0, 8) == 'nextval(') {
            return true;
        }

        return false;
    }

    private function constraintHasEquivalentXmlDefinition(array $dbConstraint, array $dbSignatures, array $xmlSignatures): bool
    {
        $sig = $dbSignatures[$dbConstraint['name']] ?? null;
        return $sig !== null && in_array($sig, $xmlSignatures, true);
    }

    private function xmlConstraintHasEquivalentDbDefinition(array $xmlConstraint, array $dbSignatures, array $xmlSignatures): bool
    {
        $sig = $xmlSignatures[$xmlConstraint['nombre']] ?? null;
        return $sig !== null && in_array($sig, $dbSignatures, true);
    }

    private function buildXmlConstraintSignatures(array $xmlConstraints): array
    {
        $sigs = [];
        foreach ($xmlConstraints as $c) {
            $normalized = $this->normalizeXmlConstraintSignature($c['consulta']);
            if ($normalized !== null) {
                $sigs[$c['nombre']] = $normalized;
            }
        }
        return $sigs;
    }

    private function buildDbConstraintSignatures(string $tableName): array
    {
        $grouped = [];
        foreach ($this->inspector->getConstraintsExtended($tableName) as $row) {
            if (empty($row['name']) || empty($row['type'])) {
                continue;
            }

            $name = $row['name'];
            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'type' => strtoupper($row['type']),
                    'rows' => [],
                ];
            }

            $grouped[$name]['rows'][] = $row;
        }

        $signatures = [];
        foreach ($grouped as $name => $constraint) {
            $signature = $this->normalizeDbConstraintSignature($constraint);
            if ($signature !== null) {
                $signatures[$name] = $signature;
            }
        }

        return $signatures;
    }

    private function normalizeXmlConstraintSignature(string $query): ?string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($query));
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        if (preg_match('/^PRIMARY KEY\s*\(([^)]+)\)$/i', $normalized, $matches)) {
            return 'PRIMARY KEY|' . implode(',', $this->normalizeIdentifierList($matches[1]));
        }

        if (preg_match('/^UNIQUE\s*\(([^)]+)\)$/i', $normalized, $matches)) {
            return 'UNIQUE|' . implode(',', $this->normalizeIdentifierList($matches[1]));
        }

        if (!preg_match('/^FOREIGN KEY\s*\(([^)]+)\)\s+REFERENCES\s+([^\s(]+)\s*\(([^)]+)\)(.*)$/i', $normalized, $matches)) {
            return null;
        }

        $localColumns = implode(',', $this->normalizeIdentifierList($matches[1]));
        $foreignTable = $this->normalizeIdentifier($matches[2]);
        $foreignColumns = implode(',', $this->normalizeIdentifierList($matches[3]));
        $tail = $matches[4] ?? '';

        return sprintf(
            'FOREIGN KEY|%s|%s|%s|%s|%s',
            $localColumns,
            $foreignTable,
            $foreignColumns,
            $this->extractRuleFromSqlTail($tail, 'UPDATE'),
            $this->extractRuleFromSqlTail($tail, 'DELETE')
        );
    }

    private function normalizeDbConstraintSignature(array $constraint): ?string
    {
        if (empty($constraint['type']) || empty($constraint['rows'])) {
            return null;
        }

        $rows = $constraint['rows'];
        usort($rows, static function (array $left, array $right): int {
            $leftPosition = (int) ($left['ordinal_position'] ?? 0);
            $rightPosition = (int) ($right['ordinal_position'] ?? 0);

            if ($leftPosition !== $rightPosition) {
                return $leftPosition <=> $rightPosition;
            }

            return strcmp((string) ($left['column_name'] ?? ''), (string) ($right['column_name'] ?? ''));
        });

        $type = strtoupper($constraint['type']);
        $columns = [];
        foreach ($rows as $row) {
            if (!empty($row['column_name'])) {
                $columns[] = $this->normalizeIdentifier($row['column_name']);
            }
        }

        if ($type === 'PRIMARY KEY' || $type === 'UNIQUE') {
            return $type . '|' . implode(',', $columns);
        }

        if ($type !== 'FOREIGN KEY') {
            return null;
        }

        $firstRow = $rows[0];
        $foreignColumns = [];
        foreach ($rows as $row) {
            if (!empty($row['foreign_column_name'])) {
                $foreignColumns[] = $this->normalizeIdentifier($row['foreign_column_name']);
            }
        }

        return sprintf(
            'FOREIGN KEY|%s|%s|%s|%s|%s',
            implode(',', $columns),
            $this->normalizeIdentifier((string) ($firstRow['foreign_table_name'] ?? '')),
            implode(',', $foreignColumns),
            strtoupper((string) ($firstRow['on_update'] ?? 'RESTRICT')),
            strtoupper((string) ($firstRow['on_delete'] ?? 'RESTRICT'))
        );
    }

    private function validateFkConstraints(array $xmlCons): array
    {
        if (empty($xmlCons)) {
            return $xmlCons;
        }

        $tables = $this->db->list_tables();
        $tableNames = [];
        if (is_array($tables)) {
            foreach ($tables as $t) {
                $tableNames[] = strtolower($t['name']);
            }
        }

        $validated = [];
        foreach ($xmlCons as $con) {
            if (stripos($con['consulta'], 'FOREIGN KEY') === false) {
                $validated[] = $con;
                continue;
            }

            if (preg_match('/REFERENCES\s+(?:`([^`]+)`|"([^"]+)"|([A-Za-z0-9_]+))/i', $con['consulta'], $m)) {
                $refTable = $m[1] ?: ($m[2] ?: ($m[3] ?: ''));
                $refTable = trim($refTable, '"`');

                if ($refTable !== '' && in_array(strtolower($refTable), $tableNames)) {
                    $validated[] = $con;
                }
            } else {
                $validated[] = $con;
            }
        }

        return $validated;
    }

    private function generateTableConstraints(array $xmlCons): string
    {
        $sql = '';
        foreach ($xmlCons as $c) {
            $consulta = trim((string) ($c['consulta'] ?? ''));
            if ($consulta === '') {
                continue;
            }

            $constraintType = $this->detectConstraintType($consulta);
            if ($constraintType === 'FOREIGN KEY' && defined('FS_FOREIGN_KEYS') && !FS_FOREIGN_KEYS) {
                continue;
            }

            if ($constraintType === 'PRIMARY KEY' || $this->hasExplicitConstraintName($consulta) || empty($c['nombre'])) {
                $sql .= ', ' . $consulta;
                continue;
            }

            $sql .= ', CONSTRAINT ' . $this->quoteIdentifier((string) $c['nombre']) . ' ' . $consulta;
        }

        return $sql;
    }

    private function fixPostgresql(string $sql): string
    {
        if (!defined('FS_DB_TYPE') || FS_DB_TYPE !== 'POSTGRESQL') {
            return $sql;
        }

        $sql = str_replace(
            ['INT(11)', 'TINYINT(1)', 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],
            ['INTEGER', 'BOOLEAN', ''],
            $sql
        );

        return (string) preg_replace('/ENGINE=InnoDB DEFAULT CHARSET=[a-z0-9_]+ COLLATE=[a-z0-9_]+/i', '', $sql);
    }

    private function tableCharsetCollationSql(): string
    {
        if (defined('FS_DB_TYPE') && FS_DB_TYPE !== 'MYSQL') {
            return '';
        }

        $charset = 'utf8mb4';
        $collation = 'utf8mb4_unicode_ci';

        $dbConf = $this->db->select('SELECT @@character_set_database AS db_charset, @@collation_database AS db_collation;');
        if (!empty($dbConf)) {
            $dbCharset = isset($dbConf[0]['db_charset']) ? strtolower((string) $dbConf[0]['db_charset']) : '';
            $dbCollation = isset($dbConf[0]['db_collation']) ? strtolower((string) $dbConf[0]['db_collation']) : '';

            if (preg_match(self::IDENTIFIER_REGEX, $dbCharset)) {
                $charset = $dbCharset;
            }

            if (preg_match(self::IDENTIFIER_REGEX, $dbCollation)) {
                $collation = $dbCollation;
            }
        }

        return 'ENGINE=InnoDB DEFAULT CHARSET=' . $charset . ' COLLATE=' . $collation;
    }

    private function searchInArray(array $array, string $key, string $value): ?array
    {
        foreach ($array as $item) {
            if (($item[$key] ?? null) === $value) {
                return $item;
            }
        }

        return null;
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

    private function normalizeIdentifierList(string $list): array
    {
        $items = array_map('trim', explode(',', $list));
        $items = array_values(array_filter($items, static function (string $item): bool {
            return $item !== '';
        }));

        return array_map(function (string $item): string {
            return $this->normalizeIdentifier($item);
        }, $items);
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        $identifier = trim($identifier, "`\" ");

        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier);
            $identifier = (string) end($parts);
            $identifier = trim($identifier, "`\" ");
        }

        return strtolower($identifier);
    }

    private function detectConstraintType(string $consulta): ?string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($consulta));
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        if (!preg_match('/^(?:CONSTRAINT\s+(?:`[^`]+`|"[^"]+"|[A-Za-z0-9_]+)\s+)?(PRIMARY KEY|UNIQUE|FOREIGN KEY)\b/i', $normalized, $matches)) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    private function hasExplicitConstraintName(string $consulta): bool
    {
        return preg_match('/^CONSTRAINT\s+(?:`[^`]+`|"[^"]+"|[A-Za-z0-9_]+)\s+/i', trim($consulta)) === 1;
    }

    private function extractRuleFromSqlTail(string $tail, string $ruleType): string
    {
        if (!preg_match('/ON ' . $ruleType . '\s+(RESTRICT|CASCADE|SET NULL|NO ACTION|SET DEFAULT)/i', $tail, $matches)) {
            return 'RESTRICT';
        }

        return strtoupper($matches[1]);
    }
}
