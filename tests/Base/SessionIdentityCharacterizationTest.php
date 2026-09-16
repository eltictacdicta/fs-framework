<?php
declare(strict_types=1);

/**
 * Characterization tests for the authentication core: how the session
 * manager decides who is logged in, and where that identity actually lives.
 *
 * These tests pin down CURRENT behaviour of the real
 * FSFramework\Security\SessionManager with an array-backed Symfony session.
 * They intentionally do not "fix" anything.
 *
 * Context: before deciding whether to add an impersonation feature we need a
 * safety net around the session identity model. The single most important
 * property pinned here is that fs_users.log_key is NOT a session validity
 * check — it only guards the legacy cookie autologin path.
 */

namespace Tests\Base;

use FSFramework\Security\LegacyAuthBridge;
use FSFramework\Security\SessionManager;
use FSFramework\Security\SessionPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(SessionManager::class)]
class SessionIdentityCharacterizationTest extends TestCase
{
    protected function setUp(): void
    {
        // No legacy cookie restore callback must leak between tests.
        LegacyAuthBridge::resetSkipLegacyCookieRestoreCheck();

        // Keep the legacy cookie path inert (no DB, no fs_user lookup).
        $_COOKIE = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    protected function tearDown(): void
    {
        LegacyAuthBridge::resetSkipLegacyCookieRestoreCheck();

        $_COOKIE = [];
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    // =====================================================================
    // isValid(): user_nick presence + SessionPolicy timestamps only
    // =====================================================================

    #[Test]
    public function isValidIsFalseWithoutUserNick(): void
    {
        $manager = $this->makeManager();

        $this->assertFalse($manager->isValid());
    }

    #[Test]
    public function isValidIsTrueWithFreshTimestamps(): void
    {
        $manager = $this->makeManager();
        $this->seedIdentity($manager, [
            'user_nick' => 'fresh-user',
            'login_time' => time(),
            'last_activity' => time(),
        ]);

        $this->assertTrue($manager->isValid());
    }

    #[Test]
    public function isValidIsFalseWhenIdleTimeoutExceeded(): void
    {
        $manager = $this->makeManager();
        $this->seedIdentity($manager, [
            'user_nick' => 'idle-user',
            'login_time' => time(),
            'last_activity' => time() - SessionPolicy::getIdleTimeout() - 1,
        ]);

        $this->assertFalse($manager->isValid());
    }

    #[Test]
    public function isValidIsFalseWhenAbsoluteTimeoutExceeded(): void
    {
        $manager = $this->makeManager();
        $this->seedIdentity($manager, [
            'user_nick' => 'absolute-user',
            'login_time' => time() - SessionPolicy::getAbsoluteTimeout() - 1,
            'last_activity' => time(),
        ]);

        $this->assertFalse($manager->isValid());
    }

    // =====================================================================
    // LOAD-BEARING PROPERTY: log_key does NOT invalidate a session
    // =====================================================================

    /**
     * A session whose user_logkey matches nothing in the database is STILL
     * valid, and isLoggedIn() still reports true.
     *
     * This is the property the impersonation decision depends on:
     * fs_users.log_key is NOT a session invalidation mechanism. It only
     * guards the legacy cookie autologin path (compared in
     * base/fs_login.php:266 with hash_equals, in
     * src/Security/LegacyAuthBridge.php:277, and in
     * base/fs_session_manager.php:633). Rotating a user's log_key — which is
     * exactly what a normal login does via new_logkey() — does NOT end an
     * existing PHP session for that user, because isValid() never reads the
     * database and never compares user_logkey against fs_users.log_key.
     *
     * If an impersonation flow rotates the target's key expecting it to kill
     * the target's other sessions, it will NOT do so.
     */
    #[Test]
    public function rotatedAwayLogKeyDoesNotInvalidateSession(): void
    {
        $manager = $this->makeManager();
        $this->seedIdentity($manager, [
            'user_nick' => 'victim',
            'user_logkey' => 'rotated-away-key',
            'login_time' => time(),
            'last_activity' => time(),
            'user_logged_in' => true,
        ]);

        $this->assertSame('rotated-away-key', $manager->get('user_logkey'));
        $this->assertTrue($manager->isValid(), 'log_key must not be a session validity check');
        $this->assertTrue($manager->isLoggedIn(), 'an existing session survives a log_key rotation');
    }

    // =====================================================================
    // SessionManager::isAdmin() / getCurrentRole() read the SESSION
    // snapshot, not the DB
    // =====================================================================

    /**
     * No user row is involved here at all — SessionManager::isAdmin() is
     * satisfied purely by the session value. This duplication is why any code
     * rewriting the identity must rewrite all of these session keys together
     * (user_nick, user_email, user_role, user_admin, ...); fs_auth::user()
     * re-fetches the user from the DB each request, and fs_auth::role() /
     * fs_auth::isAdmin() answer from that DB-loaded user object, not the
     * snapshot. SessionManager::isAdmin() and SessionManager::getCurrentRole()
     * are the methods that answer from the session snapshot.
     */
    #[Test]
    public function isAdminReflectsSessionSnapshotTrue(): void
    {
        $manager = $this->makeManager();
        $manager->set('user_nick', 'nobody');
        $manager->set('user_admin', true);
        $manager->set('user_role', 'admin');

        $this->assertTrue($manager->isAdmin());
        $this->assertSame('admin', $manager->getCurrentRole());
    }

    #[Test]
    public function isAdminReflectsSessionSnapshotFalse(): void
    {
        $manager = $this->makeManager();
        $manager->set('user_nick', 'nobody');
        $manager->set('user_admin', false);
        $manager->set('user_role', 'user');

        $this->assertFalse($manager->isAdmin());
        $this->assertSame('user', $manager->getCurrentRole());
    }

    #[Test]
    public function getCurrentRoleDefaultsToGuest(): void
    {
        $manager = $this->makeManager();

        $this->assertSame('guest', $manager->getCurrentRole());
    }

    // =====================================================================
    // login() duplicates identity across several session keys
    // =====================================================================

    #[Test]
    public function loginDuplicatesIdentityAcrossSessionKeys(): void
    {
        $manager = $this->makeManager();
        $manager->login([
            'nick' => 'alice',
            'email' => 'alice@example.com',
            'admin' => true,
            'logkey' => 'alice-logkey',
        ]);

        $session = $manager->getSymfonySession();
        $this->assertSame('alice', $session->get('user_nick'));
        $this->assertSame('alice@example.com', $session->get('user_email'));
        $this->assertSame('admin', $session->get('user_role'));
        $this->assertTrue($session->get('user_admin'));
        $this->assertSame('alice-logkey', $session->get('user_logkey'));
        $this->assertTrue($session->get('user_logged_in'));
        $this->assertIsInt($session->get('login_time'));
        $this->assertIsInt($session->get('last_activity'));
    }

    // =====================================================================
    // touch() slides last_activity forward
    // =====================================================================

    #[Test]
    public function touchSlidesLastActivityForward(): void
    {
        $manager = $this->makeManager();
        $stale = time() - 300;
        $this->seedIdentity($manager, [
            'login_time' => $stale,
            'last_activity' => $stale,
        ]);

        $this->assertSame($stale, $manager->get('last_activity'));

        $manager->touch();

        $this->assertGreaterThan($stale, (int) $manager->get('last_activity'));
    }

    // =====================================================================
    // logout() leaves the session invalid
    // =====================================================================

    #[Test]
    public function logoutLeavesSessionInvalid(): void
    {
        $manager = $this->makeManager();
        $manager->login([
            'nick' => 'logout-victim',
            'email' => 'logout@example.com',
            'admin' => false,
        ]);

        $this->assertTrue($manager->isValid());
        $this->assertTrue($manager->isLoggedIn());

        $manager->logout();

        $this->assertFalse($manager->isValid());
        $this->assertFalse($manager->isLoggedIn());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Build a REAL SessionManager over an array-backed Symfony session,
     * skipping its singleton constructor so no native PHP session starts.
     */
    private function makeManager(): SessionManager
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();

        $ref = new ReflectionClass(SessionManager::class);
        $manager = $ref->newInstanceWithoutConstructor();

        $this->setPrivate($manager, 'session', $session);
        $this->setPrivate($manager, 'legacyAuthBridge', new LegacyAuthBridge($session));
        $this->setPrivate($manager, 'initialized', true);

        return $manager;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function seedIdentity(SessionManager $manager, array $values): void
    {
        foreach ($values as $key => $value) {
            $manager->set($key, $value);
        }
    }

    private function setPrivate(object $instance, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($instance, $propertyName);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }
}
