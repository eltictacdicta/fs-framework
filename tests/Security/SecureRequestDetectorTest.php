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

use FSFramework\Security\SecureRequestDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class SecureRequestDetectorTest extends TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->resetTrustedProxyConfigurator();
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_PROTO);
        putenv('FS_TRUSTED_PROXIES');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $this->resetTrustedProxyConfigurator();
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_PROTO);
        putenv('FS_TRUSTED_PROXIES');

        parent::tearDown();
    }

    public function testIsSecureWhenHttpsIsOn(): void
    {
        $_SERVER['HTTPS'] = 'on';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['SERVER_PORT']);

        $this->assertTrue(SecureRequestDetector::isSecure());
    }

    public function testIsSecureWhenHttpsIsOff(): void
    {
        $_SERVER['HTTPS'] = 'off';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['SERVER_PORT']);

        $this->assertFalse(SecureRequestDetector::isSecure());
    }

    public function testIsSecureUsesForwardedProtoWhenTrustedProxyConfigured(): void
    {
        putenv('FS_TRUSTED_PROXIES=127.0.0.1');

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS']);

        $this->assertTrue(SecureRequestDetector::isSecure());

        putenv('FS_TRUSTED_PROXIES');
    }

    public function testIsSecureIgnoresForwardedProtoWithoutTrustedProxy(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS']);

        $this->assertFalse(SecureRequestDetector::isSecure());
    }

    private function resetTrustedProxyConfigurator(): void
    {
        $property = new \ReflectionProperty(\FSFramework\Security\TrustedProxyConfigurator::class, 'configured');
        $property->setAccessible(true);
        $property->setValue(null, false);
    }
}
