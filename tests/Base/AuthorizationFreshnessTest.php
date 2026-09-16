<?php
declare(strict_types=1);

/**
 * Characterization tests for authorization freshness: where the authorization
 * decision actually comes from, and whether it follows live state (the
 * DB-loaded fs_user object) or a stale session snapshot.
 *
 * These tests pin CURRENT behaviour of:
 *
 *  1. fs_user::have_access_to() / get_menu() — page access is evaluated
 *     against the user object's state at call time. On a real request
 *     fs_controller builds a fresh `new fs_user()` from the DB and gates the
 *     page with have_access_to() (base/fs_controller.php:253), falling back to
 *     the access_denied template when it returns false
 *     (base/fs_controller.php:271).
 *  2. fs_auth::isAdmin() — reads the fs_user object, not the session snapshot.
 *  3. fs_auth::role() — mixes both sources and keeps reporting 'admin' from the
 *     stale session snapshot for a demoted admin.
 *  4. fs_maintenance_mode::hasAdminSession() — trusts the session snapshot and
 *     never consults the database.
 *  5. fs_user has no load_from_session() method even though
 *     controller/login.php:131 calls it.
 *
 * Nothing here is a fix. The tests exist to pin what is there today.
 */

namespace Tests\Base;

use FSFramework\Security\LegacyAuthBridge;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

require_once FS_FOLDER . '/base/fs_core_log.php';
require_once FS_FOLDER . '/base/fs_functions.php';
require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/model/core/fs_user.php';
require_once FS_FOLDER . '/base/fs_session_manager.php';
require_once FS_FOLDER . '/base/fs_auth.php';
require_once FS_FOLDER . '/base/fs_maintenance_mode.php';

#[CoversClass(\FSFramework\model\fs_user::class)]
#[CoversClass(\fs_auth::class)]
#[CoversClass(\fs_maintenance_mode::class)]
class AuthorizationFreshnessTest extends TestCase
{
    private mixed $originalSessionManagerInstance = null;

    private mixed $originalAuthUser = null;

    protected function setUp(): void
    {
        // No legacy cookie restore callback must leak between tests.
        LegacyAuthBridge::resetSkipLegacyCookieRestoreCheck();

        // Capture the process-wide singletons so the harness can restore them.
        $this->originalSessionManagerInstance = $this->getStatic(SessionManager::class, 'instance');
        $this->originalAuthUser = $this->getStatic(\fs_auth::class, 'currentUser');

        // Keep the legacy cookie path inert (no DB, no fs_user lookup).
        $_COOKIE = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    protected function tearDown(): void
    {
        $this->setStatic(SessionManager::class, 'instance', $this->originalSessionManagerInstance);
        $this->setStatic(\fs_auth::class, 'currentUser', $this->originalAuthUser);

        LegacyAuthBridge::resetSkipLegacyCookieRestoreCheck();

        $_COOKIE = [];
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    // =====================================================================
    // 1. Page authorization follows the user object's state at call time
    // =====================================================================

    /**
     * have_access_to() grants a page purely from the $menu cached on the user
     * object. The controller calls it with $this->page->name, so access is a
     * property of the user object as it currently stands.
     */
    #[Test]
    public function haveAccessToGrantsOnlyPagesInTheCurrentMenu(): void
    {
        $user = $this->makeBareUser();
        $this->setPrivate($user, 'menu', [
            (object) ['name' => 'ventas_clientes'],
        ]);

        $this->assertTrue($user->have_access_to('ventas_clientes'));
        $this->assertFalse($user->have_access_to('admin_users'));
    }

    /**
     * The decision lives nowhere but in the object's current state: mutate the
     * $menu and the next call answers differently. A demoted user comes back
     * from the DB with a smaller menu on the next request, so access is lost
     * then — not by mutation of the authorization logic itself.
     */
    #[Test]
    public function haveAccessToFollowsTheMenuMutatedBetweenCalls(): void
    {
        $user = $this->makeBareUser();
        $this->setPrivate($user, 'menu', [(object) ['name' => 'ventas_clientes']]);
        $this->assertTrue($user->have_access_to('ventas_clientes'));
        $this->assertFalse($user->have_access_to('admin_users'));

        // The next request rebuilds the user (from the DB) with a new menu.
        $this->setPrivate($user, 'menu', [(object) ['name' => 'admin_home']]);

        $this->assertFalse($user->have_access_to('ventas_clientes'));
        $this->assertTrue($user->have_access_to('admin_home'));
    }

    #[Test]
    public function haveAccessToIsFalseForAnEmptyMenu(): void
    {
        $user = $this->makeBareUser();
        $this->setPrivate($user, 'menu', []);

        $this->assertFalse($user->have_access_to('ventas_clientes'));
    }

    /**
     * get_menu() returns the cached $menu as-is; it only rebuilds when the
     * property is unset or $reload is true (model/core/fs_user.php:334).
     * Rebuilding the admin menu requires fs_page::all() (a database query), so
     * only the cached branch is characterized here.
     */
    #[Test]
    public function getMenuReturnsTheCachedMenuWithoutRebuilding(): void
    {
        $user = $this->makeBareUser();
        $cached = [(object) ['name' => 'ventas_clientes']];
        $this->setPrivate($user, 'menu', $cached);

        $this->assertSame($cached, $user->get_menu());
    }

    /**
     * The cache check runs before the admin check, so a cached $menu is
     * authoritative for the lifetime of the object: setting admin = true does
     * not widen access. Freshness therefore depends on rebuilding the object
     * per request, not on the object re-checking $this->admin on every call.
     */
    #[Test]
    public function adminFlagDoesNotWidenACachedMenu(): void
    {
        $user = $this->makeBareUser();
        $this->setPrivate($user, 'menu', [(object) ['name' => 'ventas_clientes']]);
        $user->admin = true;

        $this->assertTrue($user->admin);
        $this->assertTrue($user->have_access_to('ventas_clientes'));
        $this->assertFalse($user->have_access_to('admin_users'));
    }

    // =====================================================================
    // 2. fs_auth::isAdmin() reads the fs_user object, not the session
    // =====================================================================

    /**
     * fs_auth::isAdmin() resolves the user through fs_auth::user(), which is
     * the object fetched (and cached) by the legacy user service, and then
     * reads $user->admin. It ignores the session's user_admin snapshot.
     */
    #[Test]
    public function authIsAdminIgnoresTheSessionAdminSnapshot(): void
    {
        $this->activateSession($this->identity('demoted', true, 'admin'));
        $this->installAuthUser('demoted', false);

        // The session snapshot still claims admin...
        $this->assertTrue(\fs_session_manager::isAdmin());

        // ...but fs_auth::isAdmin() answers from the user object.
        $this->assertFalse(\fs_auth::isAdmin());
    }

    #[Test]
    public function authIsAdminFollowsTheUserObjectAdminFlagAtCallTime(): void
    {
        $this->activateSession($this->identity('someone', false, 'user'));
        $this->installAuthUser('someone', false);
        $this->assertFalse(\fs_auth::isAdmin());

        // Replace the DB-loaded user object with one whose admin flag is true.
        $this->installAuthUser('someone', true);
        $this->assertTrue(\fs_auth::isAdmin());
    }

    // =====================================================================
    // 3. fs_auth::role() mixes DB state and the session snapshot
    // =====================================================================

    /**
     * The latent trap: a demoted admin (user->admin === false) whose session
     * still holds user_role = 'admin' keeps getting 'admin' from role(),
     * because role() falls back to fs_session_manager::getCurrentRole(), which
     * reads the session snapshot. isAdmin() and role() disagree.
     */
    #[Test]
    public function authRoleFallsBackToTheStaleSessionRoleForADemotedAdmin(): void
    {
        $this->activateSession($this->identity('demoted', false, 'admin'));
        $this->installAuthUser('demoted', false);

        $this->assertFalse(\fs_auth::isAdmin());
        $this->assertSame('admin', \fs_session_manager::getCurrentRole());
        $this->assertSame('admin', \fs_auth::role());
    }

    /**
     * The other half of the mixed source: when the user object IS an admin,
     * role() returns 'admin' from the DB branch even though the session
     * snapshot still says 'user'.
     */
    #[Test]
    public function authRoleReturnsAdminWhenTheUserObjectIsAdmin(): void
    {
        $this->activateSession($this->identity('member', false, 'user'));
        $this->installAuthUser('member', true);

        $this->assertTrue(\fs_auth::isAdmin());
        $this->assertSame('user', \fs_session_manager::getCurrentRole());
        $this->assertSame('admin', \fs_auth::role());
    }

    // =====================================================================
    // 4. fs_maintenance_mode::hasAdminSession() trusts the session snapshot
    // =====================================================================

    #[Test]
    public function maintenanceBypassAcceptsAnAdminSessionSnapshot(): void
    {
        $this->assertTrue(\fs_maintenance_mode::hasAdminSession($this->identity('admin', true, 'admin')));
    }

    /**
     * The snapshot is consulted without any database check, and user_role =
     * 'admin' alone is enough: a demoted admin (user_admin = false) still
     * passes the maintenance-mode bypass as long as the session row says so.
     */
    #[Test]
    public function maintenanceBypassAcceptsAdminRoleEvenWithUserAdminFalse(): void
    {
        $session = [
            'user_nick' => 'demoted',
            'user_admin' => false,
            'user_role' => 'admin',
            'user_logged_in' => true,
            'login_time' => time(),
            'last_activity' => time(),
        ];

        $this->assertTrue(\fs_maintenance_mode::hasAdminSession($session));
    }

    #[Test]
    public function maintenanceBypassRejectsANonAdminSession(): void
    {
        $this->assertFalse(\fs_maintenance_mode::hasAdminSession($this->identity('user', false, 'user')));
    }

    #[Test]
    public function maintenanceBypassRejectsAnExpiredAdminSession(): void
    {
        $session = [
            'user_nick' => 'stale',
            'user_admin' => true,
            'user_role' => 'admin',
            'user_logged_in' => true,
            'login_time' => time() - 100000,
            'last_activity' => time() - 100000,
        ];

        $this->assertFalse(\fs_maintenance_mode::hasAdminSession($session));
    }

    #[Test]
    public function maintenanceBypassReadsTheModernNestedAttributes(): void
    {
        $session = [
            '_sf2_attributes' => [
                'user_admin' => true,
                'user_logged_in' => true,
                'login_time' => time(),
                'last_activity' => time(),
            ],
        ];

        $this->assertTrue(\fs_maintenance_mode::hasAdminSession($session));
    }

    #[Test]
    public function maintenanceBypassRejectsAnEmptySession(): void
    {
        $this->assertFalse(\fs_maintenance_mode::hasAdminSession([]));
    }

    // =====================================================================
    // 5. fs_user::load_from_session() does not exist
    // =====================================================================

    /**
     * controller/login.php:131 calls $this->user->load_from_session() behind
     * the multi-DB switch, but no such method exists. Pinned with
     * method_exists() so the missing method is documented without triggering
     * the fatal error the call site would produce.
     */
    #[Test]
    public function fsUserHasNoLoadFromSessionMethod(): void
    {
        $this->assertTrue(
            class_exists(\fs_user::class),
            'the global fs_user alias must resolve through the model autoloader'
        );

        $this->assertFalse(method_exists(\fs_user::class, 'load_from_session'));
        $this->assertFalse(method_exists(\FSFramework\model\fs_user::class, 'load_from_session'));
    }

    /**
     * There is no magic __call fallback on fs_user or fs_model either, so the
     * missing method is a real fatal error on that code path.
     */
    #[Test]
    public function fsUserAndFsModelHaveNoMagicCallFallback(): void
    {
        $this->assertFalse(method_exists(\FSFramework\model\fs_user::class, '__call'));
        $this->assertFalse(method_exists(\fs_model::class, '__call'));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Build an fs_user with no constructor (no DB connection) while keeping
     * the real have_access_to()/get_menu() implementation.
     */
    private function makeBareUser(): object
    {
        $ref = new ReflectionClass(\FSFramework\model\fs_user::class);

        return $ref->newInstanceWithoutConstructor();
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(string $nick, bool $admin, string $role): array
    {
        return [
            'user_nick' => $nick,
            'user_admin' => $admin,
            'user_role' => $role,
            'user_logged_in' => true,
            'login_time' => time(),
            'last_activity' => time(),
        ];
    }

    /**
     * Install a REAL SessionManager over an array-backed Symfony session as the
     * process-wide singleton, seeded with the given identity. No database.
     *
     * @param array<string, mixed> $identity
     */
    private function activateSession(array $identity): SessionManager
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();

        $ref = new ReflectionClass(SessionManager::class);
        $manager = $ref->newInstanceWithoutConstructor();
        $this->setPrivate($manager, 'session', $session);
        $this->setPrivate($manager, 'legacyAuthBridge', new LegacyAuthBridge($session));
        $this->setPrivate($manager, 'initialized', true);

        foreach ($identity as $key => $value) {
            $manager->set($key, $value);
        }

        $this->setStatic(SessionManager::class, 'instance', $manager);

        return $manager;
    }

    /**
     * Replace the fs_auth static user cache with a stand-in for the object
     * fs_auth::user() would have fetched from the DB.
     */
    private function installAuthUser(string $nick, bool $admin): void
    {
        $user = new class ($nick, $admin) {
            public function __construct(
                public string $nick,
                public bool $admin
            ) {
            }
        };

        $this->setStatic(\fs_auth::class, 'currentUser', $user);
    }

    private function setPrivate(object $instance, string $propertyName, mixed $value): void
    {
        $property = new ReflectionProperty($instance, $propertyName);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }

    private function getStatic(string $class, string $propertyName): mixed
    {
        $property = new ReflectionProperty($class, $propertyName);
        $property->setAccessible(true);

        return $property->getValue();
    }

    private function setStatic(string $class, string $propertyName, mixed $value): void
    {
        $property = new ReflectionProperty($class, $propertyName);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}
