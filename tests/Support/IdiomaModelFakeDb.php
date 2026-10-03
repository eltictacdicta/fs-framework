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
 * In-memory fs_db2-compatible double limited to the statements the core
 * `idioma` registry model issues. It lets CI-01…CI-05 be verified without a
 * database: every emitted INSERT/UPDATE/DELETE mutates the in-memory rows
 * exactly as the SQL prescribes, so a later resolution observes the real
 * post-mutation state.
 */
final class IdiomaModelFakeDb
{
    /** @var array<string, array{codidioma: string, nombre: string, activo: bool, por_defecto: bool}> */
    public array $idiomas = [];

    /** @var list<string> */
    public array $executed = [];

    public bool $committed = false;

    public bool $rolledback = false;

    public int $transactions = 0;

    public bool $failNextExec = false;

    /** @var array<string, bool> */
    public array $tables = [];

    /**
     * @param list<array{codidioma: string, nombre?: string, activo?: bool, por_defecto?: bool}> $idiomas
     */
    public function __construct(array $idiomas = [])
    {
        foreach ($idiomas as $row) {
            $this->seed(
                (string) $row['codidioma'],
                $row['nombre'] ?? (string) $row['codidioma'],
                $row['activo'] ?? true,
                $row['por_defecto'] ?? false
            );
        }
    }

    public function seed(string $codidioma, string $nombre, bool $activo, bool $porDefecto): void
    {
        $this->idiomas[$codidioma] = [
            'codidioma' => $codidioma,
            'nombre' => $nombre,
            'activo' => $activo,
            'por_defecto' => $porDefecto,
        ];
    }

    /**
     * Codes carrying the default flag over an active language (CI-03 invariant).
     *
     * @return list<string>
     */
    public function activeDefaults(): array
    {
        $out = [];
        foreach ($this->idiomas as $cod => $row) {
            if ($row['por_defecto'] && $row['activo']) {
                $out[] = $cod;
            }
        }

        return $out;
    }

    public function tableExists(string $table): bool
    {
        return $table === \idioma::TABLE;
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

    public function escape_string(string $str): string
    {
        return addslashes($str);
    }

    public function begin_transaction()
    {
        $this->transactions++;

        return true;
    }

    public function commit()
    {
        $this->committed = true;

        return true;
    }

    public function rollback()
    {
        $this->rolledback = true;

        return true;
    }

    public function select($sql, $params = [])
    {
        $sql = trim((string) $sql);

        if (preg_match('/^SELECT COUNT\(\*\) as total FROM ' . preg_quote(\idioma::TABLE, '/') . ';?$/i', $sql)) {
            return [['total' => (string) count($this->idiomas)]];
        }

        if (preg_match('/^SELECT COUNT\(\*\) as total FROM ' . preg_quote(\idioma::TABLE, '/') . ' WHERE por_defecto = TRUE;?$/i', $sql)) {
            $count = 0;
            foreach ($this->idiomas as $row) {
                if ($row['por_defecto']) {
                    $count++;
                }
            }

            return [['total' => (string) $count]];
        }

        if (preg_match('/^SELECT \* FROM ' . preg_quote(\idioma::TABLE, '/') . ' WHERE por_defecto = TRUE AND activo = TRUE ORDER BY codidioma ASC LIMIT 1;?$/i', $sql)) {
            $flagged = [];
            foreach ($this->idiomas as $row) {
                if ($row['por_defecto'] && $row['activo']) {
                    $flagged[] = $row;
                }
            }
            usort($flagged, static fn (array $a, array $b): int => strcmp($a['codidioma'], $b['codidioma']));

            return $flagged ? [$flagged[0]] : [];
        }

        if (preg_match('/^SELECT codidioma FROM ' . preg_quote(\idioma::TABLE, '/') . ' WHERE por_defecto = TRUE AND activo = TRUE ORDER BY codidioma ASC LIMIT 1;?$/i', $sql)) {
            $flagged = [];
            foreach ($this->idiomas as $cod => $row) {
                if ($row['por_defecto'] && $row['activo']) {
                    $flagged[] = $cod;
                }
            }
            sort($flagged, SORT_STRING);

            return $flagged ? [['codidioma' => $flagged[0]]] : [];
        }

        if (preg_match('/^SELECT \* FROM ' . preg_quote(\idioma::TABLE, '/') . ' WHERE por_defecto = TRUE LIMIT 1;?$/i', $sql)) {
            foreach ($this->idiomas as $row) {
                if ($row['por_defecto']) {
                    return [$row];
                }
            }

            return [];
        }

        if (preg_match('/^SELECT codidioma FROM ' . preg_quote(\idioma::TABLE, '/') . ' WHERE activo = TRUE ORDER BY codidioma ASC LIMIT 1;?$/i', $sql)) {
            $codes = array_keys($this->idiomas);
            $active = [];
            foreach ($codes as $cod) {
                if ($this->idiomas[$cod]['activo']) {
                    $active[] = $cod;
                }
            }
            sort($active, SORT_STRING);

            return $active ? [['codidioma' => $active[0]]] : [];
        }

        if (preg_match('/^SELECT \* FROM ' . preg_quote(\idioma::TABLE, '/') . ' WHERE codidioma = \'([^\']*)\';?$/i', $sql, $m)) {
            return isset($this->idiomas[$m[1]]) ? [$this->idiomas[$m[1]]] : [];
        }

        if (preg_match('/^SELECT \* FROM ' . preg_quote(\idioma::TABLE, '/') . '(?: WHERE activo = TRUE)? ORDER BY (?:nombre|codidioma) ASC;?$/i', $sql)) {
            $rows = array_values($this->idiomas);
            if (stripos($sql, 'activo = TRUE') !== false) {
                $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['activo']));
            }

            $column = stripos($sql, 'ORDER BY codidioma') !== false ? 'codidioma' : 'nombre';
            usort($rows, static fn (array $a, array $b): int => strcmp((string) $a[$column], (string) $b[$column]));

            return $rows;
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $sql = trim((string) $sql);
        $this->executed[] = $sql;

        if ($this->failNextExec) {
            $this->failNextExec = false;

            return false;
        }

        $table = preg_quote(\idioma::TABLE, '/');

        if (preg_match('/^DELETE FROM ' . $table . ' WHERE codidioma = \'([^\']*)\';?$/i', $sql, $m)) {
            unset($this->idiomas[$m[1]]);

            return true;
        }

        if (preg_match('/^INSERT INTO ' . $table . ' \(codidioma, nombre, activo, por_defecto\) VALUES \((.*)\);?$/i', $sql, $m)) {
            $values = $this->parseSqlValues($m[1]);
            $this->idiomas[(string) $values[0]] = [
                'codidioma' => (string) $values[0],
                'nombre' => (string) $values[1],
                'activo' => (bool) $values[2],
                'por_defecto' => (bool) $values[3],
            ];

            return true;
        }

        if (preg_match('/^UPDATE ' . $table . ' SET por_defecto = FALSE WHERE codidioma != \'([^\']*)\';?$/i', $sql, $m)) {
            foreach ($this->idiomas as $cod => $row) {
                if ($cod !== $m[1]) {
                    $this->idiomas[$cod]['por_defecto'] = false;
                }
            }

            return true;
        }

        if (preg_match('/^UPDATE ' . $table . ' SET por_defecto = FALSE WHERE por_defecto = TRUE AND activo = FALSE;?$/i', $sql)) {
            foreach ($this->idiomas as $cod => $row) {
                if ($row['por_defecto'] && !$row['activo']) {
                    $this->idiomas[$cod]['por_defecto'] = false;
                }
            }

            return true;
        }

        if (preg_match('/^UPDATE ' . $table . ' SET por_defecto = FALSE WHERE por_defecto = TRUE;?$/i', $sql)) {
            foreach ($this->idiomas as $cod => $row) {
                $this->idiomas[$cod]['por_defecto'] = false;
            }

            return true;
        }

        if (preg_match('/^UPDATE ' . $table . ' SET por_defecto = TRUE, activo = TRUE WHERE codidioma = \'([^\']*)\';?$/i', $sql, $m)) {
            if (isset($this->idiomas[$m[1]])) {
                $this->idiomas[$m[1]]['por_defecto'] = true;
                $this->idiomas[$m[1]]['activo'] = true;
            }

            return true;
        }

        if (preg_match('/^UPDATE ' . $table . ' SET por_defecto = TRUE WHERE codidioma = \'([^\']*)\';?$/i', $sql, $m)) {
            foreach ($this->idiomas as $cod => $row) {
                $this->idiomas[$cod]['por_defecto'] = ($cod === $m[1]);
            }

            return true;
        }

        if (preg_match('/^UPDATE ' . $table . ' SET nombre = \'([^\']*)\', activo = (TRUE|FALSE|\d+), por_defecto = (TRUE|FALSE|\d+) WHERE codidioma = \'([^\']*)\';?$/i', $sql, $m)) {
            if (isset($this->idiomas[$m[4]])) {
                $this->idiomas[$m[4]]['nombre'] = $m[1];
                $this->idiomas[$m[4]]['activo'] = $this->toBool($m[2]);
                $this->idiomas[$m[4]]['por_defecto'] = $this->toBool($m[3]);
            }

            return true;
        }

        return true;
    }

    private function toBool(string $token): bool
    {
        if (is_numeric($token)) {
            return ((int) $token) === 1;
        }

        return strcasecmp($token, 'TRUE') === 0 || strcasecmp($token, 't') === 0;
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
