<?php
/**
 * Regression tests for fs_mysql exec() routing (prepared statements vs schema batches).
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsMysqlExecMultiQueryTest extends TestCase
{
    private \ReflectionProperty $linkProperty;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_db_engine.php';
        require_once FS_FOLDER . '/base/fs_mysql.php';

        $this->linkProperty = new \ReflectionProperty(\fs_db_engine::class, 'link');
        $this->linkProperty->setAccessible(true);
        $this->linkProperty->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $this->linkProperty->setValue(null, null);
    }

    public function testExecDoesNotSplitSemicolonsInsideStringLiterals(): void
    {
        $mysql = new \fs_mysql();
        $link = new FakeMysqliLink([
            ['affected_rows' => 1],
        ]);
        $this->linkProperty->setValue(null, $link);

        $sql = "INSERT INTO fs_logs (detalle) VALUES ('Mozilla/5.0 (X11; Linux x86_64)');";
        $this->assertTrue($mysql->exec($sql, FALSE));
        $this->assertSame(1, $link->executeQueryCalls);
        $this->assertSame(0, $link->multiQueryCalls);
        $this->assertSame($sql, $link->lastSql);
    }

    public function testExecUsesPreparedStatementsForSchemaAlterBatches(): void
    {
        $mysql = new \fs_mysql();
        $link = new FakeMysqliLink([
            ['affected_rows' => 1],
            ['affected_rows' => 1],
        ]);
        $this->linkProperty->setValue(null, $link);

        $sql = 'ALTER TABLE `fs_vars` ALTER `name` DROP DEFAULT;ALTER TABLE `fs_vars` ALTER `varchar` DROP DEFAULT;';
        $this->assertTrue($mysql->exec($sql, FALSE, [], true));
        $this->assertSame(2, $link->executeQueryCalls);
        $this->assertSame(0, $link->multiQueryCalls);
        $this->assertSame(
            'ALTER TABLE `fs_vars` ALTER `varchar` DROP DEFAULT',
            $link->lastSql
        );
    }

    public function testExecAccumulatesAffectedRowsAcrossBatchStatements(): void
    {
        $mysql = new \fs_mysql();
        $this->linkProperty->setValue(null, new FakeMysqliLink([
            ['affected_rows' => 2],
            ['affected_rows' => 3],
        ]));

        $this->assertTrue($mysql->exec('UPDATE foo SET bar = 1; UPDATE foo SET baz = 2;', FALSE, [], true));
        $this->assertSame(5, $mysql->affected_rows());
    }

    public function testExecReturnsFalseWhenBatchStatementFails(): void
    {
        $mysql = new \fs_mysql();
        $this->linkProperty->setValue(null, new FakeMysqliLink(
            [
                ['affected_rows' => 2],
                ['affected_rows' => 0],
            ],
            1,
            'Syntax error near the second statement'
        ));

        $this->assertFalse($mysql->exec('UPDATE foo SET bar = 1; UPDATE foo SET baz = 2;', FALSE, [], true));
        $this->assertSame(-1, $mysql->affected_rows());
    }
}

final class FakeMysqliLink
{
    public int $affected_rows = 0;
    public int $errno = 0;
    public string $error = '';
    public int $executeQueryCalls = 0;
    public int $multiQueryCalls = 0;
    public string $lastSql = '';
    public string $lastBatchSql = '';

    private int $position = 0;

    public function __construct(
        private array $statements,
        private ?int $failingPosition = null,
        private string $failureMessage = 'prepared statement failed'
    ) {
    }

    public function prepare($sql): object|false
    {
        return new FakeMysqliStmt($this, (string) $sql);
    }

    public function execute_query($sql, $params): bool
    {
        $this->executeQueryCalls++;
        $this->lastSql = (string) $sql;

        if ($this->failingPosition !== null && $this->position === $this->failingPosition) {
            $this->errno = 1064;
            $this->error = $this->failureMessage;
            $this->affected_rows = -1;

            return false;
        }

        if ($this->position >= count($this->statements)) {
            $this->errno = 1064;
            $this->error = $this->failureMessage;
            $this->affected_rows = -1;

            return false;
        }

        $this->errno = 0;
        $this->error = '';
        $this->affected_rows = (int) $this->statements[$this->position]['affected_rows'];
        $this->position++;

        return true;
    }

    public function multi_query($sql): bool
    {
        $this->multiQueryCalls++;
        $this->lastBatchSql = (string) $sql;

        return false;
    }
}

final class FakeMysqliStmt
{
    public int $affected_rows = 0;

    public function __construct(
        private FakeMysqliLink $link,
        private string $sql
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $executed = $this->link->execute_query($this->sql, $params ?? []);
        $this->affected_rows = $this->link->affected_rows;

        return $executed;
    }

    public function get_result(): bool
    {
        return false;
    }

    public function close(): void
    {
    }
}
