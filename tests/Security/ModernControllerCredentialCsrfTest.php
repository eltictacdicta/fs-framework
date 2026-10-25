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

namespace Tests\Security;

use FSFramework\Core\Base\Controller;
use FSFramework\Security\CsrfManager;
use FSFramework\Security\SessionManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Controller\Concerns\ExtractsMethodBody;

/**
 * LC-07 — a POST carrying credentials without a valid CSRF token must not
 * switch the session on the modern (FS2025) door.
 *
 * `FSFramework\Core\Base\Controller::initializeConnectedSession()` hands the
 * POST to `fs_login::log_in()` with no CSRF guard and no condition on the shape
 * of the request, so an AUTHENTICATED victim that posts the attacker's
 * `user`/`password` to any FS2025 route ends up logged into the attacker's
 * account with no token: login CSRF on a second door. The anonymous shape is
 * already gated (the gate redirects anonymous requests to a modern route),
 * which is why no unauthenticated test could reach it.
 *
 * The guard's effect is observable on the delivery: `fs_login::log_in()` must
 * not be reached. The delivery is a protected seam
 * (`deliverSessionLogin()`) because the full `initializeConnectedSession()`
 * path ends in `exit()` — which terminates the PHPUnit runner — right after
 * rendering the login form for a visitor that is not logged on (the same
 * documented limitation as tests/Base/FsControllerLogoutRedirectTest.php).
 */
final class ModernControllerCredentialCsrfTest extends TestCase
{
    use ExtractsMethodBody;

    private const CONTROLLER_PATH = '/src/Core/Base/Controller.php';

    protected function setUp(): void
    {
        parent::setUp();

        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];

        $this->resetCsrfManager();
    }

    protected function tearDown(): void
    {
        SessionManager::reset();
        $_COOKIE = [];
        $_SESSION = [];

        parent::tearDown();
    }

    #[Test]
    public function authenticatedVictimPostingForeignCredentialsWithAnInvalidTokenKeepsItsSession(): void
    {
        $victim = new ModernControllerHarness(
            Request::create('/ventas-articulo', 'POST', [
                'user' => 'attacker',
                'password' => 'attacker-secret',
                CsrfManager::FIELD_NAME => 'not-a-valid-token',
            ])
        );

        $this->assertTrue(
            $victim->controller->probeShouldSkipCredentialLogin(),
            'a credential POST without a valid token must be rejected'
        );

        $victim->controller->probeDeliverSessionLogin();

        $this->assertSame(
            0,
            $victim->loginTools->logInCalls,
            'the POST must not be handed to fs_login::log_in(): that is what switches the session'
        );
    }

    #[Test]
    public function theSamePostWithoutAnyTokenFieldKeepsTheSession(): void
    {
        $victim = new ModernControllerHarness(
            Request::create('/ventas-articulo', 'POST', [
                'user' => 'attacker',
                'password' => 'attacker-secret',
            ])
        );

        $this->assertTrue($victim->controller->probeShouldSkipCredentialLogin());

        $victim->controller->probeDeliverSessionLogin();

        $this->assertSame(0, $victim->loginTools->logInCalls);
    }

    #[Test]
    public function aCredentialPostWithAValidTokenBehavesAsToday(): void
    {
        $victim = new ModernControllerHarness(
            Request::create('/ventas-articulo', 'POST', [
                'user' => 'attacker',
                'password' => 'attacker-secret',
                CsrfManager::FIELD_NAME => CsrfManager::generateToken(),
            ])
        );

        $this->assertFalse(
            $victim->controller->probeShouldSkipCredentialLogin(),
            'a valid token must not be rejected'
        );

        $victim->controller->probeDeliverSessionLogin();

        $this->assertSame(1, $victim->loginTools->logInCalls, 'a valid token must still be delivered');
    }

    #[Test]
    public function aLegitimateModernFormPostWithNickAndPasswordIsNotAffected(): void
    {
        $controller = new ModernControllerHarness(
            Request::create('/mi-formulario', 'POST', [
                'nick' => 'admin',
                'password' => 'secret',
                CsrfManager::FIELD_NAME => CsrfManager::generateToken(),
            ])
        );

        $this->assertFalse($controller->controller->probeShouldSkipCredentialLogin());
        $controller->controller->probeDeliverSessionLogin();
        $this->assertSame(1, $controller->loginTools->logInCalls);
    }

    #[Test]
    public function aPostWithoutCredentialsIsNotRejectedEvenWithoutAToken(): void
    {
        $controller = new ModernControllerHarness(
            Request::create('/mi-formulario', 'POST', ['campo' => 'valor'])
        );

        $this->assertFalse(
            $controller->controller->probeShouldSkipCredentialLogin(),
            'only a credential submission is guarded'
        );
    }

    #[Test]
    public function aGetRequestIsNotRejected(): void
    {
        $controller = new ModernControllerHarness(Request::create('/ventas-articulo', 'GET'));

        $this->assertFalse($controller->controller->probeShouldSkipCredentialLogin());
        $controller->controller->probeDeliverSessionLogin();
        $this->assertSame(1, $controller->loginTools->logInCalls, 'the session restore path must keep working');
    }

    /**
     * LC-07 scenario "el predicado de credenciales es el compartido": the guard
     * must consume fs_auth_request::hasCredentialSubmission() and keep no local
     * duplicate of the credential shape.
     */
    #[Test]
    public function theCredentialPredicateIsTheSharedDefinition(): void
    {
        $body = $this->normalizedMethodBody('shouldSkipCredentialLogin');

        $this->assertStringContainsString('fs_auth_request::hasCredentialSubmission(', $body);
        $this->assertStringNotContainsString("['password']", $body, 'no local duplicate of the credential shape');
        $this->assertStringNotContainsString('->has(', $body, 'no local duplicate of the credential shape');
    }

    /**
     * The guard must use the controller's own CSRF helper, and it must sit
     * before the delivery inside `initializeConnectedSession()`.
     */
    #[Test]
    public function theGuardUsesTheControllersCsrfHelper(): void
    {
        $body = $this->normalizedMethodBody('shouldSkipCredentialLogin');

        $this->assertStringContainsString('validateFormToken()', $body);
    }

    #[Test]
    public function initializeConnectedSessionRoutesTheDeliveryThroughTheGuard(): void
    {
        $body = $this->normalizedMethodBody('initializeConnectedSession');

        $this->assertStringContainsString('$this->deliverSessionLogin();', $body);
        $this->assertStringNotContainsString(
            '$this->login_tools->log_in(',
            $body,
            'the raw delivery must not remain unguarded in initializeConnectedSession()'
        );

        $delivery = $this->normalizedMethodBody('deliverSessionLogin');
        $guard = strpos($delivery, '$this->shouldSkipCredentialLogin()');
        $handOver = strpos($delivery, '$this->login_tools->log_in(');

        $this->assertNotFalse($guard, 'the delivery must consult the guard');
        $this->assertNotFalse($handOver, 'the delivery must hand the POST to fs_login');
        $this->assertLessThan($handOver, $guard, 'the guard must run before the POST reaches fs_login');
    }

    private function normalizedMethodBody(string $method): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . self::CONTROLLER_PATH);
        $this->assertNotFalse($source, self::CONTROLLER_PATH . ' must be readable');

        return (string) preg_replace('/\s+/', ' ', $this->methodBody((string) $source, $method));
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
 * Builds the modern base controller with everything the guard touches replaced:
 * the request, the login tools (the delivery spy) and the user.
 */
final class ModernControllerHarness
{
    public object $controller;

    public ModernLoginToolsSpy $loginTools;

    public function __construct(Request $request)
    {
        $this->loginTools = new ModernLoginToolsSpy();
        $user = new class extends \fs_user {
            public function __construct()
            {
                $this->logged_on = false;
            }
        };

        $this->controller = new class($request, $this->loginTools, $user) extends Controller {
            public function __construct(
                Request $request,
                ModernLoginToolsSpy $loginTools,
                \fs_user $user
            ) {
                $this->request = $request;
                $this->className = 'ventas_articulo';
                $this->user = $user;
                $this->core_log = new ModernControllerCoreLog();

                $loginToolsProperty = new \ReflectionProperty(Controller::class, 'login_tools');
                $loginToolsProperty->setAccessible(true);
                $loginToolsProperty->setValue($this, $loginTools);
            }

            public function probeShouldSkipCredentialLogin(): bool
            {
                return $this->shouldSkipCredentialLogin();
            }

            public function probeDeliverSessionLogin(): void
            {
                parent::deliverSessionLogin();
            }
        };
    }
}

/**
 * Counts the deliveries of the POST to fs_login::log_in().
 */
final class ModernLoginToolsSpy
{
    public int $logInCalls = 0;

    public function log_in(\fs_user $user): bool
    {
        $this->logInCalls++;

        return false;
    }

    public function log_out(bool $rmuser = false): void
    {
    }
}

/**
 * The error list the modern base controller reads, without fs_core_log's
 * static state.
 */
final class ModernControllerCoreLog
{
    /** @var list<string> */
    public array $errors = [];

    public function controller_name(): string
    {
        return 'ventas_articulo';
    }

    public function new_error(string $msg, array $context = []): void
    {
        $this->errors[] = $msg;
    }

    public function new_message(string $msg, array $context = []): void
    {
    }

    public function new_advice(string $msg, array $context = []): void
    {
    }

    public function save(string $msg, string $type = 'error', bool $alert = false, array $context = []): void
    {
    }

    public function set_user_nick(?string $nick): void
    {
    }

    public function user_nick(): string
    {
        return '';
    }
}
