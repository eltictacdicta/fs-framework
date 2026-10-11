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

require_once FS_FOLDER . '/model/fs_rol_user.php';

/**
 * Minimal database double for `fs_rol_user`.
 *
 * Mirrors the shape used by `FsRolAccessAdminOnlyTest`: it captures the
 * statements and returns a scripted `select` result, so the membership upsert
 * can be exercised without a live database.
 */
final class RolUserDbStub
{
    /** @var array<int, array<string, mixed>>|false */
    public array|false $selectResult = false;

    /** @var list<string> */
    public array $executedSql = [];

    /** @var list<string> */
    public array $selectSql = [];

    public function select(string $sql, array $params = []): array|false
    {
        $this->selectSql[] = $sql;

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
 * `fs_rol_user.responsable` is a marker only: it never grants permissions. It
 * must survive the upsert path (INSERT on first assignment, UPDATE afterwards)
 * and be queryable per role.
 */
final class FsRolUserResponsableTest extends TestCase
{
    /** @var mixed */
    private $previousCheckedTables;

    private bool $hadCheckedTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep real `fs_rol_user` construction hermetic (no `check_table()` DB call).
        $prop = new \ReflectionProperty(\fs_model::class, 'checked_tables');
        $prop->setAccessible(true);
        $current = $prop->getValue();
        $this->hadCheckedTables = is_array($current);
        $this->previousCheckedTables = $current;

        $list = $this->hadCheckedTables ? $current : [];
        if (!in_array('fs_roles_users', $list, true)) {
            $list[] = 'fs_roles_users';
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

    private function makeMembership(
        RolUserDbStub $db,
        string $codrol = 'ROL1',
        string $nick = 'admin',
        bool $responsable = false
    ): \fs_rol_user {
        $model = new \fs_rol_user([
            'codrol' => $codrol,
            'fs_user' => $nick,
            'responsable' => $responsable ? '1' : '0',
        ]);

        $dbProp = new \ReflectionProperty(\fs_model::class, 'db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($model, $db);

        return $model;
    }

    #[Test]
    public function constructorDefaultsResponsableToFalse(): void
    {
        $model = new \fs_rol_user(['codrol' => 'ROL1', 'fs_user' => 'admin']);

        self::assertFalse($model->responsable);
    }

    #[Test]
    public function constructorReadsResponsableFromData(): void
    {
        $yes = new \fs_rol_user(['codrol' => 'ROL1', 'fs_user' => 'admin', 'responsable' => '1']);
        $no = new \fs_rol_user(['codrol' => 'ROL1', 'fs_user' => 'admin', 'responsable' => '0']);

        self::assertTrue($yes->responsable);
        self::assertFalse($no->responsable);
    }

    #[Test]
    public function saveInsertsWhenMembershipDoesNotExist(): void
    {
        $db = new RolUserDbStub();
        $db->selectResult = false;

        $model = $this->makeMembership($db, 'ROL1', 'admin', true);

        self::assertTrue($model->save());
        self::assertCount(1, $db->executedSql);
        self::assertStringContainsString('INSERT INTO fs_roles_users', $db->executedSql[0]);
        self::assertStringContainsString('responsable', $db->executedSql[0]);
        self::assertStringContainsString('TRUE', $db->executedSql[0]);
    }

    #[Test]
    public function saveUpdatesTheResponsableFlagWhenMembershipExists(): void
    {
        $db = new RolUserDbStub();
        $db->selectResult = [['codrol' => 'ROL1', 'fs_user' => 'admin', 'responsable' => '0']];

        $model = $this->makeMembership($db, 'ROL1', 'admin', true);

        self::assertTrue($model->save());
        self::assertCount(1, $db->executedSql);
        self::assertStringContainsString('UPDATE fs_roles_users', $db->executedSql[0]);
        self::assertStringContainsString('SET responsable = TRUE', $db->executedSql[0]);
    }

    #[Test]
    public function saveDowngradesTheResponsableFlag(): void
    {
        $db = new RolUserDbStub();
        $db->selectResult = [['codrol' => 'ROL1', 'fs_user' => 'admin', 'responsable' => '1']];

        $model = $this->makeMembership($db, 'ROL1', 'admin', false);

        self::assertTrue($model->save());
        self::assertStringContainsString('SET responsable = FALSE', $db->executedSql[0]);
    }

    #[Test]
    public function saveKeepsWorkingForLegacyCallersSettingOnlyTheKeys(): void
    {
        $db = new RolUserDbStub();
        $db->selectResult = false;

        // Legacy callers (admin_users::add_user) only set codrol + fs_user.
        $model = new \fs_rol_user();
        $model->codrol = 'ROL2';
        $model->fs_user = 'legacy';

        $dbProp = new \ReflectionProperty(\fs_model::class, 'db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($model, $db);

        self::assertTrue($model->save());
        self::assertStringContainsString('INSERT INTO fs_roles_users', $db->executedSql[0]);
        self::assertStringContainsString('FALSE', $db->executedSql[0]);
    }

    #[Test]
    public function responsablesFromRolQueriesOnlyResponsableRows(): void
    {
        $db = new RolUserDbStub();
        $db->selectResult = [
            ['codrol' => 'ROL1', 'fs_user' => 'admin', 'responsable' => '1'],
        ];

        $model = $this->makeMembership($db, 'ROL1');
        $responsables = $model->responsables_from_rol('ROL1');

        self::assertCount(1, $db->selectSql);
        self::assertStringContainsString('responsable', $db->selectSql[0]);
        self::assertCount(1, $responsables);
        self::assertInstanceOf(\fs_rol_user::class, $responsables[0]);
        self::assertTrue($responsables[0]->responsable);
        self::assertSame('admin', $responsables[0]->fs_user);
    }

    #[Test]
    public function responsablesFromRolReturnsEmptyWhenNoneMatch(): void
    {
        $db = new RolUserDbStub();
        $db->selectResult = false;

        $model = $this->makeMembership($db, 'ROL1');
        $responsables = $model->responsables_from_rol('ROL1');

        self::assertSame([], $responsables);
    }
}
