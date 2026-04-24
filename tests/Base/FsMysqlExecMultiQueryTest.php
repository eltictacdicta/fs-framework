<?php
/**
 * Regression tests for fs_mysql multi_query affected_rows accounting.
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

    public function testExecAccumulatesAffectedRowsAcrossAllStatements(): void
    {
        $mysql = new \fs_mysql();
        $this->linkProperty->setValue(null, new FakeMysqliLink([
            ['affected_rows' => 2],
            ['affected_rows' => 3],
        ]));

        $this->assertTrue($mysql->exec('UPDATE foo SET bar = 1; UPDATE foo SET baz = 2;', FALSE));
        $this->assertSame(5, $mysql->affected_rows());
    }

    public function testExecReturnsFalseAndKeepsAffectedRowsAtMinusOneWhenNextStatementFails(): void
    {
        $mysql = new \fs_mysql();
        $this->linkProperty->setValue(null, new FakeMysqliLink(
            [
                ['affected_rows' => 2],
                ['affected_rows' => 0],
            ],
            0,
            'Syntax error near the second statement'
        ));

        $this->assertFalse($mysql->exec('UPDATE foo SET bar = 1; BROKEN SQL;', FALSE));
        $this->assertSame(-1, $mysql->affected_rows());
    }
}

final class FakeMysqliLink
{
    public int $affected_rows = 0;
    public int $errno = 0;
    public string $error = '';

    private int $position = 0;

    public function __construct(
        private array $statements,
        private ?int $failingTransitionIndex = null,
        private string $failureMessage = 'multi_query failed'
    ) {
    }

    public function multi_query($sql): bool
    {
        if (empty($this->statements)) {
            $this->errno = 1064;
            $this->error = $this->failureMessage;
            $this->affected_rows = -1;
            return false;
        }

        $this->position = 0;
        $this->errno = 0;
        $this->error = '';
        $this->affected_rows = (int) $this->statements[0]['affected_rows'];
        return true;
    }

    public function store_result()
    {
        $result = $this->statements[$this->position]['result'] ?? false;
        if ($result === false) {
            return false;
        }

        return new FakeMysqliResult((int) $result);
    }

    public function more_results(): bool
    {
        return $this->position < count($this->statements) - 1;
    }

    public function next_result(): bool
    {
        if (!$this->more_results()) {
            return false;
        }

        if ($this->failingTransitionIndex !== null && $this->position === $this->failingTransitionIndex) {
            $this->errno = 1064;
            $this->error = $this->failureMessage;
            $this->affected_rows = -1;
            return false;
        }

        $this->position++;
        $this->affected_rows = (int) $this->statements[$this->position]['affected_rows'];
        return true;
    }
}

final class FakeMysqliResult
{
    public function __construct(public int $num_rows)
    {
    }

    public function free(): void
    {
    }
}