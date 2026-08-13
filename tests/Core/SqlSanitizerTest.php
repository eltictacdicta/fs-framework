<?php

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Database\SqlSanitizer;
use PHPUnit\Framework\TestCase;

class SqlSanitizerTest extends TestCase
{
    public function testSplitTrustedBatchSeparatesSchemaAlterStatements(): void
    {
        $sql = 'ALTER TABLE `fs_vars` ALTER `name` DROP DEFAULT;ALTER TABLE `fs_vars` ALTER `varchar` DROP DEFAULT;';

        $this->assertSame(
            [
                'ALTER TABLE `fs_vars` ALTER `name` DROP DEFAULT',
                'ALTER TABLE `fs_vars` ALTER `varchar` DROP DEFAULT',
            ],
            SqlSanitizer::splitTrustedBatch($sql)
        );
    }

    public function testSplitTrustedBatchKeepsSingleStatementWithSemicolonInLiteralUntouched(): void
    {
        $sql = "INSERT INTO fs_logs (detalle) VALUES ('Mozilla/5.0 (X11; Linux x86_64)');";

        $this->assertSame(
            ["INSERT INTO fs_logs (detalle) VALUES ('Mozilla/5.0 (X11; Linux x86_64)')"],
            SqlSanitizer::splitTrustedBatch($sql)
        );
    }

    public function testPrepareForExecutionRequiresMatchingPlaceholderCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SqlSanitizer::prepareForExecution('SELECT * FROM fs_users WHERE nick = ?', ['admin', 'extra']);
    }
}
