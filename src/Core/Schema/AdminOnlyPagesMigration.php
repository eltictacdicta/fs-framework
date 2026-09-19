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
 * One-shot, idempotent migration for administrator-only pages.
 *
 * It adopts the `fs_pages.admin_only` column through the lazy model schema
 * check, backfills the explicit core allowlist, removes stale role grants and
 * clears the page cache. It is gated by the `fs_var` flag written only after
 * every step succeeds, so a failed run retries on the next request.
 *
 * The class is intentionally non-final and its steps are `protected`: the test
 * harness has no database and overrides them to assert ordering and failure
 * handling. Fallible steps return a boolean; `execute()` aborts before writing
 * the flag when any of them reports `false`.
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class AdminOnlyPagesMigration
{
    /**
     * `fs_var` key written only after the migration completes successfully.
     */
    public const FLAG = 'admin_only_pages_migrated';

    /**
     * Explicit allowlist of core pages that become administrator-only. It is
     * intentionally a fixed list (never a `LIKE 'admin_%'` prefix) so plugin
     * pages named `admin_*` are not flagged by accident.
     *
     * @var list<string>
     */
    public const PAGE_ALLOWLIST = [
        'admin_users',
        'admin_user',
        'admin_rol',
        'admin_info',
        'admin_email',
        'admin_system_branding',
        'admin_stealth',
        'admin_orden_menu',
        'admin_agentes',
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
     * Executes every step once, in order. Returns TRUE on success and on the
     * already-applied no-op, and FALSE as soon as a step reports failure.
     *
     * Every SQL-executing step validates its own result and returns a boolean.
     * The first `false` aborts before `markApplied()`, so the success flag stays
     * unwritten and the next request retries. An unexpected exception still
     * propagates and leaves the flag unwritten too.
     */
    public function execute(): bool
    {
        if ($this->isApplied()) {
            return true;
        }

        $this->forgetCheckedTables();

        if (!$this->adoptColumn() || !$this->backfill() || !$this->purgeStaleGrants()) {
            return false;
        }

        $this->clearPageCache();

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
        $this->loadDependencies();
        $fsvar = new \fs_var();

        return $fsvar->simple_get(self::FLAG) === 'TRUE';
    }

    /**
     * Drops `fs_pages` from the checked-tables cache so the lazy schema check
     * re-reads the table and adopts the new column.
     */
    protected function forgetCheckedTables(): void
    {
        $this->loadDependencies();
        \fs_model::forgetCheckedTables(['fs_pages']);
    }

    /**
     * Instantiating `fs_page` re-enters `fs_model::check_table('fs_pages')` and
     * triggers `compare_columns()`, which emits the ALTER for `admin_only`.
     *
     * The lazy schema check reports its own errors through the core log without
     * throwing, so success is verified by reading the schema back: the column
     * must exist before the caller proceeds.
     */
    protected function adoptColumn(): bool
    {
        $this->loadDependencies();
        new \fs_page();

        return $this->hasAdminOnlyColumn();
    }

    /**
     * Whether `fs_pages.admin_only` exists in the live schema.
     */
    private function hasAdminOnlyColumn(): bool
    {
        foreach ($this->db()->get_columns('fs_pages') as $column) {
            if (is_array($column) && ($column['name'] ?? null) === 'admin_only') {
                return true;
            }
        }

        return false;
    }

    /**
     * Marks the explicit allowlist as administrator-only. Backfill never uses a
     * name prefix. Returns FALSE when the UPDATE fails.
     */
    protected function backfill(): bool
    {
        $quoted = [];
        foreach (self::PAGE_ALLOWLIST as $pageName) {
            $quoted[] = $this->db()->var2str($pageName);
        }

        return (bool) $this->db()->exec(
            'UPDATE fs_pages SET admin_only = TRUE WHERE name IN (' . implode(',', $quoted) . ');'
        );
    }

    /**
     * Removes role grants that target administrator-only pages. Returns FALSE
     * when the DELETE fails.
     */
    protected function purgeStaleGrants(): bool
    {
        return (bool) $this->db()->exec(
            'DELETE FROM fs_roles_access WHERE fs_page IN (SELECT name FROM fs_pages WHERE admin_only = TRUE);'
        );
    }

    /**
     * Raw SQL above bypasses `fs_page::save()`, so the page list cache is
     * cleared explicitly.
     */
    protected function clearPageCache(): void
    {
        $this->loadDependencies();
        (new \fs_cache())->delete('m_fs_page_all');
    }

    /**
     * Records success so subsequent runs are a no-op.
     *
     * Returns FALSE when the flag could not be persisted. The caller must not
     * report success in that case: an unwritten flag means the next request
     * retries, which is the intended fail-closed behaviour.
     */
    protected function markApplied(): bool
    {
        $this->loadDependencies();

        return (bool) (new \fs_var())->simple_save(self::FLAG, 'TRUE');
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
            'fs_cache' => '/base/fs_cache.php',
            'fs_db2' => '/base/fs_db2.php',
            'fs_page' => '/model/fs_page.php',
            'fs_var' => '/model/fs_var.php',
        ];

        foreach ($dependencies as $class => $relativePath) {
            if (!class_exists($class, false)) {
                require_once $folder . $relativePath;
            }
        }
    }
}
