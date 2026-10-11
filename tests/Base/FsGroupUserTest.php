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

// Both model files are non-autoloaded in the Base harness; load them when
// present so RED (file absent) surfaces as per-test "class not found" errors
// instead of a hard process abort from a missing `require`.
$groupModelFile = FS_FOLDER . '/model/fs_group.php';
if (file_exists($groupModelFile)) {
    require_once $groupModelFile;
}
$membershipModelFile = FS_FOLDER . '/model/fs_group_user.php';
if (file_exists($membershipModelFile)) {
    require_once $membershipModelFile;
}

/**
 * Minimal database double for `fs_group_user`. Records SELECT/EXEC statements so
 * the generated SQL can be asserted without a live connection.
 */
final class GroupUserDbStub
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
 * Duck-typed DB engine double. `fs_group::get_users()` / `get_responsables()`
 * build a fresh `fs_group_user` (as `fs_rol::get_users()` builds `fs_rol_user`),
 * so the injected per-instance `$db` is not reachable. Seeding `fs_db2::$engine`
 * keeps those delegation paths hermetic.
 */
final class GroupUserEngineStub
{
    /** @var array<int, array<string, mixed>>|false */
    public array|false $selectResult = false;

    /** @var list<string> */
    public array $executedSql = [];

    public function connected(): bool
    {
        return true;
    }

    public function select(string $sql, array $params = []): array|false
    {
        $this->executedSql[] = $sql;

        return $this->selectResult;
    }

    public function escape_string(string $str): string
    {
        return addslashes($str);
    }
}

/**
 * UG-02 (membership lifecycle) and UG-03/UG-04 (`funcion` assignment and
 * allowed-value validation) for `fs_group_user`, plus the three membership
 * delegation cases deferred from PR 1 (`fs_group::all_for_user`,
 * `get_users`, `get_responsables`).
 *
 * The suite has no database: an anonymous subclass skips
 * `parent::__construct()` and a `GroupUserDbStub` is injected into the inherited
 * `fs_model::$db` via reflection. `fs_model::$checked_tables` is pre-seeded with
 * both group tables so the real models built inside the queries skip
 * `check_table()`.
 */
final class FsGroupUserTest extends TestCase
{
    /** @var mixed */
    private $previousCheckedTables;

    private bool $hadCheckedTables = false;

    /** @var mixed */
    private $previousEngine;

    private GroupUserEngineStub $engineStub;

    protected function setUp(): void
    {
        parent::setUp();

        $prop = new \ReflectionProperty(\fs_model::class, 'checked_tables');
        $prop->setAccessible(true);
        $current = $prop->getValue();
        $this->hadCheckedTables = is_array($current);
        $this->previousCheckedTables = $current;

        $list = $this->hadCheckedTables ? $current : [];
        foreach (['fs_groups', 'fs_groups_users'] as $table) {
            if (!in_array($table, $list, true)) {
                $list[] = $table;
            }
        }
        $prop->setValue(null, $list);

        // Keep fresh `fs_group_user` construction hermetic: the delegation
        // cases build their own membership instance, away from the injected stub.
        $engineProp = new \ReflectionProperty(\fs_db2::class, 'engine');
        $engineProp->setAccessible(true);
        $this->previousEngine = $engineProp->getValue();
        $this->engineStub = new GroupUserEngineStub();
        $engineProp->setValue(null, $this->engineStub);
    }

    protected function tearDown(): void
    {
        $prop = new \ReflectionProperty(\fs_model::class, 'checked_tables');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->hadCheckedTables ? $this->previousCheckedTables : null);

        $engineProp = new \ReflectionProperty(\fs_db2::class, 'engine');
        $engineProp->setAccessible(true);
        $engineProp->setValue(null, $this->previousEngine);

        parent::tearDown();
    }

    /**
     * Builds a `fs_group_user` whose constructor skips the framework bootstrap,
     * and injects the recording DB double into it.
     */
    private function makeMembership(
        ?string $codgrupo,
        ?string $fs_user,
        string $funcion,
        GroupUserDbStub $db
    ): \fs_group_user {
        $membership = new class($codgrupo, $fs_user, $funcion) extends \fs_group_user {
            public function __construct($codgrupo = null, $fs_user = null, $funcion = 'miembro')
            {
                // Skip parent::__construct(): no DB, cache or default-items wiring.
                $this->table_name = 'fs_groups_users';
                $this->codgrupo = $codgrupo;
                $this->fs_user = $fs_user;
                $this->funcion = $funcion;
            }
        };

        $dbProp = new \ReflectionProperty(\fs_model::class, 'db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($membership, $db);

        return $membership;
    }

    /**
     * Builds an `fs_group` whose constructor skips the framework bootstrap, and
     * injects the recording DB double into it (delegation cases).
     */
    private function makeGroup(?string $codgrupo, GroupUserDbStub $db): \fs_group
    {
        $group = new class($codgrupo) extends \fs_group {
            public function __construct($codgrupo = null)
            {
                $this->table_name = 'fs_groups';
                $this->codgrupo = $codgrupo;
            }
        };

        $dbProp = new \ReflectionProperty(\fs_model::class, 'db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($group, $db);

        return $group;
    }

    #[Test]
    public function funcionesConstantIsExactSet(): void
    {
        self::assertSame(['miembro', 'responsable'], \fs_group_user::FUNCIONES);
    }

    #[Test]
    public function constructorDefaultsFuncionToMiembro(): void
    {
        $withData = new \fs_group_user(['codgrupo' => 'comercial', 'fs_user' => 'ana']);
        self::assertSame('miembro', $withData->funcion);

        $empty = new \fs_group_user();
        self::assertSame('miembro', $empty->funcion);
        self::assertNull($empty->codgrupo);
        self::assertNull($empty->fs_user);
    }

    #[Test]
    public function saveAcceptsMiembroAndResponsable(): void
    {
        $db = new GroupUserDbStub();
        $db->selectResult = false; // new pair
        $member = $this->makeMembership('comercial', 'ana', 'miembro', $db);
        self::assertTrue($member->save());
        self::assertStringContainsString("'miembro'", $db->executedSql[0]);

        $dbResponsable = new GroupUserDbStub();
        $dbResponsable->selectResult = false;
        $responsable = $this->makeMembership('comercial', 'luis', 'responsable', $dbResponsable);
        self::assertTrue($responsable->save());
        self::assertStringContainsString("'responsable'", $dbResponsable->executedSql[0]);
    }

    #[Test]
    public function saveRejectsOutOfSetValueAndExecutesNoSql(): void
    {
        $db = new GroupUserDbStub();
        $membership = $this->makeMembership('comercial', 'ana', 'administrador', $db);

        self::assertFalse($membership->save(), 'An out-of-set function must be rejected.');
        self::assertSame([], $db->executedSql, 'UG-04: nothing may be persisted.');
        self::assertSame([], $db->executedSelectSql, 'The guard must run before any SQL.');
    }

    #[Test]
    public function saveUpsertsExistingPair(): void
    {
        $db = new GroupUserDbStub();
        $db->selectResult = [['codgrupo' => 'comercial', 'fs_user' => 'ana', 'funcion' => 'miembro']]; // exists() === true
        $membership = $this->makeMembership('comercial', 'ana', 'responsable', $db);

        self::assertTrue($membership->save());
        self::assertCount(1, $db->executedSql);
        self::assertStringContainsString('UPDATE fs_groups_users', $db->executedSql[0]);
        self::assertStringContainsString('SET funcion', $db->executedSql[0]);
        self::assertStringNotContainsString('INSERT INTO', $db->executedSql[0]);
    }

    #[Test]
    public function saveNeverDuplicatesPair(): void
    {
        $db = new GroupUserDbStub();
        $db->selectResult = false; // first save: new pair
        $membership = $this->makeMembership('comercial', 'ana', 'miembro', $db);
        self::assertTrue($membership->save());

        // Second save on the same pair must update, never insert a second row.
        $db->selectResult = [['codgrupo' => 'comercial', 'fs_user' => 'ana', 'funcion' => 'miembro']];
        $membership->funcion = 'responsable';
        self::assertTrue($membership->save());

        $inserts = array_filter($db->executedSql, static fn (string $sql): bool => str_contains($sql, 'INSERT INTO'));
        $updates = array_filter($db->executedSql, static fn (string $sql): bool => str_contains($sql, 'UPDATE '));
        self::assertCount(1, $inserts, 'Exactly one INSERT for a new pair.');
        self::assertCount(1, $updates, 'The re-save updates the existing pair.');
    }

    #[Test]
    public function existsIsScopedToThePair(): void
    {
        $db = new GroupUserDbStub();
        $db->selectResult = false;
        $membership = $this->makeMembership('comercial', 'ana', 'miembro', $db);

        $membership->exists();

        $sql = $db->executedSelectSql[0];
        self::assertStringContainsString("codgrupo = 'comercial'", $sql);
        self::assertStringContainsString("fs_user = 'ana'", $sql);
    }

    #[Test]
    public function deleteTargetsThePair(): void
    {
        $db = new GroupUserDbStub();
        $membership = $this->makeMembership('comercial', 'ana', 'miembro', $db);

        self::assertTrue($membership->delete());
        $sql = $db->executedSql[0];
        self::assertStringContainsString('DELETE FROM fs_groups_users', $sql);
        self::assertStringContainsString("codgrupo = 'comercial'", $sql);
        self::assertStringContainsString("fs_user = 'ana'", $sql);
    }

    #[Test]
    public function allFromGroupReturnsGroupMembers(): void
    {
        $db = new GroupUserDbStub();
        $db->selectResult = [
            ['codgrupo' => 'comercial', 'fs_user' => 'ana', 'funcion' => 'miembro'],
            ['codgrupo' => 'comercial', 'fs_user' => 'luis', 'funcion' => 'responsable'],
        ];
        $membership = $this->makeMembership(null, null, 'miembro', $db);

        $list = $membership->all_from_group('comercial');

        self::assertCount(2, $list);
        self::assertInstanceOf(\fs_group_user::class, $list[0]);
        self::assertSame('comercial', $list[0]->codgrupo);
        self::assertStringContainsString("WHERE codgrupo = 'comercial'", $db->executedSelectSql[0]);
        self::assertStringContainsString('ORDER BY fs_user ASC', $db->executedSelectSql[0]);
    }

    #[Test]
    public function allFromUserReturnsUserMemberships(): void
    {
        $db = new GroupUserDbStub();
        $db->selectResult = [
            ['codgrupo' => 'comercial', 'fs_user' => 'ana', 'funcion' => 'miembro'],
            ['codgrupo' => 'soporte', 'fs_user' => 'ana', 'funcion' => 'miembro'],
        ];
        $membership = $this->makeMembership(null, null, 'miembro', $db);

        $list = $membership->all_from_user('ana');

        self::assertCount(2, $list);
        self::assertStringContainsString("WHERE fs_user = 'ana'", $db->executedSelectSql[0]);
        self::assertStringContainsString('ORDER BY codgrupo ASC', $db->executedSelectSql[0]);
    }

    #[Test]
    public function responsablesFromGroupFiltersResponsable(): void
    {
        $db = new GroupUserDbStub();
        $db->selectResult = [
            ['codgrupo' => 'comercial', 'fs_user' => 'luis', 'funcion' => 'responsable'],
        ];
        $membership = $this->makeMembership(null, null, 'miembro', $db);

        $list = $membership->responsables_from_group('comercial');

        self::assertCount(1, $list);
        self::assertSame('responsable', $list[0]->funcion);
        self::assertStringContainsString("funcion = 'responsable'", $db->executedSelectSql[0]);
    }

    #[Test]
    public function responsablesFromGroupReturnsEmptyWhenNone(): void
    {
        $db = new GroupUserDbStub();
        $db->selectResult = false;
        $membership = $this->makeMembership(null, null, 'miembro', $db);

        self::assertSame([], $membership->responsables_from_group('comercial'));
    }

    #[Test]
    public function setFuncionAcceptsAllowedAndRejectsOtherwise(): void
    {
        $db = new GroupUserDbStub();
        $db->selectResult = false; // new pair
        $membership = $this->makeMembership('comercial', 'ana', 'miembro', $db);

        self::assertTrue($membership->set_funcion('responsable'));
        self::assertSame('responsable', $membership->funcion);
        self::assertCount(1, $db->executedSql);

        $rejectedDb = new GroupUserDbStub();
        $rejected = $this->makeMembership('comercial', 'ana', 'miembro', $rejectedDb);

        self::assertFalse($rejected->set_funcion('comercial'));
        self::assertSame('miembro', $rejected->funcion, 'A rejected value must not be assigned.');
        self::assertSame([], $rejectedDb->executedSql);
        self::assertSame([], $rejectedDb->executedSelectSql);
    }

    // --- Membership delegation deferred from PR 1 (fs_group) ---

    #[Test]
    public function allForUserReturnsGroups(): void
    {
        $db = new GroupUserDbStub();
        $db->selectResult = [['codgrupo' => 'comercial', 'descripcion' => 'Equipo comercial']];
        $group = $this->makeGroup(null, $db);

        $groups = $group->all_for_user('ana');

        self::assertCount(1, $groups);
        self::assertInstanceOf(\fs_group::class, $groups[0]);
        self::assertSame('comercial', $groups[0]->codgrupo);
        self::assertStringContainsString(
            "codgrupo IN (SELECT codgrupo FROM fs_groups_users WHERE fs_user = 'ana')",
            $db->executedSelectSql[0]
        );
    }

    #[Test]
    public function getUsersDelegatesToMembership(): void
    {
        $db = new GroupUserDbStub();
        $group = $this->makeGroup('comercial', $db);
        $this->engineStub->selectResult = [
            ['codgrupo' => 'comercial', 'fs_user' => 'ana', 'funcion' => 'miembro'],
            ['codgrupo' => 'comercial', 'fs_user' => 'luis', 'funcion' => 'responsable'],
        ];

        $users = $group->get_users();

        self::assertCount(2, $users);
        self::assertInstanceOf(\fs_group_user::class, $users[0]);
        self::assertSame('comercial', $users[0]->codgrupo);
        self::assertStringContainsString("WHERE codgrupo = 'comercial'", $this->engineStub->executedSql[0]);
    }

    #[Test]
    public function getResponsablesDelegatesToMembership(): void
    {
        $db = new GroupUserDbStub();
        $group = $this->makeGroup('comercial', $db);
        $this->engineStub->selectResult = [
            ['codgrupo' => 'comercial', 'fs_user' => 'luis', 'funcion' => 'responsable'],
        ];

        $responsables = $group->get_responsables();

        self::assertCount(1, $responsables);
        self::assertInstanceOf(\fs_group_user::class, $responsables[0]);
        self::assertSame('responsable', $responsables[0]->funcion);
        self::assertStringContainsString("funcion = 'responsable'", $this->engineStub->executedSql[0]);
    }
}
