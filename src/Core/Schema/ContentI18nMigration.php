<?php

declare(strict_types=1);

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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FSFramework\Core\Schema;

/**
 * One-shot, idempotent migration for the core content-i18n language registry.
 *
 * It adopts the `idiomas` table through the lazy model schema check and seeds
 * exactly one `es` row when the table is empty. It is gated by the
 * `content_i18n_migrated` `fs_var` flag, written only after every step
 * succeeds, so a failed or database-less run leaves the flag unwritten and the
 * next request retries.
 *
 * The class is intentionally non-final and its steps are `protected`: the test
 * harness has no database and overrides them to assert ordering, the allowlist
 * guard and failure handling. A table allowlist of exactly `['idiomas']` is
 * asserted before any work, so core code structurally cannot touch a plugin
 * table (CI-18 / CI-20).
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class ContentI18nMigration
{
    /**
     * `fs_var` key written only after the migration completes successfully.
     */
    public const FLAG = 'content_i18n_migrated';

    /**
     * Explicit allowlist of core tables this migration may touch. It is a fixed
     * list (never derived from a variable) so a change to the model's table
     * cannot silently point the migration at a plugin table.
     *
     * @var list<string>
     */
    public const TABLE_ALLOWLIST = [
        'idiomas',
    ];

    private ?\fs_db2 $db = null;

    /**
     * Runs the migration. Entry point used by the bootstrap.
     */
    public static function run(): bool
    {
        return (new self())->execute();
    }

    /**
     * Executes the allowlist guard and every step once, in order. Returns TRUE
     * on success and on the already-applied no-op, and FALSE as soon as a step
     * reports failure.
     *
     * The first `false` aborts before `markApplied()`, so the success flag stays
     * unwritten and the next request retries. An unexpected exception still
     * propagates and leaves the flag unwritten too.
     */
    public function execute(): bool
    {
        if ($this->isApplied()) {
            return true;
        }

        if (!$this->assertTableAllowlisted()) {
            return false;
        }

        if (!$this->adoptTable() || !$this->seed()) {
            return false;
        }

        return $this->markApplied();
    }

    /**
     * Whether the success flag is present. Overridable so the no-op path can be
     * tested without a database.
     *
     * The comparison is deliberately strict: `simple_save()` stores the string
     * 'TRUE', and a loose cast would read a stored 'FALSE'/'0' as applied and
     * never re-run the migration.
     */
    protected function isApplied(): bool
    {
        return $this->fsVar()->simple_get(self::FLAG) === 'TRUE';
    }

    /**
     * The table this migration is allowed to touch. Overridable so the
     * allowlist guard can be exercised without loading the legacy model.
     */
    protected function tableName(): string
    {
        $this->loadDependencies();

        return \idioma::TABLE;
    }

    /**
     * Structural guard: the resolved table must be in the fixed allowlist
     * before any schema or data work runs (CI-18).
     */
    protected function assertTableAllowlisted(): bool
    {
        return in_array($this->tableName(), self::TABLE_ALLOWLIST, true);
    }

    /**
     * Instantiating `idioma` re-enters `fs_model::check_table('idiomas')` and
     * adopts the table through the lazy schema check. Success is verified by
     * reading the schema back: the table must expose at least one column before
     * the caller proceeds. When no database is available `get_columns()` reports
     * nothing and this returns `false` without throwing.
     */
    protected function adoptTable(): bool
    {
        $model = $this->model();

        return $this->tableExists($model->table_name);
    }

    /**
     * Seeds the single default `es` row only when the registry is empty and
     * verifies the table holds at least one row afterwards (CI-05).
     */
    protected function seed(): bool
    {
        $model = $this->model();
        $model->ensure_defaults();

        return $model->all() !== [];
    }

    /**
     * Records success so subsequent runs are a no-op (CI-18).
     *
     * Returns FALSE when the flag could not be persisted, so the caller does not
     * report success while nothing recorded it (CI-20).
     */
    protected function markApplied(): bool
    {
        return (bool) $this->fsVar()->simple_save(self::FLAG, 'TRUE');
    }

    /**
     * Lazily instantiates the legacy `idioma` model, which adopts the `idiomas`
     * table through the lazy schema check on construction.
     */
    private function model(): \idioma
    {
        $this->loadDependencies();

        return new \idioma();
    }

    private function fsVar(): \fs_var
    {
        $this->loadDependencies();

        return new \fs_var();
    }

    /**
     * Whether the given table exposes at least one column in the live schema.
     */
    private function tableExists(string $table): bool
    {
        foreach ($this->db()->get_columns($table) as $column) {
            if (is_array($column)) {
                return true;
            }
        }

        return false;
    }

    private function db(): \fs_db2
    {
        if ($this->db === null) {
            $this->loadDependencies();
            $this->db = new \fs_db2();
        }

        return $this->db;
    }

    /**
     * Loads the legacy bootstrap classes this migration depends on. Guarded by
     * `class_exists(..., false)` so it is safe even when the core self-heal
     * already loaded them (or failed).
     */
    protected function loadDependencies(): void
    {
        $folder = defined('FS_FOLDER') ? FS_FOLDER : '.';

        $dependencies = [
            'fs_model' => '/base/fs_model.php',
            'fs_db2' => '/base/fs_db2.php',
            'fs_var' => '/model/fs_var.php',
            'idioma' => '/model/idioma.php',
        ];

        foreach ($dependencies as $class => $relativePath) {
            if (!class_exists($class, false)) {
                require_once $folder . $relativePath;
            }
        }
    }
}
