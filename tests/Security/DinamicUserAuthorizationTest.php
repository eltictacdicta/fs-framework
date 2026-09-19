<?php

declare(strict_types=1);

/**
 * Security regression tests for FSFramework\Dinamic\Model\User.
 *
 * The class used to be a privilege-escalation primitive: it declared
 * `public $admin = true` and derived the current identity from the unsigned
 * `fsNick` cookie. With no authenticated user, or with a nonexistent/deleted
 * one, the admin flag stayed TRUE.
 *
 * These tests pin the fail-closed contract:
 *  - authority defaults to FALSE and is never read from client input;
 *  - identity comes from the server-side session, not from `fsNick`;
 *  - the session admin snapshot alone never grants admin.
 */

namespace Tests\Security;

use FSFramework\Dinamic\Model\User;
use FSFramework\Security\LegacyAuthBridge;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(User::class)]
class DinamicUserAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        LegacyAuthBridge::resetSkipLegacyCookieRestoreCheck();

        // No legacy cookie must leak between tests.
        $_COOKIE = [];

        $this->installSessionManager();
    }

    protected function tearDown(): void
    {
        LegacyAuthBridge::resetSkipLegacyCookieRestoreCheck();

        $_COOKIE = [];
        SessionManager::reset();

        parent::tearDown();
    }

    #[Test]
    public function anonymousUserIsNeverAdmin(): void
    {
        $user = new User();

        $this->assertFalse($user->admin, 'An unauthenticated User must not be an admin');
        $this->assertNull($user->nick, 'An unauthenticated User must not claim an identity');
        $this->assertNull($user->fs_user_legacy);
    }

    #[Test]
    public function forgedFsNickCookieDoesNotGrantAdminOrIdentity(): void
    {
        $_COOKIE['fsNick'] = 'admin';

        $user = new User();

        $this->assertFalse($user->admin, 'The unsigned fsNick cookie must never grant admin');
        $this->assertNull($user->nick, 'The unsigned fsNick cookie must never provide identity');
        $this->assertNull($user->fs_user_legacy);
    }

    #[Test]
    public function sessionAdminSnapshotAloneDoesNotGrantAdmin(): void
    {
        $manager = SessionManager::getInstance();
        $manager->set('user_admin', true);
        $manager->set('user_role', 'admin');

        $user = new User();

        $this->assertFalse(
            $user->admin,
            'A session admin snapshot without an authenticated DB user must not grant admin'
        );
    }

    /**
     * Installs a REAL SessionManager over an array-backed session as the
     * singleton, so fs_session_manager resolves through it without starting a
     * native PHP session or touching the database.
     */
    private function installSessionManager(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();

        $ref = new ReflectionClass(SessionManager::class);
        $manager = $ref->newInstanceWithoutConstructor();

        $this->setPrivate($manager, 'session', $session);
        $this->setPrivate($manager, 'legacyAuthBridge', new LegacyAuthBridge($session));
        $this->setPrivate($manager, 'initialized', true);

        $instance = new ReflectionProperty(SessionManager::class, 'instance');
        $instance->setAccessible(true);
        $instance->setValue(null, $manager);
    }

    private function setPrivate(object $instance, string $propertyName, mixed $value): void
    {
        $property = new ReflectionProperty($instance, $propertyName);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }
}
