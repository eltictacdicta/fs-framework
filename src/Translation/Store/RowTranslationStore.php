<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
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

namespace FSFramework\Translation\Store;

/**
 * Generic per-record translation rows (design D3, D6, D12).
 *
 * The store is generic over a table, a record column, a record key and a
 * logical-field-to-column map. It never references a plugin table, adds no
 * `codidioma` foreign key and cannot issue DDL. Orphan cleanup is owned by the
 * application through `purgeLanguage()` (CI-13, CI-15).
 */
final class RowTranslationStore implements TranslationStoreInterface
{
    /** @var array<string, string> logical field => column */
    private array $fieldColumns;

    private object $db;

    /**
     * @param array<string, string> $fieldColumns
     * @param object|null           $db fs_db2-compatible; defaults to a real fs_db2
     */
    public function __construct(
        private string $table,
        array $fieldColumns,
        private string $recordColumn,
        private string $recordKey,
        private string $localeColumn = 'codidioma',
        ?object $db = null,
        private bool $prefixFallback = false
    ) {
        $this->fieldColumns = $fieldColumns;
        $this->db = $db ?? new \fs_db2();
    }

    public function get(string $field, string $locale): ?string
    {
        $column = $this->columnFor($field);
        if ($column === null) {
            return null;
        }

        $rows = $this->db->select(
            'SELECT ' . $column . ' FROM ' . $this->table
            . ' WHERE ' . $this->recordWhere() . ' AND ' . $this->localeWhere($locale) . ';'
        );
        if (!$rows) {
            return null;
        }

        $value = $rows[0][$column] ?? null;

        return $value === null ? null : (string) $value;
    }

    public function set(string $field, string $locale, ?string $value): bool
    {
        $column = $this->columnFor($field);
        if ($column === null) {
            return false;
        }

        if ($value === null || $value === '') {
            return $this->clear($column, $locale);
        }

        return $this->write($column, $locale, $value);
    }

    public function candidates(string $locale): array
    {
        return reduce_candidates($locale, $this->prefixFallback);
    }

    /**
     * Application-level orphan cleanup for the language dimension (CI-13).
     * Returns the number of removed rows; an absent locale is a no-op.
     */
    public function purgeLanguage(string $locale): int
    {
        $rows = $this->db->select(
            'SELECT COUNT(*) as total FROM ' . $this->table . ' WHERE ' . $this->localeWhere($locale) . ';'
        );
        $count = (int) ($rows[0]['total'] ?? 0);
        if ($count < 1) {
            return 0;
        }

        $this->db->exec(
            'DELETE FROM ' . $this->table . ' WHERE ' . $this->localeWhere($locale) . ';'
        );

        return $count;
    }

    public function tableName(): string
    {
        return $this->table;
    }

    private function clear(string $column, string $locale): bool
    {
        $where = $this->recordWhere() . ' AND ' . $this->localeWhere($locale);

        // Propagation matters: a failed UPDATE/DELETE must surface as a false
        // `set()` result, never as a silent success.
        if (!$this->db->exec('UPDATE ' . $this->table . ' SET ' . $column . ' = NULL WHERE ' . $where . ';')) {
            return false;
        }

        // Absence semantics: the row only disappears once every mapped column
        // is null, so sibling fields of the same record/locale survive (D6).
        $nulls = [];
        foreach ($this->fieldColumns as $mapped) {
            $nulls[] = $mapped . ' IS NULL';
        }

        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table . ' WHERE ' . $where . ' AND ' . implode(' AND ', $nulls) . ';'
        );
    }

    private function write(string $column, string $locale, string $value): bool
    {
        $where = $this->recordWhere() . ' AND ' . $this->localeWhere($locale);

        if ($this->rowExists($locale)) {
            return (bool) $this->db->exec(
                'UPDATE ' . $this->table . ' SET ' . $column . ' = ' . $this->db->var2str($value)
                . ' WHERE ' . $where . ';'
            );
        }

        return (bool) $this->db->exec(
            'INSERT INTO ' . $this->table
            . ' (' . $this->recordColumn . ', ' . $this->localeColumn . ', ' . $column . ') VALUES ('
            . $this->db->var2str($this->recordKey) . ', '
            . $this->db->var2str($locale) . ', '
            . $this->db->var2str($value) . ');'
        );
    }

    private function rowExists(string $locale): bool
    {
        $rows = $this->db->select(
            'SELECT * FROM ' . $this->table
            . ' WHERE ' . $this->recordWhere() . ' AND ' . $this->localeWhere($locale) . ';'
        );

        return (bool) $rows;
    }

    private function columnFor(string $field): ?string
    {
        return $this->fieldColumns[$field] ?? null;
    }

    private function recordWhere(): string
    {
        return $this->recordColumn . ' = ' . $this->db->var2str($this->recordKey);
    }

    private function localeWhere(string $locale): string
    {
        return $this->localeColumn . ' = ' . $this->db->var2str($locale);
    }
}
