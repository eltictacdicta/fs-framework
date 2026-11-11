<?php

declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

/**
 * An empty <defecto></defecto> (or "NULL") means "no default", and serial
 * columns own their nextval() default. Before, a freshly created table with
 * `id serial` + empty default produced "ALTER COLUMN id SET DEFAULT ;" and
 * plugin activation failed on PostgreSQL.
 */
final class FsPostgresqlEmptyDefaultTest extends TestCase
{
    private \fs_postgresql $pg;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_db_engine.php';
        require_once FS_FOLDER . '/base/fs_postgresql.php';

        $this->pg = new \fs_postgresql();
    }

    public function testATableJustCreatedFromItsXmlNeedsNoChanges(): void
    {
        $xml = [
            ['nombre' => 'id', 'tipo' => 'serial', 'nulo' => 'NO', 'defecto' => ''],
            ['nombre' => 'nick', 'tipo' => 'character varying(50)', 'nulo' => 'NO', 'defecto' => ''],
            ['nombre' => 'journal_day', 'tipo' => 'date', 'nulo' => 'YES', 'defecto' => 'NULL'],
            ['nombre' => 'version', 'tipo' => 'integer', 'nulo' => 'NO', 'defecto' => '1'],
        ];
        $db = [
            ['name' => 'id', 'type' => 'integer', 'default' => "nextval('t_id_seq'::regclass)", 'is_nullable' => 'NO'],
            ['name' => 'nick', 'type' => 'character varying(50)', 'default' => null, 'is_nullable' => 'NO'],
            ['name' => 'journal_day', 'type' => 'date', 'default' => null, 'is_nullable' => 'YES'],
            ['name' => 'version', 'type' => 'integer', 'default' => '1', 'is_nullable' => 'NO'],
        ];

        $this->assertSame('', $this->pg->compare_columns('t', $xml, $db));
    }

    public function testEmptyDefaultsAreNotEmittedAsSql(): void
    {
        $create = $this->pg->generate_table('t', [
            ['nombre' => 'nick', 'tipo' => 'character varying(50)', 'nulo' => 'NO', 'defecto' => ''],
        ], []);
        $this->assertStringNotContainsString('DEFAULT', $create);

        $add = $this->pg->compare_columns('t', [
            ['nombre' => 'id', 'tipo' => 'serial', 'nulo' => 'NO', 'defecto' => ''],
            ['nombre' => 'nick', 'tipo' => 'character varying(50)', 'nulo' => 'NO', 'defecto' => ''],
        ], []);
        $this->assertSame('ALTER TABLE "t" ADD COLUMN "id" serial NOT NULL;ALTER TABLE "t" ADD COLUMN "nick" character varying(50) NOT NULL;', $add);
    }

    public function testSerialAliasesNeverGetAnXmlDefault(): void
    {
        $create = $this->pg->generate_table('t', [
            ['nombre' => 'id', 'tipo' => 'smallserial', 'nulo' => 'NO', 'defecto' => 'NULL'],
            ['nombre' => 'id8', 'tipo' => 'serial8', 'nulo' => 'NO', 'defecto' => "nextval('x')"],
        ], []);
        $this->assertStringNotContainsString('DEFAULT', $create);

        $sql = $this->pg->compare_columns('t',
            [['nombre' => 'id', 'tipo' => 'serial4', 'nulo' => 'NO', 'defecto' => '']],
            [['name' => 'id', 'type' => 'integer', 'default' => "nextval('t_id_seq'::regclass)", 'is_nullable' => 'NO']]
        );
        $this->assertSame('', $sql, 'the sequence default must never be dropped');
    }

    public function testASerialThatLostItsDefaultIsRepairedFromTheXml(): void
    {
        $pg = new class() extends \fs_postgresql {
            public function select($sql, $params = []) { return [[1]]; } // sequence exists
        };
        $sql = $pg->compare_columns('t',
            [['nombre' => 'id', 'tipo' => 'serial', 'nulo' => 'NO', 'defecto' => "nextval('t_id_seq'::regclass)"]],
            [['name' => 'id', 'type' => 'integer', 'default' => null, 'is_nullable' => 'NO']]
        );
        $this->assertSame('ALTER TABLE "t" ALTER COLUMN "id" SET DEFAULT nextval(\'t_id_seq\'::regclass);', $sql);
    }

    public function testARemovedDefaultIsStillDropped(): void
    {
        $sql = $this->pg->compare_columns('t',
            [['nombre' => 'n', 'tipo' => 'integer', 'nulo' => 'YES', 'defecto' => '']],
            [['name' => 'n', 'type' => 'integer', 'default' => '0', 'is_nullable' => 'YES']]
        );
        $this->assertSame('ALTER TABLE "t" ALTER COLUMN "n" DROP DEFAULT;', $sql);
    }
}
