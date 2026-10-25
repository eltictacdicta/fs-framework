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
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testInterceptRedirectsToPluginPublicLoginWhenStealthDisabledAndOverrideRegistered(): void
    {
        Plugins::registerPublicLoginRedirect('TestPlugin', '/oauth/login', 10);
        $GLOBALS['plugins'] = ['TestPlugin'];

        $stealth = new StealthMode($this->createDbStub(['stealth_enabled' => '0']));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/'));

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/oauth/login', $response->headers->get('Location'));
    }

    public function testInterceptRedirectsAnonymousLoginPageToPluginWhenOverrideRegistered(): void
    {
        $_GET['page'] = 'login';
        $_SERVER['REQUEST_URI'] = '/index.php?page=login';
        Plugins::registerPublicLoginRedirect('TestPlugin', '/oauth/login', 10);
        $GLOBALS['plugins'] = ['TestPlugin'];

        $stealth = new StealthMode($this->createDbStub(['stealth_enabled' => '0']));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/index.php', 'GET', ['page' => 'login']));

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/oauth/login', $response->headers->get('Location'));
    }

    public function testInterceptRedirectsToOauthLoginWhenOidcProviderOverrideRegistered(): void
    {
        Plugins::registerStealthHomeOverride('OidcProvider', '/oauth/login', 10);
        Plugins::registerPublicLoginRedirect('OidcProvider', '/oauth/login', 10);
        $GLOBALS['plugins'] = ['OidcProvider'];

        $stealth = new StealthMode($this->createDbStub(['stealth_enabled' => '0']));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/'));

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/oauth/login', $response->headers->get('Location'));
    }

    /**
     * Secure contract for VULN-0002 (AAG-01): the anonymous exemption is bound to
     * the requested page, never to the shape of the request. A credential POST to
     * `page=login` is exempt; the same POST to any other page — including a request
     * that names no page at all — is gated.
     */
    public function testInterceptAllowsLegacyLoginSubmissionWhenStealthDisabled(): void
    {
        $stealth = new StealthMode($this->createDbStub(['stealth_enabled' => '0']));
        $gate = new PublicAccessGate($stealth);

        // Legitimate login submission: the request names the login page.
        $_GET = ['page' => 'login', 'nlogin' => 'admin'];
        $_POST = ['user' => 'admin', 'password' => 'admin'];
        $_SERVER['REQUEST_URI'] = '/index.php?page=login&nlogin=admin';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->assertNull($gate->intercept(Request::create('/index.php?page=login', 'POST', [
            'user' => 'admin',
            'password' => 'admin',
        ])));

        // Bypass shape 1: `?nlogin` without a page parameter must not be exempt.
        $_GET = ['nlogin' => 'admin'];
        $_POST = [];
        $_SERVER['REQUEST_URI'] = '/index.php?nlogin=admin';

        $nloginResponse = $gate->intercept(Request::create('/index.php?nlogin=admin', 'POST'));

        $this->assertNotNull($nloginResponse);
        $this->assertSame(302, $nloginResponse->getStatusCode());
        $this->assertSame('/index.php?page=login', $nloginResponse->headers->get('Location'));

        // Bypass shape 2: a credential body without a page parameter must not be exempt.
        $_GET = [];
        $_POST = ['user' => 'admin', 'password' => 'admin'];
        $_SERVER['REQUEST_URI'] = '/index.php';

        $credentialResponse = $gate->intercept(Request::create('/index.php', 'POST', [
            'user' => 'admin',
            'password' => 'admin',
        ]));

        $this->assertNotNull($credentialResponse);
        $this->assertSame(302, $credentialResponse->getStatusCode());
        $this->assertSame('/index.php?page=login', $credentialResponse->headers->get('Location'));
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

    /**
     * AAG-01: the exemption depends on the requested page, never on the shape of
     * the request. Both bypass shapes must be gated for every non-login page.
     */
    #[DataProvider('provideNonLoginPageBypassShapes')]
    public function testInterceptGatesEveryNonLoginPageForBothBypassShapes(string $page, string $shape): void
    {
        if ($shape === 'nlogin') {
            $_GET = ['page' => $page, 'nlogin' => '1'];
            $_POST = [];
        } else {
            $_GET = ['page' => $page];
            $_POST = ['user' => 'admin', 'password' => 'admin'];
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/index.php?page=' . $page;

        $gate = new PublicAccessGate(new StealthMode($this->createDbStub(['stealth_enabled' => '0'])));

        $response = $gate->intercept(Request::create('/index.php?page=' . $page, 'POST'));

        $this->assertNotNull($response, sprintf('page=%s must be gated for the %s shape', $page, $shape));
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/index.php?page=login', $response->headers->get('Location'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideNonLoginPageBypassShapes(): array
    {
        $pages = [
            'admin_info',
            'admin_users',
            'admin_rol',
            'admin_stealth',
            'admin_email',
            'admin_updater',
            'admin_plugin_store',
            'password_reset',
            'ventas_clientes',
            'admin_oidc_customer_access',
        ];

        $cases = [];
        foreach ($pages as $page) {
            $cases[$page . ' with nlogin'] = [$page, 'nlogin'];
            $cases[$page . ' with credentials'] = [$page, 'credentials'];
        }

        return $cases;
    }

    public function testInterceptGatesAnonymousResetHandler(): void
    {
        $_GET = ['page' => 'password_reset', 'nlogin' => '1'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/index.php?page=password_reset&nlogin=1';

        $gate = new PublicAccessGate(new StealthMode($this->createDbStub(['stealth_enabled' => '0'])));

        $response = $gate->intercept(Request::create('/index.php?page=password_reset&nlogin=1', 'POST'));

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/index.php?page=login', $response->headers->get('Location'));

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('password-reset-enter-nick-or-email', $body);
        $this->assertStringNotContainsString('password-reset-too-many-attempts', $body);
    }

    public function testInterceptAllowsLoginPageSubmission(): void
    {
        $_GET = ['page' => 'login', 'nlogin' => 'admin'];
        $_POST = ['user' => 'admin', 'password' => 'admin'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/index.php?page=login';

        $gate = new PublicAccessGate(new StealthMode($this->createDbStub(['stealth_enabled' => '0'])));

        $this->assertNull($gate->intercept(Request::create('/index.php?page=login', 'POST', [
            'user' => 'admin',
            'password' => 'admin',
        ])));
    }

    /**
     * AAG-05: a framework exact public path is exempt only when the request does
     * not name a controller page, so the path the gate authorises on and the
     * parameter index.php dispatches on agree.
     */
    #[DataProvider('provideRequestMethods')]
    public function testInterceptGatesPublicPathCarryingAPrivatePage(string $method): void
    {
        $_GET = ['page' => 'password_reset'];
        $_POST = $method === 'POST' ? ['user' => 'admin', 'password' => 'admin'] : [];
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = '/favicon.ico?page=password_reset';

        $gate = new PublicAccessGate(new StealthMode($this->createDbStub(['stealth_enabled' => '0'])));

        $response = $gate->intercept(Request::create('/favicon.ico', $method));

        $this->assertNotNull($response, $method . ' /favicon.ico?page=password_reset must be gated');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/index.php?page=login', $response->headers->get('Location'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRequestMethods(): array
    {
        return [
            'GET' => ['GET'],
            'POST' => ['POST'],
        ];
    }

    public function testInterceptAllowsBrowserProbeWithoutPage(): void
    {
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/favicon.ico';

        $gate = new PublicAccessGate(new StealthMode($this->createDbStub(['stealth_enabled' => '0'])));

        $this->assertNull($gate->intercept(Request::create('/favicon.ico', 'GET')));
        $this->assertTrue(Plugins::shouldRespondNoContentForBrowserProbe(Request::create('/favicon.ico', 'GET')));
    }

    public function testInterceptAllowsPluginPublicPrefixWithAndWithoutPage(): void
    {
        Plugins::registerPublicPathPrefixes('OidcProvider', ['/oauth', '/.well-known']);
        $GLOBALS['plugins'] = ['OidcProvider'];

        $gate = new PublicAccessGate(new StealthMode($this->createDbStub(['stealth_enabled' => '0'])));

        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/oauth/callback';
        $this->assertNull($gate->intercept(Request::create('/oauth/callback', 'GET')));

        $_GET = ['page' => 'admin_info'];
        $_SERVER['REQUEST_URI'] = '/oauth/callback?page=admin_info';
        $this->assertNull($gate->intercept(Request::create('/oauth/callback?page=admin_info', 'GET')));

        $_SERVER['REQUEST_URI'] = '/.well-known/openid-configuration?page=admin_info';
        $this->assertNull($gate->intercept(Request::create('/.well-known/openid-configuration?page=admin_info', 'GET')));
    }

    /**
     * AAG-03: an access-control decision must not become an availability defect.
     * ?page[]=login makes $_GET['page'] an array; a trim() on it would be a
     * TypeError in PHP 8 and turn the gate into a 500.
     */
    public function testInterceptGatesArrayPageInputWithoutFatalError(): void
    {
        $_GET = ['page' => ['login'], 'nlogin' => '1'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/index.php?page[]=login&nlogin=1';

        $gate = new PublicAccessGate(new StealthMode($this->createDbStub(['stealth_enabled' => '0'])));

        $response = $gate->intercept(Request::create('/index.php?page[]=login&nlogin=1', 'POST'));

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/index.php?page=login', $response->headers->get('Location'));
    }

    public function testInterceptGatesInvalidPageValuesButAgreesWithTheDispatcher(): void
    {
        $gate = new PublicAccessGate(new StealthMode($this->createDbStub(['stealth_enabled' => '0'])));

        // `LOGIN` is not a canonical page for index.php either, so the request is
        // gated (fail-closed) and the redirect lands on the real login page.
        $_GET = ['page' => 'LOGIN'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/index.php?page=LOGIN';

        $invalid = $gate->intercept(Request::create('/index.php?page=LOGIN', 'GET'));

        $this->assertNotNull($invalid);
        $this->assertSame(302, $invalid->getStatusCode());
        $this->assertSame('/index.php?page=login', $invalid->headers->get('Location'));

        // A trailing space canonicalises to `login` for index.php, so the gate MUST
        // resolve it the same way instead of redirecting a legitimate login
        // submission away (AAG-02 "MUST NOT compare the raw $_GET['page'] value",
        // design D2). This assertion deliberately disagrees with the task list's
        // "?page=login%20 -> 302" bullet, which contradicts the normative
        // requirement and D2's chosen rule; see the apply report.
        $_GET = ['page' => 'login '];
        $_SERVER['REQUEST_URI'] = '/index.php?page=login%20';

        $this->assertNull($gate->intercept(Request::create('/index.php?page=login%20', 'GET')));
    }

    public function testInterceptAllowsFrameworkFaviconWhileStealthEnabled(): void
    {
        $GLOBALS['plugins'] = [];

        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/favicon.ico'));

        $this->assertNull($response);
    }

    public function testPluginsMarksBrowserProbesAsPublicPath(): void
    {
        Plugins::resetRuntimeState();
        $GLOBALS['plugins'] = [];

        $this->assertTrue(Plugins::isPublicPath('/favicon.ico'));
        $this->assertTrue(Plugins::shouldRespondNoContentForBrowserProbe(Request::create('/favicon.ico', 'GET')));
        $this->assertTrue(Plugins::shouldRespondNoContentForBrowserProbe(Request::create('/favicon.ico', 'HEAD')));
        $this->assertFalse(Plugins::shouldRespondNoContentForBrowserProbe(Request::create('/favicon.ico', 'POST')));
        $this->assertFalse(Plugins::shouldRespondNoContentForBrowserProbe(Request::create('/oauth/login', 'GET')));
    }

    /**
     * AAG-05: the 204 short-circuit must not swallow a request that names a
     * controller page, because index.php dispatches on `page=`.
     */
    public function testPluginsRefuseTheBrowserProbeShortCircuitWhenTheRequestNamesAPage(): void
    {
        Plugins::resetRuntimeState();
        $GLOBALS['plugins'] = [];

        $this->assertFalse(Plugins::shouldRespondNoContentForBrowserProbe(
            Request::create('/favicon.ico?page=password_reset', 'GET')
        ));
        $this->assertFalse(Plugins::shouldRespondNoContentForBrowserProbe(
            Request::create('/favicon.ico', 'GET', ['page' => 'password_reset'])
        ));

        // Array page input names no canonical page, so the probe still applies
        // and stays consistent with the gate.
        $this->assertTrue(Plugins::shouldRespondNoContentForBrowserProbe(
            Request::create('/favicon.ico?page[]=login', 'GET')
        ));
    }

    public function testPluginsFrameworkExactPathHasOneDefinition(): void
    {
        Plugins::resetRuntimeState();
        $GLOBALS['plugins'] = [];

        $this->assertTrue(Plugins::isFrameworkExactPublicPath('/favicon.ico'));
        $this->assertTrue(Plugins::isFrameworkExactPublicPath('/apple-touch-icon.png'));
        $this->assertTrue(Plugins::isFrameworkExactPublicPath('/apple-touch-icon-precomposed.png'));
        $this->assertFalse(Plugins::isFrameworkExactPublicPath('/oauth/login'));
        $this->assertFalse(Plugins::isFrameworkExactPublicPath('/'));

        // isPublicPath() resolves the framework exact paths through the same
        // predicate, so the allowlist literal lives in one place.
        $this->assertTrue(Plugins::isPublicPath('/favicon.ico'));
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

    public function testInterceptRedirectsToPluginOverrideWhenStealthEnabledAndOverrideRegistered(): void
    {
        Plugins::registerStealthHomeOverride('TestPlugin', '/custom/login', 10);
        $GLOBALS['plugins'] = ['TestPlugin'];

        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/'));

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/custom/login', $response->headers->get('Location'));
    }

    public function testInterceptShowsHomepageWhenStealthEnabledButNoOverrideRegistered(): void
    {
        $GLOBALS['plugins'] = [];

        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/'));

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<html lang="es">', (string) $response->getContent());
    }

    public function testInterceptUsesHighestPriorityOverrideWhenMultipleRegistered(): void
    {
        Plugins::registerStealthHomeOverride('PluginA', '/plugin-a/login', 5);
        Plugins::registerStealthHomeOverride('PluginB', '/plugin-b/login', 20);
        Plugins::registerStealthHomeOverride('PluginC', '/plugin-c/login', 10);
        $GLOBALS['plugins'] = ['PluginA', 'PluginB', 'PluginC'];

        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/'));

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/plugin-b/login', $response->headers->get('Location'));
    }

    public function testInterceptIgnoresOverrideFromDisabledPlugin(): void
    {
        Plugins::registerStealthHomeOverride('DisabledPlugin', '/disabled/login', 100);
        Plugins::registerStealthHomeOverride('EnabledPlugin', '/enabled/login', 10);
        $GLOBALS['plugins'] = ['EnabledPlugin'];

        $stealth = new StealthMode($this->createDbStub([
            'stealth_enabled' => '1',
            'stealth_param_name' => 'adminpanel',
            'stealth_param_value' => 'secret-token',
        ]));
        $gate = new PublicAccessGate($stealth);

        $response = $gate->intercept(Request::create('/'));

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/enabled/login', $response->headers->get('Location'));
    }

    public function testPluginsRejectUnsafePublicLoginRedirectsAndResetState(): void
    {
        Plugins::registerPublicLoginRedirect('UnsafeJs', 'javascript:alert(1)', 100);
        Plugins::registerPublicLoginRedirect('UnsafeHost', 'https://example.com/login', 90);
        Plugins::registerPublicLoginRedirect('EnabledPlugin', '/enabled/login', 10);
        $GLOBALS['plugins'] = ['UnsafeJs', 'UnsafeHost', 'EnabledPlugin'];

        $this->assertSame('/enabled/login', Plugins::getPublicLoginRedirect());

        Plugins::resetRuntimeState();
        $GLOBALS['plugins'] = ['EnabledPlugin'];

        $this->assertNull(Plugins::getPublicLoginRedirect());
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
                if ($sql === '') {
                    return false;
                }

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

            public function exec($sql, $transaction = null, $params = [], $batch = false)
            {
                return true;
            }
        };
    }
}

