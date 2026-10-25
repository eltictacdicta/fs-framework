<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Security;

use FSFramework\Security\SecureRequestDetector;
use FSFramework\Security\SessionManager;
use FSFramework\Security\SessionPolicy;
use FSFramework\Security\TrustedProxyConfigurator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Controller\Concerns\ExtractsMethodBody;

/**
 * STH-01 — one definition of the session cookie attribute set.
 *
 * These assertions target the INPUT seam: the exact parameter set the
 * application hands to session_set_cookie_params()/NativeSessionStorage, which
 * is what decides the emitted cookie. The emitted `Set-Cookie` header itself is
 * NOT observable here — the suite runs under the CLI SAPI, where
 * header()/setcookie() are accepted but `headers_list()` always returns []. The
 * header-level criterion ("exactly one Set-Cookie carrying Secure, HttpOnly and
 * SameSite=Lax") is the operator-owned live probe O.3, not a passing test.
 */
final class SessionCookieParamsTest extends TestCase
{
    use ExtractsMethodBody;

    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        putenv('FS_TRUSTED_PROXIES');
        $this->resetTrustedProxyConfigurator();
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_PROTO);

        unset(
            $_SERVER['HTTPS'],
            $_SERVER['HTTP_X_FORWARDED_PROTO'],
            $_SERVER['SERVER_PORT'],
            $_SERVER['SCRIPT_NAME'],
            $_SERVER['REQUEST_URI']
        );
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        putenv('FS_TRUSTED_PROXIES');
        $this->resetTrustedProxyConfigurator();
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_PROTO);

        parent::tearDown();
    }

    public function testTheParameterSetIsExactlyTheDocumentedSixKeys(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['REQUEST_URI'] = '/index.php';

        $params = SessionManager::sessionCookieParams();

        $this->assertSame(
            ['lifetime', 'path', 'domain', 'secure', 'httponly', 'samesite'],
            array_keys($params),
            'The attribute set is a fixed contract: exactly these six keys, no more and no fewer.'
        );
        $this->assertSame(SessionPolicy::getIdleTimeout(), $params['lifetime']);
        $this->assertSame(SessionManager::resolveCookiePath(), $params['path']);
        $this->assertSame('', $params['domain']);
        $this->assertFalse($params['secure'], 'An insecure request must not mark the cookie Secure.');
        $this->assertTrue($params['httponly']);
        $this->assertSame('Lax', $params['samesite']);
    }

    public function testSecureIsTrueBehindATlsTerminatingTrustedProxy(): void
    {
        putenv('FS_TRUSTED_PROXIES=127.0.0.1');
        $this->resetTrustedProxyConfigurator();

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REQUEST_URI'] = '/index.php';

        $this->assertTrue(SecureRequestDetector::isSecure(), 'Precondition: the detector classifies this request as secure.');
        $this->assertTrue(SessionManager::sessionCookieParams()['secure']);
    }

    public function testSecureIsFalseWithoutATrustedProxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REQUEST_URI'] = '/index.php';

        $this->assertFalse(SecureRequestDetector::isSecure(), 'Precondition: an untrusted X-Forwarded-Proto is ignored.');
        $this->assertFalse(SessionManager::sessionCookieParams()['secure']);
    }

    public function testPathComesFromTheSharedResolverForASubdirectoryInstall(): void
    {
        // FS_PATH is empty in the test bootstrap, so the resolver falls back to
        // SCRIPT_NAME, which is exactly how a subdirectory install resolves.
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['SCRIPT_NAME'] = '/fsf/index.php';
        $_SERVER['REQUEST_URI'] = '/fsf/index.php?page=login';

        $params = SessionManager::sessionCookieParams();

        $this->assertSame('/fsf/', $params['path']);
        $this->assertSame(SessionManager::resolveCookiePath(), $params['path']);
    }

    /**
     * STH-01 scenario "no second copy of the attribute set": the Symfony
     * storage options are derived from the shared definition instead of
     * restating the six keys.
     */
    public function testTheSymfonyStorageOptionsAreBuiltFromTheSharedDefinition(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Security/SessionManager.php');
        $body = $this->methodBody($source, 'initialize');

        $this->assertStringContainsString('sessionCookieParams(', $body);
        $this->assertStringNotContainsString('cookie_httponly', $body, 'A hardcoded attribute name is a second copy of the attribute set.');
        $this->assertStringNotContainsString('cookie_samesite', $body);
        $this->assertStringNotContainsString('cookie_secure', $body);
        $this->assertStringNotContainsString('cookie_path', $body);
        $this->assertStringNotContainsString('resolveCookiePath(', $body);
    }

    /**
     * STH-01 scenario "no second copy of the attribute set": StealthMode — the
     * outermost path that may start the session — consumes the shared
     * definition instead of restating the attributes before session_start().
     */
    public function testStealthModeConsumesTheSharedDefinition(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Core/StealthMode.php');
        $body = $this->methodBody($source, 'ensurePhpSessionStarted');

        $this->assertStringContainsString('sessionCookieParams(', $body);
        $this->assertStringContainsString('session_set_cookie_params(', $body);
        $this->assertStringContainsString('session.use_strict_mode', $body);
        $this->assertStringNotContainsString('httponly', $body);
        $this->assertStringNotContainsString('samesite', $body);
    }

    private function resetTrustedProxyConfigurator(): void
    {
        $property = new \ReflectionProperty(TrustedProxyConfigurator::class, 'configured');
        $property->setAccessible(true);
        $property->setValue(null, false);
    }
}
