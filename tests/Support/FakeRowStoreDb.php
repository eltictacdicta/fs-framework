<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\Support;

/**
 * In-memory fs_db2-compatible double for `RowTranslationStore`.
 *
 * The store is generic over an injected table, record column and locale
 * column, so this fake keys rows by `record|locale` exactly as the
 * `UNIQUE(record_key, codidioma)` constraint does. Every emitted
 * SELECT/INSERT/UPDATE/DELETE mutates the in-memory rows so a later read
 * observes the real post-write state (CI-08…CI-15).
 */
final class FakeRowStoreDb
{
    /**
     * @var array<string, array<string, array{record: string, locale: string, columns: array<string, ?string>}>>
     *      table => rowKey => row
     */
    public array $tables = [];

    /** @var list<string> */
    public array $executed = [];

    /** @var array<string, bool> */
    public array $tablesSeen = [];

    /**
     * @param array<string, list<array{record: string, locale: string, columns: array<string, ?string>}>> $seed
     */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $table => $rows) {
            foreach ($rows as $row) {
                $this->put((string) $table, $row['record'], $row['locale'], $row['columns']);
            }
        }
    }

    // --- write helpers ------------------------------------------------------

    /**
     * Seed a row directly, bypassing SQL.
     *
     * @param array<string, ?string> $columns
     */
    public function seed(string $table, string $recordKey, string $locale, array $columns): void
    {
        $this->put($table, $recordKey, $locale, $columns);
    }

    /**
     * @param array<string, ?string> $columns
     */
    private function put(string $table, string $recordKey, string $locale, array $columns): void
    {
        $key = $recordKey . '|' . $locale;
        $existing = $this->tables[$table][$key]['columns'] ?? [];
        $this->tables[$table][$key] = [
            'record' => $recordKey,
            'locale' => $locale,
            'columns' => $columns + $existing,
        ];
    }

    // --- assertion helpers --------------------------------------------------

    /**
     * @return list<array{record: string, locale: string, columns: array<string, ?string>}>
     */
    public function rows(string $table): array
    {
        return array_values($this->tables[$table] ?? []);
    }

    public function count(string $table, ?string $locale = null): int
    {
        if ($locale === null) {
            return count($this->tables[$table] ?? []);
        }

        return count(array_filter(
            $this->tables[$table] ?? [],
            static fn (array $row): bool => $row['locale'] === $locale
        ));
    }

    public function value(string $table, string $recordKey, string $locale, string $column): ?string
    {
        return $this->tables[$table][$recordKey . '|' . $locale]['columns'][$column] ?? null;
    }

    /**
     * Distinct locale codes present in a table, ascending (CI-10).
     *
     * @return list<string>
     */
    public function codes(string $table): array
    {
        $codes = [];
        foreach ($this->tables[$table] ?? [] as $row) {
            $codes[$row['locale']] = true;
        }
        $codes = array_keys($codes);
        sort($codes, SORT_STRING);

        return $codes;
    }

    // --- fs_db2-compatible surface ------------------------------------------

    public function escape_string(string $str): string
    {
        return addslashes($str);
    }

    public function var2str($val)
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? 'TRUE' : 'FALSE';
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function select($sql, $params = [])
    {
        $sql = trim((string) $sql);

        // SELECT COUNT(*) as total FROM <table> [WHERE <column> = '<value>'];
        if (preg_match('/^SELECT COUNT\(\*\) AS total FROM (\w+)(?: WHERE \w+ = \'([^\']*)\')?;?$/i', $sql, $m)) {
            $where = isset($m[2]) && $m[2] !== '' ? $m[2] : null;

            return [['total' => (string) $this->countWhere($m[1], $where)]];
        }

        // SELECT * FROM <table> WHERE <c1> = '<v1>' AND <c2> = '<v2>';
        if (preg_match('/^SELECT \* FROM (\w+) WHERE \w+ = \'([^\']*)\' AND \w+ = \'([^\']*)\';?$/i', $sql, $m)) {
            $rows = $this->filter($m[1], [$m[2], $m[3]]);

            return $rows ? [$rows[0]['columns']] : [];
        }

        // SELECT <column> FROM <table> WHERE <c1> = '<v1>' AND <c2> = '<v2>';
        if (preg_match('/^SELECT (\w+) FROM (\w+) WHERE \w+ = \'([^\']*)\' AND \w+ = \'([^\']*)\';?$/i', $sql, $m)) {
            $rows = $this->filter($m[2], [$m[3], $m[4]]);
            if (!$rows) {
                return [];
            }

            $column = $m[1];
            $row = $rows[0];

            return [[$column => $row['columns'][$column] ?? null]];
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $sql = trim((string) $sql);
        $this->executed[] = $sql;

        // UPDATE <table> SET <column> = NULL WHERE <c1> = '<v1>' AND <c2> = '<v2>';
        if (preg_match('/^UPDATE (\w+) SET (\w+) = NULL WHERE \w+ = \'([^\']*)\' AND \w+ = \'([^\']*)\';?$/i', $sql, $m)) {
            $this->setColumn($m[1], $m[2], null, [$m[3], $m[4]]);

            return true;
        }

        // UPDATE <table> SET <column> = '<value>' WHERE <c1> = '<v1>' AND <c2> = '<v2>';
        if (preg_match('/^UPDATE (\w+) SET (\w+) = \'([^\']*)\' WHERE \w+ = \'([^\']*)\' AND \w+ = \'([^\']*)\';?$/i', $sql, $m)) {
            $this->setColumn($m[1], $m[2], $m[3], [$m[4], $m[5]]);

            return true;
        }

        // DELETE FROM <table> WHERE <c1> = '<v1>' AND <c2> = '<v2>' AND <col> IS NULL [AND <col> IS NULL ...];
        if (preg_match('/^DELETE FROM (\w+) WHERE \w+ = \'([^\']*)\' AND \w+ = \'([^\']*)\' AND (.+?);?$/i', $sql, $m)) {
            preg_match_all('/(\w+) IS NULL/i', $m[4], $nulls);
            $this->deleteAllNull($m[1], [$m[2], $m[3]], $nulls[1]);

            return true;
        }

        // DELETE FROM <table> WHERE <column> = '<value>';
        if (preg_match('/^DELETE FROM (\w+) WHERE \w+ = \'([^\']*)\';?$/i', $sql, $m)) {
            foreach ($this->filter($m[1], [$m[2]]) as $row) {
                unset($this->tables[$m[1]][$row['record'] . '|' . $row['locale']]);
            }

            return true;
        }

        // INSERT INTO <table> (<c1>, <c2>, <c3>) VALUES ('<v1>', '<v2>', '<v3>');
        if (preg_match('/^INSERT INTO (\w+) \(([^)]+)\) VALUES \((.+)\);?$/i', $sql, $m)) {
            $columns = array_map('trim', explode(',', $m[2]));
            $values = $this->parseSqlValues($m[3]);
            $this->insertRow($m[1], $columns, $values);

            return true;
        }

        return true;
    }

    // --- internals ----------------------------------------------------------

    /**
     * @param list<string> $values
     *
     * @return list<array{record: string, locale: string, columns: array<string, ?string>}>
     */
    private function filter(string $table, array $values): array
    {
        return array_values(array_filter(
            $this->tables[$table] ?? [],
            static function (array $row) use ($values): bool {
                foreach ($values as $value) {
                    if ($row['record'] !== $value && $row['locale'] !== $value) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    private function countWhere(string $table, ?string $value): int
    {
        if ($value === null) {
            return count($this->tables[$table] ?? []);
        }

        return count($this->filter($table, [$value]));
    }

    /**
     * @param list<string> $conditions
     */
    private function setColumn(string $table, string $column, ?string $value, array $conditions): void
    {
        foreach ($this->filter($table, $conditions) as $row) {
            $key = $row['record'] . '|' . $row['locale'];
            $this->tables[$table][$key]['columns'][$column] = $value;
        }
    }

    /**
     * @param list<string> $conditions
     * @param list<string> $nullColumns
     */
    private function deleteAllNull(string $table, array $conditions, array $nullColumns): void
    {
        foreach ($this->filter($table, $conditions) as $row) {
            $allNull = true;
            foreach ($nullColumns as $column) {
                if (($row['columns'][$column] ?? null) !== null) {
                    $allNull = false;
                    break;
                }
            }
            if ($allNull) {
                unset($this->tables[$table][$row['record'] . '|' . $row['locale']]);
            }
        }
    }

    /**
     * @param list<string> $columns
     * @param list<mixed>  $values
     */
    private function insertRow(string $table, array $columns, array $values): void
    {
        $record = '';
        $locale = '';
        $cells = [];
        foreach ($columns as $i => $column) {
            $value = $values[$i] ?? null;
            if (in_array($column, ['record_key', 'record', 'referencia', 'codigo', 'id'], true)) {
                $record = (string) $value;
            }
            if (in_array($column, ['codidioma', 'locale', 'lang'], true)) {
                $locale = (string) $value;
            }
            $cells[$column] = $value === null ? null : (string) $value;
        }
        unset($cells['record_key'], $cells['record'], $cells['codidioma'], $cells['locale']);
        unset($cells['referencia'], $cells['codigo'], $cells['id'], $cells['lang']);

        $this->put($table, $record, $locale, $cells);
    }

    /**
     * @return list<mixed>
     */
    private function parseSqlValues(string $raw): array
    {
        $values = [];
        foreach (array_map('trim', explode(',', $raw)) as $token) {
            if (strcasecmp($token, 'NULL') === 0) {
                $values[] = null;
            } elseif (strcasecmp($token, 'TRUE') === 0) {
                $values[] = true;
            } elseif (strcasecmp($token, 'FALSE') === 0) {
                $values[] = false;
            } elseif (preg_match('/^(\d+)$/', $token)) {
                $values[] = $token;
            } else {
                $values[] = trim($token, "'");
            }
        }

        return $values;
    }
}
