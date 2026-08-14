<?php

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Database\LegacySqlExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacySqlExecutor::class)]
class LegacySqlExecutorTest extends TestCase
{
    #[Test]
    public function executeMysqlWriteUpdatesNullAffectedRowsByReferenceOnSuccess(): void
    {
        $link = new LegacySqlExecutorFakeLink([
            ['affected_rows' => 4],
        ]);
        $affectedRows = null;

        $this->assertTrue(LegacySqlExecutor::executeMysqlWrite($link, 'UPDATE foo SET bar = 1', [], $affectedRows));
        $this->assertSame(4, $affectedRows);
    }

    #[Test]
    public function executeMysqlWriteUpdatesNullAffectedRowsByReferenceWhenPrepareFails(): void
    {
        $link = new LegacySqlExecutorFakeLink([], prepareFails: true);
        $affectedRows = null;

        $this->assertFalse(LegacySqlExecutor::executeMysqlWrite($link, 'UPDATE foo SET bar = 1', [], $affectedRows));
        $this->assertSame(-1, $affectedRows);
    }

    #[Test]
    public function executeMysqlWriteUpdatesNullAffectedRowsByReferenceWhenExecuteFails(): void
    {
        $link = new LegacySqlExecutorFakeLink(
            [['affected_rows' => 0]],
            failingPosition: 0
        );
        $affectedRows = null;

        $this->assertFalse(LegacySqlExecutor::executeMysqlWrite($link, 'UPDATE foo SET bar = 1', [], $affectedRows));
        $this->assertSame(-1, $affectedRows);
    }

    #[Test]
    public function executeMysqlWriteDoesNotTouchAffectedRowsWhenArgumentOmitted(): void
    {
        $link = new LegacySqlExecutorFakeLink([
            ['affected_rows' => 2],
        ]);

        $this->assertTrue(LegacySqlExecutor::executeMysqlWrite($link, 'UPDATE foo SET bar = 1'));
    }
}

final class LegacySqlExecutorFakeLink
{
    public int $affected_rows = 0;
    public int $errno = 0;
    public string $error = '';

    private int $position = 0;

    public function __construct(
        private array $statements,
        private bool $prepareFails = false,
        private ?int $failingPosition = null,
        private string $failureMessage = 'prepared statement failed'
    ) {
    }

    public function prepare($sql): object|false
    {
        if ($this->prepareFails) {
            return false;
        }

        return new LegacySqlExecutorFakeStmt($this, (string) $sql);
    }

    public function execute_query(string $sql, array $params): bool
    {
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
}

final class LegacySqlExecutorFakeStmt
{
    public int $affected_rows = 0;

    public function __construct(
        private LegacySqlExecutorFakeLink $link,
        private string $sql
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $executed = $this->link->execute_query($this->sql, $params ?? []);
        $this->affected_rows = $this->link->affected_rows;

        return $executed;
    }

    public function close(): void
    {
    }
}
