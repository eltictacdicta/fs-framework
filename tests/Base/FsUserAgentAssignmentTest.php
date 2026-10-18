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

use FSFramework\model\fs_user;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/model/core/fs_user.php';

/**
 * Minimal database double for `fs_user::test()`.
 *
 * It captures every statement and returns a scripted `select` result, so the
 * agent-assignment guard can be exercised without a live database.
 */
final class FsUserAgentAssignmentDbStub
{
    /** @var array<int, array<string, mixed>>|false */
    public array|false $selectResult = false;

    /** @var list<string> */
    public array $selectSql = [];

    public function select(string $sql, array $params = []): array|false
    {
        $this->selectSql[] = $sql;

        return $this->selectResult;
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
 * `fs_user::test()` must keep OIDC identity correlation unambiguous: an agent
 * can only be linked to a single non-admin user. The only two-user combination
 * admitted is {1 admin + 1 non-admin}.
 */
final class FsUserAgentAssignmentTest extends TestCase
{
    /**
     * Builds a real `fs_user` without touching the database: the constructor
     * is skipped and `new_error_msg()` is captured instead of logged.
     */
    private function makeUser(FsUserAgentAssignmentDbStub $db): fs_user
    {
        return new class ($db) extends fs_user {
            /** @var list<string> */
            public array $errors = [];

            public function __construct(FsUserAgentAssignmentDbStub $db)
            {
                // @phpstan-ignore assign.propertyType (anonymous stub exposes only the fs_db2 methods fs_user::test() calls)
                $this->db = $db;
                $this->table_name = 'fs_users';
            }

            protected function new_error_msg($msg)
            {
                $this->errors[] = $msg;
            }
        };
    }

    #[Test]
    public function nonAdminUserIsRejectedWhenAgentIsHeldByAnotherNonAdmin(): void
    {
        $db = new FsUserAgentAssignmentDbStub();
        $db->selectResult = [['nick' => 'otro1', 'admin' => '0']];

        $user = $this->makeUser($db);
        $user->nick = 'user1';
        $user->admin = false;
        $user->codagente = 'A1';

        self::assertFalse($user->test());
        self::assertNotEmpty($db->selectSql, 'The guard must query the other holders.');
        self::assertStringContainsString('codagente', $db->selectSql[0]);
    }

    #[Test]
    public function nonAdminUserIsAllowedWhenAgentIsHeldOnlyByAnAdmin(): void
    {
        $db = new FsUserAgentAssignmentDbStub();
        $db->selectResult = [['nick' => 'jefe', 'admin' => '1']];

        $user = $this->makeUser($db);
        $user->nick = 'user1';
        $user->admin = false;
        $user->codagente = 'A1';

        self::assertTrue($user->test());
    }

    #[Test]
    public function adminUserIsAllowedWhenAgentIsHeldByANonAdmin(): void
    {
        $db = new FsUserAgentAssignmentDbStub();
        $db->selectResult = [['nick' => 'otro1', 'admin' => '0']];

        $user = $this->makeUser($db);
        $user->nick = 'admin1';
        $user->admin = true;
        $user->codagente = 'A1';

        self::assertTrue($user->test());
    }

    #[Test]
    public function agentHeldByTwoUsersRejectsAThirdAssignment(): void
    {
        $db = new FsUserAgentAssignmentDbStub();
        $db->selectResult = [
            ['nick' => 'jefe', 'admin' => '1'],
            ['nick' => 'otro1', 'admin' => '0'],
        ];

        $user = $this->makeUser($db);
        $user->nick = 'user2';
        $user->admin = false;
        $user->codagente = 'A1';

        self::assertFalse($user->test());
    }

    #[Test]
    public function resavingAUserWithItsOwnAgentIsAllowed(): void
    {
        $db = new FsUserAgentAssignmentDbStub();
        // The self row never comes back: the query excludes `nick = self`.
        $db->selectResult = false;

        $user = $this->makeUser($db);
        $user->nick = 'user1';
        $user->admin = false;
        $user->codagente = 'A1';

        self::assertTrue($user->test());
        self::assertStringContainsString("nick != 'user1'", $db->selectSql[0]);
    }

    #[Test]
    public function noAgentSkipsTheGuardEntirely(): void
    {
        $db = new FsUserAgentAssignmentDbStub();
        // Rows that WOULD reject the assignment if the guard ran.
        $db->selectResult = [['nick' => 'otro1', 'admin' => '0']];

        $user = $this->makeUser($db);
        $user->nick = 'user1';
        $user->admin = false;
        $user->codagente = null;

        self::assertTrue($user->test());

        foreach ($db->selectSql as $sql) {
            self::assertStringNotContainsString('codagente', $sql, 'Without an agent the guard must not query.');
        }
    }
}
