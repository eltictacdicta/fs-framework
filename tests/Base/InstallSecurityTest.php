<?php

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class InstallSecurityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once FS_FOLDER . '/base/install_security.php';
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
            session_write_close();
        }

        $_SESSION = [];
    }

    public function testDatabaseNameValidatorAcceptsSafeIdentifiers(): void
    {
        $this->assertTrue(fs_install_is_valid_database_name('fsframework'));
        $this->assertTrue(fs_install_is_valid_database_name('demo_2026'));
    }

    public function testDatabaseNameValidatorRejectsUnsafeIdentifiers(): void
    {
        $this->assertFalse(fs_install_is_valid_database_name('test-db'));
        $this->assertFalse(fs_install_is_valid_database_name('test;drop'));
        $this->assertFalse(fs_install_is_valid_database_name('db name'));
    }

    public function testCsrfTokenRoundTripValidation(): void
    {
        $token = fs_install_get_csrf_token();

        $this->assertTrue(fs_install_validate_csrf_token($token));
        $this->assertFalse(fs_install_validate_csrf_token('invalid-token'));
    }
}