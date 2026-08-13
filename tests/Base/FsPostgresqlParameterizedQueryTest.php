<?php

declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsPostgresqlParameterizedQueryTest extends TestCase
{
    private \fs_postgresql $postgresql;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_db_engine.php';
        require_once FS_FOLDER . '/base/fs_postgresql.php';

        $this->postgresql = new \fs_postgresql();
    }

    public function testConvertPlaceholdersRewritesQuestionMarks(): void
    {
        $method = new \ReflectionMethod(\fs_postgresql::class, 'convert_placeholders');
        $method->setAccessible(true);

        $sql = $method->invoke($this->postgresql, 'SELECT * FROM fs_users WHERE nick = ? AND enabled = ?;');

        $this->assertSame('SELECT * FROM fs_users WHERE nick = $1 AND enabled = $2;', $sql);
    }

    public function testConvertPlaceholdersLeavesSqlWithoutBindingsUntouched(): void
    {
        $method = new \ReflectionMethod(\fs_postgresql::class, 'convert_placeholders');
        $method->setAccessible(true);

        $sql = $method->invoke($this->postgresql, 'SELECT 1;');

        $this->assertSame('SELECT 1;', $sql);
    }
}
