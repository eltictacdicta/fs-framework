<?php
/**
 * Tests para fs_ip_filter — lógica de ban/whitelist por IP.
 */

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsIpFilterTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/base/fs_ip_filter.php';

        // Limpiar archivo de IPs antes de cada test
        $this->tmpFile = FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'ip.log';
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    // =====================================================================
    // is_banned()
    // =====================================================================

    public function testNewIpIsNotBanned(): void
    {
        $filter = new \fs_ip_filter();
        $this->assertFalse($filter->is_banned('1.2.3.4'));
    }

    public function testIpBannedAfterMaxAttempts(): void
    {
        $filter = new \fs_ip_filter();
        $ip = '10.20.30.40';

        // MAX_ATTEMPTS es 5, se ban después de 5
        for ($i = 0; $i <= \fs_ip_filter::MAX_ATTEMPTS; $i++) {
            $filter->set_attempt($ip);
        }

        // Reload para verificar persistencia
        $filter2 = new \fs_ip_filter();
        $this->assertTrue($filter2->is_banned($ip));
    }

    public function testIpNotBannedBelowMaxAttempts(): void
    {
        $filter = new \fs_ip_filter();
        $ip = '10.20.30.41';

        // Solo 3 intentos (menos que MAX_ATTEMPTS=5)
        for ($i = 0; $i < 3; $i++) {
            $filter->set_attempt($ip);
        }

        $filter2 = new \fs_ip_filter();
        $this->assertFalse($filter2->is_banned($ip));
    }

    // =====================================================================
    // in_white_list()
    // =====================================================================

    public function testWhitelistAllowsAllWhenWildcard(): void
    {
        // FS_IP_WHITELIST está definido como '*' en el bootstrap
        $filter = new \fs_ip_filter();
        $this->assertTrue($filter->in_white_list('1.2.3.4'));
        $this->assertTrue($filter->in_white_list('10.0.0.1'));
    }

    // =====================================================================
    // clear()
    // =====================================================================

    public function testClearRemovesAllIps(): void
    {
        $filter = new \fs_ip_filter();

        for ($i = 0; $i <= \fs_ip_filter::MAX_ATTEMPTS; $i++) {
            $filter->set_attempt('50.50.50.50');
        }

        $filter->clear();

        $filter2 = new \fs_ip_filter();
        $this->assertFalse($filter2->is_banned('50.50.50.50'));
    }

    // =====================================================================
    // Múltiples IPs
    // =====================================================================

    public function testMultipleIpsTrackedIndependently(): void
    {
        $filter = new \fs_ip_filter();

        // Ban IP1
        for ($i = 0; $i <= \fs_ip_filter::MAX_ATTEMPTS; $i++) {
            $filter->set_attempt('1.1.1.1');
        }

        // Solo 1 intento para IP2
        $filter->set_attempt('2.2.2.2');

        $filter2 = new \fs_ip_filter();
        $this->assertTrue($filter2->is_banned('1.1.1.1'));
        $this->assertFalse($filter2->is_banned('2.2.2.2'));
    }
}
