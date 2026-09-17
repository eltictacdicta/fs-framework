<?php
declare(strict_types=1);

/**
 * Characterization tests for fs_login::applyTrustedSessionLogin() and the
 * session identity re-stamp the framework performs on every authenticated
 * request.
 *
 * On every authenticated request fs_controller::log_in() (base/fs_controller.php:903)
 * calls fs_login::log_in(), whose trusted-session branch re-establishes the
 * controller user straight from the session and re-stamps the session
 * identity — including login_time / last_activity — via save_session_data()
 * (base/fs_login.php:527).
 *
 * These tests pin CURRENT behaviour only; nothing here is a fix. They build the
 * instance with ReflectionClass::newInstanceWithoutConstructor() and inject
 * collaborators with reflection and anonymous-class fakes, mirroring
 * tests/Base/FsLoginCharacterizationTest.php and
 * tests/Base/SessionIdentityCharacterizationTest.php. There is no database.
 */

namespace Tests\Base;

use FSFramework\Security\LegacyAuthBridge;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

require_once FS_FOLDER . '/base/fs_core_log.php';
require_once FS_FOLDER . '/base/fs_cache.php';
require_once FS_FOLDER . '/base/fs_functions.php';
require_once FS_FOLDER . '/model/core/fs_user.php';
require_once FS_FOLDER . '/base/fs_login.php';

#[CoversClass(\fs_login::class)]
class TrustedSessionRestampTest extends TestCase
{
    private const SESSION_NICK = 'session-identity-user';
    private const SESSION_LOG_KEY = 'session-log-key';

    private Session $session;
    private mixed $previousSessionManagerInstance = null;

    protected function setUp(): void
    {
        $_COOKIE = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $_SERVER['REQUEST_URI'] = '/index.php';

        // Remember the singleton so tearDown can restore it exactly as found.
        $this->previousSessionManagerInstance = $this->sessionManagerInstanceProperty()->getValue();
    }

    protected function tearDown(): void
    {
        $this->sessionManagerInstanceProperty()->setValue(null, $this->previousSessionManagerInstance);

        $_COOKIE = [];
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_URI']);
    }

    // =====================================================================
    // applyTrustedSessionLogin(): the session is the authority
    // =====================================================================

    #[Test]
    public function applyTrustedSessionLoginRejectsMismatchedSessionNick(): void
    {
        $user = $this->makeUser(self::SESSION_NICK, self::SESSION_LOG_KEY);
        $login = $this->makeLogin($user);
        $this->seedSessionIdentity('some-other-nick', true);

        $controllerUser = (object) ['logged_on' => false];
        $result = $this->callPrivate($login, 'applyTrustedSessionLogin', [$user, &$controllerUser]);

        $this->assertFalse($result);
        $this->assertFalse($user->logged_on);
        $this->assertFalse($controllerUser->logged_on);
        $this->assertNotSame($user, $controllerUser);
    }

    #[Test]
    public function applyTrustedSessionLoginRejectsSessionNotMarkedLoggedIn(): void
    {
        $user = $this->makeUser(self::SESSION_NICK, self::SESSION_LOG_KEY);
        $login = $this->makeLogin($user);
        $this->seedSessionIdentity(self::SESSION_NICK, false);

        $controllerUser = (object) ['logged_on' => false];
        $result = $this->callPrivate($login, 'applyTrustedSessionLogin', [$user, &$controllerUser]);

        $this->assertFalse($result);
        $this->assertFalse($user->logged_on);
        $this->assertFalse($controllerUser->logged_on);
    }

    #[Test]
    public function applyTrustedSessionLoginAdoptsSessionUser(): void
    {
        $user = $this->makeUser(self::SESSION_NICK, self::SESSION_LOG_KEY);
        $login = $this->makeLogin($user);
        $this->seedSessionIdentity(self::SESSION_NICK, true);

        $controllerUser = (object) ['logged_on' => false];
        $result = $this->callPrivate($login, 'applyTrustedSessionLogin', [$user, &$controllerUser]);

        $this->assertTrue($result);
        // The session's user becomes the controller user by reference.
        $this->assertSame($user, $controllerUser);
        $this->assertTrue($user->logged_on);

        // The identity written back is the session's nick, not anything else.
        $this->assertSame(self::SESSION_NICK, $this->session->get('user_nick'));
        $this->assertSame(self::SESSION_NICK, $controllerUser->nick);
        $this->assertSame(self::SESSION_LOG_KEY, $this->session->get('user_logkey'));
        $this->assertTrue($this->session->get('user_logged_in'));
    }

    /**
     * The re-stamp is why login_time cannot bound a session: save_session_data()
     * sets login_time = now on EVERY authenticated request, so login_time never
     * ages. An absolute "expires after N minutes" bound must track its own
     * timestamp; anything reading login_time would extend itself forever.
     */
    #[Test]
    public function applyTrustedSessionLoginRestampsLoginTimeAndLastActivity(): void
    {
        $user = $this->makeUser(self::SESSION_NICK, self::SESSION_LOG_KEY);
        $login = $this->makeLogin($user);

        $staleLoginTime = time() - 100000;
        $staleLastActivity = time() - 90000;
        $this->seedSessionIdentity(self::SESSION_NICK, true);
        $this->session->set('login_time', $staleLoginTime);
        $this->session->set('last_activity', $staleLastActivity);

        $calledAt = time();
        $controllerUser = (object) ['logged_on' => false];
        $result = $this->callPrivate($login, 'applyTrustedSessionLogin', [$user, &$controllerUser]);

        $this->assertTrue($result);
        $this->assertGreaterThan($staleLoginTime, (int) $this->session->get('login_time'));
        $this->assertGreaterThan($staleLastActivity, (int) $this->session->get('last_activity'));
        $this->assertGreaterThanOrEqual($calledAt, (int) $this->session->get('login_time'));
        $this->assertGreaterThanOrEqual($calledAt, (int) $this->session->get('last_activity'));
    }

    // =====================================================================
    // Legacy cookie re-issue: observable through the $_COOKIE mirror only
    // =====================================================================

    /**
     * save_session_data() → save_cookie() → LegacyAuthBridge::issueLegacyCookies()
     * ends in writeLegacyCookies(), which calls setcookie('user'|'logkey'|'auth_sig')
     * and then mirrors those values into $_COOKIE (the "no romper nada que lea
     * $_COOKIE directamente" compatibility path).
     *
     * The setcookie()/Set-Cookie header calls are NOT observable from CLI: PHP
     * never exposes queued cookies to userland (headers_list() omits them). The
     * $_COOKIE mirror IS observable, and it is fed from the nick/logkey passed
     * down from the SESSION identity, overwriting whatever $_COOKIE already had.
     * A non-zero expiry is used so the mirror is populated rather than cleared.
     */
    #[Test]
    public function trustedSessionLoginMirrorsLegacyCookiesForSessionNick(): void
    {
        $user = $this->makeUser(self::SESSION_NICK, self::SESSION_LOG_KEY);
        $login = $this->makeLogin($user);
        $this->seedSessionIdentity(self::SESSION_NICK, true);
        $this->session->set('remember_me', true);

        $_COOKIE['user'] = 'stale-cookie-nick';
        $_COOKIE['logkey'] = 'stale-cookie-logkey';
        $_COOKIE['auth_sig'] = 'stale-cookie-signature';

        $controllerUser = (object) ['logged_on' => false];
        $result = $this->callPrivate($login, 'applyTrustedSessionLogin', [$user, &$controllerUser]);

        $this->assertTrue($result);
        // Re-issued for the session's nick/log_key, not the incoming cookie's.
        $this->assertSame(self::SESSION_NICK, $_COOKIE['user']);
        $this->assertSame(self::SESSION_LOG_KEY, $_COOKIE['logkey']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $_COOKIE['auth_sig']);
    }

    /**
     * Companion to the test above: for a non remember-me session
     * SessionPolicy::cookieExpireFor(false) returns 0 (browser-session cookie),
     * and LegacyAuthBridge::syncLegacyCookieGlobals() treats any expiry below
     * time() as "clear" — 0 included. So the $_COOKIE mirror is removed even
     * though issueLegacyCookies() was still invoked. Pinned as observed.
     */
    #[Test]
    public function trustedSessionLoginClearsLegacyCookieMirrorWhenNotRemembered(): void
    {
        $user = $this->makeUser(self::SESSION_NICK, self::SESSION_LOG_KEY);
        $login = $this->makeLogin($user);
        $this->seedSessionIdentity(self::SESSION_NICK, true);
        $this->session->set('remember_me', false);

        $_COOKIE['user'] = 'stale-cookie-nick';
        $_COOKIE['logkey'] = 'stale-cookie-logkey';
        $_COOKIE['auth_sig'] = 'stale-cookie-signature';

        $controllerUser = (object) ['logged_on' => false];
        $result = $this->callPrivate($login, 'applyTrustedSessionLogin', [$user, &$controllerUser]);

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('user', $_COOKIE);
        $this->assertArrayNotHasKey('logkey', $_COOKIE);
        $this->assertArrayNotHasKey('auth_sig', $_COOKIE);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function makeUser(string $nick, string $logKey): object
    {
        return new class($nick, $logKey) {
            public string $nick;
            public string $email = 'trusted-session@example.com';
            public bool $admin = true;
            public string $log_key;
            public bool $logged_on = false;

            public function __construct(string $nick, string $logKey)
            {
                $this->nick = $nick;
                $this->log_key = $logKey;
            }
        };
    }

    /**
     * Seed the minimum the trusted-session branch inspects:
     * user_nick === $user->nick and user_logged_in === true.
     */
    private function seedSessionIdentity(string $nick, bool $loggedIn): void
    {
        $this->session->set('user_nick', $nick);
        $this->session->set('user_logged_in', $loggedIn);
    }

    private function makeLogin(object $user): \fs_login
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start();

        $this->installControlledSessionManager($this->session);

        $ref = new ReflectionClass(\fs_login::class);
        $login = $ref->newInstanceWithoutConstructor();

        $this->setPrivate($login, 'ban_message', 'banned');
        $this->setPrivate($login, 'core_log', new class {
            public array $errors = [];

            public function new_error(string $message): void
            {
                $this->errors[] = $message;
            }

            public function save(string $message, string $channel = '', bool $important = false): void
            {
            }
        });
        $this->setPrivate($login, 'cache', new class {
            public function clean(): void
            {
            }
        });
        $this->setPrivate($login, 'ip_filter', new class {
            public function in_white_list(string $ip): bool
            {
                return true;
            }

            public function clear(): void
            {
            }

            public function is_banned(string $ip): bool
            {
                return false;
            }

            public function set_attempt(string $ip): void
            {
            }
        });
        $this->setPrivate($login, 'user_model', new class($user) {
            public function __construct(private object $user)
            {
            }

            public function get(string $nick)
            {
                return $this->user;
            }

            public function clean_cache(bool $force = false): void
            {
            }
        });
        $this->setPrivate($login, 'session', $this->session);

        return $login;
    }

    /**
     * Install a controlled SessionManager singleton bound to the same
     * array-backed session as fs_login, so getLegacyAuthBridge() returns a
     * bridge over the session under test instead of a native one.
     */
    private function installControlledSessionManager(Session $session): void
    {
        $ref = new ReflectionClass(SessionManager::class);
        $manager = $ref->newInstanceWithoutConstructor();

        $this->setPrivate($manager, 'session', $session);
        $this->setPrivate($manager, 'legacyAuthBridge', new LegacyAuthBridge($session));
        $this->setPrivate($manager, 'initialized', true);

        $this->sessionManagerInstanceProperty()->setValue(null, $manager);
    }

    private function sessionManagerInstanceProperty(): ReflectionProperty
    {
        $property = new ReflectionProperty(SessionManager::class, 'instance');
        $property->setAccessible(true);

        return $property;
    }

    private function callPrivate(object $instance, string $methodName, array $args = []): mixed
    {
        $method = new ReflectionMethod($instance, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($instance, $args);
    }

    private function setPrivate(object $instance, string $propertyName, mixed $value): void
    {
        $property = new ReflectionProperty($instance, $propertyName);
        $property->setAccessible(true);
        $property->setValue($instance, $value);
    }
}
