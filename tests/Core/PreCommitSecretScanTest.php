<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
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
 */

namespace Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DSH-03 (VULN-0005) — the pre-commit hook rejects a staged credential.
 *
 * This is a BEHAVIOURAL test: it runs `scripts/hooks/pre-commit` itself against a
 * temporary `GIT_INDEX_FILE`, so the developer's real index is never touched and the
 * assertion is on the exit status and the reported file/line, not on the hook's
 * source text.
 *
 * The ordering case is the point of the control. The hook used to `exit 0` as soon as
 * `composer.json` was not staged, so a scan appended after that block would never run
 * for a normal commit. The fixtures here never stage `composer.json`, which is exactly
 * the path that used to short-circuit — a non-zero exit on that path proves the scan
 * runs before the early exit.
 *
 * Documented limitation: this drives the hook through `bash`, i.e. the same way git
 * does, but it is not a `git commit`. Git-level hooks installation and the operator's
 * live behaviour are outside the suite.
 */
final class PreCommitSecretScanTest extends TestCase
{
    private string $workDir = '';

    private string $indexFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = FS_FOLDER . '/tmp/pre-commit-scan-' . bin2hex(random_bytes(4));
        if (!is_dir($this->workDir) && !mkdir($this->workDir, 0777, true)) {
            $this->fail('Unable to create the fixture directory ' . $this->workDir);
        }

        $this->indexFile = $this->workDir . '/index';

        // Seed the temporary index from HEAD so `git diff --cached` reports exactly
        // what this test stages, instead of every tracked file as a deletion.
        [$exitCode, $output] = $this->runShell('git read-tree HEAD');
        $this->assertSame(0, $exitCode, 'Unable to seed the temporary index: ' . $output);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if ($this->workDir !== '' && is_dir($this->workDir)) {
            rmdir($this->workDir);
        }

        parent::tearDown();
    }

    /**
     * DSH-03 scenario "a staged credential is rejected".
     */
    #[Test]
    public function testAStagedCredentialIsRejectedWithFileAndLine(): void
    {
        $secret = str_repeat('cd', 32);
        $fixture = $this->writeFixture('credential.md', "# fixture\n\nGET /index.php with logkey=" . $secret . "\n");
        $this->stage([$fixture]);

        [$exitCode, $output] = $this->runHook();

        $this->assertNotSame(0, $exitCode, 'The hook must reject a staged credential.');
        $this->assertStringContainsString(
            $fixture . ':3',
            $output,
            'The hook must name the offending file and line.'
        );
        $this->assertStringContainsString('logkey', $output);
        $this->assertStringNotContainsString(
            $secret,
            $output,
            'The hook must not echo the credential value into a terminal or CI log.'
        );
    }

    /**
     * CodeRabbit review of PR 5: the hook listed paths from the index but grepped
     * the file on disk, so a credential staged with `git add -p` and then deleted
     * from the working tree still reached the commit.
     */
    #[Test]
    public function testTheScanReadsTheStagedBlobNotTheWorkingTree(): void
    {
        $secret = str_repeat('ef', 32);
        $fixture = $this->writeFixture('deleted-after-stage.md', "logkey=" . $secret . "\n");
        $this->stage([$fixture]);

        unlink($this->workDir . '/deleted-after-stage.md');
        $this->assertFileDoesNotExist(FS_FOLDER . '/' . $fixture);

        [$exitCode, $output] = $this->runHook();

        $this->assertNotSame(
            0,
            $exitCode,
            'A staged credential must block even when the working-tree file is gone: ' . $output
        );
        $this->assertStringContainsString($fixture . ':1', $output);
        $this->assertStringNotContainsString($secret, $output);
    }

    /**
     * CodeRabbit review of PR 5: PHP array literals separate with `=>`, which the
     * rule did not accept, so `['logkey' => '<hex>']` passed the hook unnoticed.
     */
    #[Test]
    public function testAPhpArrayAssignmentIsDetected(): void
    {
        $secret = str_repeat('ab', 32);
        $fixture = $this->writeFixture(
            'array-assignment.php',
            "<?php \$config = ['logkey' => '" . $secret . "'];\n"
        );
        $this->stage([$fixture]);

        [$exitCode, $output] = $this->runHook();

        $this->assertNotSame(0, $exitCode, 'A PHP array assignment must not bypass the rule: ' . $output);
        $this->assertStringContainsString($fixture . ':1', $output);
        $this->assertStringNotContainsString($secret, $output);
    }

    /**
     * DSH-03 scenario "the opt-out marker works".
     */
    #[Test]
    public function testTheInlineOptOutMarkerSuppressesTheFinding(): void
    {
        $secret = str_repeat('ab', 32);
        $fixture = $this->writeFixture('fixture.md', "logkey=" . $secret . " # nosecret — public fixture\n");
        $this->stage([$fixture]);

        [$exitCode, $output] = $this->runHook();

        $this->assertSame(0, $exitCode, 'An opted-out line must not block the commit: ' . $output);
        $this->assertStringNotContainsString('credential material', $output);
    }

    /**
     * DSH-03 scenario "the scan runs before the early exit" — the clean half.
     */
    #[Test]
    public function testACleanCommitIsNotBlocked(): void
    {
        $fixture = $this->writeFixture('clean.md', "auth_sig=<redacted>\nlogkey=<redacted>\n");
        $this->stage([$fixture]);

        [$exitCode, $output] = $this->runHook();

        $this->assertSame(0, $exitCode, 'A clean commit must not be blocked: ' . $output);
    }

    /**
     * DSH-03 scenario "the scan runs before the early exit" — the ordering half.
     *
     * Nothing but the fixture is staged, so `composer.json` is absent and the
     * Composer block would previously have returned 0 without ever scanning.
     */
    #[Test]
    public function testTheScanRunsOnAPathWhereTheHookUsedToExitEarly(): void
    {
        $secret = str_repeat('ef', 32);
        $fixture = $this->writeFixture('early-exit.md', "password=" . $secret . "\n");
        $this->stage([$fixture]);

        [, $staged] = $this->runShell('git diff --cached --name-only');
        $this->assertStringNotContainsString(
            'composer.json',
            $staged,
            'This case must exercise the early-exit path, so composer.json must not be staged.'
        );

        [$exitCode, $output] = $this->runHook();

        $this->assertNotSame(
            0,
            $exitCode,
            'The scan must run before the composer.json early exit; a credential staged on that path was accepted.'
        );
        $this->assertStringContainsString($fixture . ':1', $output);
    }

    private function writeFixture(string $name, string $contents): string
    {
        $path = $this->workDir . '/' . $name;
        if (file_put_contents($path, $contents) === false) {
            $this->fail('Unable to write the fixture ' . $path);
        }

        return $this->relativePath($path);
    }

    private function relativePath(string $path): string
    {
        return ltrim(str_replace(FS_FOLDER, '', $path), '/');
    }

    /**
     * @param array<int, string> $relativePaths
     */
    private function stage(array $relativePaths): void
    {
        // -f because the fixtures live under tmp/, which is gitignored.
        $this->runShell('git add -f ' . implode(' ', array_map('escapeshellarg', $relativePaths)));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runHook(): array
    {
        return $this->runShell('bash ' . escapeshellarg('scripts/hooks/pre-commit'));
    }

    /**
     * Runs a shell command in the repository root with the temporary index.
     *
     * @return array{0: int, 1: string}
     */
    private function runShell(string $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            'GIT_INDEX_FILE=' . escapeshellarg($this->indexFile) . ' ' . $command,
            $descriptors,
            $pipes,
            FS_FOLDER
        );

        if (!is_resource($process)) {
            $this->fail('Unable to start the command: ' . $command);
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, $stdout . $stderr];
    }
}
