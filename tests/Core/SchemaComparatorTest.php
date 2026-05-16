<?php
/**
 * This file is part of FSFramework
 */

namespace Tests\Core;

use FSFramework\Database\SchemaComparator;
use PHPUnit\Framework\TestCase;

class SchemaComparatorTest extends TestCase
{
    public function testGenerateTableAddsNamedForeignKeyDespiteWhitespaceAndCaseDifferences(): void
    {
        $db = new class() {
            public array $queries = [];

            public function __call(string $name, array $arguments): array
            {
                if ($name !== 'list_tables') {
                    throw new \BadMethodCallException('Unexpected method: ' . $name);
                }

                return [['name' => 'parent_table']];
            }

            public function select(string $sql): array
            {
                $this->queries[] = $sql;

                return [[
                    'db_charset' => 'utf8mb4',
                    'db_collation' => 'utf8mb4_unicode_ci',
                ]];
            }
        };

        $comparator = new SchemaComparator($db);
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
}
