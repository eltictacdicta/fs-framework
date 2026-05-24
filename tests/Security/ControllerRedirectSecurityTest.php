<?php

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Tests\Security;

use FSFramework\Core\Base\Controller;
use FSFramework\Core\Plugins;
use PHPUnit\Framework\TestCase;

class ControllerRedirectSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        Plugins::resetRuntimeState();
        $GLOBALS['plugins'] = [];

        parent::tearDown();
    }

    public function testExternalRedirectFallsBackToSafeHome(): void
    {
        $controller = $this->createController();

        $this->assertSame('index.php', $controller->resolveRedirectTarget('https://evil.example/phish'));
    }

    public function testPageNamesRemainCompatible(): void
    {
        $controller = $this->createController();

        $this->assertSame('index.php?page=login', $controller->resolveRedirectTarget('login'));
    }

    public function testDangerousProtocolFallsBackToSafeHome(): void
    {
        $controller = $this->createController();

        $this->assertSame('index.php', $controller->resolveRedirectTarget('javascript:alert(1)'));
    }

    public function testStealthOverrideBecomesFallbackWhenLocal(): void
    {
        $GLOBALS['plugins'] = ['OidcProvider'];
        Plugins::registerStealthHomeOverride('OidcProvider', '/oauth/login', 10);
        $controller = $this->createController();

        $this->assertSame('/oauth/login', $controller->resolveFallbackTarget());
        $this->assertSame('/oauth/login', $controller->resolveRedirectTarget('https://evil.example/phish'));
    }

    private function createController(): object
    {
        return new class extends Controller {
            public function __construct()
            {
            }

            public function resolveRedirectTarget(string $url): string
            {
                return $this->resolveRedirectUrl($url);
            }

            public function resolveFallbackTarget(): string
            {
                return $this->resolvePublicHomeFallback();
            }
        };
    }
}