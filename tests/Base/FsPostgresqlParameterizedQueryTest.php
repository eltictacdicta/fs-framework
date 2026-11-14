<?php

declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsPostgresqlParameterizedQueryTest extends TestCase
{
    public function testConvertPlaceholdersRewritesQuestionMarks(): void
    {
        $this->assertSame(
            'SELECT * FROM fs_users WHERE nick = $1 AND enabled = $2;',
            \FSFramework\Database\LegacySqlExecutor::toPostgresPlaceholders('SELECT * FROM fs_users WHERE nick = ? AND enabled = ?;')
        );
    }

    public function testConvertPlaceholdersLeavesSqlWithoutBindingsUntouched(): void
    {
        $this->assertSame('SELECT 1;', \FSFramework\Database\LegacySqlExecutor::toPostgresPlaceholders('SELECT 1;'));
    }

    public function testAQuestionMarkWithoutParamsIsLiteralText(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/src/Database/LegacySqlExecutor.php');
        $this->assertStringContainsString('$params === [] ? $sqlTemplate : self::toPostgresPlaceholders($sqlTemplate)', $source);
    }
}
