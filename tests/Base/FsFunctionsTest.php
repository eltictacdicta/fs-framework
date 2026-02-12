<?php
/**
 * Tests para las funciones globales en base/fs_functions.php
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsFunctionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once FS_FOLDER . '/base/fs_functions.php';
    }

    // =====================================================================
    // bround() — Redondeo bancario
    // =====================================================================

    public function testBroundBasicRounding(): void
    {
        $this->assertSame(1.24, bround(1.235, 2));  // Se redondea hacia arriba
        $this->assertSame(1.24, bround(1.245, 2));  // .5 con dígito par -> abajo (banker's)
        $this->assertSame(1.26, bround(1.255, 2));  // .5 con dígito impar -> arriba
    }

    public function testBroundZero(): void
    {
        $this->assertSame(0.0, bround(0.0, 2));
    }

    public function testBroundNegativeValues(): void
    {
        $result = bround(-1.235, 2);
        $this->assertSame(-1.24, $result);
    }

    public function testBroundNoPrecision(): void
    {
        $this->assertSame(3.14, bround(3.14159, 2));
    }

    public function testBroundHighPrecision(): void
    {
        $this->assertSame(3.14159, bround(3.14159, 5));
    }

    // =====================================================================
    // fs_fix_html() — Inversa de no_html()
    // =====================================================================

    public function testFsFixHtmlRestoresAngleBrackets(): void
    {
        $this->assertSame('<b>bold</b>', fs_fix_html('&lt;b&gt;bold&lt;/b&gt;'));
    }

    public function testFsFixHtmlRestoresQuotes(): void
    {
        // Nota: tanto &quot; como &#39; se convierten a comilla simple
        $this->assertSame("'hello'", fs_fix_html('&quot;hello&quot;'));
        $this->assertSame("'hello'", fs_fix_html('&#39;hello&#39;'));
    }

    public function testFsFixHtmlTrims(): void
    {
        $this->assertSame('hello', fs_fix_html('  hello  '));
    }

    // =====================================================================
    // fs_is_local_ip()
    // =====================================================================

    public function testLocalIpLoopback(): void
    {
        $this->assertTrue(fs_is_local_ip('127.0.0.1'));
        $this->assertTrue(fs_is_local_ip('::1'));
        $this->assertTrue(fs_is_local_ip('localhost'));
    }

    public function testLocalIpPrivateRanges(): void
    {
        $this->assertTrue(fs_is_local_ip('10.0.0.1'));
        $this->assertTrue(fs_is_local_ip('10.255.255.255'));
        $this->assertTrue(fs_is_local_ip('192.168.1.1'));
        $this->assertTrue(fs_is_local_ip('192.168.0.100'));
        $this->assertTrue(fs_is_local_ip('172.16.0.1'));
        $this->assertTrue(fs_is_local_ip('172.31.255.255'));
    }

    public function testLocalIpPublicAddresses(): void
    {
        $this->assertFalse(fs_is_local_ip('8.8.8.8'));
        $this->assertFalse(fs_is_local_ip('1.1.1.1'));
        $this->assertFalse(fs_is_local_ip('203.0.113.1'));
    }

    public function testLocalIpBorderCases172(): void
    {
        // 172.15.x.x no es privado
        $this->assertFalse(fs_is_local_ip('172.15.0.1'));
        // 172.32.x.x no es privado
        $this->assertFalse(fs_is_local_ip('172.32.0.1'));
    }

    // =====================================================================
    // fs_get_max_file_upload()
    // =====================================================================

    public function testGetMaxFileUploadReturnsPositiveInt(): void
    {
        $max = fs_get_max_file_upload();
        $this->assertIsInt($max);
        $this->assertGreaterThan(0, $max);
    }
}
