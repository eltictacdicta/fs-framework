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

namespace Tests\Base;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_maintenance_mode.php';

/**
 * The maintenance bypass reads a session snapshot to decide whether the
 * current visitor is an admin. The session id comes straight from a cookie and
 * was passed to session_id() unvalidated, while use_strict_mode is only set
 * later by SessionManager. A malformed or foreign id must be discarded before
 * PHP opens anything.
 */
final class MaintenanceSessionSnapshotTest extends TestCase
{
    private string $sessionName = 'FSSESS_TEST';

    protected function tearDown(): void
    {
        unset($_COOKIE[$this->sessionName]);

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedSessionIds(): array
    {
        return [
            'path traversal' => ['../../etc/passwd'],
            'sql-ish' => ["abc'; DROP TABLE fs_users; --"],
            'too short' => ['abc'],
            'spaces' => ['abcdefghij klmnopqrstuvwx'],
            'html' => ['<script>alert(1)</script>'],
        ];
    }

    #[DataProvider('rejectedSessionIds')]
    public function testMalformedSessionIdIsDiscarded(string $sessionId): void
    {
        $_COOKIE[$this->sessionName] = $sessionId;

        $before = session_id();
        $snapshot = $this->readSnapshot($this->sessionName);

        $this->assertSame([], $snapshot, 'A malformed session id must not open a session.');
        $this->assertSame($before, session_id(), 'session_id() must not be changed for malformed input.');
    }

    public function testEmptyCookieReturnsEmptySnapshot(): void
    {
        $_COOKIE[$this->sessionName] = '   ';

        $this->assertSame([], $this->readSnapshot($this->sessionName));
    }

    private function readSnapshot(string $sessionName): array
    {
        $method = new \ReflectionMethod(\fs_maintenance_mode::class, 'readSessionSnapshotForName');
        $method->setAccessible(true);

        return (array) $method->invoke(null, $sessionName);
    }
}
