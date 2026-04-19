<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com> (lead developer of Facturascript)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Tests\Components;

use FSFramework\Core\Plugins;
use FSFramework\Core\PublicAccessGate;
use FSFramework\Core\StealthMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../../base/fs_db2.php';

class PublicAccessGateTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_SESSION = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Plugins::resetRuntimeState();
        $GLOBALS['plugins'] = [];
    }

    public function testInterceptRedirectsPublicRootRequestToLegacyLoginWhenStealthDisabled(): void
    {
        $stealth = new StealthMode($this->createDbStub(['stealth_enabled' => '0']));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/'));

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/index.php?page=login', $response->headers->get('Location'));
    }

    public function testInterceptRedirectsAnonymousProtectedLegacyRequestToLoginWhenStealthDisabled(): void
    {
        $_GET['page'] = 'admin_home';
        $_SERVER['REQUEST_URI'] = '/index.php?page=admin_home';

        $stealth = new StealthMode($this->createDbStub(['stealth_enabled' => '0']));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/index.php', 'GET', ['page' => 'admin_home']));

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/index.php?page=login', $response->headers->get('Location'));
    }

    public function testInterceptAllowsLegacyLoginPageWhenStealthDisabled(): void
    {
        $_GET['page'] = 'login';
        $_SERVER['REQUEST_URI'] = '/index.php?page=login';

        $stealth = new StealthMode($this->createDbStub(['stealth_enabled' => '0']));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/index.php', 'GET', ['page' => 'login']));

        $this->assertNull($response);
    }

    public function testInterceptAllowsLegacyLoginSubmissionWhenStealthDisabled(): void
    {
        $_GET['nlogin'] = 'admin';
        $_POST['user'] = 'admin';
        $_POST['password'] = 'admin';
        $_SERVER['REQUEST_URI'] = '/index.php?nlogin=admin';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $stealth = new StealthMode($this->createDbStub(['stealth_enabled' => '0']));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/index.php', 'POST', ['nlogin' => 'admin'], [], [], [], http_build_query([
            'user' => 'admin',
            'password' => 'admin',
        ])));

        $this->assertNull($response);
    }

    public function testInterceptAllowsPluginManagedPublicPath(): void
    {
        Plugins::registerPublicPathPrefixes('OidcProvider', ['/oauth', '/.well-known']);
        $GLOBALS['plugins'] = ['OidcProvider'];

        $stealth = new StealthMode($this->createDbStub(['stealth_enabled' => '0']));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/oauth/authorize'));

        $this->assertNull($response);
    }

    public function testInterceptBlocksAnonymousRequestWithStealthEnabled(): void
    {
        $_GET['page'] = 'admin_home';
        $_SERVER['REQUEST_URI'] = '/index.php?page=admin_home';

        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
        ]));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/index.php', 'GET', ['page' => 'admin_home']));

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<html lang="es">', (string) $response->getContent());
    }

    public function testInterceptKeepsHomeClosedAfterStealthUnlockWithoutSecretParameter(): void
    {
        $_SESSION['stealth_unlocked'] = true;
        $_SERVER['REQUEST_URI'] = '/index.php';

        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/index.php'));

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<html lang="es">', (string) $response->getContent());
    }

    public function testInterceptAllowsHiddenLoginOnlyWhenSecretParameterIsStillPresent(): void
    {
        $_SESSION['stealth_unlocked'] = true;
        $_GET['page'] = 'login';
        $_GET['adminpanel'] = 'secret-token';
        $_SERVER['REQUEST_URI'] = '/index.php?page=login&adminpanel=secret-token';

        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/index.php', 'GET', [
            'page' => 'login',
            'adminpanel' => 'secret-token',
        ]));

        $this->assertNull($response);
    }

    public function testInterceptAllowsOidcPublicLoginWhileStealthEnabled(): void
    {
        Plugins::registerPublicPathPrefixes('OidcProvider', ['/oauth', '/.well-known', '/account']);
        $GLOBALS['plugins'] = ['OidcProvider'];

        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/oauth/login'));

        $this->assertNull($response);
    }

    public function testInterceptPrioritizesStealthAccessBeforeHomepageRendering(): void
    {
        $stealth = $this->getMockBuilder(StealthMode::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'consumeSecretEntryRedirect',
                'isPublicHomepageRequest',
                'hasAuthenticatedSession',
                'isEnabled',
                'hasAccess',
                'createPublicHomepageResponse',
            ])
            ->getMock();

        $stealth->expects($this->never())
            ->method('createPublicHomepageResponse');
        $stealth->expects($this->once())
            ->method('hasAuthenticatedSession')
            ->willReturn(false);
        $stealth->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);
        $stealth->expects($this->once())
            ->method('consumeSecretEntryRedirect')
            ->willReturn(null);
        $stealth->expects($this->once())
            ->method('hasAccess')
            ->willReturn(true);
        $stealth->expects($this->never())
            ->method('isPublicHomepageRequest');

        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/index.php', 'GET', ['adminpanel' => 'secret-token']));

        $this->assertNull($response);
    }

    /**
     * @return \fs_db2&object
     */
    private function createDbStub(array $settings = []): \fs_db2
    {
        return new class($settings) extends \fs_db2 {
            private array $settings = [];

            public function __construct(array $settings = [])
            {
                $this->settings = $settings;
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
                return true;
            }
        };
    }
}

