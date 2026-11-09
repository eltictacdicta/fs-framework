<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CI-19 — the content-i18n migration is hooked after the core self-heal and
 * the admin-only migration. The sequence lives in CoreSchemaBootstrap, which
 * contains each step's failure (see CoreSchemaBootstrapTest); every bootstrap
 * entry point delegates to it.
 */
final class ContentI18nHookTest extends TestCase
{
    private const ENTRY_POINTS = ['index.php', 'api.php', 'cron.php'];

    /**
     * @return array<string, array{string}>
     */
    public static function entryPoints(): array
    {
        $cases = [];
        foreach (self::ENTRY_POINTS as $file) {
            $cases[$file] = [$file];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('entryPoints')]
    public function entryPointDelegatesToTheCoreSchemaBootstrap(string $file): void
    {
        self::assertStringContainsString(
            '\FSFramework\Core\Schema\CoreSchemaBootstrap::ensure(',
            $this->source($file),
            $file . ' must run the core schema steps through CoreSchemaBootstrap.'
        );
    }

    #[Test]
    public function migrationRunsAfterSelfHealAndTheAdminOnlyMigration(): void
    {
        $source = $this->source('src/Core/Schema/CoreSchemaBootstrap.php');

        $selfHeal = strpos($source, 'selfHealCoreTables');
        $adminOnly = strpos($source, 'AdminOnlyPagesMigration::run');
        $migration = strpos($source, 'ContentI18nMigration::run');

        self::assertIsInt($selfHeal);
        self::assertIsInt($adminOnly);
        self::assertIsInt($migration);
        self::assertGreaterThan($selfHeal, $adminOnly);
        self::assertGreaterThan($adminOnly, $migration);
    }

    private function source(string $file): string
    {
        $path = FS_FOLDER . '/' . $file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
