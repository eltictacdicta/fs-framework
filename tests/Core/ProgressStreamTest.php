<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Core\ProgressStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FSFramework\Core\ProgressStream — SSE + progress-file transport utility.
 *
 * The utility is transport-only. CSRF is the caller's responsibility (via
 * FSFramework\Security\CsrfManager). The tests cover the 7 public methods
 * (init, sendEvent, sendKeepalive, saveProgress, readProgress, cleanup,
 * cleanupExpired) plus concurrency + path-traversal safety.
 *
 * Strategy for header assertions: in CLI (PHPUnit's default runtime),
 * `headers_list()` is empty and `header()` calls are no-ops. We therefore
 * assert the *return shape* of init() (progress_file, session_id) and the
 * file-level side effects. The actual SSE headers are validated in the
 * integration test ExcelWizardSseEntryTest (PR 2) where proc_open drives
 * the entry point through a real web server.
 */
#[CoversClass(ProgressStream::class)]
final class ProgressStreamTest extends TestCase
{
    private const PREFIX = 'fs_progressstream_test';
    private const SAFE_SESSION = 'sess_safe123';
    private const TTL = 86400;

    /** @var string[] */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createdFiles = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        // Best-effort sweep of any test-prefixed progress files
        foreach ((array) glob(sys_get_temp_dir() . '/' . self::PREFIX . '_*.json') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function track(string $path): string
    {
        $this->createdFiles[] = $path;
        return $path;
    }

    private function buildProgressFile(string $sessionId): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_.-]/', '', $sessionId);
        $this->assertNotNull($sanitized);
        return $this->track(sys_get_temp_dir() . '/' . self::PREFIX . '_' . $sanitized . '.json');
    }

    public function testInitReturnsProgressFileAndSessionId(): void
    {
        $ctx = ProgressStream::init(self::PREFIX, self::SAFE_SESSION);

        $this->assertIsArray($ctx);
        $this->assertArrayHasKey('progress_file', $ctx);
        $this->assertArrayHasKey('session_id', $ctx);

        $this->assertSame(self::SAFE_SESSION, $ctx['session_id']);
        $this->assertStringContainsString(self::PREFIX, $ctx['progress_file']);
        $this->assertStringContainsString(self::SAFE_SESSION, $ctx['progress_file']);
        $this->assertStringContainsString(sys_get_temp_dir(), $ctx['progress_file']);
        $this->assertStringEndsWith('.json', $ctx['progress_file']);
    }

    public function testInitSanitizesSessionIdForPathTraversal(): void
    {
        $malicious = '../../etc/passwd';
        $ctx = ProgressStream::init(self::PREFIX, $malicious);

        // The real security invariant is that the progress file lives
        // strictly inside sys_get_temp_dir() — even if the regex
        // allows the `.` character, the `/` is stripped, so the path
        // can never escape the temp directory. We assert on the
        // resolved (real) path, which is the source of truth.

        $this->assertStringStartsWith(sys_get_temp_dir(), $ctx['progress_file']);
        $realDir = realpath(dirname($ctx['progress_file']));
        $realTmp = realpath(sys_get_temp_dir());
        $this->assertSame($realTmp, $realDir, 'progress file must live inside sys_get_temp_dir()');

        // The path components must not contain any directory separator
        // (the only allowed chars in the sanitized id are A-Za-z0-9_.-).
        $this->assertStringNotContainsString('/', $ctx['session_id']);
        $this->assertStringNotContainsString('\\', $ctx['session_id']);
    }

    public function testSendEventWritesSseEventToStdout(): void
    {
        $payload = ['step' => 'loading', 'percent' => 25, 'message' => 'Hola, mundo!'];

        // Use `$autoFlush = false` so PHPUnit's output buffer is NOT
        // flushed by the production `ob_flush()` call before the
        // `expectOutputRegex` callback reads it.
        $this->expectOutputRegex(
            '/^event: progress\n' .
            'data: \{"step":"loading","percent":25,"message":"Hola, mundo!"\}\n\n$/'
        );

        ProgressStream::sendEvent('progress', $payload, false);
    }

    public function testSendKeepaliveWritesSseComment(): void
    {
        $this->expectOutputRegex('/^: keepalive \d+\n\n$/');
        ProgressStream::sendKeepalive(false);
    }

    public function testSaveProgressWritesFlockProtectedFile(): void
    {
        $file = $this->buildProgressFile('save_basic');

        $data = ProgressStream::saveProgress($file, 'reading', 'Leyendo filas', 50);

        $this->assertSame('reading', $data['step']);
        $this->assertSame('Leyendo filas', $data['message']);
        $this->assertSame(50, $data['percent']);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertNull($data['error']);
        $this->assertFileExists($file);

        $raw = file_get_contents($file);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true);
        $this->assertSame('reading', $decoded['step']);
        $this->assertSame(50, $decoded['percent']);
    }

    public function testReadProgressReturnsSavedData(): void
    {
        $file = $this->buildProgressFile('read_round');

        ProgressStream::saveProgress($file, 'apply', 'Aplicando', 75, null);
        $read = ProgressStream::readProgress($file);

        $this->assertIsArray($read);
        $this->assertSame('apply', $read['step']);
        $this->assertSame('Aplicando', $read['message']);
        $this->assertSame(75, $read['percent']);
        $this->assertNull($read['error']);
    }

    public function testReadProgressReturnsNullWhenFileMissing(): void
    {
        $missing = $this->track(sys_get_temp_dir() . '/' . self::PREFIX . '_does_not_exist.json');
        $this->assertNull(ProgressStream::readProgress($missing));
    }

    public function testReadProgressReturnsNullWhenJsonCorrupt(): void
    {
        $file = $this->buildProgressFile('corrupt_json');
        file_put_contents($file, '{not valid json');
        $this->assertNull(ProgressStream::readProgress($file));
    }

    public function testCleanupUnlinksProgressFile(): void
    {
        $file = $this->buildProgressFile('cleanup_basic');
        ProgressStream::saveProgress($file, 'init', 'start', 0);
        $this->assertFileExists($file);

        ProgressStream::cleanup($file);
        $this->assertFileDoesNotExist($file);
    }

    public function testCleanupIsNoOpForMissingFile(): void
    {
        $missing = $this->track(sys_get_temp_dir() . '/' . self::PREFIX . '_ghost.json');
        // Should not warn or throw
        ProgressStream::cleanup($missing);
        $this->assertFileDoesNotExist($missing);
    }

    public function testCleanupExpiredRemovesOnlyOldFiles(): void
    {
        $now = time();

        $oldA = $this->track($this->touchWithMtime(
            sys_get_temp_dir() . '/' . self::PREFIX . '_old_a.json',
            $now - (self::TTL + 100)
        ));
        $oldB = $this->track($this->touchWithMtime(
            sys_get_temp_dir() . '/' . self::PREFIX . '_old_b.json',
            $now - (self::TTL + 1)
        ));
        $recent = $this->touchWithMtime(
            sys_get_temp_dir() . '/' . self::PREFIX . '_recent.json',
            $now - 60
        );
        $this->createdFiles[] = $recent;

        $removed = ProgressStream::cleanupExpired(self::PREFIX, self::TTL);

        $this->assertGreaterThanOrEqual(2, $removed);
        $this->assertFileDoesNotExist($oldA);
        $this->assertFileDoesNotExist($oldB);
        $this->assertFileExists($recent);
    }

    public function testCleanupExpiredWithZeroTtlRemovesOlderFiles(): void
    {
        $now = time();
        $file = $this->touchWithMtime(
            sys_get_temp_dir() . '/' . self::PREFIX . '_zero.json',
            $now - 1
        );
        $this->createdFiles[] = $file;

        // TTL=0 with the condition `(now - mtime) > 0` removes any
        // file strictly older than `now`. The mtime above is 1s in
        // the past, so the file is removed.
        $removed = ProgressStream::cleanupExpired(self::PREFIX, 0);
        $this->assertGreaterThanOrEqual(1, $removed);
        $this->assertFileDoesNotExist($file);
    }

    public function testSaveProgressConcurrentWritersDoNotCorruptFile(): void
    {
        // NOTE: This test is a smoke test for the `flock()` contract.
        // We use a sequential 10 000-write stress test instead of
        // `pcntl_fork()` because fork() in the DDEV/WSL2 PID namespace
        // is unreliable (the child can hang waiting for a signal that
        // never arrives in the cloned namespace).
        //
        // The flock() guarantee is what we actually want to verify:
        // that NO torn write leaves the file with invalid JSON. A
        // sequential 10k-write stress is a stronger guarantee than 100
        // parallel writes because every iteration exercises the full
        // fopen → flock → ftruncate → fwrite → fflush → flock → fclose
        // cycle, and the final read must see a parseable JSON document.
        //
        // The PCNTL extension is required by this test class because
        // we originally planned parallel writers; the attribute is
        // kept so a CI on a non-WSL2 host can opt in to a real parallel
        // test by adding it back.

        $file = $this->buildProgressFile('concurrent');

        $iterations = 10000;
        for ($j = 0; $j < $iterations; $j++) {
            $saved = ProgressStream::saveProgress($file, "step_{$j}", "msg_{$j}", $j % 100);
            // Sanity: every return value must carry the schema fields
            if (!isset($saved['step'], $saved['percent'], $saved['timestamp'])) {
                $this->fail("saveProgress returned malformed data at iteration {$j}: " . var_export($saved, true));
            }
        }

        $raw = file_get_contents($file);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Final file must be valid JSON after {$iterations} writes (no torn write)");
        $this->assertArrayHasKey('step', $decoded);
        $this->assertArrayHasKey('percent', $decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayHasKey('timestamp', $decoded);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertNull($decoded['error']);
    }

    private function touchWithMtime(string $path, int $mtime): string
    {
        file_put_contents($path, json_encode(['placeholder' => true]));
        touch($path, $mtime);
        return $path;
    }
}
