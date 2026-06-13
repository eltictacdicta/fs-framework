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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/model/core/fs_user.php';
require_once dirname(__DIR__, 2) . '/model/fs_var.php';

/**
 * Tests the first-login force-password-change flow.
 *
 * Verifies that:
 * - When initial setup is pending, the login flow sets the force_password_change session flag
 * - When initial setup is completed, no flag is set (existing installs unaffected)
 * - The session flag contract matches what shouldForcePasswordChange() expects
 */
#[CoversClass(\fs_user::class)]
final class FirstLoginForcePasswordTest extends TestCase
{
    private const VAR_NAME = 'initial_admin_setup';

    protected function setUp(): void
    {
        $fsVar = new \fs_var();
        $fsVar->simple_delete(self::VAR_NAME);

        unset($_SESSION['force_password_change'], $_SESSION['force_password_change_reason']);
    }

    protected function tearDown(): void
    {
        $fsVar = new \fs_var();
        $fsVar->simple_delete(self::VAR_NAME);

        unset($_SESSION['force_password_change'], $_SESSION['force_password_change_reason']);
    }

    /**
     * Simulates the conditional branch in log_in_user() after successful auth.
     */
    private function simulateLoginInitialSetupBranch(): void
    {
        if (class_exists('fs_user') && \fs_user::isInitialSetupPending()) {
            $_SESSION['force_password_change'] = true;
            $_SESSION['force_password_change_reason'] = 'initial_setup';
        }
    }

    #[Test]
    public function pendingSetupSetsForcePasswordFlag(): void
    {
        $fsVar = new \fs_var();
        $fsVar->simple_save(self::VAR_NAME, 'pending');

        self::assertTrue(\fs_user::isInitialSetupPending());

        $this->simulateLoginInitialSetupBranch();

        self::assertTrue($_SESSION['force_password_change']);
        self::assertSame('initial_setup', $_SESSION['force_password_change_reason']);
    }

    #[Test]
    public function completedSetupDoesNotSetFlag(): void
    {
        $fsVar = new \fs_var();
        $fsVar->simple_save(self::VAR_NAME, 'completed');

        self::assertFalse(\fs_user::isInitialSetupPending());

        $this->simulateLoginInitialSetupBranch();

        self::assertNull($_SESSION['force_password_change'] ?? null);
        self::assertNull($_SESSION['force_password_change_reason'] ?? null);
    }

    #[Test]
    public function noVarDoesNotSetFlag(): void
    {
        // No fs_var set at all — simulates pre-migration state
        self::assertFalse(\fs_user::isInitialSetupPending());

        $this->simulateLoginInitialSetupBranch();

        self::assertNull($_SESSION['force_password_change'] ?? null);
    }
}
