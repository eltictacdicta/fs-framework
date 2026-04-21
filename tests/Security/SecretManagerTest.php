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

namespace Tests\Security;

use FSFramework\Security\SecretManager;
use FSFramework\Security\Exception\MissingSecretKeyException;
use PHPUnit\Framework\TestCase;

class SecretManagerTest extends TestCase
{
    protected function setUp(): void
    {
        SecretManager::resetCache();
    }

    public function testGetSecretReturnsConfiguredFrameworkSecret(): void
    {
        $this->assertSame(constant('FS_SECRET_KEY'), SecretManager::getSecret());
    }

    public function testHmacProducesConsistentHash(): void
    {
        $data = 'test-data-for-hmac';
        $hash1 = SecretManager::hmac($data);
        $hash2 = SecretManager::hmac($data);
        
        $this->assertSame($hash1, $hash2);
        $this->assertSame(64, strlen($hash1));
    }

    public function testHmacProducesDifferentHashesForDifferentData(): void
    {
        $hash1 = SecretManager::hmac('data-one');
        $hash2 = SecretManager::hmac('data-two');
        
        $this->assertNotSame($hash1, $hash2);
    }

    public function testGetSecretFailsInReadonlyEnvironmentWithoutConfig(): void
    {
        $secretManagerPath = FS_FOLDER . '/src/Security/SecretManager.php';
        $exceptionPath = FS_FOLDER . '/src/Security/Exception/MissingSecretKeyException.php';
        $php = escapeshellarg(PHP_BINARY);

        $code = <<<'PHP'
putenv('FS_SECRET_KEY');
unset($_ENV['FS_SECRET_KEY'], $_SERVER['FS_SECRET_KEY']);

require __EXCEPTION__;
require __SECRET_MANAGER__;

try {
    \FSFramework\Security\SecretManager::getSecret();
    fwrite(STDOUT, 'got-secret');
    exit(0);
} catch (\FSFramework\Security\Exception\MissingSecretKeyException $e) {
    fwrite(STDERR, get_class($e) . ':' . $e->getMessage());
    exit(42);
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ':' . $e->getMessage());
    exit(43);
}
PHP;

        $tempDir = sys_get_temp_dir() . '/fsframework_test_readonly_' . uniqid();
        mkdir($tempDir, 0555, true);

        $wrappedCode = 'define("FS_FOLDER", ' . var_export($tempDir, true) . ');' . "\n" . $code;

        $command = $php . ' -r ' . escapeshellarg(str_replace(
            ['__EXCEPTION__', '__SECRET_MANAGER__'],
            [var_export($exceptionPath, true), var_export($secretManagerPath, true)],
            $wrappedCode
        )) . ' 2>&1';

        exec($command, $output, $exitCode);

        chmod($tempDir, 0755);
        @rmdir($tempDir);

        $this->assertSame(42, $exitCode, 'Expected MissingSecretKeyException. Output: ' . implode("\n", $output));
        $this->assertStringContainsString(MissingSecretKeyException::class, implode("\n", $output));
    }
}
