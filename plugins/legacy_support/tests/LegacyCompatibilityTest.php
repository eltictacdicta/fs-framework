<?php

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
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
/**
 * Tests para el puente de compatibilidad legacy.
 */

namespace Tests\LegacySupport;

use FSFramework\Plugins\legacy_support\LegacyCompatibility;
use FSFramework\Plugins\legacy_support\LegacyUsageTracker;
use PHPUnit\Framework\TestCase;

class LegacyCompatibilityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once FS_FOLDER . '/plugins/legacy_support/LegacyUsageTracker.php';
        require_once FS_FOLDER . '/plugins/legacy_support/LegacyCompatibility.php';
    }

    protected function setUp(): void
    {
        LegacyUsageTracker::reset();
    }

    protected function tearDown(): void
    {
        LegacyUsageTracker::reset();
    }

    public function testReportDeprecatedComponentRegistersCompatibilityUsage(): void
    {
        LegacyCompatibility::reportDeprecatedComponent('legacy.router', 'generateLegacyUrl', 'Router::generate()');

        $summary = LegacyUsageTracker::getSummary();

        $this->assertSame(1, $summary['totals']['component_hits']);
        $this->assertSame(1, $summary['totals']['unique_components']);
        $this->assertSame(
            ['legacy.router:generateLegacyUrl' => 1],
            $summary['top_components']
        );
    }

    public function testVerifyAndUpgradeLegacyPasswordMigratesMatchingUser(): void
    {
        $user = new class() {
            public string $password;
            public string $updatedPassword = '';
            public int $saveCalls = 0;

            public function __construct()
            {
                $this->password = sha1(mb_strtolower('SecretPass', 'UTF8'));
            }

            public function __call(string $name, array $arguments): void
            {
                if ($name !== 'set_password') {
                    throw new \BadMethodCallException('Unexpected method: ' . $name);
                }

                $this->updatedPassword = (string) ($arguments[0] ?? '');
                $property = 'password';
                $this->{$property} = 'rehashed-value';
            }

            public function save(): bool
            {
                ++$this->saveCalls;
                return true;
            }
        };

        $result = LegacyCompatibility::verifyAndUpgradeLegacyPassword($user, 'SecretPass');
        $summary = LegacyUsageTracker::getSummary();

        $this->assertTrue($result);
        $this->assertSame('SecretPass', $user->updatedPassword);
        $this->assertSame(1, $user->saveCalls);
        $this->assertSame(1, $summary['totals']['component_hits']);
        $this->assertSame(1, $summary['component_groups']['Autenticación legacy']);
        $this->assertSame(
            ['legacy.auth:legacy_password_fallback' => 1],
            $summary['top_components']
        );
    }

    public function testVerifyLegacyPasswordAlsoMatchesMd5(): void
    {
        $this->assertTrue(LegacyCompatibility::verifyLegacyPassword(md5('demo-pass'), 'demo-pass'));
    }

    public function testVerifyLegacyPasswordMatchesSaltedSha1(): void
    {
        $salt = 'legacy-salt';

        $this->assertTrue(
            LegacyCompatibility::verifyLegacyPassword(sha1($salt . 'demo-pass'), 'demo-pass', $salt)
        );
    }
}
