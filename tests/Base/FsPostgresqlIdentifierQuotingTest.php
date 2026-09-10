<?php

declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

/**
 * H4 — PostgreSQL identifier quoting: the DDL emitted by the PostgreSQL driver
 * must quote table, column and constraint identifiers (reserved words /
 * mixed case). For lowercase identifiers quoting is equivalent to not quoting.
 */
class FsPostgresqlIdentifierQuotingTest extends TestCase
{
    private \fs_postgresql $pg;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_db_engine.php';
        require_once FS_FOLDER . '/base/fs_postgresql.php';

        $this->pg = new \fs_postgresql();
    }

    public function testGenerateTableQuotesTableAndColumnIdentifiers(): void
    {
        $sql = $this->pg->generate_table('mi_tabla', [
            ['nombre' => 'id', 'tipo' => 'serial', 'nulo' => 'NO', 'defecto' => "nextval('mi_tabla_id_seq')"],
            ['nombre' => 'nombre', 'tipo' => 'character varying(50)', 'nulo' => 'YES', 'defecto' => null],
        ], []);

        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS "mi_tabla" (', $sql);
        $this->assertStringContainsString('"id" serial NOT NULL', $sql);
        $this->assertStringContainsString('"nombre" character varying(50)', $sql);
    }

    public function testCompareColumnsQuotesTableAndColumnWhenCreating(): void
    {
        $sql = $this->pg->compare_columns('mi_tabla', [
            ['nombre' => 'nueva', 'tipo' => 'integer', 'nulo' => 'NO', 'defecto' => null],
        ], []);

        $this->assertSame('ALTER TABLE "mi_tabla" ADD COLUMN "nueva" integer NOT NULL;', $sql);
    }

    public function testCompareConstraintsQuotesTableAndConstraint(): void
    {
        $sql = $this->pg->compare_constraints('mi_tabla', [
            ['nombre' => 'mi_pk', 'consulta' => 'PRIMARY KEY (id)'],
        ], []);

        $this->assertSame('ALTER TABLE "mi_tabla" ADD CONSTRAINT "mi_pk" PRIMARY KEY (id);', $sql);
    }

    public function testQuoteIdentifierEscapesEmbeddedQuotes(): void
    {
        $method = new \ReflectionMethod(\fs_postgresql::class, 'quote_identifier');
        $method->setAccessible(true);

        $this->assertSame('"a""b"', $method->invoke($this->pg, 'a"b'));
    }
}
