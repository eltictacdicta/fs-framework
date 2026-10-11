<?php
/**
 * This file is part of FSFramework
 */

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Database\SchemaComparator;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_schema.php';

/**
 * Cobertura de la declaración opcional <charset>/<collation> por tabla en el XML:
 * su parseo, su emisión en el CREATE (fs_schema y SchemaComparator) y su efecto
 * sobre la FK local en el CREATE (comparación real, no "desconocido").
 */
final class TableCharsetSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        $this->injectFsSchemaDb(null);
        $this->resetFsModelStaticState();
    }

    protected function tearDown(): void
    {
        $this->injectFsSchemaDb(null);
        $this->resetFsModelStaticState();
    }

    public function testFsSchemaCreateTableHonorsDeclaredCharsetAndCollation(): void
    {
        $db = $this->makeFakeDb();
        $this->injectFsSchemaDb($db);

        $xml = $this->xmlFromString($this->childXml(
            '<charset>utf8mb3</charset><collation>utf8mb3_bin</collation>',
            'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)'
        ));

        $this->assertTrue(\fs_schema::createTable('child_table', $xml));

        $sql = $db->executed[0] ?? '';
        $this->assertIsString($sql);
        $this->assertStringContainsString(
            'ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin',
            $sql
        );
    }

    public function testFsSchemaCreateTableLeavesDatabaseDefaultWhenNothingDeclared(): void
    {
        $db = $this->makeFakeDb(dbCharset: 'utf8mb4', dbCollation: 'utf8mb4_general_ci');
        $this->injectFsSchemaDb($db);

        $xml = $this->xmlFromString($this->childXml('', ''));

        $this->assertTrue(\fs_schema::createTable('child_table', $xml));

        $sql = $db->executed[0] ?? '';
        $this->assertStringContainsString(
            'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            $sql
        );
    }

    public function testFsSchemaCreateTableIgnoresInvalidDeclaredCharset(): void
    {
        $db = $this->makeFakeDb(dbCharset: 'utf8mb4', dbCollation: 'utf8mb4_general_ci');
        $this->injectFsSchemaDb($db);

        $xml = $this->xmlFromString($this->childXml(
            '<charset>utf8mb3; DROP TABLE x</charset><collation>utf8mb3_bin</collation>',
            ''
        ));

        $this->assertTrue(\fs_schema::createTable('child_table', $xml));

        $sql = $db->executed[0] ?? '';
        $this->assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $sql);
        $this->assertStringNotContainsString('DROP TABLE', $sql);
    }

    public function testFsSchemaCreateTableDropsFkWhenDeclaredCharsetDiffersFromReferenced(): void
    {
        $db = $this->makeFakeDb(
            refColumns: [
                'parent_table' => [
                    'id' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'type' => 'varchar(32)'],
                ],
            ],
            tables: ['parent_table']
        );
        $this->injectFsSchemaDb($db);

        $xml = $this->xmlFromString($this->childXml(
            '<charset>utf8mb3</charset><collation>utf8mb3_bin</collation>',
            'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)'
        ));

        $this->assertTrue(\fs_schema::createTable('child_table', $xml));

        $sql = $db->executed[0] ?? '';
        $this->assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin', $sql);
        $this->assertStringNotContainsString('FOREIGN KEY', $sql);
    }

    public function testFsSchemaCreateTableKeepsFkWhenDeclaredCharsetMatchesReferenced(): void
    {
        $db = $this->makeFakeDb(
            refColumns: [
                'parent_table' => [
                    'id' => ['charset' => 'utf8mb3', 'collation' => 'utf8mb3_bin', 'type' => 'varchar(32)'],
                ],
            ],
            tables: ['parent_table']
        );
        $this->injectFsSchemaDb($db);

        $xml = $this->xmlFromString($this->childXml(
            '<charset>utf8mb3</charset><collation>utf8mb3_bin</collation>',
            'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)'
        ));

        $this->assertTrue(\fs_schema::createTable('child_table', $xml));

        $sql = $db->executed[0] ?? '';
        $this->assertStringContainsString('FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)', $sql);
    }

    public function testGenerateSqlHonorsDeclaredCharset(): void
    {
        $db = $this->makeFakeDb();
        $this->injectFsSchemaDb($db);

        $file = tempnam(sys_get_temp_dir(), 'fsx');
        $xmlFile = $file . '.xml';
        rename($file, $xmlFile);
        file_put_contents($xmlFile, $this->childXml(
            '<charset>utf8mb3</charset><collation>utf8mb3_bin</collation>',
            ''
        ));

        try {
            $sql = \fs_schema::generateSql($xmlFile, false);
        } finally {
            @unlink($xmlFile);
        }

        $this->assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin', $sql);
    }

    public function testSchemaComparatorGenerateTableHonorsDeclaredCharsetAndKeepsMatchingFk(): void
    {
        $comparator = new SchemaComparator($this->makeFakeDb(
            refColumns: [
                'parent_table' => [
                    'id' => ['charset' => 'utf8mb3', 'collation' => 'utf8mb3_bin', 'type' => 'varchar(32)'],
                ],
            ],
            tables: ['parent_table']
        ));

        $sql = $comparator->generateTable(
            'child_table',
            $this->childColumns(),
            [['nombre' => 'child_parent_fk', 'consulta' => 'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)']],
            ['charset' => 'utf8mb3', 'collation' => 'utf8mb3_bin']
        );

        $this->assertStringContainsString('DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin', $sql);
        $this->assertStringContainsString('FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)', $sql);
    }

    public function testSchemaComparatorGenerateTableDropsFkWhenDeclaredCharsetDiffers(): void
    {
        $comparator = new SchemaComparator($this->makeFakeDb(
            refColumns: [
                'parent_table' => [
                    'id' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'type' => 'varchar(32)'],
                ],
            ],
            tables: ['parent_table']
        ));

        $sql = $comparator->generateTable(
            'child_table',
            $this->childColumns(),
            [['nombre' => 'child_parent_fk', 'consulta' => 'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)']],
            ['charset' => 'utf8mb3', 'collation' => 'utf8mb3_bin']
        );

        $this->assertStringContainsString('DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin', $sql);
        $this->assertStringNotContainsString('FOREIGN KEY', $sql);
    }

    public function testLazyCheckTableThreadsDeclaredCharsetIntoGenerateTable(): void
    {
        $fakeDb = new class() {
            /** @var list<array<int, mixed>> */
            public array $generated = [];

            public function table_exists(string $table, $list = false): bool
            {
                return false;
            }

            public function generate_table(string $table, array $cols, array $cons, array $options = []): string
            {
                $this->generated[] = [$table, $options];

                return 'CREATE TABLE `' . $table . '` ( id INT );';
            }

            public function exec(string $sql, $transaction = true, array $params = [], $batch = false)
            {
                return true;
            }

            public function select(string $sql): array
            {
                return [];
            }

            public function get_error_msg(): string
            {
                return '';
            }
        };

        $model = new class() extends \fs_model {
            public function __construct()
            {
            }

            public function delete()
            {
                return false;
            }

            public function exists()
            {
                return false;
            }

            public function save()
            {
                return false;
            }
        };

        $ref = new \ReflectionClass(\fs_model::class);

        // Fixture self-contained: fs_model resuelve el XML relativo a FS_FOLDER,
        // así que escribimos un XML temporal en lugar de depender de una tabla
        // core que declare charset (evita acoplar el test a un modelo concreto).
        $fixtureDir = 'tmp/table_charset_fixture';
        $fixtureXml = FS_FOLDER . '/' . $fixtureDir . '/model/table/fs_groups_users.xml';
        if (!is_dir(dirname($fixtureXml))) {
            mkdir(dirname($fixtureXml), 0777, true);
        }
        file_put_contents(
            $fixtureXml,
            '<tabla><charset>utf8mb3</charset><collation>utf8mb3_bin</collation>'
            . '<columna><nombre>id</nombre><tipo>serial</tipo><nulo>NO</nulo></columna>'
            . '<restriccion><nombre>fs_groups_users_pkey</nombre><consulta>PRIMARY KEY (id)</consulta></restriccion>'
            . '</tabla>'
        );

        try {
            $this->setProperty($ref, 'base_dir', null, ['fs_groups_users' => $fixtureDir . '/']);
            $this->setProperty($ref, 'table_name', $model, 'fs_groups_users');
            $this->setProperty($ref, 'db', $model, $fakeDb);

            $method = $ref->getMethod('check_table');
            $method->setAccessible(true);
            $method->invoke($model, 'fs_groups_users');

            $this->assertCount(1, $fakeDb->generated);
            [$table, $options] = $fakeDb->generated[0];
            $this->assertSame('fs_groups_users', $table);
            $this->assertSame('utf8mb3', $options['charset'] ?? null);
            $this->assertSame('utf8mb3_bin', $options['collation'] ?? null);
        } finally {
            @unlink($fixtureXml);
        }
    }

    private function setProperty(\ReflectionClass $ref, string $name, ?object $instance, $value): void
    {
        $property = $ref->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }

    private function resetFsModelStaticState(): void
    {
        $ref = new \ReflectionClass(\fs_model::class);
        $this->setProperty($ref, 'base_dir', null, null);
        $checked = $ref->getProperty('checked_tables');
        $checked->setAccessible(true);
        $checked->setValue(null, null);
    }

    private function childXml(string $tableElements, string $foreignKey): string
    {
        $constraint = $foreignKey !== ''
            ? '<restriccion><nombre>child_parent_fk</nombre><consulta>' . $foreignKey . '</consulta></restriccion>'
            : '';

        return '<tabla>' . $tableElements
            . '<columna><nombre>id</nombre><tipo>serial</tipo><nulo>NO</nulo></columna>'
            . '<columna><nombre>parent_id</nombre><tipo>character varying(32)</tipo><nulo>NO</nulo></columna>'
            . '<restriccion><nombre>child_pk</nombre><consulta>PRIMARY KEY (id)</consulta></restriccion>'
            . $constraint
            . '</tabla>';
    }

    /**
     * @return list<array{nombre: string, tipo: string, nulo: string, defecto: null}>
     */
    private function childColumns(): array
    {
        return [
            ['nombre' => 'id', 'tipo' => 'serial', 'nulo' => 'NO', 'defecto' => null],
            ['nombre' => 'parent_id', 'tipo' => 'character varying(32)', 'nulo' => 'NO', 'defecto' => null],
        ];
    }

    private function injectFsSchemaDb($db): void
    {
        $ref = new \ReflectionClass(\fs_schema::class);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue(null, $db);
    }

    private function xmlFromString(string $xml): \SimpleXMLElement
    {
        $element = simplexml_load_string($xml);
        if ($element === false) {
            throw new \RuntimeException('XML inválido en el test');
        }

        return $element;
    }

    /**
     * @param array<string, array<string, array{charset: string, collation: string, type: string}>> $refColumns
     * @param list<string> $tables
     */
    private function makeFakeDb(
        array $refColumns = [],
        array $tables = [],
        string $dbCharset = 'utf8mb4',
        string $dbCollation = 'utf8mb4_unicode_ci'
    ): object {
        return new class($refColumns, $tables, $dbCharset, $dbCollation) {
            /** @var list<string> */
            public array $executed = [];

            /**
             * @param array<string, array<string, array{charset: string, collation: string, type: string}>> $refColumns
             * @param list<string> $tables
             */
            public function __construct(
                private array $refColumns,
                private array $tables,
                private string $dbCharset,
                private string $dbCollation,
            ) {
            }

            public function select(string $sql): array
            {
                if (strpos($sql, '@@character_set_database') !== false) {
                    return [['db_charset' => $this->dbCharset, 'db_collation' => $this->dbCollation]];
                }

                if (preg_match("/table_name\s*=\s*'(.*?)'\s+AND\s+column_name\s*=\s*'(.*?)'/i", $sql, $m)) {
                    $rows = $this->refColumns[$m[1]] ?? [];
                    if (!isset($rows[$m[2]])) {
                        return [];
                    }

                    return [[
                        'charset_name' => $rows[$m[2]]['charset'],
                        'collation_name' => $rows[$m[2]]['collation'],
                        'column_type' => $rows[$m[2]]['type'],
                    ]];
                }

                return [];
            }

            public function list_tables(): array
            {
                return array_map(static fn(string $t): array => ['name' => $t], $this->tables);
            }

            public function exec(string $sql, $transaction = true, array $params = [], $batch = false)
            {
                $this->executed[] = $sql;

                return true;
            }

            public function table_exists(string $table, $list = false): bool
            {
                return in_array($table, $this->tables, true);
            }

            public function escape_string(string $str): string
            {
                return addslashes($str);
            }
        };
    }
}
