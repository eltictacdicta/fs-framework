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

use FSFramework\Core\Schema\AdminOnlyPagesMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PA-08: idempotent one-shot migration for admin-only pages.
 *
 * The migration is exercised through a no-DB recorder: every step is
 * overridable, so ordering, the success flag and retries are verified without
 * touching the database.
 */
final class AdminOnlyPagesMigrationTest extends TestCase
{
    private const EXPECTED_ORDER = [
        'forgetCheckedTables',
        'adoptColumn',
        'backfill',
        'purgeStaleGrants',
        'clearPageCache',
        'markApplied',
    ];

    /**
     * @param array{applied?: bool, throwOn?: string|null, failOn?: string|null} $options
     */
    private function recorder(array $options = [])
    {
        return new class($options) extends AdminOnlyPagesMigration {
            /** @var list<string> */
            public array $steps = [];

            private bool $applied;
            private ?string $throwOn;
            private ?string $failOn;

            /**
             * @param array{applied?: bool, throwOn?: string|null, failOn?: string|null} $options
             */
            public function __construct(array $options)
            {
                $this->applied = $options['applied'] ?? false;
                $this->throwOn = $options['throwOn'] ?? null;
                $this->failOn = $options['failOn'] ?? null;
            }

            protected function isApplied(): bool
            {
                return $this->applied;
            }

            private function record(string $step): void
            {
                $this->steps[] = $step;
                if ($this->throwOn === $step) {
                    throw new \RuntimeException('migration step failed: ' . $step);
                }
            }

            /**
             * Models a non-throwing SQL failure (`exec()` returned false).
             */
            private function fails(string $step): bool
            {
                return $this->failOn === $step;
            }

            protected function forgetCheckedTables(): void
            {
                $this->record('forgetCheckedTables');
            }

            protected function adoptColumn(): bool
            {
                $this->record('adoptColumn');

                return !$this->fails('adoptColumn');
            }

            protected function backfill(): bool
            {
                $this->record('backfill');

                return !$this->fails('backfill');
            }

            protected function purgeStaleGrants(): bool
            {
                $this->record('purgeStaleGrants');

                return !$this->fails('purgeStaleGrants');
            }

            protected function clearPageCache(): void
            {
                $this->record('clearPageCache');
            }

            protected function markApplied(): bool
            {
                $this->record('markApplied');

                return !$this->fails('markApplied');
            }
        };
    }

    #[Test]
    public function firstRunPerformsAllStepsInOrder(): void
    {
        $migration = $this->recorder();

        self::assertTrue($migration->execute());
        self::assertSame(self::EXPECTED_ORDER, $migration->steps);
    }

    #[Test]
    public function alreadyAppliedIsANoOp(): void
    {
        $migration = $this->recorder(['applied' => true]);

        self::assertTrue($migration->execute());
        self::assertSame([], $migration->steps);
    }

    /**
     * Every SQL-executing step signals failure by returning `false` (the way
     * `fs_db2::exec()` reports a non-throwing SQL error). The migration must
     * abort on the first one, so `markApplied()` never runs and the `fs_var`
     * flag stays unwritten for the next retry (PA-08).
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function failingSqlSteps(): array
    {
        return [
            'adoptColumn fails' => ['adoptColumn', ['forgetCheckedTables', 'adoptColumn']],
            'backfill fails' => ['backfill', ['forgetCheckedTables', 'adoptColumn', 'backfill']],
            'purgeStaleGrants fails' => [
                'purgeStaleGrants',
                ['forgetCheckedTables', 'adoptColumn', 'backfill', 'purgeStaleGrants'],
            ],
        ];
    }

    /**
     * @param list<string> $expectedSteps
     */
    #[Test]
    #[DataProvider('failingSqlSteps')]
    public function aFailingSqlStepAbortsBeforeTheFlagIsWritten(string $step, array $expectedSteps): void
    {
        $migration = $this->recorder(['failOn' => $step]);

        self::assertFalse(
            $migration->execute(),
            'A step returning false must abort the migration instead of reporting success.'
        );
        self::assertSame($expectedSteps, $migration->steps, 'Steps after the failure must not run.');
        self::assertNotContains(
            'markApplied',
            $migration->steps,
            'The success flag must not be written after a silent SQL failure.'
        );
    }

    /**
     * A failed flag write must NOT be reported as success: the migration would
     * otherwise claim to be applied while nothing recorded it, and the next
     * request would silently re-run the data operations (S-5).
     */
    #[Test]
    public function aFailedFlagWriteIsNotReportedAsSuccess(): void
    {
        $migration = $this->recorder(['failOn' => 'markApplied']);

        self::assertFalse(
            $migration->execute(),
            'An unwritten success flag must surface as a failed migration, not as success.'
        );
        self::assertContains('markApplied', $migration->steps, 'The flag write must still be attempted.');
    }

    #[Test]
    public function failingStepSkipsTheFlagAndTheNextRunRetries(): void
    {
        $migration = $this->recorder(['throwOn' => 'backfill']);

        $threw = false;
        try {
            $migration->execute();
        } catch (\RuntimeException $exception) {
            $threw = true;
        }

        self::assertTrue($threw, 'A failing migration step must propagate so the caller can retry.');
        self::assertSame(
            ['forgetCheckedTables', 'adoptColumn', 'backfill'],
            $migration->steps,
            'Steps after the failure must not run.'
        );
        self::assertNotContains('markApplied', $migration->steps, 'The success flag must not be written.');

        $retry = $this->recorder();
        self::assertTrue($retry->execute());
        self::assertSame(self::EXPECTED_ORDER, $retry->steps);
    }

    #[Test]
    public function allowlistIsExplicitAndExcludesNonScopedPages(): void
    {
        $allowlist = AdminOnlyPagesMigration::PAGE_ALLOWLIST;

        self::assertCount(9, $allowlist);
        self::assertSame([
            'admin_users',
            'admin_user',
            'admin_rol',
            'admin_info',
            'admin_email',
            'admin_system_branding',
            'admin_stealth',
            'admin_orden_menu',
            'admin_agentes',
        ], $allowlist);
        self::assertNotContains('admin_home', $allowlist);
        self::assertNotContains('admin_custom', $allowlist);
    }

    #[Test]
    public function bootstrapRunsTheMigrationAfterSelfHeal(): void
    {
        foreach (['index.php', 'api.php', 'cron.php'] as $file) {
            $source = (string) file_get_contents(FS_FOLDER . '/' . $file);

            $selfHeal = strpos($source, 'selfHealCoreTables');
            $migration = strpos($source, 'AdminOnlyPagesMigration');

            self::assertIsInt($selfHeal, $file . ' must call fs_schema::selfHealCoreTables().');
            self::assertIsInt($migration, $file . ' must invoke AdminOnlyPagesMigration.');
            self::assertGreaterThan(
                $selfHeal,
                $migration,
                $file . ' must run the migration after the core self-heal.'
            );
        }
    }

    #[Test]
    public function cronLoadsTheComposerAutoloaderBeforeTheMigration(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/cron.php');

        $autoload = strpos($source, 'vendor/autoload.php');
        $migration = strpos($source, 'AdminOnlyPagesMigration');

        self::assertIsInt(
            $autoload,
            'cron.php does not include index.php, so it must load Composer\'s autoloader to resolve namespaced src/ classes.'
        );
        self::assertIsInt($migration);
        self::assertLessThan(
            $migration,
            $autoload,
            'The Composer autoloader must be loaded before any namespaced src/ class is used in cron.php.'
        );
    }
}
