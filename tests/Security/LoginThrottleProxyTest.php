<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Security;

use FSFramework\Cache\CacheManager;
use FSFramework\Security\LoginThrottle;
use FSFramework\Security\TrustedProxyConfigurator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * N2: LoginThrottle::getClientIp() must respect FS_TRUSTED_PROXIES (via Symfony
 *     Request::getClientIp()) so that X-Forwarded-For headers cannot bypass the
 *     rate limit.
 * N3: fs_get_ip() must mirror the same trust policy (for audit-log consistency).
 *
 * These tests assert that:
 *  - Without trusted proxies, an attacker-controlled X-Forwarded-For is ignored.
 *  - With trusted proxies, the real client IP from X-Forwarded-For is honored.
 *  - The throttle key remains stable across rotated X-Forwarded-For headers
 *    because the cache key is bound to the connection-level REMOTE_ADDR.
 *  - fs_get_ip() agrees with LoginThrottle::getClientIp() when no proxy is trusted.
 */
final class LoginThrottleProxyTest extends TestCase
{
    private array $serverBackup = [];
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshot state so each test is hermetic.
        $this->serverBackup = $_SERVER;
        $this->envBackup = [
            'FS_TRUSTED_PROXIES' => getenv('FS_TRUSTED_PROXIES'),
            'FS_TRUSTED_HEADERS' => getenv('FS_TRUSTED_HEADERS'),
        ];

        // Reset the one-shot "configured" guard inside TrustedProxyConfigurator
        // so each test re-reads the (possibly test-set) FS_TRUSTED_PROXIES env.
        $this->resetTrustedProxyConfigurator();

        // Also reset the underlying Symfony Request static state: an empty list
        // of trusted proxies + 0 header set means "trust nothing forwarded".
        Request::setTrustedProxies([], 0);

        putenv('FS_TRUSTED_PROXIES');
        putenv('FS_TRUSTED_HEADERS');

        // Strip all forwarded-* headers; leave a known REMOTE_ADDR.
        unset(
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_X_REAL_IP'],
            $_SERVER['HTTP_CF_CONNECTING_IP'],
            $_SERVER['HTTP_CLIENT_IP'],
            $_SERVER['HTTP_X_FORWARDED_PROTO'],
            $_SERVER['HTTP_X_FORWARDED_HOST']
        );
        $_SERVER['REMOTE_ADDR'] = '5.6.7.8';

        // Make the throttle cache deterministic.
        CacheManager::reset();
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup: clear the test nick so we don't leak state.
        try {
            LoginThrottle::clear('spoof_test_user');
        } catch (\Throwable) {
            // ignore
        }

        CacheManager::reset();
        $this->resetTrustedProxyConfigurator();
        Request::setTrustedProxies([], 0);

        // Restore env + $_SERVER.
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        $_SERVER = $this->serverBackup;

        parent::tearDown();
    }

    /**
     * N2.1 / N2.3: no forwarded headers present → getClientIp() returns REMOTE_ADDR.
     */
    public function testGetClientIpFallsBackToRemoteAddrWithoutForwardedHeaders(): void
    {
        $this->assertSame(
            '5.6.7.8',
            $this->invokeGetClientIp(),
            'Sin headers forwarded y sin proxies configurados, getClientIp() debe devolver REMOTE_ADDR'
        );
    }

    /**
     * N2.2: an attacker-controlled X-Forwarded-For must be IGNORED when no
     * proxy is trusted. This is the core security property that the fix restores.
     */
    public function testGetClientIpIgnoresForwardedForWithoutTrustedProxies(): void
    {
        // Attacker rotates X-Forwarded-For; trust config is empty.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        $this->assertSame(
            '5.6.7.8',
            $this->invokeGetClientIp(),
            'getClientIp() debe ignorar X-Forwarded-For cuando no hay proxies confiables'
        );
    }

    /**
     * N2.1: when REMOTE_ADDR IS a trusted proxy, the X-Forwarded-For header
     * is honored and the real client IP is returned.
     */
    public function testGetClientIpHonorsTrustedProxy(): void
    {
        // Simulate a deployment behind an internal reverse proxy.
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        putenv('FS_TRUSTED_PROXIES=10.0.0.1');
        $this->resetTrustedProxyConfigurator();
        // Re-configure with the new env (configure() reads getenv and
        // calls Symfony Request::setTrustedProxies()).
        TrustedProxyConfigurator::configure();

        $this->assertSame(
            '1.2.3.4',
            $this->invokeGetClientIp(),
            'Cuando REMOTE_ADDR es un proxy confiable, getClientIp() debe devolver la IP real del cliente'
        );
    }

    /**
     * N2.4: 7 failed logins with rotating X-Forwarded-For headers must all
     * hit the same throttle bucket (keyed on REMOTE_ADDR). After the 6th
     * attempt the throttle should be active.
     */
    public function testThrottleKeyIsConsistentDespiteForwardedForSpoofing(): void
    {
        $nick = 'spoof_test_user';
        LoginThrottle::clear($nick);

        for ($i = 0; $i < 7; $i++) {
            // Each request pretends to come from a different X-Forwarded-For.
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.' . $i;
            LoginThrottle::recordFailure($nick);
        }

        $this->assertTrue(
            LoginThrottle::isThrottled($nick),
            '7 intentos con X-Forwarded-For rotativo deben activar el throttle (cache key ligada a REMOTE_ADDR)'
        );
        $this->assertGreaterThanOrEqual(
            LoginThrottle::MAX_ATTEMPTS,
            LoginThrottle::getAttemptCount($nick),
            'El contador debe reflejar todos los intentos fallidos, no estar fragmentado por IP'
        );

        LoginThrottle::clear($nick);
    }

    /**
     * N3.1: fs_get_ip() and LoginThrottle::getClientIp() must agree when no
     * proxy is trusted. They diverge when trust is misconfigured, which would
     * silently break audit logs vs security decisions.
     */
    public function testFsGetIpMatchesGetClientIpWithoutProxies(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'],
              $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_CLIENT_IP']);

        $clientIp = $this->invokeGetClientIp();

        $this->assertSame(
            '203.0.113.42',
            $clientIp,
            'getClientIp() debe devolver REMOTE_ADDR'
        );
        $this->assertSame(
            $clientIp,
            fs_get_ip(),
            'fs_get_ip() y LoginThrottle::getClientIp() deben coincidir cuando no hay proxies'
        );
    }

    private function invokeGetClientIp(): string
    {
        $ref = new \ReflectionMethod(LoginThrottle::class, 'getClientIp');
        $ref->setAccessible(true);
        // Static method → pass null as the object argument.
        return (string) $ref->invoke(null);
    }

    private function resetTrustedProxyConfigurator(): void
    {
        $property = new \ReflectionProperty(TrustedProxyConfigurator::class, 'configured');
        $property->setAccessible(true);
        $property->setValue(null, false);
    }
}
