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

/**
 * Valida la compatibilidad charset/collation/tipo entre una columna local
 * (que lleva la FOREIGN KEY) y la columna referenciada antes de emitir la
 * constraint en el CREATE TABLE.
 *
 * MySQL exige que ambas columnas de una FK compartan charset, collation y
 * tipo; si difieren, el CREATE falla con errno 150. Este validador es
 * PERMISIVO por defecto: solo omite la FK cuando hay certeza real de
 * incompatibilidad (MySQL + metadatos presentes + diferencia real). En
 * cualquier caso ambiguo (no-MySQL, tipo no colacionable, metadatos
 * ausentes, columna referenciada inexistente) permite la FK.
 *
 * Es el ÚNICO validador usado por ambos generadores de CREATE:
 * SchemaComparator y fs_schema::addForeignKeyConstraint (SS-03).
 */
final class FkCompatibilityValidator
{
    private const IDENTIFIER_REGEX = '/^[a-z0-9_]+$/i';

    /** @var array<string, string>|null Configuración @@ de la BD (charset => collation). */
    private ?array $dbConfig = null;

    public function __construct(
        private object $db,
        private ?bool $isMySql = null
    ) {
    }

    /**
     * Decide si la FK referenciada por $constraintSql es compatible con la
     * columna local descrita en $localColInfo.
     *
     * @param array{name?: string, type?: string, charset?: ?string, collation?: ?string} $localColInfo
     */
    public function isFkCompatible(string $constraintSql, array $localColInfo): bool
    {
        $parts = self::parseFkParts($constraintSql);
        if ($parts === null) {
            return true;
        }

        if (!$this->isMySqlEngine()) {
            return true;
        }

        $localColInfo = $this->resolveLocalCollationDefaults($localColInfo);

        // Tipo no colacionable (int, date, ...): no hay collation que comparar.
        if (!$this->isCollatableType($localColInfo['type'] ?? '')) {
            return true;
        }

        $refMeta = $this->fetchReferencedColumnMetadata($parts['refTable'], $parts['refColumn']);
        if ($refMeta === null) {
            return true;
        }

        if (!$this->charsetMatches($localColInfo, $refMeta)) {
            $this->warn(
                'charset',
                $localColInfo['name'] ?? '',
                $localColInfo['charset'] ?? '?',
                $refMeta['charset'],
                $constraintSql
            );

            return false;
        }

        if (!$this->collationMatches($localColInfo, $refMeta)) {
            $this->warn(
                'collation',
                $localColInfo['name'] ?? '',
                $localColInfo['collation'] ?? '?',
                $refMeta['collation'],
                $constraintSql
            );

            return false;
        }

        if (!$this->typesMatch($localColInfo['type'] ?? '', $refMeta['type'])) {
            $this->warn(
                'tipo',
                $localColInfo['name'] ?? '',
                $localColInfo['type'] ?? '?',
                $refMeta['type'],
                $constraintSql
            );

            return false;
        }

        return true;
    }

    /**
     * Extrae la columna local, la tabla referenciada y la columna referenciada
     * de una constraint FOREIGN KEY. Devuelve null si el patrón no coincide.
     *
     * @return array{localColumn: string, refTable: string, refColumn: string}|null
     */
    public static function parseFkParts(string $constraintSql): ?array
    {
        if (stripos($constraintSql, 'FOREIGN KEY') === false) {
            return null;
        }

        if (!preg_match(
            '/FOREIGN\s+KEY\s*\(\s*`?([a-zA-Z0-9_]+)`?\s*\)\s*REFERENCES\s*`?([a-zA-Z0-9_]+)`?\s*\(\s*`?([a-zA-Z0-9_]+)`?\s*\)/i',
            $constraintSql,
            $matches
        )) {
            return null;
        }

        return [
            'localColumn' => $matches[1],
            'refTable' => $matches[2],
            'refColumn' => $matches[3],
        ];
    }

    private function isMySqlEngine(): bool
    {
        if ($this->isMySql !== null) {
            return $this->isMySql;
        }

        return defined('FS_DB_TYPE') && strtolower(FS_DB_TYPE) === 'mysql';
    }

    /**
     * Rellena charset/collation local ausentes con la configuración @@ de la BD,
     * porque en el CREATE la tabla local todavía no existe y ambos generadores
     * emiten DEFAULT CHARSET/COLLATE desde esas mismas variables.
     *
     * @param array{name?: string, type?: string, charset?: ?string, collation?: ?string} $localColInfo
     *
     * @return array{name?: string, type?: string, charset: ?string, collation: ?string}
     */
    private function resolveLocalCollationDefaults(array $localColInfo): array
    {
        if (!empty($localColInfo['charset']) && !empty($localColInfo['collation'])) {
            return $localColInfo;
        }

        $config = $this->dbConfig();
        if ($config === null) {
            return $localColInfo;
        }

        $charset = $localColInfo['charset'] ?? array_key_first($config);
        $collation = $localColInfo['collation'] ?? reset($config);

        return array_merge($localColInfo, [
            'charset' => is_string($charset) ? $charset : null,
            'collation' => is_string($collation) ? $collation : null,
        ]);
    }

    /**
     * @return array<string, string>|null Mapa charset => collation, o null si no se pudo leer.
     */
    private function dbConfig(): ?array
    {
        if ($this->dbConfig !== null) {
            return $this->dbConfig;
        }

        $rows = $this->db->select('SELECT @@character_set_database AS db_charset, @@collation_database AS db_collation;');
        if (empty($rows)) {
            return null;
        }

        $charset = isset($rows[0]['db_charset']) ? strtolower((string) $rows[0]['db_charset']) : '';
        $collation = isset($rows[0]['db_collation']) ? strtolower((string) $rows[0]['db_collation']) : '';

        if (!preg_match(self::IDENTIFIER_REGEX, $charset) || !preg_match(self::IDENTIFIER_REGEX, $collation)) {
            return null;
        }

        $this->dbConfig = [$charset => $collation];

        return $this->dbConfig;
    }

    private function isCollatableType(string $type): bool
    {
        $base = strtolower(preg_replace('/\(\d+(?:,\d+)?\)/', '', trim($type)) ?? '');
        $base = strtolower(preg_replace('/\s+/', ' ', trim($base)) ?? '');

        return in_array($base, ['varchar', 'char', 'character varying', 'character', 'text', 'enum', 'set'], true);
    }

    /**
     * Consulta charset/collation/tipo de la columna referenciada en
     * information_schema.columns. Devuelve null si no hay metadatos.
     *
     * @return array{charset: string, collation: string, type: string}|null
     */
    private function fetchReferencedColumnMetadata(string $refTable, string $refColumn): ?array
    {
        $sql = "SELECT character_set_name AS charset_name, collation_name AS collation_name, column_type AS column_type"
            . " FROM information_schema.columns"
            . " WHERE table_schema = DATABASE()"
            . " AND table_name = '" . $this->escape($refTable) . "'"
            . " AND column_name = '" . $this->escape($refColumn) . "'"
            . " LIMIT 1;";

        $rows = $this->db->select($sql);
        if (empty($rows)) {
            return null;
        }

        $charset = isset($rows[0]['charset_name']) ? strtolower((string) $rows[0]['charset_name']) : '';
        $collation = isset($rows[0]['collation_name']) ? strtolower((string) $rows[0]['collation_name']) : '';
        $type = isset($rows[0]['column_type']) ? strtolower((string) $rows[0]['column_type']) : '';

        if (!preg_match(self::IDENTIFIER_REGEX, $charset) || !preg_match(self::IDENTIFIER_REGEX, $collation) || $type === '') {
            return null;
        }

        return [
            'charset' => $charset,
            'collation' => $collation,
            'type' => $type,
        ];
    }

    /**
     * @param array{name?: string, type?: string, charset?: ?string, collation?: ?string} $local
     * @param array{charset: string, collation: string, type: string} $ref
     */
    private function charsetMatches(array $local, array $ref): bool
    {
        $localCharset = strtolower((string) ($local['charset'] ?? ''));
        $refCharset = strtolower($ref['charset']);

        return $localCharset !== '' && $localCharset === $refCharset;
    }

    /**
     * @param array{name?: string, type?: string, charset?: ?string, collation?: ?string} $local
     * @param array{charset: string, collation: string, type: string} $ref
     */
    private function collationMatches(array $local, array $ref): bool
    {
        $localCollation = strtolower((string) ($local['collation'] ?? ''));
        $refCollation = strtolower($ref['collation']);

        return $localCollation !== '' && $localCollation === $refCollation;
    }

    private function typesMatch(string $localType, string $refType): bool
    {
        $normalizedLocal = $this->normalizeType($localType);
        $normalizedRef = $this->normalizeType($refType);

        return $normalizedLocal === $normalizedRef;
    }

    /**
     * Normalización exacta tras normalizar: convertPostgresType, minúsculas,
     * quitar el ancho de display de enteros (int(n)->int), conservar varchar(n).
     */
    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = strtolower(trim(TypeNormalizer::convertPostgresType($type)));

        if (preg_match('/^(int|tinyint|smallint|mediumint|bigint)\(\d+\)$/', $type)) {
            $type = preg_replace('/\(\d+\)$/', '', $type) ?? $type;
        }

        return $type;
    }

    private function warn(string $kind, string $localName, string $localValue, string $refValue, string $query): void
    {
        error_log(
            "Advertencia: Foreign key columna '{$localName}' omitida - incompatibilidad de {$kind}"
            . " (local {$localValue} vs referenciada {$refValue}). Query: {$query}"
        );
    }

    private function escape(string $value): string
    {
        if (method_exists($this->db, 'escape_string')) {
            return $this->db->escape_string($value);
        }

        return addslashes($value);
    }
}
