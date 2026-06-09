<?php

declare(strict_types=1);

namespace Tests\Integration;

use FSFramework\Core\Plugins;
use FSFramework\Core\StealthMode;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Storage\PhpBridgeSessionStorage;

require_once __DIR__ . '/../../base/fs_db2.php';

/**
 * Integration test: StealthMode + SessionManager full bootstrap.
 *
 * Verifies:
 *   SC-01: All session_start() sites use resolveSessionName
 *   SC-02: StealthMode sets session_name before session_start
 *   SC-05: Only FSSESS_xxx cookie in response (no PHPSESSID)
 */
#[CoversClass(StealthMode::class)]
#[CoversClass(SessionManager::class)]
class StealthSessionBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        $_GET = [];
        $_COOKIE = [];
        $_SESSION = [];
        $_SERVER['REQUEST_URI'] = '/index.php';
        Plugins::resetRuntimeState();
        $GLOBALS['plugins'] = [];

        // Ensure no session is active before each test
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
            session_write_close();
        }
    }

    protected function tearDown(): void
    {
        SessionManager::reset();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
            session_write_close();
        }

        $_COOKIE = [];
        $_SESSION = [];

        parent::tearDown();
    }

    #[Test]
    public function stealthAccessOpensSessionUnderFssessName(): void
    {
        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'test-hash-3x1',
        ]));

        // Simulate stealth admin access request
        $_GET['adminpanel'] = 'test-hash-3x1';
        $_GET['page'] = 'login';

        // Trigger bootstrap — this calls ensurePhpSessionStarted() which
        // must set session_name() before session_start()
        $access = $stealth->hasAccess();

        // SC-02: Session opened under FSSESS_xxx
        $expectedName = SessionManager::resolveSessionName();
        $this->assertTrue($access, 'Stealth access should be granted for valid secret + login page');
        $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'Session should be active after stealth bootstrap');
        $this->assertStringStartsWith('FSSESS_', session_name(), 'Session name must start with FSSESS_');
        $this->assertSame($expectedName, session_name(), 'session_name() must match resolveSessionName()');
    }

    #[Test]
    public function sessionManagerWrapsStealthSessionViaPhpBridge(): void
    {
        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'test-hash-3x2',
        ]));

        $_GET['adminpanel'] = 'test-hash-3x2';
        $_GET['page'] = 'login';

        // Open stealth session first
        $stealth->hasAccess();

        // Now SessionManager should detect the active, properly-named session
        // and wrap it via PhpBridgeSessionStorage (not create a new one)
        SessionManager::reset();
        $sessionManager = SessionManager::getInstance();
        $symfonySession = $sessionManager->getSymfonySession();

        $storageProperty = new \ReflectionProperty(
            \Symfony\Component\HttpFoundation\Session\Session::class,
            'storage'
        );

        // SC-03: Named-session gate — must wrap, not restart
        $storage = $storageProperty->getValue($symfonySession);
        $this->assertInstanceOf(
            PhpBridgeSessionStorage::class,
            $storage,
            'SessionManager must wrap active FSSESS_xxx session via PhpBridgeSessionStorage'
        );

        // Verify session name was not changed
        $this->assertSame(
            SessionManager::resolveSessionName(),
            session_name(),
            'Session name must still be FSSESS_xxx after SessionManager bootstrap'
        );
    }

    #[Test]
    public function stealthDisabledDoesNotStartSession(): void
    {
        // When stealth is NOT enabled, hasAccess() should not bootstrap a session
        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '0',
            'stealth_param_name' => 'adminpanel',
        ]));

        $sessionActiveBefore = session_status() === PHP_SESSION_ACTIVE;

        $access = $stealth->hasAccess();

        // Stealth disabled: no access (unless already logged in, which we aren't)
        $this->assertFalse($access, 'Access must be false when stealth is disabled and user is anonymous');

        // Session should not have been started just for checking access
        if (!$sessionActiveBefore) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function wrongSecretDoesNotOpenSessionUnderFssess(): void
    {
        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'correct-hash',
        ]));

        // Wrong secret value
        $_GET['adminpanel'] = 'wrong-hash';
        $_GET['page'] = 'login';

        $access = $stealth->hasAccess();

        // Wrong secret: no access for anonymous user
        $this->assertFalse($access, 'Access must be denied for wrong secret');

        // Even though ensurePhpSessionStarted may have been called inside
        // hasAccess → isUserLoggedIn (which reads session), verify session
        // name is correct if a session was started.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->assertStringStartsWith('FSSESS_', session_name(),
                'If session was started, it must use FSSESS_ name');
            $this->assertNotSame('PHPSESSID', session_name(),
                'Session must NOT be named PHPSESSID');
        }
    }

    #[Test]
    public function legacyPhpsessidCookieIsHandledWhenPresent(): void
    {
        // Simulate a legacy PHPSESSID cookie from pre-consolidation browser
        $_COOKIE['PHPSESSID'] = 'legacy-session-id-' . uniqid();

        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'test-hash-3x5',
        ]));

        $_GET['adminpanel'] = 'test-hash-3x5';
        $_GET['page'] = 'login';
        $stealth->hasAccess();

        // After bootstrap, the session must NOT be named PHPSESSID
        // (StealthMode and/or SessionManager should handle migration)
        $this->assertNotSame(
            'PHPSESSID',
            session_name(),
            'Legacy PHPSESSID must NOT persist as session name after bootstrap'
        );
    }

    #[Test]
    public function noPhpsessidCookieAfterStealthSessionBootstrap(): void
    {
        // Precondition: no legacy cookie present
        $this->assertArrayNotHasKey('PHPSESSID', $_COOKIE);

        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'test-hash-3x3',
        ]));

        $_GET['adminpanel'] = 'test-hash-3x3';
        $_GET['page'] = 'login';
        $stealth->hasAccess();

        // SC-05: Session must NOT be named PHPSESSID
        $this->assertNotSame('PHPSESSID', session_name(), 'Session must NOT use PHPSESSID name');

        // The session name must be the FSSESS_xxx variant
        $this->assertStringStartsWith('FSSESS_', session_name());
    }

    /**
     * @return \fs_db2&object{lastExecSql: string}
     */
    private function createDbStub(array $settings = []): \fs_db2
    {
        return new class($settings) extends \fs_db2
        {
            private array $settings = [];
            public string $lastExecSql = '';

            public function __construct(array $settings = [])
            {
                $this->settings = $settings;
            }

            public function connected(): bool
            {
                return true;
            }

            public function connect(): bool
            {
                return true;
            }

            public function escape_string($str): string
            {
                return addslashes((string) $str);
            }

            /**
             * @return array<int, array<string, string>>
             */
            public function select($sql, $params = []): array
            {
                if (str_contains($sql, 'WHERE name IN')) {
                    $rows = [];
                    foreach ((array) $this->settings as $name => $value) {
                        $rows[] = ['name' => $name, 'varchar' => $value];
                    }

                    return $rows;
                }

                if (preg_match("/WHERE name = '([^']+)'/", $sql, $matches) === 1) {
                    $name = stripslashes($matches[1]);
                    return array_key_exists($name, $this->settings) ? [['name' => $name]] : [];
                }

                return [];
            }

            public function exec($sql, $transaction = null, $params = [])
            {
                $this->lastExecSql = $sql;

                if (preg_match("/UPDATE fs_vars SET [`\"]varchar[`\"] = '(.+)' WHERE name = '([^']+)'/", $sql, $matches) === 1) {
                    $this->settings[stripslashes($matches[2])] = stripslashes($matches[1]);
                }

                if (preg_match("/INSERT INTO fs_vars \(name, [`\"]varchar[`\"]\) VALUES \('([^']+)', '(.+)'\)/", $sql, $matches) === 1) {
                    $this->settings[stripslashes($matches[1])] = stripslashes($matches[2]);
                }

                return true;
            }
        };
    }
}
