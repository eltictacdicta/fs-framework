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
 */

namespace Tests\Base;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/model/fs_page.php';
require_once FS_FOLDER . '/model/fs_rol_access.php';

/**
 * Minimal database double for `fs_rol_access::save()`.
 */
final class RolAccessDbStub
{
    /** @var array<int, array<string, mixed>>|false */
    public array|false $selectResult = false;

    /** @var list<string> */
    public array $executedSql = [];

    public function select(string $sql, array $params = []): array|false
    {
        return $this->selectResult;
    }

    public function exec(string $sql, $transaction = null, array $params = [], bool $batch = false): bool
    {
        $this->executedSql[] = $sql;
        return true;
    }

    public function var2str($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        return "'" . addslashes((string) $value) . "'";
    }
}

/**
 * PA-05 and D5: `fs_rol_access::save()` refuses administrator-only pages
 * unconditionally, and treats a missing page row as not admin-only.
 */
final class FsRolAccessAdminOnlyTest extends TestCase
{
    /** @var mixed */
    private $previousCheckedTables;

    private bool $hadCheckedTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep real `fs_page` construction hermetic (no `check_table()` DB call).
        $prop = new \ReflectionProperty(\fs_model::class, 'checked_tables');
        $prop->setAccessible(true);
        $current = $prop->getValue();
        $this->hadCheckedTables = is_array($current);
        $this->previousCheckedTables = $current;

        $list = $this->hadCheckedTables ? $current : [];
        if (!in_array('fs_pages', $list, true)) {
            $list[] = 'fs_pages';
        }
        $prop->setValue(null, $list);
    }

    protected function tearDown(): void
    {
        $prop = new \ReflectionProperty(\fs_model::class, 'checked_tables');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->hadCheckedTables ? $this->previousCheckedTables : null);

        parent::tearDown();
    }

    private function realPage(string $name, bool $adminOnly): \fs_page
    {
        return new \fs_page([
            'name' => $name,
            'title' => $name,
            'folder' => 'admin',
            'show_on_menu' => true,
            'important' => false,
            'orden' => 100,
            'admin_only' => $adminOnly,
        ]);
    }

    /**
     * @param \fs_page|false $lookup
     */
    private function makeAccess(RolAccessDbStub $db, string $pageName, $lookup)
    {
        $access = new class($lookup) extends \fs_rol_access {
            /** @var \fs_page|false */
            private $lookup;

            /**
             * @param \fs_page|false $lookup
             */
            public function __construct($lookup)
            {
                $this->lookup = $lookup;
            }

            protected function findPage(string $name): \fs_page|false
            {
                return $this->lookup;
            }

            public function guardCheck(string $name): bool
            {
                return $this->is_admin_only_page($name);
            }
        };

        $access->codrol = 'ROL1';
        $access->fs_page = $pageName;
        $access->allow_delete = false;

        $tableProp = new \ReflectionProperty(\fs_model::class, 'table_name');
        $tableProp->setAccessible(true);
        $tableProp->setValue($access, 'fs_roles_access');

        $dbProp = new \ReflectionProperty(\fs_model::class, 'db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($access, $db);

        return $access;
    }

    #[Test]
    public function saveRefusesAnAdminOnlyPageWithoutExecutingSql(): void
    {
        $db = new RolAccessDbStub();
        $access = $this->makeAccess($db, 'admin_users', $this->realPage('admin_users', true));

        self::assertFalse($access->save());
        self::assertSame([], $db->executedSql, 'An admin-only grant must not reach the database.');
    }

    #[Test]
    public function saveAllowsAnOrdinaryPage(): void
    {
        $db = new RolAccessDbStub();
        $access = $this->makeAccess($db, 'ventas', $this->realPage('ventas', false));

        self::assertTrue($access->save());
        self::assertCount(1, $db->executedSql);
        self::assertStringContainsString('INSERT INTO fs_roles_access', $db->executedSql[0]);
    }

    #[Test]
    public function saveAllowsWhenThePageRowIsMissing(): void
    {
        $db = new RolAccessDbStub();
        $access = $this->makeAccess($db, 'unknown_page', false);

        self::assertTrue($access->save(), 'A missing page row must not block the save (D5).');
        self::assertCount(1, $db->executedSql);
    }

    #[Test]
    public function saveAllowsAnEmptyPageName(): void
    {
        $db = new RolAccessDbStub();
        $access = $this->makeAccess($db, '', false);

        self::assertTrue($access->save());
        self::assertCount(1, $db->executedSql);
    }

    #[Test]
    public function isAdminOnlyPageResolvesThePersistedFlag(): void
    {
        $db = new RolAccessDbStub();

        $adminOnly = $this->makeAccess($db, 'admin_users', $this->realPage('admin_users', true));
        $ordinary = $this->makeAccess($db, 'ventas', $this->realPage('ventas', false));
        $missing = $this->makeAccess($db, 'unknown_page', false);

        self::assertTrue($adminOnly->guardCheck('admin_users'));
        self::assertFalse($ordinary->guardCheck('ventas'));
        self::assertFalse($missing->guardCheck('unknown_page'));
        self::assertFalse($ordinary->guardCheck(''), 'An empty name is never admin-only.');
    }
}
