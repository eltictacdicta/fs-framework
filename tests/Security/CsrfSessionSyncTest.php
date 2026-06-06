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
    public function csrfTokenSurvivesSessionMigration(): void
    {
        $session = SessionManager::getInstance()->getSymfonySession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $token = CsrfManager::generateToken();
        $this->assertTrue(CsrfManager::isValid($token), 'Token should be valid before session migrate');

        $login = new \fs_login();

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

        $method = new \ReflectionMethod(\fs_login::class, 'save_session_data');
        $method->setAccessible(true);
        $method->invoke($login, $user, true);

        $this->assertTrue(
            CsrfManager::isValid($token),
            'CSRF token must remain valid after session migration (migrate preserves session data)'
        );
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
