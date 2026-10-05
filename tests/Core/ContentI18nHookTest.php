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
 * CI-19 — the content-i18n migration is hooked after the core self-heal in
 * every bootstrap entry point, each call wrapped in `try/catch` so a failure is
 * contained and the request continues.
 *
 * This is a structural (source-reading) contract: it is authored before the
 * production hooks exist and must fail until they are added. It is the
 * threat-matrix RED test for the Process-integration boundary.
 */
final class ContentI18nHookTest extends TestCase
{
    private const ENTRY_POINTS = ['index.php', 'api.php', 'cron.php'];

    private const MIGRATION_CALL = 'ContentI18nMigration::run';

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
    public function bootstrapRunsTheMigrationAfterSelfHeal(string $file): void
    {
        $source = $this->source($file);

        self::assertStringContainsString(
            '\FSFramework\Core\Schema\ContentI18nMigration::run()',
            $source,
            $file . ' must invoke the fully-qualified ContentI18nMigration::run().'
        );

        $selfHeal = strpos($source, 'selfHealCoreTables');
        $migration = strpos($source, self::MIGRATION_CALL);

        self::assertIsInt($selfHeal, $file . ' must call fs_schema::selfHealCoreTables().');
        self::assertIsInt($migration, $file . ' must invoke ContentI18nMigration::run().');
        self::assertGreaterThan(
            $selfHeal,
            $migration,
            $file . ' must run the content-i18n migration after the core self-heal.'
        );
    }

    #[Test]
    #[DataProvider('entryPoints')]
    public function migrationCallIsContainedInATryCatch(string $file): void
    {
        $source = $this->source($file);

        $call = strpos($source, self::MIGRATION_CALL);
        self::assertIsInt($call, $file . ' must invoke ContentI18nMigration::run().');

        $try = strrpos(substr($source, 0, $call), 'try {');
        self::assertIsInt($try, $file . ' must open a try block before the migration call.');

        $catch = strpos($source, 'catch (', $call);
        self::assertIsInt($catch, $file . ' must close the try block with a catch after the migration call.');

        // No closing `catch` may appear between the opening `try` and the call,
        // otherwise the call would sit outside the guarded block.
        self::assertStringNotContainsString(
            'catch (',
            substr($source, $try, $call - $try),
            $file . ' must keep the migration call inside the try block.'
        );
    }

    /**
     * The content-i18n hook is additive: it must sit after the pre-existing
     * AdminOnlyPagesMigration block, which proves the new lines were inserted
     * rather than replacing the existing ones.
     */
    #[Test]
    #[DataProvider('entryPoints')]
    public function hookFollowsTheExistingAdminOnlyMigration(string $file): void
    {
        $source = $this->source($file);

        $adminOnly = strpos($source, 'AdminOnlyPagesMigration');
        $migration = strpos($source, self::MIGRATION_CALL);

        self::assertIsInt($adminOnly, $file . ' must keep the AdminOnlyPagesMigration block.');
        self::assertIsInt($migration);
        self::assertGreaterThan(
            $adminOnly,
            $migration,
            $file . ' must keep the content-i18n hook additive, after the admin-only block.'
        );
    }

    private function source(string $file): string
    {
        $path = FS_FOLDER . '/' . $file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
