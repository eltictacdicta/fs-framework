<?php
/**
 * This file is part of FSFramework
 */

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_schema.php';

/**
 * Regression coverage for fs_schema::convertType() (SS-07 / SS-07a / SS-07b).
 *
 * The method is private static, so it is reached through ReflectionMethod.
 * tests/bootstrap.php does NOT load base/fs_schema.php, hence the require_once
 * above (mirrors tests/Core/FsSchemaTest.php).
 */
final class FsSchemaConvertTypeTest extends TestCase
{
    /** Inputs whose keys prefix one another (date/datetime, time/timestamp, character/character varying). */
    private const ORDER_PROBES = ['timestamp', 'datetime', 'character varying(6)'];

    private static ?\ReflectionMethod $convertType = null;
    private static ?\ReflectionProperty $typeMapping = null;

    /** Canonical map captured before any test mutates it. */
    private static ?array $originalTypeMapping = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$originalTypeMapping === null) {
            self::$originalTypeMapping = $this->readTypeMapping();
        }

        $this->injectDb(null);
    }

    protected function tearDown(): void
    {
        // The order-independence test inverts the private static $typeMapping.
        // Restore it unconditionally so the inverted map never leaks into any
        // other test running in the same process.
        if (self::$originalTypeMapping !== null) {
            $this->writeTypeMapping(self::$originalTypeMapping);
        }

        $this->injectDb(null);
        parent::tearDown();
    }

    #[DataProvider('provideMappedTypes')]
    public function testMapsEveryMappingKeyExactly(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->convertType($input, true));
    }

    public static function provideMappedTypes(): array
    {
        return [
            'character varying' => ['character varying', 'VARCHAR'],
            'character' => ['character', 'CHAR'],
            'text' => ['text', 'TEXT'],
            'integer' => ['integer', 'INT'],
            'smallint' => ['smallint', 'SMALLINT'],
            'bigint' => ['bigint', 'BIGINT'],
            'boolean' => ['boolean', 'TINYINT(1)'],
            'double precision' => ['double precision', 'DOUBLE'],
            'real' => ['real', 'FLOAT'],
            'numeric' => ['numeric', 'DECIMAL'],
            'date' => ['date', 'DATE'],
            'time' => ['time', 'TIME'],
            'timestamp' => ['timestamp', 'TIMESTAMP'],
            'datetime' => ['datetime', 'DATETIME'],
            'bytea' => ['bytea', 'BLOB'],
            'serial' => ['serial', 'INT AUTO_INCREMENT'],
        ];
    }

    #[DataProvider('providePrefixHazards')]
    public function testPrefixHazardsDoNotResolveToTheShorterKey(string $input, string $expected, string $mustNotBe): void
    {
        $result = $this->convertType($input, true);

        $this->assertSame($expected, $result);
        $this->assertNotSame($mustNotBe, $result);
    }

    public static function providePrefixHazards(): array
    {
        return [
            'character varying(6)' => ['character varying(6)', 'VARCHAR(6)', 'CHAR(6)'],
            'character(10)' => ['character(10)', 'CHAR(10)', 'VARCHAR(10)'],
            'timestamp' => ['timestamp', 'TIMESTAMP', 'TIME'],
            'datetime' => ['datetime', 'DATETIME', 'DATE'],
            'timestamp(6)' => ['timestamp(6)', 'TIMESTAMP(6)', 'TIME(6)'],
        ];
    }

    public function testIntegerFamilyMapsDistinctly(): void
    {
        $this->assertSame('INT', $this->convertType('integer', true));
        $this->assertSame('SMALLINT', $this->convertType('smallint', true));
        $this->assertSame('BIGINT', $this->convertType('bigint', true));
        $this->assertNotSame($this->convertType('integer', true), $this->convertType('smallint', true));
        $this->assertNotSame($this->convertType('integer', true), $this->convertType('bigint', true));
    }

    public function testPlainTemporalTypesKeepTheirMapping(): void
    {
        $this->assertSame('DATE', $this->convertType('date', true));
        $this->assertSame('TIME', $this->convertType('time', true));
        $this->assertSame('TIME', $this->convertType('time without time zone', true));
    }

    public function testTimestampVariantsAndLengthSuffix(): void
    {
        $this->assertSame('TIMESTAMP(6)', $this->convertType('timestamp(6)', true));
        $this->assertSame('TIMESTAMP', $this->convertType('timestamp without time zone', true));
        // The mapped type already carries parentheses: no length is appended.
        $this->assertSame('TINYINT(1)', $this->convertType('boolean', true));
        $this->assertSame('VARCHAR(6)', $this->convertType('character varying(6)', true));
    }

    /**
     * PostgreSQL time-zone qualifiers have no MySQL equivalent. The translated
     * type MUST be the bare temporal type so the fallback can never emit the
     * invalid MySQL DDL `TIMESTAMP WITH TIME ZONE` / `TIME WITH TIME ZONE`.
     */
    #[DataProvider('provideTimeZoneQualifiedTypes')]
    public function testTimeZoneQualifiedTypesNeverEmitInvalidMysqlDdl(string $input, string $expected): void
    {
        $result = $this->convertType($input, true);

        $this->assertSame($expected, $result);
        // Discriminating assertion: valid MySQL temporal types never carry the
        // PostgreSQL qualifier, so its presence proves the DDL would be invalid.
        $this->assertStringNotContainsString('TIME ZONE', $result);
    }

    public static function provideTimeZoneQualifiedTypes(): array
    {
        return [
            'timestamp with time zone' => ['timestamp with time zone', 'TIMESTAMP'],
            'timestamp(6) with time zone' => ['timestamp(6) with time zone', 'TIMESTAMP(6)'],
            'time with time zone' => ['time with time zone', 'TIME'],
            'timestamp(6) without time zone' => ['timestamp(6) without time zone', 'TIMESTAMP(6)'],
        ];
    }

    public function testRemainingMappedTypesKeepTheirMapping(): void
    {
        $this->assertSame('DOUBLE', $this->convertType('double precision', true));
        $this->assertSame('DECIMAL(12,2)', $this->convertType('numeric(12,2)', true));
        $this->assertSame('TINYINT(1)', $this->convertType('boolean', true));
        $this->assertSame('INT AUTO_INCREMENT', $this->convertType('serial', true));
        $this->assertSame('TEXT', $this->convertType('text', true));
        $this->assertSame('BLOB', $this->convertType('bytea', true));
        $this->assertSame('FLOAT', $this->convertType('real', true));
    }

    #[DataProvider('providePostgresPassthrough')]
    public function testPostgresPassthroughReturnsInputUnchanged(string $input): void
    {
        $this->assertSame($input, $this->convertType($input, false));
    }

    public static function providePostgresPassthrough(): array
    {
        return [
            'timestamp' => ['timestamp'],
            'datetime' => ['datetime'],
        ];
    }

    public function testMappingIsIndependentOfDeclarationOrder(): void
    {
        $normalResults = [];
        foreach (self::ORDER_PROBES as $probe) {
            $normalResults[$probe] = $this->convertType($probe, true);
        }

        // Prefix matching converts a probe to the mapping of whichever key is
        // merely a prefix of it and happens to come first. Reversing the map
        // changes which key wins, so the buggy implementation yields different
        // results per order (timestamp->TIME|TIMESTAMP, datetime->DATE|DATETIME,
        // character varying(6)->VARCHAR(6)|CHAR(6)). Exact match makes the
        // result a pure function of the normalized base type, identical under
        // any declaration order.
        $this->writeTypeMapping(array_reverse($this->readTypeMapping(), true));

        $reversedResults = [];
        foreach (self::ORDER_PROBES as $probe) {
            $reversedResults[$probe] = $this->convertType($probe, true);
        }

        $this->assertSame($normalResults, $reversedResults);
        // Pin the actual values so this test also fails if the fix is reverted.
        $this->assertSame(
            [
                'timestamp' => 'TIMESTAMP',
                'datetime' => 'DATETIME',
                'character varying(6)' => 'VARCHAR(6)',
            ],
            $normalResults
        );
    }

    public function testCreateTableEmitsTimestampForTimestampColumn(): void
    {
        $db = $this->fakeDb(['utf8mb4' => 'utf8mb4_general_ci'], []);
        $this->injectDb($db);

        $xml = $this->xmlFromString(<<<'XML'
<tabla>
    <columna>
        <nombre>id</nombre>
        <tipo>serial</tipo>
        <nulo>NO</nulo>
    </columna>
    <columna>
        <nombre>created_at</nombre>
        <tipo>timestamp</tipo>
    </columna>
</tabla>
XML);

        $this->assertTrue(\fs_schema::createTable('probe', $xml));

        $sql = end($db->executed);
        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `probe`', $sql);
        $this->assertStringContainsString('`created_at` TIMESTAMP', $sql);
        // TIME is a substring of TIMESTAMP, so the negative check must exclude
        // TIMESTAMP explicitly instead of searching for a bare 'TIME'.
        $this->assertSame(0, preg_match('/`created_at`\s+TIME(?!STAMP)/', $sql));
    }

    private function convertType(string $type, bool $isMySQL = true): string
    {
        if (self::$convertType === null) {
            $method = new \ReflectionMethod(\fs_schema::class, 'convertType');
            $method->setAccessible(true);
            self::$convertType = $method;
        }

        return self::$convertType->invoke(null, $type, $isMySQL);
    }

    private function readTypeMapping(): array
    {
        return $this->typeMappingProperty()->getValue();
    }

    private function writeTypeMapping(array $map): void
    {
        $this->typeMappingProperty()->setValue(null, $map);
    }

    private function typeMappingProperty(): \ReflectionProperty
    {
        if (self::$typeMapping === null) {
            $property = new \ReflectionProperty(\fs_schema::class, 'typeMapping');
            $property->setAccessible(true);
            self::$typeMapping = $property;
        }

        return self::$typeMapping;
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
            throw new \RuntimeException('Invalid XML in test fixture');
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
