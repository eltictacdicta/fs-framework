<?php
declare(strict_types=1);

/**
 * Characterization tests for the legacy login entry point
 * (base/fs_login.php).
 *
 * fs_login::__construct() calls `new fs_user()` and `new fs_cache()` with no
 * injection point, so these tests build the instance with
 * ReflectionClass::newInstanceWithoutConstructor() and inject anonymous-class
 * collaborators, then call the private methods with reflection. This mirrors
 * the established pattern in tests/Base/LoginSuperglobalsTest.php and
 * tests/Security/FsLogin*Test.php.
 *
 * These tests pin CURRENT behaviour only; nothing here is a fix.
 */

namespace Tests\Base;

use FSFramework\Security\LoginThrottle;
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
class FsLoginCharacterizationTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $_COOKIE = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    // =====================================================================
    // log_in_user(): disabled user
    // =====================================================================

    #[Test]
    public function logInUserRejectsDisabledUser(): void
    {
        $nick = 'disabled-characterization-user';
        LoginThrottle::clear($nick);

        $user = new class() {
            public string $nick = 'disabled-characterization-user';
            public bool $enabled = false;
            public bool $admin = false;
        };

        $logger = $this->makeLogger();
        $login = $this->makeLogin($user, $logger);
        $controllerUser = (object) ['logged_on' => false];

        $result = $this->callPrivate($login, 'log_in_user', [
            &$controllerUser,
            $nick,
            'Secret123',
            '127.0.0.1',
        ]);

        $this->assertFalse($result);
        $this->assertFalse($controllerUser->logged_on);
        $this->assertNotEmpty($logger->errors);
        $this->assertStringContainsString('desactivado', $logger->errors[0]);
    }

    // =====================================================================
    // log_in_user(): unknown user
    // =====================================================================

    #[Test]
    public function logInUserRejectsUnknownUser(): void
    {
        $nick = 'unknown-characterization-user';
        LoginThrottle::clear($nick);

        $logger = $this->makeLogger();
        // A null user makes the fake model's get() return false.
        $login = $this->makeLogin(null, $logger);
        $controllerUser = (object) ['logged_on' => false];

        $result = $this->callPrivate($login, 'log_in_user', [
            &$controllerUser,
            $nick,
            'Secret123',
            '127.0.0.1',
        ]);

        $this->assertFalse($result);
        $this->assertFalse($controllerUser->logged_on);
        $this->assertContains(LoginThrottle::GENERIC_ERROR, $logger->errors);
    }

    // =====================================================================
    // completeSuccessfulLogin(): rotates log_key and regenerates the session
    // =====================================================================

    /**
     * A successful password login rotates fs_users.log_key (via
     * fs_user::new_logkey()) and regenerates the PHP session id.
     *
     * This is why an impersonation flow must NOT reuse the normal login path
     * if it wants to leave the target's own cookies and key untouched:
     * logging in as the target rotates THEIR key and would invalidate their
     * remember-me cookies.
     */
    #[Test]
    public function successfulPasswordLoginRotatesLogKeyAndRegeneratesSession(): void
    {
        $nick = 'rotator-characterization-user';
        LoginThrottle::clear($nick);

        // Real fs_user behaviour for new_logkey()/rotate_logkey(); only the DB
        // write is stubbed out.
        $user = new class() extends \fs_user {
            public function __construct()
            {
            }

            public function save(): bool
            {
                return true;
            }
        };
        $user->nick = $nick;
        $user->email = 'rotator@example.com';
        $user->admin = true;
        $user->enabled = true;
        $user->log_key = 'original-key';
        $user->logged_on = false;
        $user->password = password_hash('Secret123', PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);

        $logger = $this->makeLogger();
        $login = $this->makeLogin($user, $logger);
        $sessionIdBefore = $this->session->getId();
        $controllerUser = (object) ['logged_on' => false];

        $result = $this->callPrivate($login, 'log_in_user', [
            &$controllerUser,
            $nick,
            'Secret123',
            '127.0.0.1',
        ]);

        $this->assertTrue($result);
        $this->assertTrue($user->logged_on);

        // log_key was rotated away from its previous value.
        $this->assertNotSame('original-key', $user->log_key);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $user->log_key);

        // The session was regenerated (session-fixation prevention).
        $this->assertNotSame($sessionIdBefore, $this->session->getId());

        // The session snapshot carries the freshly rotated key.
        $this->assertSame($user->log_key, $this->session->get('user_logkey'));
        $this->assertSame($nick, $this->session->get('user_nick'));
        $this->assertTrue($this->session->get('user_logged_in'));
    }

    // =====================================================================
    // Cookie path validates log_key with hash_equals (fs_login.php:266)
    // =====================================================================

    #[Test]
    public function cookieLoginFailsWhenLogKeyDoesNotMatch(): void
    {
        $user = $this->makeCookieUser('expected-logkey');
        $logger = $this->makeLogger();
        $login = $this->makeLogin($user, $logger);
        $controllerUser = (object) ['logged_on' => false];

        $result = $this->callPrivate($login, 'applyValidCookieLogin', [
            $user,
            'wrong-logkey',
            '',
            &$controllerUser,
        ]);

        $this->assertFalse($result);
        $this->assertFalse($user->logged_on);
        $this->assertFalse($this->session->has('user_nick'));
    }

    #[Test]
    public function cookieLoginSucceedsWhenLogKeyMatches(): void
    {
        $user = $this->makeCookieUser('matching-logkey');
        $logger = $this->makeLogger();
        $login = $this->makeLogin($user, $logger);
        $controllerUser = (object) ['logged_on' => false];

        $result = $this->callPrivate($login, 'applyValidCookieLogin', [
            $user,
            'matching-logkey',
            '',
            &$controllerUser,
        ]);

        $this->assertTrue($result);
        $this->assertTrue($user->logged_on);
        $this->assertSame($user, $controllerUser);
        $this->assertSame('matching-logkey', $this->session->get('user_logkey'));
        $this->assertSame($user->nick, $this->session->get('user_nick'));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function makeCookieUser(string $logKey): object
    {
        return new class($logKey) {
            public string $nick = 'cookie-characterization-user';
            public string $email = 'cookie@example.com';
            public bool $admin = false;
            public bool $enabled = true;
            public string $log_key;
            public bool $logged_on = false;

            public function __construct(string $logKey)
            {
                $this->log_key = $logKey;
            }

            public function update_login(): void
            {
                // No-op: update_login() writes to the DB, which is out of scope here.
            }
        };
    }

    private function makeLogger(): object
    {
        return new class {
            public array $errors = [];
            public array $saved = [];

            public function new_error(string $message): void
            {
                $this->errors[] = $message;
            }

            public function save(string $message, string $channel = '', bool $important = false): void
            {
                $this->saved[] = $message;
            }
        };
    }

    private function makeLogin(?object $user, object $logger): \fs_login
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start();

        $ref = new ReflectionClass(\fs_login::class);
        $login = $ref->newInstanceWithoutConstructor();

        $this->setPrivate($login, 'ban_message', 'banned');
        $this->setPrivate($login, 'core_log', $logger);
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
            public function __construct(private ?object $user)
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
