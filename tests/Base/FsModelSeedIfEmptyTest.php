<?php

declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../base/fs_model.php';

final class FsModelSeedIfEmptyTest extends TestCase
{
    #[Test]
    public function seedIfEmptyInsertsWhenTableHasNoRows(): void
    {
        $db = new class {
            public bool $tableExists = true;
            public array $selectResult = [];
            public int $execCalls = 0;
            public string $lastSql = '';

            public function table_exists(string $name): bool
            {
                return $this->tableExists;
            }

            public function select(string $sql): array
            {
                return $this->selectResult;
            }

            public function exec(string $sql, $transaction = null, array $params = [], bool $isBatch = false): bool
            {
                $this->execCalls++;
                $this->lastSql = $sql;

                return true;
            }
        };

        $model = new class($db) extends \fs_model {
            public function __construct(private object $stubDb)
            {
                $this->table_name = 'series';
                $this->db = $this->stubDb;
            }

            public function delete() { return false; }
            public function exists() { return false; }
            public function save() { return false; }

            protected function install()
            {
                return "INSERT INTO series (codserie) VALUES ('A');";
            }
        };

        $this->assertTrue($model->seed_if_empty());
        $this->assertSame(1, $db->execCalls);
        $this->assertStringContainsString('INSERT INTO series', $db->lastSql);
    }

    #[Test]
    public function seedIfEmptySkipsWhenTableAlreadyHasRows(): void
    {
        $model = new class() extends \fs_model {
            public function __construct()
            {
                $this->table_name = 'series';
                $this->db = new class {
                    public function table_exists(string $name): bool
                    {
                        return true;
                    }

                    public function select(string $sql): array
                    {
                        return [['1' => 1]];
                    }

                    public function exec(string $sql, $transaction = null, array $params = [], bool $isBatch = false): bool
                    {
                        $this->fail('exec must not run when table already has rows');
                    }
                };
            }

            public function delete() { return false; }
            public function exists() { return false; }
            public function save() { return false; }

            protected function install()
            {
                return "INSERT INTO series (codserie) VALUES ('A');";
            }
        };

        $this->assertFalse($model->seed_if_empty());
    }

    #[Test]
    public function seedIfEmptyThrowsWhenInsertFails(): void
    {
        $model = new class() extends \fs_model {
            public function __construct()
            {
                $this->table_name = 'series';
                $this->db = new class {
                    public function table_exists(string $name): bool
                    {
                        return true;
                    }

                    public function select(string $sql): array
                    {
                        return [];
                    }

                    public function exec(string $sql, $transaction = null, array $params = [], bool $isBatch = false): bool
                    {
                        return false;
                    }
                };
            }

            public function delete() { return false; }
            public function exists() { return false; }
            public function save() { return false; }

            protected function install()
            {
                return "INSERT INTO series (codserie) VALUES ('A');";
            }
        };

        $this->expectException(\RuntimeException::class);
        $model->seed_if_empty();
    }
}
