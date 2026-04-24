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

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/base/fs_controller.php';
require_once dirname(__DIR__, 2) . '/controller/login.php';

final class LoginInitialCredentialsMessageTest extends TestCase
{
    public function testInitialCredentialsMessageIncludesTemporaryPassword(): void
    {
        $message = LoginMessageHarness::buildMessage([
            'nick' => 'admin',
            'password' => 'Secret<123>',
        ]);

        self::assertStringContainsString('Usuario: <code>admin</code>', $message);
        self::assertStringContainsString('Contraseña temporal: <code>Secret&lt;123&gt;</code>', $message);
        self::assertStringContainsString('solo hasta el primer acceso correcto', $message);
    }
}

final class LoginMessageHarness extends \login
{
    public static function buildMessage(array $credentials): string
    {
        return parent::buildInitialCredentialsMessage($credentials);
    }
}
