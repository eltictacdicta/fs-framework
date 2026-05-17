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

    public function testCookiePathUsesFsPathWhenDefined(): void
    {
        $this->assertSame('/myapp/', fs_install_normalize_cookie_path('/myapp', ['SCRIPT_NAME' => '/ignored/install.php']));
    }

    public function testCookiePathFallsBackToScriptNameWhenFsPathIsEmpty(): void
    {
        $this->assertSame('/myapp/', fs_install_normalize_cookie_path('', ['SCRIPT_NAME' => '/myapp/install.php']));
    }

    public function testCookiePathFallsBackToRequestUriWhenScriptNameIsMissing(): void
    {
        $this->assertSame('/myapp/', fs_install_normalize_cookie_path('', ['REQUEST_URI' => '/myapp/install.php?step=1']));
    }

    public function testCookiePathDefaultsToRootWhenNoBasePathIsAvailable(): void
    {
        $this->assertSame('/', fs_install_normalize_cookie_path(null, []));
    }

    public function testSessionNameIsScopedToCurrentInstallation(): void
    {
        if (defined('FS_SESSION_NAME') && trim((string) FS_SESSION_NAME) !== '') {
            $this->markTestSkipped('FS_SESSION_NAME override is active.');
        }

        $sessionName = fs_install_resolve_session_name();

        $this->assertStringStartsWith('FSINSTALL_', $sessionName);
        $this->assertNotSame('FSINSTALL', $sessionName);
        $this->assertSame(22, strlen($sessionName));
    }
}
