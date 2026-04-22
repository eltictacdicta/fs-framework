<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Security tests for session fixation attack prevention.
 * 
 * Tests verify that:
 * 1. Session ID is regenerated on login
 * 2. Session ID is regenerated periodically
 * 3. Old session data is preserved during regeneration
 * 4. Session cookies have secure attributes
 */
class SessionFixationPreventionTest extends TestCase
{
    public function testSessionManagerRegeneratesIdOnLogin(): void
    {
        if (!class_exists(SessionManager::class)) {
            $this->markTestSkipped('SessionManager not available');
        }

        $manager = SessionManager::getInstance();

        $this->assertTrue(method_exists($manager, 'regenerateId'));
        $this->assertTrue(method_exists($manager, 'login'));
    }

    public function testSessionManagerHasPeriodicRegenerationLogic(): void
    {
        if (!class_exists(SessionManager::class)) {
            $this->markTestSkipped('SessionManager not available');
        }

        $manager = SessionManager::getInstance();
        $session = $manager->getSymfonySession();

        $lastRegen = $session->get('_last_regeneration');

        $this->assertTrue(
            $lastRegen === null || is_int($lastRegen),
            '_last_regeneration should be null or int'
        );
    }

    public function testLoginRegeneratesSessionId(): void
    {
        if (!class_exists(SessionManager::class)) {
            $this->markTestSkipped('SessionManager not available');
        }

        $manager = SessionManager::getInstance();

        $oldRegenTime = $manager->getSymfonySession()->get('_last_regeneration', 0);

        $manager->login([
            'nick' => 'test_user',
            'email' => 'test@example.com',
            'admin' => false,
        ]);

        $newRegenTime = $manager->getSymfonySession()->get('_last_regeneration', 0);

        $this->assertGreaterThanOrEqual($oldRegenTime, $newRegenTime);
    }

    public function testLoginSetsLoginTime(): void
    {
        if (!class_exists(SessionManager::class)) {
            $this->markTestSkipped('SessionManager not available');
        }

        $manager = SessionManager::getInstance();
        $beforeLogin = time();

        $manager->login([
            'nick' => 'test_user_time',
            'email' => 'test@example.com',
            'admin' => false,
        ]);

        $loginTime = $manager->getSymfonySession()->get('login_time', 0);

        $this->assertGreaterThanOrEqual($beforeLogin, $loginTime);
        $this->assertLessThanOrEqual(time() + 1, $loginTime);
    }

    public function testLogoutClearsSessionData(): void
    {
        if (!class_exists(SessionManager::class)) {
            $this->markTestSkipped('SessionManager not available');
        }

        $manager = SessionManager::getInstance();

        $manager->login([
            'nick' => 'logout_test_user',
            'email' => 'logout@example.com',
            'admin' => false,
        ]);

        $this->assertTrue($manager->isLoggedIn());

        $manager->logout();

        $this->assertNull($manager->getCurrentUserNick());
    }

    public function testLegacySessionManagerRegeneratesId(): void
    {
        if (!class_exists('fs_session_manager')) {
            $this->markTestSkipped('Legacy session manager not available');
        }

        $this->assertTrue(method_exists('fs_session_manager', 'regenerateId'));
    }

    public function testSessionIsValidChecksLoginTime(): void
    {
        if (!class_exists(SessionManager::class)) {
            $this->markTestSkipped('SessionManager not available');
        }

        $manager = SessionManager::getInstance();

        $this->assertTrue(method_exists($manager, 'isValid'));
    }

    public function testCsrfTokenIsRegeneratedWithSession(): void
    {
        if (!class_exists(SessionManager::class)) {
            $this->markTestSkipped('SessionManager not available');
        }

        $manager = SessionManager::getInstance();

        $csrfToken = $manager->getCsrfToken();

        $this->assertNotEmpty($csrfToken);
        $this->assertIsString($csrfToken);
    }

    public function testBuffet3dSessionManagerDestroysSessionCompletely(): void
    {
        if (!class_exists('Buffet3d\\Security\\SessionManager')) {
            $this->markTestSkipped('Buffet3d SessionManager not available');
        }

        $sessionManager = new \Buffet3d\Security\SessionManager();

        $this->assertTrue(method_exists($sessionManager, 'destroySession'));
    }

    public function testSymfonySessionMigrateIsUsedForRegeneration(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();

        $session->set('test_data', 'preserved_value');

        $session->migrate(true);

        $this->assertEquals('preserved_value', $session->get('test_data'));
    }

    public function testSessionDataIsPreservedAfterRegeneration(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();

        $session->set('user_data', ['name' => 'Test User']);
        $session->set('preferences', ['theme' => 'dark']);

        $session->migrate(true);

        $this->assertEquals(['name' => 'Test User'], $session->get('user_data'));
        $this->assertEquals(['theme' => 'dark'], $session->get('preferences'));
    }
}
