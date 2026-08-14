<?php

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Database\SqlSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlSanitizer::class)]
class SqlSanitizerTest extends TestCase
{
    #[Test]
    public function testSplitTrustedBatchSeparatesCreateTableAndInstallSeed(): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `fs_pages` (`name` VARCHAR(30) NOT NULL, PRIMARY KEY (name));'
            . "INSERT INTO fs_pages (name,title,folder,version,show_on_menu) VALUES ('admin_home','panel de control','admin',NULL,TRUE);";

        $this->assertSame(
            [
                'CREATE TABLE IF NOT EXISTS `fs_pages` (`name` VARCHAR(30) NOT NULL, PRIMARY KEY (name))',
                "INSERT INTO fs_pages (name,title,folder,version,show_on_menu) VALUES ('admin_home','panel de control','admin',NULL,TRUE)",
            ],
            SqlSanitizer::splitTrustedBatch($sql)
        );
    }

    #[Test]
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

    #[Test]
    public function testSplitTrustedBatchKeepsSingleStatementWithSemicolonInLiteralUntouched(): void
    {
        $sql = "INSERT INTO fs_logs (detalle) VALUES ('Mozilla/5.0 (X11; Linux x86_64)');";

        $this->assertSame(
            ["INSERT INTO fs_logs (detalle) VALUES ('Mozilla/5.0 (X11; Linux x86_64)')"],
            SqlSanitizer::splitTrustedBatch($sql)
        );
    }

    #[Test]
    public function testPrepareForExecutionRequiresMatchingPlaceholderCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SqlSanitizer::prepareForExecution('SELECT * FROM fs_users WHERE nick = ?', ['admin', 'extra']);
    }
}
