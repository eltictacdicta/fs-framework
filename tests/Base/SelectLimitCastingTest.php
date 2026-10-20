<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

/**
 * `select_limit()` concatenates `$limit` and `$offset` into the SQL string.
 * Both values reach the engines from pagination, and `fs_list_controller`
 * used to read the offset straight from the request without a cast, so a
 * crafted `?offset=` could escape the LIMIT/OFFSET clause.
 *
 * The engines now cast both values to int. These tests pin that contract by
 * capturing the SQL they build.
 */
final class CapturingMysqlEngine extends \fs_mysql
{
    public string $capturedSql = '';

    public function select($sql, $params = [])
    {
        $this->capturedSql = $sql;

        return [];
    }
}

final class CapturingPostgresqlEngine extends \fs_postgresql
{
    public string $capturedSql = '';

    public function select($sql, $params = [])
    {
        $this->capturedSql = $sql;

        return [];
    }
}

final class SelectLimitCastingTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_db_engine.php';
        require_once FS_FOLDER . '/base/fs_mysql.php';
        require_once FS_FOLDER . '/base/fs_postgresql.php';
    }

    public function testMysqlCastsLimitAndOffsetToIntegers(): void
    {
        $engine = new CapturingMysqlEngine();
        $engine->select_limit('SELECT * FROM fs_users', "0 INTO OUTFILE '/tmp/x.php'", '1 OR 1=1');

        $this->assertSame('SELECT * FROM fs_users LIMIT 0 OFFSET 1;', $engine->capturedSql);
    }

    public function testPostgresqlCastsLimitAndOffsetToIntegers(): void
    {
        $engine = new CapturingPostgresqlEngine();
        $engine->select_limit('SELECT * FROM fs_users', "0 INTO OUTFILE '/tmp/x.php'", '1 OR 1=1');

        $this->assertSame('SELECT * FROM fs_users LIMIT 0 OFFSET 1;', $engine->capturedSql);
    }

    public function testMysqlKeepsNumericPaginationUnchanged(): void
    {
        $engine = new CapturingMysqlEngine();
        $engine->select_limit('SELECT * FROM fs_users', 50, 100);

        $this->assertSame('SELECT * FROM fs_users LIMIT 50 OFFSET 100;', $engine->capturedSql);
    }

    public function testPostgresqlKeepsNumericPaginationUnchanged(): void
    {
        $engine = new CapturingPostgresqlEngine();
        $engine->select_limit('SELECT * FROM fs_users', 50, 100);

        $this->assertSame('SELECT * FROM fs_users LIMIT 50 OFFSET 100;', $engine->capturedSql);
    }

    public function testMysqlTurnsNonNumericOffsetIntoZero(): void
    {
        $engine = new CapturingMysqlEngine();
        $engine->select_limit('SELECT * FROM fs_users', 25, 'abc');

        $this->assertSame('SELECT * FROM fs_users LIMIT 25 OFFSET 0;', $engine->capturedSql);
    }

    public function testPostgresqlTurnsNonNumericOffsetIntoZero(): void
    {
        $engine = new CapturingPostgresqlEngine();
        $engine->select_limit('SELECT * FROM fs_users', 25, 'abc');

        $this->assertSame('SELECT * FROM fs_users LIMIT 25 OFFSET 0;', $engine->capturedSql);
    }

    public function testMysqlTurnsNumericStringOffsetIntoInteger(): void
    {
        $engine = new CapturingMysqlEngine();
        $engine->select_limit('SELECT * FROM fs_users', '50', '150');

        $this->assertSame('SELECT * FROM fs_users LIMIT 50 OFFSET 150;', $engine->capturedSql);
    }

    public function testPostgresqlTurnsNumericStringOffsetIntoInteger(): void
    {
        $engine = new CapturingPostgresqlEngine();
        $engine->select_limit('SELECT * FROM fs_users', '50', '150');

        $this->assertSame('SELECT * FROM fs_users LIMIT 50 OFFSET 150;', $engine->capturedSql);
    }
}
