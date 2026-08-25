<?php
/**
 * This file is part of FSFramework
 */

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_schema.php';

final class FsSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        $this->injectDb(null);
    }

    protected function tearDown(): void
    {
        $this->injectDb(null);
    }

    public function testCreateTableOmitsFkOnCollationMismatch(): void
    {
        $db = $this->fakeDb([
            'utf8mb4' => 'utf8mb4_general_ci',
            'parent_table' => [
                'id' => ['charset' => 'utf8mb3', 'collation' => 'utf8mb3_general_ci', 'type' => 'varchar(32)'],
            ],
        ], ['parent_table' => true]);
        $this->injectDb($db);

        $xml = $this->xmlFromString(<<<'XML'
<tabla>
    <columna>
        <nombre>id</nombre>
        <tipo>serial</tipo>
        <nulo>NO</nulo>
    </columna>
    <columna>
        <nombre>parent_id</nombre>
        <tipo>character varying(32)</tipo>
        <nulo>NO</nulo>
    </columna>
    <restriccion>
        <nombre>child_pk</nombre>
        <consulta>PRIMARY KEY (id)</consulta>
    </restriccion>
    <restriccion>
        <nombre>child_parent_fk</nombre>
        <consulta>FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)</consulta>
    </restriccion>
</tabla>
XML);

        $result = \fs_schema::createTable('child_table', $xml);

        $this->assertTrue($result);
        $lastSql = end($db->executed);
        $this->assertIsString($lastSql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `child_table`', $lastSql);
        $this->assertStringNotContainsString('FOREIGN KEY', $lastSql);
    }

    public function testCreateTableKeepsFkOnCollationMatch(): void
    {
        $db = $this->fakeDb([
            'utf8mb4' => 'utf8mb4_general_ci',
            'parent_table' => [
                'id' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'type' => 'varchar(32)'],
            ],
        ], ['parent_table' => true]);
        $this->injectDb($db);

        $xml = $this->xmlFromString(<<<'XML'
<tabla>
    <columna>
        <nombre>id</nombre>
        <tipo>serial</tipo>
        <nulo>NO</nulo>
    </columna>
    <columna>
        <nombre>parent_id</nombre>
        <tipo>character varying(32)</tipo>
        <nulo>NO</nulo>
    </columna>
    <restriccion>
        <nombre>child_pk</nombre>
        <consulta>PRIMARY KEY (id)</consulta>
    </restriccion>
    <restriccion>
        <nombre>child_parent_fk</nombre>
        <consulta>FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)</consulta>
    </restriccion>
</tabla>
XML);

        $result = \fs_schema::createTable('child_table', $xml);

        $this->assertTrue($result);
        $lastSql = end($db->executed);
        $this->assertIsString($lastSql);
        $this->assertStringContainsString('FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)', $lastSql);
    }

    private function injectDb($db): void
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
     * @param array<string, mixed> $fixtures
     * @param array<string, bool> $tables
     */
    private function fakeDb(array $fixtures, array $tables): object
    {
        return new class($fixtures, $tables) {
            public array $executed = [];

            public function __construct(
                private array $fixtures,
                private array $tables,
            ) {
            }

            public function select(string $sql): array
            {
                if (strpos($sql, '@@character_set_database') !== false) {
                    $charset = array_key_first($this->fixtures);
                    $collation = $this->fixtures[$charset];

                    return [['db_charset' => $charset, 'db_collation' => $collation]];
                }

                if (preg_match('/table_name\s*=\s*\'(.*?)\'\s+AND\s+column_name\s*=\s*\'(.*?)\'/i', $sql, $m)) {
                    $table = $m[1];
                    $column = $m[2];
                    $rows = $this->fixtures[$table] ?? [];

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

            public function exec(string $sql, $transaction = true, array $params = [], $batch = false)
            {
                $this->executed[] = $sql;

                return true;
            }

            public function table_exists(string $table, $list = false): bool
            {
                return $this->tables[$table] ?? false;
            }

            public function escape_string(string $str): string
            {
                return addslashes($str);
            }
        };
    }
}
