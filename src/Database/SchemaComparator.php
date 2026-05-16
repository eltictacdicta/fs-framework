<?php

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
        $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

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
        $constraints = $this->inspector->getConstraints($tableName);
        $extended = $this->inspector->getConstraintsExtended($tableName);
        $sigs = [];

        foreach ($constraints as $c) {
            $found = null;
            foreach ($extended as $e) {
                if ($e['name'] === $c['name']) {
                    $found = $e;
                    break;
                }
            }
            if ($found !== null) {
                $normalized = $this->normalizeDbConstraintSignature($found);
                if ($normalized !== null) {
                    $sigs[$c['name']] = $normalized;
                }
            }
        }

        return $sigs;
    }

    private function normalizeXmlConstraintSignature(string $query): ?string
    {
        $q = trim((string) preg_replace('/\s+/', ' ', $query));
        $q = str_replace(' ON DELETE RESTRICT', '', $q);
        $q = str_replace(' ON UPDATE RESTRICT', '', $q);

        $q = preg_replace_callback('/ ON (DELETE|UPDATE) (?:NO ACTION|RESTRICT|CASCADE|SET NULL)/i', function ($m) {
            return ' ON ' . strtoupper($m[1]) . ' ' . strtoupper($m[2]);
        }, $q);

        return $q !== '' ? $q : null;
    }

    private function normalizeDbConstraintSignature(array $constraint): ?string
    {
        $type = $constraint['type'] ?? '';
        $col = $constraint['column_name'] ?? '';
        $refTable = $constraint['foreign_table_name'] ?? '';
        $refCol = $constraint['foreign_column_name'] ?? '';

        if ($type === 'PRIMARY KEY') {
            return "PRIMARY KEY ({$col})";
        }

        if ($type === 'UNIQUE') {
            return "UNIQUE ({$col})";
        }

        if ($type === 'FOREIGN KEY' && $refTable && $refCol) {
            $onDelete = ($constraint['on_delete'] ?? '') ?: 'RESTRICT';
            $onUpdate = ($constraint['on_update'] ?? '') ?: 'RESTRICT';
            return "FOREIGN KEY ({$col}) REFERENCES {$refTable} ({$refCol}) ON DELETE {$onDelete} ON UPDATE {$onUpdate}";
        }

        return null;
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
            $sql .= ", " . $c['consulta'];
        }
        return $sql;
    }

    private function fixPostgresql(string $sql): string
    {
        if (!defined('FS_DB_TYPE') || FS_DB_TYPE !== 'POSTGRESQL') {
            return $sql;
        }

        return str_replace(
            ['INT(11)', 'TINYINT(1)', 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],
            ['INTEGER', 'BOOLEAN', ''],
            $sql
        );
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
}
