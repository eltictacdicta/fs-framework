<?php

declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_pg_conninfo.php';

final class FsPgConninfoTest extends TestCase
{
    public function testValuesAreQuotedAndEscapedForLibpq(): void
    {
        $this->assertSame(
            "host='db' port='5432' password='a b\\'c\\\\d'",
            fs_pg_conninfo(['host' => 'db', 'port' => 5432, 'password' => "a b'c\\d"])
        );
    }

    public function testEmptyValuesAreSkippedSoLibpqDefaultsApply(): void
    {
        $this->assertSame("host='db'", fs_pg_conninfo(['host' => 'db', 'port' => null, 'password' => '']));
    }
}
