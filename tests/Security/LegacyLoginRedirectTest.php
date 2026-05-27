<?php

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Core\Plugins;
use FSFramework\Core\StealthMode;
use PHPUnit\Framework\TestCase;

if (!defined('FS_LAZY_MODELS')) {
    define('FS_LAZY_MODELS', true);
}

require_once dirname(__DIR__, 2) . '/base/fs_controller.php';
require_once dirname(__DIR__, 2) . '/controller/login.php';

final class LegacyLoginRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Plugins::resetRuntimeState();
        $GLOBALS['plugins'] = [];
        $_COOKIE = [];
        $_SESSION = [];
        $_POST = [];
        $_GET = ['page' => 'login'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/index.php?page=login';
    }

    protected function tearDown(): void
    {
        Plugins::resetRuntimeState();
        $GLOBALS['plugins'] = [];
        $_COOKIE = [];
        $_SESSION = [];
        $_POST = [];
        $_GET = [];

        parent::tearDown();
    }

    public function testAnonymousUserRedirectsToPluginLoginWithoutStealthUnlock(): void
    {
        Plugins::registerPublicLoginRedirect('OidcProvider', '/oauth/login', 10);
        $GLOBALS['plugins'] = ['OidcProvider'];

        $controller = $this->createController([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]);

        $this->assertSame('/oauth/login', $controller->probeAnonymousPublicLoginRedirect());
    }

    public function testLoggedOnUserSkipsAnonymousRedirectGuard(): void
    {
        Plugins::registerPublicLoginRedirect('OidcProvider', '/oauth/login', 10);
        $GLOBALS['plugins'] = ['OidcProvider'];

        $controller = $this->createController([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ], true);

        $this->assertNull($controller->probeAnonymousPublicLoginRedirect());
    }

    public function testStealthSecretRequestSkipsAnonymousRedirectGuard(): void
    {
        Plugins::registerPublicLoginRedirect('OidcProvider', '/oauth/login', 10);
        $GLOBALS['plugins'] = ['OidcProvider'];
        $_GET['adminpanel'] = 'secret-token';
        $_SERVER['REQUEST_URI'] = '/index.php?page=login&adminpanel=secret-token';

        $controller = $this->createController([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]);

        $this->assertNull($controller->probeAnonymousPublicLoginRedirect());
    }

    private function createController(array $stealthSettings, bool $loggedOn = false): object
    {
        $stealth = new StealthMode($this->createDbStub($stealthSettings));
        $user = $this->createUserStub($loggedOn);

        return new class($stealth, $user) extends \login {
            public function __construct(private readonly StealthMode $stealth, \fs_user $user)
            {
                $this->user = $user;
            }

            public function probeAnonymousPublicLoginRedirect(): ?string
            {
                return $this->resolveAnonymousPublicLoginRedirect();
            }

            protected function createStealthMode(): StealthMode
            {
                return $this->stealth;
            }
        };
    }

    private function createUserStub(bool $loggedOn): \fs_user
    {
        return new class($loggedOn) extends \fs_user {
            public function __construct(bool $loggedOn)
            {
                $this->logged_on = $loggedOn;
            }
        };
    }

    /**
     * @return \fs_db2&object
     */
    private function createDbStub(array $settings): \fs_db2
    {
        return new class($settings) extends \fs_db2 {
            public function __construct(private readonly array $settings)
            {
            }

            public function connected()
            {
                return true;
            }

            public function connect()
            {
                return true;
            }

            public function escape_string($str)
            {
                return addslashes((string) $str);
            }

            public function select($sql, $params = [])
            {
                if ($sql === '') {
                    return false;
                }

                if (str_contains($sql, 'WHERE name IN')) {
                    $rows = [];
                    foreach ($this->settings as $name => $value) {
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
                return true;
            }
        };
    }
}