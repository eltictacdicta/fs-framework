<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\CsrfManager;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once dirname(__DIR__, 2) . '/base/fs_core_log.php';
require_once dirname(__DIR__, 2) . '/base/fs_cache.php';
require_once dirname(__DIR__, 2) . '/base/fs_functions.php';
require_once dirname(__DIR__, 2) . '/model/core/fs_user.php';
require_once dirname(__DIR__, 2) . '/base/fs_login.php';

final class CsrfSessionSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $this->resetCsrfState();
    }

    protected function tearDown(): void
    {
        $this->resetCsrfState();
        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

        parent::tearDown();
    }

    #[Test]
    public function oldCsrfTokenInvalidatedAfterSaveSessionData(): void
    {
        // Start a session and generate an initial CSRF token
        $session = SessionManager::getInstance()->getSymfonySession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $oldToken = CsrfManager::generateToken();

        // Verify the old token is valid before save_session_data()
        $this->assertTrue(CsrfManager::isValid($oldToken), 'Token should be valid before session migrate');

        // Create fs_login instance
        $login = new \fs_login();

        // Create a minimal user with log_key
        $user = new class() extends \fs_user {
            public function __construct()
            {
                $this->nick = 'admin';
                $this->log_key = bin2hex(random_bytes(32));
                $this->logged_on = false;
                $this->email = null;
                $this->admin = false;
            }

            public function save(): bool { return true; }
            public function exists(): bool { return false; }
            public function delete(): bool { return false; }
        };

        // Call save_session_data() via Reflection — this migrates session
        $method = new \ReflectionMethod(\fs_login::class, 'save_session_data');
        $method->setAccessible(true);
        $method->invoke($login, $user);

        // After session migrate + CSRF refresh (if implemented),
        // the old token should be INVALIDATED
        // Without the refreshToken() call, it might still be valid
        // (depends on Symfony's session-persisted token storage)
        $stillValid = CsrfManager::isValid($oldToken);

        // The fix adds refreshToken() after migrate, which invalidates old tokens.
        // Without the fix, old tokens may remain valid across session migrations.
        $this->assertFalse($stillValid, 'Old CSRF token MUST be invalid after session migrate with refresh');
    }

    #[Test]
    public function csrfRefreshTokenProducesValidToken(): void
    {
        $session = SessionManager::getInstance()->getSymfonySession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $token = CsrfManager::refreshToken();

        $this->assertNotEmpty($token, 'refreshToken() should return a non-empty string');
        $this->assertTrue(CsrfManager::isValid($token), 'Refreshed token should be valid');
    }

    private function resetCsrfState(): void
    {
        $ref = new \ReflectionClass(CsrfManager::class);

        foreach (['manager', 'session'] as $propertyName) {
            $property = $ref->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }
    }
}
