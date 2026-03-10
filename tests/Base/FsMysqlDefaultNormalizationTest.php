<?php
/**
 * Regression tests for MySQL default normalization from XML schemas.
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsMysqlDefaultNormalizationTest extends TestCase
{
    private \fs_mysql $mysql;
    private \ReflectionMethod $normalizeDefault;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_db_engine.php';
        require_once FS_FOLDER . '/base/fs_mysql.php';

        $this->mysql = new \fs_mysql();
        $this->normalizeDefault = new \ReflectionMethod(\fs_mysql::class, 'normalize_mysql_default');
        $this->normalizeDefault->setAccessible(true);
    }

    public function testQuotesStringDefaultsForVarcharColumns(): void
    {
        $this->assertSame(
            "'confidential'",
            $this->normalize('confidential', 'VARCHAR(20)')
        );

        $this->assertSame(
            "'client_secret_post'",
            $this->normalize('client_secret_post', 'VARCHAR(30)')
        );
    }

    public function testNormalizesBooleanDefaultsForMysql(): void
    {
        $this->assertSame('1', $this->normalize('true', 'boolean'));
        $this->assertSame('0', $this->normalize('false', 'tinyint(1)'));
    }

    public function testStripsPostgresqlCastsFromStringDefaults(): void
    {
        $this->assertSame(
            "'+1month'",
            $this->normalize("'+1month'::character varying", 'text')
        );
    }

    public function testPreservesSequenceDefaultsAsAutoIncrementMarkers(): void
    {
        $this->assertSame(
            "nextval('empresa_id_seq')",
            $this->normalize("nextval('empresa_id_seq'::regclass)", 'INTEGER')
        );
    }

    private function normalize(?string $default, string $columnType): ?string
    {
        return $this->normalizeDefault->invoke($this->mysql, $default, $columnType);
    }
}