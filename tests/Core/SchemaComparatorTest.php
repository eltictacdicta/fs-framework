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

    public function testExtractReferencedTableNameHandlesSchemaQualifiedReferences(): void
    {
        $comparator = new SchemaComparator(new class() {
        });
        $method = new \ReflectionMethod(SchemaComparator::class, 'extractReferencedTableName');
        $method->setAccessible(true);

        $this->assertSame(
            'parent',
            $method->invoke($comparator, 'FOREIGN KEY (`child_id`) REFERENCES public.parent (`id`)')
        );
        $this->assertSame(
            'parent',
            $method->invoke($comparator, 'FOREIGN KEY (`child_id`) REFERENCES "public"."parent" (`id`)')
        );
    }

    public function testGenerateTableOmitsFkOnCollationMismatch(): void
    {
        $comparator = new SchemaComparator($this->createSchemaDb(
            ['parent_table'],
            [
                'parent_table' => [
                    // collation distinta a la de la tabla local (utf8mb4_general_ci)
                    'id' => ['charset' => 'utf8mb3', 'collation' => 'utf8mb3_general_ci', 'type' => 'varchar(32)'],
                ],
            ]
        ));
        $sql = $comparator->generateTable(
            'child_table',
            [
                ['nombre' => 'id', 'tipo' => 'serial', 'nulo' => 'NO', 'defecto' => null],
                ['nombre' => 'parent_id', 'tipo' => 'character varying(32)', 'nulo' => 'NO', 'defecto' => null],
            ],
            [
                ['nombre' => 'child_pk', 'consulta' => 'PRIMARY KEY (id)'],
                ['nombre' => 'child_parent_fk', 'consulta' => 'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)'],
            ]
        );

        $this->assertStringContainsString('PRIMARY KEY (id)', $sql);
        $this->assertStringNotContainsString('FOREIGN KEY', $sql);
    }

    public function testGenerateTableKeepsFkOnCollationMatch(): void
    {
        $comparator = new SchemaComparator($this->createSchemaDb(
            ['parent_table'],
            [
                'parent_table' => [
                    // collation que coincide con la de la tabla local
                    'id' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'type' => 'varchar(32)'],
                ],
            ]
        ));
        $sql = $comparator->generateTable(
            'child_table',
            [
                ['nombre' => 'id', 'tipo' => 'serial', 'nulo' => 'NO', 'defecto' => null],
                ['nombre' => 'parent_id', 'tipo' => 'character varying(32)', 'nulo' => 'NO', 'defecto' => null],
            ],
            [
                ['nombre' => 'child_pk', 'consulta' => 'PRIMARY KEY (id)'],
                ['nombre' => 'child_parent_fk', 'consulta' => 'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)'],
            ]
        );

        $this->assertStringContainsString('FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)', $sql);
    }

    public function testGenerateTableOmitsFkWhenReferencedTableMissing(): void
    {
        // La tabla referenciada no está en la lista de tablas existentes: la FK
        // debe omitirse para no fallar el CREATE con errno 150 (regresión: antes
        // de la corrección se devolvía true y se emitía la FK a tabla inexistente).
        $comparator = new SchemaComparator($this->createSchemaDb(['other_table']));
        $sql = $comparator->generateTable(
            'child_table',
            [
                ['nombre' => 'id', 'tipo' => 'serial', 'nulo' => 'NO', 'defecto' => null],
                ['nombre' => 'parent_id', 'tipo' => 'character varying(32)', 'nulo' => 'NO', 'defecto' => null],
            ],
            [
                ['nombre' => 'child_pk', 'consulta' => 'PRIMARY KEY (id)'],
                ['nombre' => 'child_parent_fk', 'consulta' => 'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)'],
            ]
        );

        $this->assertStringContainsString('PRIMARY KEY (id)', $sql);
        $this->assertStringNotContainsString('FOREIGN KEY', $sql);
    }

    public function testCompareConstraintsReaddsOmittedFk(): void
    {
        // Evidencia de SS-04: una FK omitida en el CREATE (tabla padre ausente o
        // collation incompatible) se reintenta vía compare_constraints cuando la
        // tabla referenciada existe y los metadatos son compatibles.
        $comparator = new SchemaComparator($this->createSchemaDb(['parent_table']));
        $sql = $comparator->compareConstraints(
            'child_table',
            [
                ['nombre' => 'child_parent_fk', 'consulta' => 'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)'],
            ],
            []
        );

        $this->assertStringContainsString('ALTER TABLE `child_table` ADD', $sql);
        $this->assertStringContainsString('FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)', $sql);
    }

    public function testCompareConstraintsOmitsFkAddOnCollationMismatch(): void
    {
        // Completa la defensividad de SS-04: el ADD CONSTRAINT FOREIGN KEY también
        // debe omitirse cuando la columna local y la referenciada no comparten
        // collation (padre utf8mb3 vs local utf8mb4). Sin este guard, cada
        // check_table/sync reintentaba el ALTER y fallaba con errno 150.
        $comparator = new SchemaComparator($this->createSchemaDb(
            ['child_table', 'parent_table'],
            [
                'child_table' => [
                    'parent_id' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'type' => 'varchar(32)'],
                ],
                'parent_table' => [
                    'id' => ['charset' => 'utf8mb3', 'collation' => 'utf8mb3_general_ci', 'type' => 'varchar(32)'],
                ],
            ]
        ));
        $sql = $comparator->compareConstraints(
            'child_table',
            [
                ['nombre' => 'child_parent_fk', 'consulta' => 'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)'],
            ],
            []
        );

        $this->assertSame('', $sql);
    }

    public function testCompareConstraintsKeepsFkAddOnMatch(): void
    {
        $comparator = new SchemaComparator($this->createSchemaDb(
            ['child_table', 'parent_table'],
            [
                'child_table' => [
                    'parent_id' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'type' => 'varchar(32)'],
                ],
                'parent_table' => [
                    'id' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'type' => 'varchar(32)'],
                ],
            ]
        ));
        $sql = $comparator->compareConstraints(
            'child_table',
            [
                ['nombre' => 'child_parent_fk', 'consulta' => 'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)'],
            ],
            []
        );

        $this->assertStringContainsString('ALTER TABLE `child_table` ADD FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)', $sql);
    }

    private function createSchemaDb(array $tables = [], array $columns = []): object
    {
        return new class($tables, $columns) {
            public array $queries = [];

            public function __construct(
                private array $tables,
                private array $columns,
            ) {
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
                        'db_collation' => 'utf8mb4_general_ci',
                    ]];
                }

                if (preg_match('/table_name\s*=\s*\'(.*?)\'\s+AND\s+column_name\s*=\s*\'(.*?)\'/i', $sql, $m)) {
                    $table = $m[1];
                    $column = $m[2];
                    $rows = $this->columns[$table] ?? [];

                    if (!isset($rows[$column])) {
                        return [];
                    }

                    return [[
                        'charset_name' => $rows[$column]['charset'],
                        'collation_name' => $rows[$column]['collation'],
                        'column_type' => $rows[$column]['type'],
                    ]];
                }

                return [];
            }
        };
    }
}
