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
 * Tests de seguridad para RequestHelper.
 */

namespace Tests\Api;

use FSFramework\Api\Helper\RequestHelper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

class RequestHelperTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
        $_POST = [];
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_AUTH_TOKEN']);
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(RequestHelper::class);
        $property = $reflection->getProperty('request');
        $property->setAccessible(true);
        $property->setValue(null, null);

        parent::tearDown();
    }

    public function testGetAuthTokenReadsBearerHeader(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secure-token';

        $this->assertSame('secure-token', RequestHelper::getAuthToken());
    }

    public function testGetAuthTokenIgnoresQueryAndPostParameters(): void
    {
        $_GET['token'] = 'query-token';
        $_POST['token'] = 'post-token';

        $this->assertNull(RequestHelper::getAuthToken());
    }

    public function testGetParamUsesSymfonyRequestAndEmitsDeprecation(): void
    {
        RequestHelper::setRequest(new Request(['page' => 'from-query'], ['page' => 'from-post']));

        $capturedDeprecation = null;
        set_error_handler(static function (int $severity, string $message) use (&$capturedDeprecation): bool {
            if ($severity === E_USER_DEPRECATED) {
                $capturedDeprecation = $message;
                return true;
            }

            return false;
        });

        try {
            $value = RequestHelper::getParam('page');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('from-query', $value);
        $this->assertIsString($capturedDeprecation);
        $this->assertStringContainsString('getParam() está deprecado', $capturedDeprecation);
    }
}
