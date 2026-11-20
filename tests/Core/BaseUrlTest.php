<?php

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Core\BaseUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_db2.php';

/**
 * FS_BASE_URL can live in fs_vars; config.php keeps winning so existing
 * installs do not change, and a bad stored value never defines the constant.
 */
final class BaseUrlTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function urls(): iterable
    {
        yield 'https' => ['https://erp.example.com', 'https://erp.example.com'];
        yield 'trailing slash and case' => ['HTTPS://ERP.Example.com/', 'https://erp.example.com'];
        yield 'port and subdirectory' => ['http://host:8080/fs/', 'http://host:8080/fs'];
        yield 'surrounding spaces' => ['  https://a.example  ', 'https://a.example'];
        yield 'empty' => ['', null];
        yield 'relative' => ['/index.php', null];
        yield 'no scheme' => ['erp.example.com', null];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'ftp' => ['ftp://erp.example.com', null];
        yield 'userinfo' => ['https://user:pw@erp.example.com', null];
        yield 'query' => ['https://erp.example.com/?x=1', null];
        yield 'fragment' => ['https://erp.example.com/#x', null];
        yield 'inner whitespace' => ["https://erp.example.com/a b", null];
        yield 'header injection' => ["https://erp.example.com\r\nX: y", null];
    }

    #[DataProvider('urls')]
    public function testNormalize(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, BaseUrl::normalize($raw));
    }

    #[RunInSeparateProcess]
    public function testDatabaseValueDefinesTheConstantWhenConfigDoesNot(): void
    {
        BaseUrl::reset();
        BaseUrl::defineFromDatabase($this->db('https://stored.example/'));

        $this->assertSame('https://stored.example', constant('FS_BASE_URL'));
        $this->assertSame(BaseUrl::SOURCE_DATABASE, BaseUrl::source());
    }

    #[RunInSeparateProcess]
    public function testConfigConstantWinsAndTheDatabaseIsNotRead(): void
    {
        define('FS_BASE_URL', 'https://from-config.example');
        BaseUrl::reset();
        $db = $this->db('https://stored.example');

        BaseUrl::defineFromDatabase($db);

        $this->assertSame('https://from-config.example', FS_BASE_URL);
        $this->assertSame(BaseUrl::SOURCE_CONFIG, BaseUrl::source());
        $this->assertSame(0, $db->selects);
    }

    #[RunInSeparateProcess]
    public function testInvalidOrMissingValueLeavesTheRequestFallback(): void
    {
        BaseUrl::reset();
        BaseUrl::defineFromDatabase($this->db('not a url'));
        BaseUrl::defineFromDatabase($this->db(null));
        BaseUrl::defineFromDatabase($this->db(null, false));

        $this->assertFalse(defined('FS_BASE_URL'));
        $this->assertNull(BaseUrl::source());
    }

    private function db(?string $value, bool $connects = true): BaseUrlFakeDb
    {
        return new BaseUrlFakeDb($value, $connects);
    }
}

/** fs_db2 that answers the fs_vars lookup without a database. */
final class BaseUrlFakeDb extends \fs_db2
{
    public int $selects = 0;

    public function __construct(private readonly ?string $value, private readonly bool $connects)
    {
    }

    public function connect()
    {
        return $this->connects;
    }

    /** @return list<array<string, string>> */
    public function select($sql, $params = [])
    {
        $this->selects++;

        return $this->value === null ? [] : [['name' => 'base_url', 'varchar' => $this->value]];
    }

    public function var2str($val)
    {
        return "'" . addslashes((string) $val) . "'";
    }
}
