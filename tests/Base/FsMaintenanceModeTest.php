<?php

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class FsMaintenanceModeTest extends TestCase
{
    private string $lockFile;

    public static function setUpBeforeClass(): void
    {
        require_once FS_FOLDER . '/base/fs_maintenance_mode.php';
    }

    protected function setUp(): void
    {
        $this->resetAmbientSessionState();

        $this->lockFile = FS_FOLDER . '/tmp/fs-maintenance-mode-test.json';

        if (!defined('FS_MAINTENANCE_LOCK_FILE')) {
            define('FS_MAINTENANCE_LOCK_FILE', $this->lockFile);
        }

        if (!defined('FS_MAINTENANCE_STEALTH_ENABLED')) {
            define('FS_MAINTENANCE_STEALTH_ENABLED', true);
        }

        if (!defined('FS_MAINTENANCE_STEALTH_PARAM_NAME')) {
            define('FS_MAINTENANCE_STEALTH_PARAM_NAME', 'secret');
        }

        if (!defined('FS_MAINTENANCE_STEALTH_PARAM_VALUE')) {
            define('FS_MAINTENANCE_STEALTH_PARAM_VALUE', 'token');
        }

        \fs_maintenance_mode::clearLock();
    }

    protected function tearDown(): void
    {
        $this->resetAmbientSessionState();
        \fs_maintenance_mode::clearLock();
    }

    public function testIsInactiveWithoutForceOrLock(): void
    {
        $this->assertFalse(\fs_maintenance_mode::isActive());
        $this->assertFalse(\fs_maintenance_mode::hasLock());
    }

    public function testWriteLockActivatesMaintenance(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock([
            'message' => 'Updating core',
            'active' => true,
        ]));

        $this->assertTrue(\fs_maintenance_mode::hasLock());
        $this->assertTrue(\fs_maintenance_mode::isEnabled());
        $this->assertTrue(\fs_maintenance_mode::isActive([], [], [], []));

        $state = \fs_maintenance_mode::readLockState();
        $this->assertIsArray($state);
        $this->assertSame('Updating core', $state['message']);
    }

    public function testInactiveLockStateDoesNotActivateMaintenance(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock([
            'active' => false,
        ]));

        $this->assertFalse(\fs_maintenance_mode::hasLock());
        $this->assertFalse(\fs_maintenance_mode::isEnabled());
        $this->assertFalse(\fs_maintenance_mode::isActive());
    }

    public function testStealthAccessStatusReportsReadyWhenConfigured(): void
    {
        $status = \fs_maintenance_mode::stealthAccessStatus();

        $this->assertTrue($status['enabled']);
        $this->assertSame('secret', $status['param_name']);
        $this->assertSame('token', $status['param_value']);
        $this->assertTrue($status['ready']);
    }

    public function testBypassTokenSkipsMaintenanceWhenIpMatches(): void
    {
        if (!defined('FS_MAINTENANCE_BYPASS_TOKEN')) {
            define('FS_MAINTENANCE_BYPASS_TOKEN', 'secret-bypass');
        }

        if (!defined('FS_MAINTENANCE_BYPASS_IPS')) {
            define('FS_MAINTENANCE_BYPASS_IPS', '127.0.0.1');
        }

        $this->assertTrue(\fs_maintenance_mode::writeLock());
        $this->assertTrue(\fs_maintenance_mode::isActive([], [], [], []));

        $this->assertFalse(\fs_maintenance_mode::isActive(
            ['REMOTE_ADDR' => '127.0.0.1'],
            [\fs_maintenance_mode::bypassQueryParam() => 'secret-bypass'],
            [],
            []
        ));
    }

    public function testAdminSessionSkipsMaintenance(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock());
        $this->assertTrue(\fs_maintenance_mode::isEnabled());

        $this->assertFalse(\fs_maintenance_mode::isActive(
            [],
            [],
            [
                'user_nick' => 'admin',
                'user_admin' => true,
                'user_role' => 'admin',
                'user_logged_in' => true,
                'login_time' => time(),
                'last_activity' => time(),
            ],
            []
        ));
    }

    public function testMaintenanceCanBeEnabledWhileAdminRequestIsNotBlocked(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock());

        $this->assertTrue(\fs_maintenance_mode::isEnabled());
        $this->assertFalse(\fs_maintenance_mode::isActive(
            [],
            [],
            [
                'user_nick' => 'admin',
                'user_admin' => true,
                'user_role' => 'admin',
                'user_logged_in' => true,
                'login_time' => time(),
                'last_activity' => time(),
            ],
            []
        ));
    }

    public function testNonAdminSessionDoesNotSkipMaintenance(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock());

        $this->assertTrue(\fs_maintenance_mode::isActive(
            [],
            [],
            [
                'user_nick' => 'user',
                'user_admin' => false,
                'user_role' => 'user',
                'user_logged_in' => true,
                'login_time' => time(),
                'last_activity' => time(),
            ],
            []
        ));
    }

    public function testStealthLoginRequestSkipsMaintenance(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock());

        $this->assertFalse(\fs_maintenance_mode::isActive(
            ['REQUEST_URI' => '/index.php?secret=token&page=login', 'REQUEST_METHOD' => 'GET'],
            ['secret' => 'token', 'page' => 'login'],
            [],
            [],
        ));
    }

    public function testStealthRootEntrySkipsMaintenance(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock());

        $this->assertFalse(\fs_maintenance_mode::isActive(
            ['REQUEST_URI' => '/index.php?secret=token', 'REQUEST_METHOD' => 'GET'],
            ['secret' => 'token'],
            [],
            [],
        ));
    }

    public function testLoginPageSkipsMaintenance(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock());

        $this->assertFalse(\fs_maintenance_mode::isActive(
            ['REQUEST_URI' => '/index.php?page=login', 'REQUEST_METHOD' => 'GET'],
            ['page' => 'login'],
            [],
            [],
        ));
    }

    public function testLoginSubmissionSkipsMaintenance(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock());

        $this->assertFalse(\fs_maintenance_mode::isActive(
            ['REQUEST_URI' => '/index.php?page=login', 'REQUEST_METHOD' => 'POST'],
            ['page' => 'login'],
            [],
            ['user' => 'admin', 'password' => 'secret'],
        ));
    }

    public function testOidcLoginPathSkipsMaintenance(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock());

        $this->assertFalse(\fs_maintenance_mode::isActive(
            ['REQUEST_URI' => '/oauth/login', 'REQUEST_METHOD' => 'GET'],
            [],
            [],
            [],
        ));
    }

    public function testOidcEntrypointSkipsMaintenance(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock());

        $this->assertFalse(\fs_maintenance_mode::isActive(
            ['REQUEST_URI' => '/oauth', 'REQUEST_METHOD' => 'GET'],
            [],
            [],
            [],
        ));
    }

    public function testBackendPageRequestSkipsMaintenance(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock());

        $this->assertFalse(\fs_maintenance_mode::isActive(
            ['REQUEST_URI' => '/index.php?page=admin_updater', 'REQUEST_METHOD' => 'GET'],
            ['page' => 'admin_updater'],
            [],
            [],
        ));
    }

    public function testNonWhitelistedBackendPageDoesNotSkipMaintenance(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock());

        $this->assertTrue(\fs_maintenance_mode::isActive(
            ['REQUEST_URI' => '/index.php?page=customers', 'REQUEST_METHOD' => 'GET'],
            ['page' => 'customers'],
            [],
            [],
        ));
    }

    public function testAdminSessionFromCookieSkipsMaintenanceForPluginPage(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock());

        $this->assertFalse(\fs_maintenance_mode::isActive(
            ['REQUEST_URI' => '/index.php?page=admin_oidc_customer_access', 'REQUEST_METHOD' => 'GET'],
            ['page' => 'admin_oidc_customer_access'],
            [
                'user_nick' => 'admin',
                'user_admin' => true,
                'user_role' => 'admin',
                'user_logged_in' => true,
                'login_time' => time(),
                'last_activity' => time(),
            ],
            [],
        ));
    }

    public function testAdminSessionFromPhpSessionCookieAlsoSkipsMaintenance(): void
    {
        $this->assertTrue(\fs_maintenance_mode::writeLock());

        $this->assertFalse(\fs_maintenance_mode::isActive(
            ['REQUEST_URI' => '/index.php?page=admin_oidc_customer_access', 'REQUEST_METHOD' => 'GET'],
            ['page' => 'admin_oidc_customer_access'],
            [
                '_sf2_attributes' => [
                    'user_nick' => 'admin',
                    'user_admin' => true,
                    'user_role' => 'admin',
                    'user_logged_in' => true,
                    'login_time' => time(),
                    'last_activity' => time(),
                ],
            ],
            [],
        ));
    }

    private function resetAmbientSessionState(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }

        session_id('');
        $_SESSION = [];
        $_COOKIE = [];
    }
}
