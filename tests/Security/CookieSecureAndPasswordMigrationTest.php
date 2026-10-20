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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/base/fs_core_log.php';
require_once dirname(__DIR__, 2) . '/base/fs_cache.php';
require_once dirname(__DIR__, 2) . '/base/fs_functions.php';
require_once dirname(__DIR__, 2) . '/model/core/fs_user.php';
require_once dirname(__DIR__, 2) . '/base/fs_login.php';

/**
 * Two regressions pinned here:
 *
 * 1. Every place that decides the `Secure` cookie flag (or builds an absolute
 *    URL) used to read `$_SERVER['HTTPS']`, which is empty when TLS terminates
 *    at a reverse proxy. They all go through SecureRequestDetector now.
 * 2. Legacy SHA1/MD5 hashes were only migrated when the plaintext password was
 *    8-32 chars long, so accounts outside that range kept the weak hash
 *    forever. Rehashing on a verified login does not need that policy.
 */
final class CookieSecureAndPasswordMigrationTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function proxyAwareCallSites(): array
    {
        return [
            'fs_auth (issue + clear legacy cookies)' => ['/base/fs_auth.php', 2],
            'fs_login (legacy cookie flags)' => ['/base/fs_login.php', 1],
            'fs_session_manager (session + legacy cookies)' => ['/base/fs_session_manager.php', 2],
            'fs_controller (preference cookies)' => ['/base/fs_controller.php', 1],
            'password_reset (reset URL scheme)' => ['/controller/password_reset.php', 1],
        ];
    }

    #[DataProvider('proxyAwareCallSites')]
    public function testSecureDecisionGoesThroughTheProxyAwareDetector(string $relativePath, int $minimum): void
    {
        $path = dirname(__DIR__, 2) . $relativePath;
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        $this->assertGreaterThanOrEqual(
            $minimum,
            substr_count($source, 'SecureRequestDetector::isSecure()'),
            sprintf(
                '%s must decide cookie/URL security through SecureRequestDetector, not $_SERVER[\'HTTPS\'].',
                basename($relativePath)
            )
        );
    }

    public function testLegacyMigrationRehashesAPasswordShorterThanThePolicy(): void
    {
        $user = new class {
            public string $password = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
            public int $saved = 0;

            public function save(): bool
            {
                $this->saved++;

                return true;
            }
        };

        $this->invokeMigration($user, 'abc');

        $this->assertSame(1, $user->saved, 'The migrated hash must be persisted.');
        $this->assertNotSame('da39a3ee5e6b4b0d3255bfef95601890afd80709', $user->password);
        $this->assertTrue(password_verify('abc', $user->password));
    }

    public function testLegacyMigrationRehashesAPasswordLongerThanThePolicy(): void
    {
        $longPassword = str_repeat('x', 40);

        $user = new class {
            public string $password = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
            public int $saved = 0;

            public function save(): bool
            {
                $this->saved++;

                return true;
            }
        };

        $this->invokeMigration($user, $longPassword);

        $this->assertTrue(password_verify($longPassword, $user->password));
    }

    public function testLegacyMigrationStillWorksInsideThePolicy(): void
    {
        $user = new class {
            public string $password = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
            public int $saved = 0;

            public function save(): bool
            {
                $this->saved++;

                return true;
            }
        };

        $this->invokeMigration($user, 'correct-horse');

        $this->assertSame(1, $user->saved);
        $this->assertTrue(password_verify('correct-horse', $user->password));
    }

    public function testMigrationDoesNothingWhenTheUserCannotBeSaved(): void
    {
        $user = new class {
            public string $password = 'legacy-hash';
        };

        $this->invokeMigration($user, 'abc');

        $this->assertSame('legacy-hash', $user->password);
    }

    private function invokeMigration(object $user, string $password): void
    {
        $login = (new \ReflectionClass(\fs_login::class))->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(\fs_login::class, 'migrate_legacy_password');
        $method->setAccessible(true);
        $method->invoke($login, $user, $password);
    }
}
