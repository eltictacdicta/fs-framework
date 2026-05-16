<?php

declare(strict_types=1);

namespace FSFramework\Database;

final class TypeNormalizer
{
    private static array $pgToMysqlTypes = [
        'serial' => 'SERIAL',
        'integer' => 'INT(11)',
        'int' => 'INT(11)',
        'bigint' => 'BIGINT',
        'smallint' => 'SMALLINT',
        'tinyint' => 'TINYINT',
        'boolean' => 'TINYINT(1)',
        'double precision' => 'DOUBLE',
        'real' => 'DOUBLE',
        'float' => 'FLOAT',
        'numeric' => 'DECIMAL',
        'decimal' => 'DECIMAL',
        'date' => 'DATE',
        'datetime' => 'DATETIME',
        'timestamp' => 'TIMESTAMP',
        'time' => 'TIME',
        'character varying' => 'VARCHAR',
        'varchar' => 'VARCHAR',
        'character' => 'CHAR',
        'char' => 'CHAR',
        'text' => 'TEXT',
        'bytea' => 'LONGBLOB',
    ];

    public static function convertPostgresType(string $type): string
    {
        $matches = [];
        if (preg_match('/^([a-z\s]+)(?:\((\d+(?:,\d+)?)\))?$/i', trim($type), $matches)) {
            $baseType = strtolower(trim($matches[1]));
            $baseType = preg_replace('/\s+without\s+time\s+zone$/i', '', $baseType);
            $length = isset($matches[2]) ? $matches[2] : null;

            foreach (self::$pgToMysqlTypes as $pgType => $mysqlType) {
                if ($baseType === $pgType || strpos($baseType, $pgType) === 0) {
                    if ($length && strpos($mysqlType, '(') === false) {
                        return "{$mysqlType}({$length})";
                    }
                    return $mysqlType;
                }
            }
        }

        return $type;
    }

    public static function normalizeDefault(?string $default, string $columnType): string
    {
        if (!defined('FS_DB_TYPE') || FS_DB_TYPE !== 'MYSQL') {
            return $default ?? '';
        }

        if ($default === null) {
            return 'NULL';
        }

        $upperDefault = strtoupper($default);
        $upperType = strtoupper($columnType);

        if ($upperDefault === 'CURRENT_TIMESTAMP' || $upperDefault === 'NOW()') {
            return 'CURRENT_TIMESTAMP';
        }

        if (strpos($upperType, 'TIMESTAMP') !== false) {
            $normalized = self::normalizeTimestampDefault($upperDefault, $columnType);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        if (is_numeric($default)) {
            return $default;
        }

        if (strpos($upperType, 'INT') !== false || $upperType === 'SERIAL') {
            return is_numeric($default) ? $default : '0';
        }

        if ($upperDefault === 'TRUE' || $upperDefault === '1') {
            return '1';
        }

        if ($upperDefault === 'FALSE' || $upperDefault === '0') {
            return '0';
        }

        return "'" . str_replace("'", "''", $default) . "'";
    }

    public static function compareDataTypes(string $dbType, string $xmlType): bool
    {
        if (FS_CHECK_DB_TYPES != 1) {
            return true;
        }

        $dbLower = strtolower($dbType);
        $xmlLower = strtolower($xmlType);

        if ($dbLower == $xmlLower || $xmlLower == 'serial') {
            return true;
        }

        if ($dbLower == 'tinyint(1)' && $xmlLower == 'boolean') {
            return true;
        }

        if (substr($dbLower, 0, 3) == 'int' && $xmlLower == 'integer') {
            return true;
        }

        if (substr($dbLower, 0, 6) == 'double' && $xmlLower == 'double precision') {
            return true;
        }

        if (substr($dbLower, 0, 4) == 'time' && substr($xmlLower, 0, 4) == 'time') {
            return true;
        }

        if (
            self::sameCharacterLength($dbLower, $xmlLower, 'varchar(', 8)
            || self::sameCharacterLength($dbLower, $xmlLower, 'char(', 5)
        ) {
            return true;
        }

        return false;
    }

    public static function extractTypeInfo(string $type): array
    {
        $type = strtolower(trim($type));
        if (preg_match('/^([a-z\s]+)\(([^)]+)\)$/', $type, $m)) {
            return ['base' => trim($m[1]), 'length' => trim($m[2])];
        }
        return ['base' => $type, 'length' => null];
    }

    private static function sameCharacterLength(string $dbType, string $xmlType, string $prefix, int $start): bool
    {
        $dbType = strtolower($dbType);
        $xmlType = strtolower($xmlType);

        if (substr($dbType, 0, strlen($prefix)) == $prefix && substr($xmlType, 0, strlen($prefix)) == $prefix) {
            return substr($dbType, $start, -1) == substr($xmlType, $start, -1);
        }

        $xmlPrefix = $prefix === 'char(' ? 'character(' : 'character varying(';
        $xmlStart = strlen($xmlPrefix);

        if (substr($dbType, 0, strlen($prefix)) != $prefix || substr($xmlType, 0, $xmlStart) != $xmlPrefix) {
            return false;
        }

        return substr($dbType, $start, -1) == substr($xmlType, $xmlStart, -1);
    }

    private static function normalizeTimestampDefault(string $upperDefault, string $type): ?string
    {
        $upperType = strtoupper($type);
        if (strpos($upperType, 'TIMESTAMP') === false) {
            return null;
        }

        if ($upperDefault === '0000-00-00 00:00:00' || $upperDefault === "'0000-00-00 00:00:00'") {
            return '0000-00-00 00:00:00';
        }

        if ($upperDefault === 'CURRENT_TIMESTAMP' || $upperDefault === 'NOW()' || $upperDefault === 'CURRENT_TIMESTAMP()') {
            return 'CURRENT_TIMESTAMP';
        }

        return null;
    }
}
