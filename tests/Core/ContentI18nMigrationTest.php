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

use FSFramework\Core\Schema\ContentI18nMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CI-18, CI-20 — idempotent, flagged, single-table content-i18n migration (D7).
 *
 * The migration is exercised through a no-DB recorder: every fallible step is
 * overridable, so ordering, the success flag, retries and the allowlist guard
 * are verified without touching the database. This is the threat-matrix RED
 * test for the Process-integration boundary (bootstrap entry points).
 */
final class ContentI18nMigrationTest extends TestCase
{
    private const EXPECTED_ORDER = [
        'assertTableAllowlisted',
        'adoptTable',
        'seed',
        'markApplied',
    ];

    /**
     * @param array{applied?: bool, throwOn?: string|null, failOn?: string|null, table?: string} $options
     */
    private function recorder(array $options = [])
    {
        return new class($options) extends ContentI18nMigration {
            /** @var list<string> */
            public array $steps = [];

            private bool $applied;
            private ?string $throwOn;
            private ?string $failOn;
            private string $table;

            /**
             * @param array{applied?: bool, throwOn?: string|null, failOn?: string|null, table?: string} $options
             */
            public function __construct(array $options)
            {
                $this->applied = $options['applied'] ?? false;
                $this->throwOn = $options['throwOn'] ?? null;
                $this->failOn = $options['failOn'] ?? null;
                $this->table = $options['table'] ?? 'idiomas';
            }

            protected function isApplied(): bool
            {
                return $this->applied;
            }

            /**
             * Overrides the table seam so the real allowlist guard runs against
             * the caller-supplied table name without loading the legacy model.
             */
            protected function tableName(): string
            {
                return $this->table;
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

            protected function assertTableAllowlisted(): bool
            {
                $this->record('assertTableAllowlisted');

                return parent::assertTableAllowlisted() && !$this->fails('assertTableAllowlisted');
            }

            protected function adoptTable(): bool
            {
                $this->record('adoptTable');

                return !$this->fails('adoptTable');
            }

            protected function seed(): bool
            {
                $this->record('seed');

                return !$this->fails('seed');
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
     * The allowlist guard MUST run before any schema or data step, so core code
     * structurally cannot touch a plugin table (CI-18).
     */
    #[Test]
    public function aNonAllowlistedTableAbortsBeforeAnyWork(): void
    {
        $migration = $this->recorder(['table' => 'catalogo_idiomas']);

        self::assertFalse(
            $migration->execute(),
            'A table outside the allowlist must abort the migration.'
        );
        self::assertSame(
            ['assertTableAllowlisted'],
            $migration->steps,
            'The allowlist guard must abort before adoptTable(), seed() and markApplied().'
        );
        self::assertNotContains('adoptTable', $migration->steps);
        self::assertNotContains('seed', $migration->steps);
        self::assertNotContains('markApplied', $migration->steps);
    }

    /**
     * Every SQL-executing step signals failure by returning `false` (the way
     * `fs_db2::exec()` reports a non-throwing SQL error). The migration must
     * abort on the first one, so `markApplied()` never runs and the `fs_var`
     * flag stays unwritten for the next retry (CI-18, CI-20).
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function failingSqlSteps(): array
    {
        return [
            'adoptTable fails' => ['adoptTable', ['assertTableAllowlisted', 'adoptTable']],
            'seed fails' => ['seed', ['assertTableAllowlisted', 'adoptTable', 'seed']],
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
     * request would silently re-run the data operations (CI-20).
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
        $migration = $this->recorder(['throwOn' => 'adoptTable']);

        $threw = false;
        try {
            $migration->execute();
        } catch (\RuntimeException $exception) {
            $threw = true;
        }

        self::assertTrue($threw, 'A failing migration step must propagate so the caller can retry.');
        self::assertSame(
            ['assertTableAllowlisted', 'adoptTable'],
            $migration->steps,
            'Steps after the failure must not run.'
        );
        self::assertNotContains('markApplied', $migration->steps, 'The success flag must not be written.');

        $retry = $this->recorder();
        self::assertTrue($retry->execute());
        self::assertSame(self::EXPECTED_ORDER, $retry->steps);
    }

    /**
     * CI-19 scenario 4: when no database/table is available the migration must
     * be a safe no-op that returns without throwing so the request continues.
     */
    #[Test]
    public function aMissingDatabaseIsASafeNoOp(): void
    {
        $migration = $this->recorder(['failOn' => 'adoptTable']);

        $threw = false;
        try {
            $result = $migration->execute();
        } catch (\Throwable $exception) {
            $threw = true;
            $result = null;
        }

        self::assertFalse($threw, 'A missing database must not throw out of the migration.');
        self::assertFalse($result, 'A missing database must resolve to a failed no-op, not to success.');
        self::assertNotContains(
            'markApplied',
            $migration->steps,
            'No flag may be written when the table could not be adopted.'
        );
    }

    #[Test]
    public function allowlistIsExactlyIdiomas(): void
    {
        self::assertSame(['idiomas'], ContentI18nMigration::TABLE_ALLOWLIST);
        self::assertNotContains('catalogo_idiomas', ContentI18nMigration::TABLE_ALLOWLIST);
        self::assertNotContains('articulo_descripciones', ContentI18nMigration::TABLE_ALLOWLIST);
    }

    #[Test]
    public function flagNameTargetsTheContentI18nMigration(): void
    {
        self::assertSame('content_i18n_migrated', ContentI18nMigration::FLAG);
    }
}
