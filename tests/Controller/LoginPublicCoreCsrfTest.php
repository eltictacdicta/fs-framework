<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
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
 */

namespace Tests\Controller;

use FSFramework\Core\Plugins;
use FSFramework\Security\CsrfManager;
use FSFramework\Security\LoginThrottle;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Controller\Concerns\ExtractsMethodBody;

if (!defined('FS_LAZY_MODELS')) {
    define('FS_LAZY_MODELS', true);
}

require_once dirname(__DIR__, 2) . '/base/fs_auth_request.php';
require_once dirname(__DIR__, 2) . '/base/fs_controller.php';
require_once dirname(__DIR__, 2) . '/controller/login.php';

/**
 * LC-06 — the CSRF verdict is authoritative and a credential submission is
 * evaluated at most once per request.
 *
 * The request lifecycle runs `fs_controller::log_in()` first (from the
 * controller constructor) and `login::public_core()` afterwards. Today
 * `log_in()` rejects a credential POST whose token is invalid, and
 * `public_core()` hands the very same POST to `fs_login` again — so the flow
 * runs twice and the second run is unvalidated (VULN-0003). The guard lives
 * inside `process_login_logic()`, after the plugin public-login redirect and
 * before the credential branch (design D7).
 *
 * What this test can and cannot observe in-process:
 *  - It observes the two credential-evaluation sites (`fs_login::log_in()` via
 *    the controller's login tools, and `fs_user::login()` via the credential
 *    branch) and the error list handed to the login form.
 *  - It cannot observe a successful login's redirect: `handleCredentialLogin()`
 *    ends in `redirectToSafeUrl()` -> `exit()`, which terminates the PHPUnit
 *    runner (the same documented limitation as
 *    tests/Base/FsControllerLogoutRedirectTest.php). The successful-login path
 *    is pinned by the source assertions instead, and by the existing LC-03
 *    redirect tests.
 *
 * The doubles mirror the only effect this test measures: `fs_login::log_in()`
 * emits `LoginThrottle::GENERIC_ERROR` once per failed credential evaluation
 * (base/fs_login.php:352,387), and `fs_user::login()` delegates to
 * `fs_login::log_in()` (model/core/fs_user.php:494).
 */
final class LoginPublicCoreCsrfTest extends TestCase
{
    use ExtractsMethodBody;

    private const CSRF_MISSING_ERROR = 'Sesión expirada o token de seguridad faltante. Por favor, recarga la página.';

    private const CSRF_INVALID_ERROR = 'Token de seguridad inválido. Por favor, recarga la página.';

    private RecordingCoreLog $coreLog;

    private LoginToolsSpy $loginTools;

    private UserSpy $user;

    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        Plugins::resetRuntimeState();
        $GLOBALS['plugins'] = [];
        $_COOKIE = [];
        $_SESSION = [];
        $_POST = [];
        $_GET = ['page' => 'login'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/index.php?page=login';

        $this->resetCsrfManager();

        $this->coreLog = new RecordingCoreLog();
        $this->loginTools = new LoginToolsSpy($this->coreLog);
        $this->user = new UserSpy($this->coreLog);
    }

    protected function tearDown(): void
    {
        Plugins::resetRuntimeState();
        $GLOBALS['plugins'] = [];
        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];
        $_POST = [];
        $_GET = [];

        parent::tearDown();
    }

    #[Test]
    public function invalidTokenCredentialPostNeverReachesTheCredentialBranch(): void
    {
        $controller = $this->makeController(
            Request::create('/index.php?page=login', 'POST', [
                'user' => 'attacker',
                'password' => 'secret',
                CsrfManager::FIELD_NAME => 'not-a-valid-token',
            ])
        );

        $this->runRequest($controller);

        $this->assertSame(0, $this->loginTools->logInCalls, 'an invalid token must not be handed to fs_login');
        $this->assertSame(0, $this->user->loginCalls, 'an invalid token must not reach the credential branch');
        $this->assertSame(
            [self::CSRF_INVALID_ERROR],
            $this->coreLog->errors,
            'the CSRF error must be the only message: no credential evaluation may happen'
        );
        $this->assertNull($controller->mensajeLogin(), 'the form must not report a credential failure');
    }

    #[Test]
    public function missingTokenFieldBehavesExactlyLikeAnInvalidToken(): void
    {
        $controller = $this->makeController(
            Request::create('/index.php?page=login', 'POST', [
                'user' => 'attacker',
                'password' => 'secret',
            ])
        );

        $this->runRequest($controller);

        $this->assertSame(0, $this->loginTools->logInCalls);
        $this->assertSame(0, $this->user->loginCalls);
        $this->assertSame([self::CSRF_MISSING_ERROR], $this->coreLog->errors);
        $this->assertNull($controller->mensajeLogin());
    }

    #[Test]
    public function validTokenWithWrongCredentialsEvaluatesTheCredentialExactlyOnce(): void
    {
        $controller = $this->makeController($this->credentialPost('admin', 'wrong', CsrfManager::generateToken()));

        $this->runRequest($controller);

        $this->assertSame(
            1,
            $this->credentialEvaluations(),
            'the credential must be evaluated exactly once per request (it used to be evaluated twice)'
        );
        $this->assertSame(
            [LoginThrottle::GENERIC_ERROR],
            $this->coreLog->errors,
            'the credential-evaluation message must appear exactly once in the response (it used to appear twice)'
        );
    }

    #[Test]
    public function validTokenCredentialPostIsStillDeliveredToFsLogin(): void
    {
        $this->loginTools->accept = true;
        $controller = $this->makeController($this->credentialPost('admin', 'right', CsrfManager::generateToken()));

        $loggedOn = $controller->runLogIn();

        $this->assertTrue($loggedOn, 'a valid token and correct credentials must still authenticate');
        $this->assertSame(1, $this->loginTools->logInCalls, 'the credential POST must reach fs_login exactly once');
        $this->assertSame(0, $this->user->loginCalls, 'the credential branch must not evaluate it a second time');
        $this->assertTrue(
            $controller->probeCredentialLoginEvaluated(),
            'log_in() must record that it handed the credential POST to fs_login'
        );
    }

    #[Test]
    public function getRequestRendersTheFormWithoutEvaluatingCredentials(): void
    {
        $controller = $this->makeController(Request::create('/index.php?page=login', 'GET'));

        $this->runRequest($controller);

        $this->assertSame('login.html.twig', $controller->template, 'the login form must still be rendered');
        $this->assertSame(0, $this->user->loginCalls);
        $this->assertSame([], $this->coreLog->errors, 'a GET must not be rejected by the guard');
    }

    #[Test]
    public function getRequestWithAutologinStillReachesTheAutoLoginBranch(): void
    {
        $controller = $this->makeController(Request::create('/index.php?page=login&autologin=token-123', 'GET'));

        $this->runRequest($controller);

        $this->assertSame(1, $this->user->loginFromCookieCalls, '?autologin= must keep working');
    }

    #[Test]
    public function getRequestWithLogoutStillLogsOut(): void
    {
        $controller = $this->makeController(Request::create('/index.php?page=login&logout=1', 'GET'));

        $this->runRequest($controller);

        $this->assertSame(1, $this->user->logoutCalls, '?logout=1 must keep working');
    }

    #[Test]
    public function pluginPublicLoginRedirectIsHonouredForAnAnonymousPost(): void
    {
        Plugins::registerPublicLoginRedirect('OidcProvider', '/oauth/login', 10);
        $GLOBALS['plugins'] = ['OidcProvider'];

        $controller = $this->makeController(
            Request::create('/index.php?page=login', 'POST', [
                'user' => 'attacker',
                'password' => 'secret',
                CsrfManager::FIELD_NAME => 'not-a-valid-token',
            ])
        );

        try {
            $controller->runLogIn();
            $controller->runPublicCore();
            $this->fail('The plugin public-login redirect must be honoured before any credential work');
        } catch (PublicLoginRedirected $redirect) {
            $this->assertSame('/oauth/login', $redirect->url);
        }

        $this->assertSame(0, $this->user->loginCalls, 'the redirect must win over the credential branch');
    }

    #[Test]
    public function postWithoutCredentialsAndWithoutTokenIsNotRejectedByTheGuard(): void
    {
        $controller = $this->makeController(
            Request::create('/index.php?page=login&nlogin=', 'POST', ['nlogin' => ''])
        );

        $controller->runLogIn();

        $this->assertFalse(
            $controller->probeShouldSkipCredentialLogin(),
            'a POST without credentials must not be rejected: the predicate reads isCsrfValid(), never requireCsrf()'
        );
        $this->assertSame([], $this->coreLog->errors);
    }

    #[Test]
    public function theGuardReadsTheRecordedVerdictInsteadOfRequiringANewOne(): void
    {
        $controller = $this->makeController($this->credentialPost('admin', 'wrong', CsrfManager::generateToken()));

        $controller->runLogIn();
        $this->assertTrue(
            $controller->probeShouldSkipCredentialLogin(),
            'once log_in() evaluated the credential POST the guard must skip the second evaluation'
        );

        $invalid = $this->makeController(
            Request::create('/index.php?page=login', 'POST', [
                'user' => 'admin',
                'password' => 'wrong',
                CsrfManager::FIELD_NAME => 'not-a-valid-token',
            ])
        );
        $invalid->runLogIn();
        $this->assertTrue(
            $invalid->probeShouldSkipCredentialLogin(),
            'the false returned by validateCsrf() is final'
        );
    }

    /**
     * The guard's placement is normative (LC-06): inside
     * `process_login_logic()`, after the plugin public-login redirect and
     * before the credential branch. A guard at the top of `public_core()`
     * would also block the plugin redirect, which the change must preserve.
     */
    #[Test]
    public function theGuardSitsInsideProcessLoginLogicBetweenThePublicRedirectAndTheCredentialBranch(): void
    {
        $body = $this->normalizedMethodBody('/controller/login.php', 'process_login_logic');

        $guard = strpos($body, '$this->shouldSkipCredentialLogin()');
        $publicRedirect = strpos($body, '$this->redirectToPublicLogin(');
        $credentialBranch = strpos($body, '$this->handleCredentialLogin(');

        $this->assertNotFalse($guard, 'process_login_logic() must consult the guard');
        $this->assertNotFalse($publicRedirect, 'the plugin public-login redirect must stay in place');
        $this->assertNotFalse($credentialBranch, 'the credential branch must stay in place');

        $this->assertGreaterThan($publicRedirect, $guard, 'the guard must sit after the plugin public-login redirect');
        $this->assertLessThan($credentialBranch, $guard, 'the guard must sit before the credential branch');
    }

    /**
     * A successful login redirects from the `logged_on` branch, which stays
     * ahead of the guard: skipping the credential branch for an already
     * evaluated POST must not cost the post-login redirect (LC-03).
     */
    #[Test]
    public function theSuccessfulLoginRedirectStaysAheadOfTheGuard(): void
    {
        $body = $this->normalizedMethodBody('/controller/login.php', 'process_login_logic');

        $loggedOnRedirect = strpos($body, 'if ($this->user->logged_on)');
        $guard = strpos($body, '$this->shouldSkipCredentialLogin()');

        $this->assertNotFalse($loggedOnRedirect);
        $this->assertNotFalse($guard);
        $this->assertLessThan($guard, $loggedOnRedirect, 'a successful login must still redirect as today');
    }

    #[Test]
    public function theGuardPredicateReadsIsCsrfValidAndNeverRequiresANewValidation(): void
    {
        $body = $this->normalizedMethodBody('/controller/login.php', 'shouldSkipCredentialLogin');

        $this->assertStringContainsString('isCsrfValid()', $body);
        $this->assertStringNotContainsString('requireCsrf(', $body);
    }

    #[Test]
    public function theCredentialPredicateIsTheSharedDefinition(): void
    {
        $body = $this->normalizedMethodBody('/base/fs_controller.php', 'requestHasCredentials');

        $this->assertStringContainsString('fs_auth_request::hasCredentialSubmission(', $body);
        $this->assertStringNotContainsString("has('password')", $body, 'no local duplicate of the credential shape');
    }

    #[Test]
    public function logInRecordsTheDeliveryOfTheCredentialPostToFsLogin(): void
    {
        $body = $this->normalizedMethodBody('/base/fs_controller.php', 'log_in');

        $this->assertStringContainsString('credentialLoginEvaluated = true', $body);
    }

    private function credentialPost(string $nick, string $password, ?string $token = null): Request
    {
        $fields = ['user' => $nick, 'password' => $password];
        if ($token !== null) {
            $fields[CsrfManager::FIELD_NAME] = $token;
        }

        return Request::create('/index.php?page=login', 'POST', $fields);
    }

    private function credentialEvaluations(): int
    {
        return $this->loginTools->logInCalls + $this->user->loginCalls;
    }

    /**
     * Mirrors the constructor of fs_controller for the login page: log_in()
     * runs first, and public_core() only when the visitor is still anonymous.
     */
    private function runRequest(object $controller): void
    {
        if (!$controller->runLogIn()) {
            $controller->runPublicCore();
        }
    }

    private function normalizedMethodBody(string $relativePath, string $method): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . $relativePath);
        $this->assertNotFalse($source, $relativePath . ' must be readable');

        return (string) preg_replace('/\s+/', ' ', $this->methodBody((string) $source, $method));
    }

    private function makeController(Request $request): object
    {
        return new class($request, $this->coreLog, $this->loginTools, $this->user) extends \login {
            public ?string $publicLoginRedirect = null;

            public function __construct(
                Request $request,
                RecordingCoreLog $coreLog,
                LoginToolsSpy $loginTools,
                UserSpy $user
            ) {
                $this->request = $request;
                $this->core_log = $coreLog;
                $this->class_name = 'login';
                $this->user = $user;
                $this->db = new LoginFlowDbStub();
                $this->template = '';

                $loginTools->request = $request;

                $loginToolsProperty = new \ReflectionProperty(\fs_controller::class, 'login_tools');
                $loginToolsProperty->setValue($this, $loginTools);
            }

            public function runLogIn(): bool
            {
                $logIn = new \ReflectionMethod(\fs_controller::class, 'log_in');
                $logIn->setAccessible(true);

                return (bool) $logIn->invoke($this);
            }

            public function runPublicCore(): void
            {
                $this->public_core();
            }

            public function probeShouldSkipCredentialLogin(): bool
            {
                return $this->shouldSkipCredentialLogin();
            }

            public function probeCredentialLoginEvaluated(): bool
            {
                return $this->credentialLoginEvaluated();
            }

            public function mensajeLogin(): ?string
            {
                return $this->mensaje_login ?? null;
            }

            protected function redirectToPublicLogin(string $url): void
            {
                $this->publicLoginRedirect = $url;

                // The real implementation redirects and exits; the marker keeps
                // the "the redirect wins" observable without killing the runner.
                throw new PublicLoginRedirected($url);
            }
        };
    }

    private function resetCsrfManager(): void
    {
        $reflection = new \ReflectionClass(CsrfManager::class);

        foreach (['manager', 'session'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }

        $tokenVerified = $reflection->getProperty('tokenVerified');
        $tokenVerified->setAccessible(true);
        $tokenVerified->setValue(null, false);
    }
}

/**
 * The error list the login form reads, isolated from fs_core_log's shared
 * static state: every accessor the controllers use is overridden.
 */
final class RecordingCoreLog extends \fs_core_log
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $saved = [];

    public function __construct(private string $name = 'login')
    {
    }

    public function controller_name()
    {
        return $this->name;
    }

    public function new_error($msg, $context = [])
    {
        $this->errors[] = (string) $msg;
    }

    public function new_message($msg, $context = [])
    {
    }

    public function new_advice($msg, $context = [])
    {
    }

    public function save($msg, $type = 'error', $alert = FALSE, $context = [])
    {
        $this->saved[] = (string) $msg;
    }

    public function set_user_nick($nick)
    {
    }

    public function user_nick()
    {
        return '';
    }
}

/**
 * Stands in for `fs_login` as the controller's login tools. It counts the
 * deliveries of the credential POST and mirrors the generic credential error
 * the real `fs_login::log_in()` emits on a failed evaluation.
 */
final class LoginToolsSpy
{
    public int $logInCalls = 0;

    public int $logOutCalls = 0;

    public bool $accept = false;

    public ?Request $request = null;

    public function __construct(private RecordingCoreLog $coreLog)
    {
    }

    public function log_in(\fs_user $user): bool
    {
        $this->logInCalls++;

        // fs_login::log_in() only evaluates credentials when the POST carried
        // them (base/fs_login.php:139); otherwise it restores the session from
        // the cookie/session and reports no credential error.
        if (!$this->carriesCredentials()) {
            return false;
        }

        if (!$this->accept) {
            $this->coreLog->new_error(LoginThrottle::GENERIC_ERROR);
            return false;
        }

        $user->logged_on = true;
        return true;
    }

    public function log_out(bool $rmuser = false): void
    {
        $this->logOutCalls++;
    }

    private function carriesCredentials(): bool
    {
        return $this->request !== null
            && \fs_auth_request::hasCredentialSubmission($this->request->getMethod(), $this->request->request->all());
    }
}

/**
 * Stands in for `fs_user`. `login()` models the delegation to
 * `fs_login::log_in()` (model/core/fs_user.php:494): it counts the second
 * credential-evaluation site and mirrors the error the delegation emits.
 */
final class UserSpy extends \fs_user
{
    public int $loginCalls = 0;

    public int $loginFromCookieCalls = 0;

    public int $logoutCalls = 0;

    public function __construct(private RecordingCoreLog $coreLog)
    {
        $this->logged_on = false;
    }

    public function login(string $nick, string $password): bool
    {
        $this->loginCalls++;
        $this->coreLog->new_error(LoginThrottle::GENERIC_ERROR);

        return false;
    }

    public function login_from_cookie(string $autologin): bool
    {
        $this->loginFromCookieCalls++;

        return false;
    }

    public function logout(): void
    {
        $this->logoutCalls++;
        $this->logged_on = false;
    }

    public function get_menu($reload = false)
    {
        return [];
    }
}

/**
 * StealthMode reads its settings through fs_db2 and gives up before querying
 * when the connection is unavailable; that is the "stealth disabled" case this
 * test needs.
 */
final class LoginFlowDbStub extends \fs_db2
{
    public function __construct()
    {
    }

    public function connected()
    {
        return false;
    }

    public function connect()
    {
        return false;
    }
}

/**
 * Marker for "the plugin public-login redirect ended the request".
 */
final class PublicLoginRedirected extends \RuntimeException
{
    public function __construct(public readonly string $url)
    {
        parent::__construct('plugin public login redirect: ' . $url);
    }
}
