<?php
/**
 * This file is part of FSFramework
 */

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Database\FkCompatibilityValidator;
use PHPUnit\Framework\TestCase;

final class FkCompatibilityValidatorTest extends TestCase
{
    public function testParseFkPartsExtractsLocalColumnRefTableAndRefColumn(): void
    {
        $parts = FkCompatibilityValidator::parseFkParts(
            'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)'
        );

        $this->assertSame(
            ['localColumn' => 'parent_id', 'refTable' => 'parent_table', 'refColumn' => 'id'],
            $parts
        );
    }

    public function testParseFkPartsReturnsNullOnNonFk(): void
    {
        $this->assertNull(FkCompatibilityValidator::parseFkParts('PRIMARY KEY (`id`)'));
    }

    public function testCompatibleMatchReturnsTrue(): void
    {
        $validator = new FkCompatibilityValidator($this->fakeDb([
            'utf8mb4' => 'utf8mb4_general_ci',
            'parent_table' => [
                'id' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'type' => 'varchar(32)'],
            ],
        ]), true);

        $this->assertTrue($validator->isFkCompatible(
            'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)',
            ['name' => 'parent_id', 'type' => 'varchar(32)', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci']
        ));
    }

    public function testCollationMismatchReturnsFalseAndWarns(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'fs_fk_');
        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $validator = new FkCompatibilityValidator($this->fakeDb([
                'utf8mb4' => 'utf8mb4_general_ci',
                'parent_table' => [
                    // mismo charset que la local, pero collation distinta
                    'id' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'type' => 'varchar(32)'],
                ],
            ]), true);

            $result = $validator->isFkCompatible(
                'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)',
                ['name' => 'parent_id', 'type' => 'varchar(32)', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci']
            );
        } finally {
            ini_set('error_log', $previous);
        }

        $this->assertFalse($result);

        $log = @file_get_contents($logFile);
        $this->assertIsString($log);
        $this->assertStringContainsString('parent_id', $log);
        $this->assertStringContainsString('collation', $log);

        @unlink($logFile);
    }

    public function testCharsetMismatchReturnsFalse(): void
    {
        $validator = new FkCompatibilityValidator($this->fakeDb([
            'utf8mb4' => 'utf8mb4_general_ci',
            'parent_table' => [
                'id' => ['charset' => 'latin1', 'collation' => 'latin1_swedish_ci', 'type' => 'varchar(32)'],
            ],
        ]), true);

        $this->assertFalse($validator->isFkCompatible(
            'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)',
            ['name' => 'parent_id', 'type' => 'varchar(32)', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci']
        ));
    }

    public function testTypeMismatchReturnsFalse(): void
    {
        $validator = new FkCompatibilityValidator($this->fakeDb([
            'utf8mb4' => 'utf8mb4_general_ci',
            'parent_table' => [
                // charset+collation coinciden con la local; solo difiere el tipo
                'id' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'type' => 'int(11)'],
            ],
        ]), true);

        $this->assertFalse($validator->isFkCompatible(
            'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)',
            ['name' => 'parent_id', 'type' => 'varchar(32)', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci']
        ));
    }

    public function testNonCollatableIntLocalTypeReturnsTrue(): void
    {
        $validator = new FkCompatibilityValidator($this->fakeDb([
            'utf8mb4' => 'utf8mb4_general_ci',
            'parent_table' => [
                'id' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'type' => 'int(11)'],
            ],
        ]), true);

        $this->assertTrue($validator->isFkCompatible(
            'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)',
            ['name' => 'parent_id', 'type' => 'int(11)', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci']
        ));
    }

    public function testLengthMismatchReturnsFalse(): void
    {
        // column_type incluye el largo (varchar(12) vs local varchar(32)):
        // un desajuste de largo tambien debe omitir la FK (regresion del fix
        // data_type -> column_type; con data_type el largo se perdia y esto
        // devolvia true, dejando pasar un errno 150 por largo).
        $validator = new FkCompatibilityValidator($this->fakeDb([
            'utf8mb4' => 'utf8mb4_general_ci',
            'parent_table' => [
                'id' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'type' => 'varchar(12)'],
            ],
        ]), true);

        $this->assertFalse($validator->isFkCompatible(
            'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)',
            ['name' => 'parent_id', 'type' => 'varchar(32)', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci']
        ));
    }

    public function testNonMySqlReturnsTrue(): void
    {
        $validator = new FkCompatibilityValidator($this->fakeDb([
            'utf8mb4' => 'utf8mb4_general_ci',
            'parent_table' => [
                'id' => ['charset' => 'utf8mb3', 'collation' => 'utf8mb3_general_ci', 'type' => 'varchar(32)'],
            ],
        ]), false);

        $this->assertTrue($validator->isFkCompatible(
            'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)',
            ['name' => 'parent_id', 'type' => 'varchar(32)', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci']
        ));
    }

    public function testMissingMetadataReturnsTrue(): void
    {
        $validator = new FkCompatibilityValidator($this->fakeDb([
            'utf8mb4' => 'utf8mb4_general_ci',
        ]), true);

        $this->assertTrue($validator->isFkCompatible(
            'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)',
            ['name' => 'parent_id', 'type' => 'varchar(32)', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci']
        ));
    }

    public function testMissingReferencedColumnReturnsTrue(): void
    {
        $validator = new FkCompatibilityValidator($this->fakeDb([
            'utf8mb4' => 'utf8mb4_general_ci',
            'parent_table' => [
                // no 'id' row => referenced column metadata missing
                'other' => ['charset' => 'utf8mb3', 'collation' => 'utf8mb3_general_ci', 'type' => 'varchar(32)'],
            ],
        ]), true);

        $this->assertTrue($validator->isFkCompatible(
            'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)',
            ['name' => 'parent_id', 'type' => 'varchar(32)', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci']
        ));
    }

    /**
     * Fake db that answers @@ configuration and information_schema.columns queries.
     *
     * @param array<string, mixed> $fixtures [
     *     charset key => db collation, table name => [column => [charset, collation, type]]
     * ]
     */
    private function fakeDb(array $fixtures): object
    {
        return new class($fixtures) {
            public function __construct(private array $fixtures)
            {
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
        };
    }
}
