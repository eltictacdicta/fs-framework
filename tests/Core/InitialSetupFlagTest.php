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

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/model/core/fs_user.php';
require_once dirname(__DIR__, 2) . '/model/fs_var.php';

final class InitialSetupFlagTest extends TestCase
{
    private const VAR_NAME = 'initial_admin_setup';

    protected function setUp(): void
    {
        $this->cleanupTestFlag();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestFlag();
    }

    private function cleanupTestFlag(): void
    {
        $fsVar = new \fs_var();
        $fsVar->simple_delete(self::VAR_NAME);
    }

    private function setFlag(string $value): void
    {
        $fsVar = new \fs_var();
        $fsVar->simple_save(self::VAR_NAME, $value);
    }

    private function getFlag(): string|false
    {
        $fsVar = new \fs_var();
        return $fsVar->simple_get(self::VAR_NAME);
    }

    public function testIsInitialSetupPendingReturnsFalseWhenNoFlag(): void
    {
        self::assertFalse(\fs_user::isInitialSetupPending());
    }

    public function testIsInitialSetupPendingReturnsTrueWhenPending(): void
    {
        $this->setFlag('pending');

        self::assertTrue(\fs_user::isInitialSetupPending());
    }

    public function testIsInitialSetupPendingReturnsFalseWhenCompleted(): void
    {
        $this->setFlag('completed');

        self::assertFalse(\fs_user::isInitialSetupPending());
    }

    public function testCompleteInitialSetupChangesPendingToCompleted(): void
    {
        $this->setFlag('pending');

        $result = \fs_user::completeInitialSetup();

        self::assertTrue($result);
        self::assertSame('completed', $this->getFlag());
        self::assertFalse(\fs_user::isInitialSetupPending());
    }

    public function testCompleteInitialSetupReturnsTrueWhenAlreadyCompleted(): void
    {
        $this->setFlag('completed');

        $result = \fs_user::completeInitialSetup();

        self::assertTrue($result);
        self::assertSame('completed', $this->getFlag());
    }

    public function testCompleteInitialSetupReturnsFalseWhenNoFlag(): void
    {
        $result = \fs_user::completeInitialSetup();

        self::assertFalse($result);
    }

    public function testFlagValuesAreCorrect(): void
    {
        $this->setFlag('pending');
        self::assertTrue(\fs_user::isInitialSetupPending());

        \fs_user::completeInitialSetup();
        self::assertFalse(\fs_user::isInitialSetupPending());
        self::assertSame('completed', $this->getFlag());
    }

    public function testInvalidFlagValueTreatedAsNotPending(): void
    {
        $this->setFlag('invalid_value');

        self::assertFalse(\fs_user::isInitialSetupPending());
    }

    public function testLegacyCredentialsFileIsCleanedUpOnCheck(): void
    {
        $legacyPath = FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'initial_credentials.json';
        $dir = dirname($legacyPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($legacyPath, '{"nick":"admin","password":"secret"}');
        self::assertFileExists($legacyPath);

        \fs_user::isInitialSetupPending();

        self::assertFileDoesNotExist($legacyPath);
    }

    public function testLegacyCredentialsFileIsCleanedUpOnComplete(): void
    {
        $legacyPath = FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'initial_credentials.json';
        $dir = dirname($legacyPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->setFlag('pending');
        file_put_contents($legacyPath, '{"nick":"admin","password":"secret"}');
        self::assertFileExists($legacyPath);

        \fs_user::completeInitialSetup();

        self::assertFileDoesNotExist($legacyPath);
    }
}
