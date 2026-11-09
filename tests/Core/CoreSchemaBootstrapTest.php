<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Core;

use FSFramework\Core\Schema\CoreSchemaBootstrap;
use PHPUnit\Framework\TestCase;

final class CoreSchemaBootstrapTest extends TestCase
{
    private string $stamp;
    private array $calls = [];
    private array $errors = [];

    protected function setUp(): void
    {
        $this->stamp = sys_get_temp_dir() . '/core_schema_test_' . uniqid('', true) . '.ok';
    }

    protected function tearDown(): void
    {
        @unlink($this->stamp);
    }

    public function testAllStepsSucceedingWritesTheStampAndLaterRunsSkipTheSteps(): void
    {
        $bootstrap = new CoreSchemaBootstrap($this->stamp, $this->steps(['a' => true, 'b' => true]));

        self::assertTrue($bootstrap->run($this->logger()));
        self::assertFileExists($this->stamp);
        self::assertSame(['a', 'b'], $this->calls);

        self::assertTrue($bootstrap->run($this->logger()));
        self::assertSame(['a', 'b'], $this->calls, 'With the stamp present no step may run again.');
    }

    public function testAFailingStepWritesNoStampAndDoesNotStopTheNextSteps(): void
    {
        $bootstrap = new CoreSchemaBootstrap($this->stamp, $this->steps(['a' => false, 'b' => 'throw', 'c' => true]));

        self::assertFalse($bootstrap->run($this->logger()));
        self::assertFileDoesNotExist($this->stamp);
        self::assertSame(['a', 'b', 'c'], $this->calls);
        self::assertSame(['a failed: step returned false', 'b failed: boom'], $this->errors);

        $bootstrap->run($this->logger());
        self::assertSame(['a', 'b', 'c', 'a', 'b', 'c'], $this->calls, 'Without a stamp the next request retries.');
    }

    public function testAnUnwritableStampIsReported(): void
    {
        $bootstrap = new CoreSchemaBootstrap('/nonexistent-dir/x.ok', $this->steps(['a' => true]));

        $bootstrap->run($this->logger());

        self::assertSame(['Core schema stamp could not be written: /nonexistent-dir/x.ok'], $this->errors);
    }

    public function testTheDefaultStampIsKeyedByVersionAndDatabase(): void
    {
        $file = CoreSchemaBootstrap::defaultStampFile();
        $version = trim((string) file_get_contents(FS_FOLDER . '/VERSION'));

        self::assertStringStartsWith(FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'core_schema_' . $version . '_', $file);
        self::assertMatchesRegularExpression('/_[0-9a-f]{12}\.ok$/', $file);
    }

    public function testInvalidateDropsEveryStampSoTheStepsRunAgain(): void
    {
        $current = CoreSchemaBootstrap::defaultStampFile();
        $other = preg_replace('/core_schema_.*$/', 'core_schema_0.0.1_000000000000.ok', $current);
        file_put_contents($current, 'x');
        file_put_contents($other, 'x');

        CoreSchemaBootstrap::invalidate();

        self::assertFileDoesNotExist($current);
        self::assertFileDoesNotExist($other);

        $bootstrap = new CoreSchemaBootstrap($current, $this->steps(['a' => true]));
        $bootstrap->run($this->logger());
        @unlink($current);
        self::assertSame(['a'], $this->calls);
    }

    /**
     * @param array<string, bool|string> $results
     * @return array<string, callable(): bool>
     */
    private function steps(array $results): array
    {
        $steps = [];
        foreach ($results as $name => $result) {
            $steps[$name] = function () use ($name, $result): bool {
                $this->calls[] = $name;
                if ($result === 'throw') {
                    throw new \RuntimeException('boom');
                }

                return $result;
            };
        }

        return $steps;
    }

    private function logger(): callable
    {
        return function (string $msg): void {
            $this->errors[] = $msg;
        };
    }
}
