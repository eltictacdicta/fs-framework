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

require_once dirname(__DIR__, 2) . '/model/core/fs_user.php';
require_once dirname(__DIR__, 2) . '/model/fs_var.php';

/**
 * Tests para verificar que el mensaje de login no expone la contraseña temporal.
 * La contraseña solo se muestra UNA VEZ durante la instalación.
 */
final class LoginInitialCredentialsMessageTest extends TestCase
{
    private const VAR_NAME = 'initial_admin_setup';

    protected function setUp(): void
    {
        $fsVar = new \fs_var();
        $fsVar->simple_delete(self::VAR_NAME);
    }

    protected function tearDown(): void
    {
        $fsVar = new \fs_var();
        $fsVar->simple_delete(self::VAR_NAME);
    }

    public function testInitialSetupPendingDoesNotExposePassword(): void
    {
        $fsVar = new \fs_var();
        $fsVar->simple_save(self::VAR_NAME, 'pending');

        self::assertTrue(\fs_user::isInitialSetupPending());
    }

    public function testAfterLoginCompletesSetup(): void
    {
        $fsVar = new \fs_var();
        $fsVar->simple_save(self::VAR_NAME, 'pending');

        \fs_user::completeInitialSetup();

        self::assertFalse(\fs_user::isInitialSetupPending());
        self::assertSame('completed', $fsVar->simple_get(self::VAR_NAME));
    }

    public function testCompletedSetupNeverShowsMessageAgain(): void
    {
        $fsVar = new \fs_var();
        $fsVar->simple_save(self::VAR_NAME, 'completed');

        self::assertFalse(\fs_user::isInitialSetupPending());
    }
}
