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
 * `escape_string()` returned the value untouched when the engine had no live
 * connection. `fs_query_builder::toSql()` escapes while building SQL, which can
 * happen before connecting, so those literals reached the statement unescaped.
 */
final class EscapeStringFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_db_engine.php';
        require_once FS_FOLDER . '/base/fs_mysql.php';
        require_once FS_FOLDER . '/base/fs_postgresql.php';

        $this->setLink(null);
    }

    protected function tearDown(): void
    {
        $this->setLink(null);
    }

    public function testPostgresqlDoublesQuotesWithoutConnection(): void
    {
        $payload = "Robert'); DROP TABLE fs_users; --";

        $escaped = (new \fs_postgresql())->escape_string($payload);

        // standard_conforming_strings: la comilla se duplica, no se barra.
        $this->assertSame("Robert''); DROP TABLE fs_users; --", $escaped);
        $this->assertStringNotContainsString("'", str_replace("''", '', $escaped));
    }

    public function testMysqlEscapesQuoteWithoutConnection(): void
    {
        $payload = "Robert'); DROP TABLE fs_users; --";

        $escaped = (new \fs_mysql())->escape_string($payload);

        $this->assertSame("Robert\\'); DROP TABLE fs_users; --", $escaped);
        $this->assertSame(
            substr_count($payload, "'"),
            substr_count($escaped, "\\'"),
            'Every single quote in the payload must be backslash-escaped.'
        );
    }

    public function testBothEnginesEscapeEveryQuoteInPayload(): void
    {
        $payload = "' OR '1'='1";

        $pg = (new \fs_postgresql())->escape_string($payload);
        $my = (new \fs_mysql())->escape_string($payload);

        $this->assertSame(4, substr_count($pg, "''"));
        $this->assertSame(0, substr_count(str_replace("\\'", '', $my), "'"));
    }

    private function setLink(mixed $value): void
    {
        $property = new \ReflectionProperty(\fs_db_engine::class, 'link');
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}
