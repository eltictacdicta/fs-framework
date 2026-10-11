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

require_once FS_FOLDER . '/base/fs_schema.php';

// The model file is non-autoloaded in the Base harness; load it when present so
// RED (file absent) surfaces as per-test "class not found" errors instead of a
// hard process abort from a missing `require`.
$groupModelFile = FS_FOLDER . '/model/fs_group.php';
if (file_exists($groupModelFile)) {
    require_once $groupModelFile;
}

/**
 * Minimal database double for `fs_group`. Records SELECT/EXEC statements so the
 * generated SQL can be asserted without a live connection.
 */
final class GroupDbStub
{
    /** @var array<int, array<string, mixed>>|false */
    public array|false $selectResult = false;

    /** @var list<string> */
    public array $executedSql = [];

    /** @var list<string> */
    public array $executedSelectSql = [];

    public bool $execResult = true;

    public function select(string $sql, array $params = []): array|false
    {
        $this->executedSelectSql[] = $sql;

        return $this->selectResult;
    }

    public function exec(string $sql, $transaction = null, array $params = [], bool $batch = false): bool
    {
        $this->executedSql[] = $sql;

        return $this->execResult;
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
 * UG-01 (group entity lifecycle) and UG-05 (registry) for `fs_group`.
 *
 * The suite has no database: an anonymous `fs_group` subclass skips
 * `parent::__construct()` and a `GroupDbStub` is injected into the inherited
 * `fs_model::$db` via reflection. `fs_model::$checked_tables` is pre-seeded with
 * `fs_groups` so the real models built by `get()`/`all()` skip `check_table()`.
 */
final class FsGroupTest extends TestCase
{
    /** @var mixed */
    private $previousCheckedTables;

    private bool $hadCheckedTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        $prop = new \ReflectionProperty(\fs_model::class, 'checked_tables');
        $prop->setAccessible(true);
        $current = $prop->getValue();
        $this->hadCheckedTables = is_array($current);
        $this->previousCheckedTables = $current;

        $list = $this->hadCheckedTables ? $current : [];
        if (!in_array('fs_groups', $list, true)) {
            $list[] = 'fs_groups';
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

    /**
     * Builds an `fs_group` whose constructor skips the framework bootstrap, and
     * injects the recording DB double into it.
     */
    private function makeGroup(?string $codgrupo, ?string $descripcion, GroupDbStub $db): \fs_group
    {
        $group = new class($codgrupo, $descripcion) extends \fs_group {
            public function __construct($codgrupo = null, $descripcion = null)
            {
                // Skip parent::__construct(): no DB, cache or default-items wiring.
                $this->table_name = 'fs_groups';
                $this->codgrupo = $codgrupo;
                $this->descripcion = $descripcion;
            }
        };

        $dbProp = new \ReflectionProperty(\fs_model::class, 'db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($group, $db);

        return $group;
    }

    #[Test]
    public function getReturnsModelWhenRowExists(): void
    {
        $db = new GroupDbStub();
        $db->selectResult = [['codgrupo' => 'comercial', 'descripcion' => 'Equipo comercial']];
        $group = $this->makeGroup(null, null, $db);

        $found = $group->get('comercial');

        self::assertInstanceOf(\fs_group::class, $found);
        self::assertSame('comercial', $found->codgrupo);
        self::assertSame('Equipo comercial', $found->descripcion);
        self::assertStringContainsString("WHERE codgrupo = 'comercial'", $db->executedSelectSql[0]);
    }

    #[Test]
    public function getReturnsFalseWhenMissing(): void
    {
        $db = new GroupDbStub();
        $db->selectResult = false;
        $group = $this->makeGroup(null, null, $db);

        self::assertFalse($group->get('inexistente'));
        self::assertSame([], $db->executedSql, 'A missing lookup must not write.');
    }

    #[Test]
    public function saveInsertsWhenNew(): void
    {
        $db = new GroupDbStub();
        $db->selectResult = false; // exists() === false
        $group = $this->makeGroup('comercial', 'Equipo comercial', $db);

        self::assertTrue($group->save());
        self::assertCount(1, $db->executedSql);
        self::assertStringContainsString('INSERT INTO fs_groups', $db->executedSql[0]);
        self::assertStringContainsString("'comercial'", $db->executedSql[0]);
        self::assertStringContainsString("'Equipo comercial'", $db->executedSql[0]);
    }

    #[Test]
    public function saveUpdatesWhenExisting(): void
    {
        $db = new GroupDbStub();
        $db->selectResult = [['codgrupo' => 'comercial', 'descripcion' => 'Vieja']]; // exists() === true
        $group = $this->makeGroup('comercial', 'Ventas', $db);

        self::assertTrue($group->save());
        self::assertCount(1, $db->executedSql);
        self::assertStringContainsString('UPDATE fs_groups', $db->executedSql[0]);
        self::assertStringContainsString('SET descripcion', $db->executedSql[0]);
        self::assertStringNotContainsString('INSERT INTO', $db->executedSql[0]);
    }

    #[Test]
    public function deleteReturnsTrueAndTargetsCode(): void
    {
        $db = new GroupDbStub();
        $group = $this->makeGroup('comercial', 'Equipo comercial', $db);

        self::assertTrue($group->delete());
        self::assertCount(1, $db->executedSql);
        self::assertStringContainsString('DELETE FROM fs_groups', $db->executedSql[0]);
        self::assertStringContainsString("WHERE codgrupo = 'comercial'", $db->executedSql[0]);
    }

    #[Test]
    public function allOrdersByDescripcion(): void
    {
        $db = new GroupDbStub();
        $db->selectResult = [
            ['codgrupo' => 'a', 'descripcion' => 'Alfa'],
            ['codgrupo' => 'b', 'descripcion' => 'Zeta'],
        ];
        $group = $this->makeGroup(null, null, $db);

        $list = $group->all();

        self::assertCount(2, $list);
        self::assertInstanceOf(\fs_group::class, $list[0]);
        self::assertSame('Alfa', $list[0]->descripcion);
        self::assertSame('Zeta', $list[1]->descripcion);
        self::assertStringContainsString('ORDER BY descripcion ASC, codgrupo ASC', $db->executedSelectSql[0]);
    }

    #[Test]
    public function saveSanitizesDescripcion(): void
    {
        $db = new GroupDbStub();
        $db->selectResult = false; // exists() === false
        $group = $this->makeGroup('xss', '<script>alert(1)</script>', $db);

        self::assertTrue($group->save());
        self::assertStringNotContainsString('<script>', $group->descripcion);
        self::assertStringContainsString('&lt;script&gt;', $group->descripcion);
        self::assertStringContainsString('&lt;script&gt;', $db->executedSql[0]);
    }

    #[Test]
    public function urlFallsBackToListWhenCodeIsNull(): void
    {
        $db = new GroupDbStub();
        $group = $this->makeGroup(null, null, $db);

        self::assertSame('index.php?page=admin_groups', $group->url());
    }

    #[Test]
    public function urlPointsToEditor(): void
    {
        $db = new GroupDbStub();
        $group = $this->makeGroup('comercial', 'Equipo comercial', $db);

        self::assertSame('index.php?page=admin_group&codgrupo=comercial', $group->url());
    }

    #[Test]
    public function coreRegistryListsBothGroupTables(): void
    {
        $reflection = new \ReflectionClass(\fs_schema::class);

        $tableFiles = $reflection->getReflectionConstant('CORE_TABLE_FILES')->getValue();
        $modelMap = $reflection->getReflectionConstant('CORE_MODEL_MAP')->getValue();

        $rolesUsersIndex = array_search('fs_roles_users.xml', $tableFiles, true);
        self::assertNotFalse($rolesUsersIndex, 'fs_roles_users.xml must stay registered.');
        self::assertSame('fs_groups.xml', $tableFiles[$rolesUsersIndex + 1], 'fs_groups.xml must follow fs_roles_users.xml.');
        self::assertSame('fs_groups_users.xml', $tableFiles[$rolesUsersIndex + 2], 'fs_groups_users.xml must follow fs_groups.xml.');

        self::assertArrayHasKey('fs_groups', $modelMap);
        self::assertSame('model/fs_group.php', $modelMap['fs_groups']['file']);
        self::assertSame('fs_group', $modelMap['fs_groups']['class']);

        self::assertArrayHasKey('fs_groups_users', $modelMap);
        self::assertSame('model/fs_group_user.php', $modelMap['fs_groups_users']['file']);
        self::assertSame('fs_group_user', $modelMap['fs_groups_users']['class']);
    }
}
