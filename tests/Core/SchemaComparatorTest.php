<?php
/**
 * This file is part of FSFramework
 */

namespace Tests\Core;

use FSFramework\Database\SchemaComparator;
use PHPUnit\Framework\TestCase;

class SchemaComparatorTest extends TestCase
{
    public function testGenerateTableUsesAutoIncrementForSerialSequenceColumns(): void
    {
        $comparator = new SchemaComparator($this->createSchemaDb(['fs_logs']));
        $sql = $comparator->generateTable(
            'fs_logs',
            [
                ['nombre' => 'id', 'tipo' => 'serial', 'nulo' => 'NO', 'defecto' => "nextval('fs_logs_id_seq'::regclass)"],
                ['nombre' => 'detalle', 'tipo' => 'text', 'nulo' => 'NO', 'defecto' => null],
            ],
            [
                ['nombre' => 'fs_logs_pkey', 'consulta' => 'PRIMARY KEY (id)'],
            ]
        );

        $this->assertStringContainsString('`id` ' . FS_DB_INTEGER . ' NOT NULL AUTO_INCREMENT', $sql);
        $this->assertStringNotContainsString('DEFAULT 0', $sql);
        $this->assertStringNotContainsString("nextval('fs_logs_id_seq'::regclass)", $sql);
    }

    public function testCompareColumnsUsesAutoIncrementForSerialSequenceDefaults(): void
    {
        $comparator = new SchemaComparator($this->createSchemaDb());
        $sql = $comparator->compareColumns(
            'fs_logs',
            [
                ['nombre' => 'id', 'tipo' => 'serial', 'nulo' => 'NO', 'defecto' => "nextval('fs_logs_id_seq'::regclass)"],
            ],
            [
                ['name' => 'id', 'type' => FS_DB_INTEGER, 'default' => null, 'is_nullable' => 'NO', 'extra' => ''],
            ]
        );

        $this->assertSame(
            'ALTER TABLE `fs_logs` MODIFY `id` ' . FS_DB_INTEGER . ' NOT NULL AUTO_INCREMENT;',
            $sql
        );
    }

    public function testNormalizeXmlConstraintSignatureHandlesForeignKeyWithoutTail(): void
    {
        $comparator = new SchemaComparator(new class() {
        });

        $method = new \ReflectionMethod(SchemaComparator::class, 'normalizeXmlConstraintSignature');
        $method->setAccessible(true);

        $signature = $method->invoke($comparator, 'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)');

        $this->assertSame('FOREIGN KEY|parent_id|parent_table|id|RESTRICT|RESTRICT', $signature);
    }

    public function testGenerateTableAddsNamedForeignKeyDespiteWhitespaceAndCaseDifferences(): void
    {
        $comparator = new SchemaComparator($this->createSchemaDb(['parent_table']));
        $sql = $comparator->generateTable(
            'child_table',
            [
                ['nombre' => 'id', 'tipo' => 'serial', 'nulo' => 'NO', 'defecto' => null],
                ['nombre' => 'parent_id', 'tipo' => 'INT(11)', 'nulo' => 'NO', 'defecto' => null],
            ],
            [
                ['nombre' => 'child_pk', 'consulta' => ' primary key (`id`)'],
                ['nombre' => 'child_parent_fk', 'consulta' => '  foreign key (`parent_id`) references `parent_table` (`id`) ON DELETE cascade'],
            ]
        );

        $this->assertStringContainsString('PRIMARY KEY (`ID`)', strtoupper($sql));
        $this->assertStringContainsString('CONSTRAINT `child_parent_fk` foreign key (`parent_id`) references `parent_table` (`id`) ON DELETE cascade', $sql);
    }

    private function createSchemaDb(array $tables = []): object
    {
        return new class($tables) {
            public array $queries = [];

            public function __construct(private array $tables)
            {
            }

            public function __call(string $name, array $arguments): array
            {
                if ($name !== 'list_tables') {
                    throw new \BadMethodCallException('Unexpected method: ' . $name);
                }

                return array_map(static fn(string $table): array => ['name' => $table], $this->tables);
            }

            public function select(string $sql): array
            {
                $this->queries[] = $sql;

                if (strpos($sql, '@@character_set_database') !== false) {
                    return [[
                        'db_charset' => 'utf8mb4',
                        'db_collation' => 'utf8mb4_unicode_ci',
                    ]];
                }

                return [];
            }
        };
    }
}
