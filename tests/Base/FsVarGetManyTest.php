<?php
/**
 * Tests para fs_var::get_many(): resolución de varias claves en una sola consulta.
 *
 * Usa un mock de fs_db2 (sin conexión real) inyectado en una subclase anónima
 * con constructor vacío, siguiendo el patrón de tests/Base/FsModelMethodsTest.php.
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsVarGetManyTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/model/fs_var.php';
    }

    private function makeDb(array $rows): object
    {
        return new class($rows) {
            /** @var string[] */
            public array $queries = [];

            public function __construct(private array $rows)
            {
            }

            public function var2str($val): string
            {
                return "'" . addslashes((string) $val) . "'";
            }

            public function select(string $sql): array
            {
                $this->queries[] = $sql;

                return $this->rows;
            }
        };
    }

    private function makeVar(object $db): \fs_var
    {
        return new class($db) extends \fs_var {
            public function __construct(private object $mockDb)
            {
                $this->db = $this->mockDb;
                $this->table_name = 'fs_vars';
            }
        };
    }

    public function testGetManyReturnsOnlyRequestedKeysInOneQuery(): void
    {
        $db = $this->makeDb([
            ['name' => 'mail_host', 'varchar' => 'smtp.example.com'],
            ['name' => 'mail_port', 'varchar' => '587'],
        ]);
        $var = $this->makeVar($db);

        $result = $var->get_many(['mail_host', 'mail_port', 'mail_user']);

        $this->assertSame('smtp.example.com', $result['mail_host']);
        $this->assertSame('587', $result['mail_port']);
        $this->assertArrayNotHasKey('mail_user', $result);
        $this->assertCount(1, $db->queries);
        $this->assertStringContainsString(
            "WHERE name IN ('mail_host','mail_port','mail_user')",
            $db->queries[0]
        );
    }

    public function testGetManyReturnsEmptyArrayWithoutQueryWhenNoNames(): void
    {
        $db = $this->makeDb([]);
        $var = $this->makeVar($db);

        $this->assertSame([], $var->get_many([]));
        $this->assertCount(0, $db->queries);
    }

    public function testGetManyLeavesPlainValuesUntouchedWhenDecryptRequested(): void
    {
        $db = $this->makeDb([
            ['name' => 'mail_host', 'varchar' => 'smtp.example.com'],
        ]);
        $var = $this->makeVar($db);

        $result = $var->get_many(['mail_host'], true);

        $this->assertSame('smtp.example.com', $result['mail_host']);
    }

    public function testGetManyReturnsFalseForEncryptedValueWithoutUsableService(): void
    {
        $db = $this->makeDb([
            ['name' => 'mail_password', 'varchar' => 'v1:ciphertext'],
        ]);
        $var = $this->makeVar($db);

        $result = $var->get_many(['mail_password'], true);

        $this->assertArrayHasKey('mail_password', $result);
        $this->assertFalse($result['mail_password']);
    }
}
