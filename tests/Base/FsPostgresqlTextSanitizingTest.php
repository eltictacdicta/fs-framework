<?php

declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

/**
 * PostgreSQL text holds neither NUL bytes nor invalid UTF-8. libpq silently
 * truncated values at a NUL, and invalid UTF-8 made pg_escape_string() throw
 * (aborting the request) or the server reject the query.
 */
final class FsPostgresqlTextSanitizingTest extends TestCase
{
    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_db_engine.php';
        require_once FS_FOLDER . '/base/fs_postgresql.php';
    }

    public function testNulBytesAreRemovedInsteadOfTruncatingTheValue(): void
    {
        $this->assertSame('antesdespués', \fs_postgresql::to_text("antes\0después"));
    }

    public function testInvalidUtf8IsReadAsWindows1252(): void
    {
        $this->assertSame('café “ñ”', \fs_postgresql::to_text("caf\xE9 \x93\xF1\x94"));
    }

    public function testValidUtf8IsUntouched(): void
    {
        foreach (['', 'plain', "comillas ' y \\ barra", 'emoji 🚀 ñ €'] as $value) {
            $this->assertSame($value, \fs_postgresql::to_text($value));
        }
    }

    public function testEscapingSanitizesToo(): void
    {
        // Without a connection escape_string doubles quotes; the text is cleaned first.
        $this->assertSame("caf\u{e9} l''a", (new \fs_postgresql())->escape_string("caf\xE9 l'\0a"));
    }

    public function testOnlyTheInvalidBytesOfAMixedStringAreConverted(): void
    {
        $this->assertSame('ñ é ñ', \fs_postgresql::to_text("\xC3\xB1 \xE9 \xC3\xB1"));
    }
}
