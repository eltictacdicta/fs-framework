<?php
/**
 * Regression tests for fs_mysql identifier validation.
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsMysqlIdentifierValidationTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_db_engine.php';
        require_once FS_FOLDER . '/base/fs_mysql.php';
    }

    public function testGetColumnsQuotesValidTableName(): void
    {
        $mysql = new class() extends \fs_mysql {
            public array $queries = [];

            public function select($sql, $offset = 0, $limit = 0)
            {
                $this->queries[] = $sql;
                return [];
            }
        };

        $mysql->get_columns('demo_table');

        $this->assertSame('SHOW COLUMNS FROM `demo_table`;', $mysql->queries[0]);
    }

    public function testCheckTableAuxRejectsUnsafeTableName(): void
    {
        $mysql = new \fs_mysql();

        $this->expectException(\InvalidArgumentException::class);
        $mysql->check_table_aux('demo; DROP TABLE fs_users;--');
    }

    public function testGenerateTableRejectsUnsafeTableName(): void
    {
        $mysql = new \fs_mysql();

        $this->expectException(\InvalidArgumentException::class);
        $mysql->generate_table('bad-name', [], []);
    }
}