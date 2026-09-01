<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
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

namespace Tests\Security;

use FSFramework\Security\SecretManager;
use PHPUnit\Framework\TestCase;

/**
 * Guards the test-environment contract of SecretManager (R2):
 * the bootstrap-defined FS_SECRET_KEY must satisfy the >= 32 char
 * validation so getSecret() never silently degrades to a file fallback.
 */
class SecretManagerValidationTest extends TestCase
{
    protected function setUp(): void
    {
        SecretManager::resetCache();
    }

    protected function tearDown(): void
    {
        SecretManager::resetCache();
    }

    public function testSecretKeyConstantIsDefined(): void
    {
        // Runtime env-contract guard: statically true because the bootstrap
        // defines the constant, but it protects PHPUnit runs against regressions.
        // @phpstan-ignore-next-line method.alreadyNarrowedType
        $this->assertTrue(
            defined('FS_SECRET_KEY'),
            'tests/bootstrap.php must define FS_SECRET_KEY'
        );
    }

    public function testSecretKeyConstantMeetsMinimumLength(): void
    {
        $this->assertGreaterThanOrEqual(
            32,
            strlen((string) constant('FS_SECRET_KEY')),
            'FS_SECRET_KEY defined by tests/bootstrap.php must be at least 32 characters'
        );
    }

    public function testGetSecretReturnsBootstrapSecret(): void
    {
        $secret = SecretManager::getSecret();

        // getSecret() is typed string, so a null assertion is redundant.
        $this->assertGreaterThanOrEqual(32, strlen($secret));
        $this->assertSame(constant('FS_SECRET_KEY'), $secret);
    }

    public function testHmacWorksOnValidSecret(): void
    {
        $hmac = SecretManager::hmac('test-context');

        $this->assertNotEmpty($hmac);
    }

    public function testSymfonyPhpunitBridgeIsRegistered(): void
    {
        // Con PHPUnit >= 10 el puente se integra vía SymfonyExtension
        // (registrada como extensión en phpunit.xml), no vía el listener legacy.
        $this->assertTrue(
            interface_exists(\PHPUnit\Runner\Extension\Extension::class),
            'PHPUnit 11 extension API expected'
        );
        $this->assertTrue(
            class_exists(\Symfony\Bridge\PhpUnit\SymfonyExtension::class),
            'symfony/phpunit-bridge must be installed and autoloadable'
        );
        $this->assertInstanceOf(
            \PHPUnit\Runner\Extension\Extension::class,
            new \Symfony\Bridge\PhpUnit\SymfonyExtension()
        );
    }
}
