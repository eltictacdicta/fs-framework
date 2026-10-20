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

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/updater.php';

/**
 * The self-update finalize endpoint is authorized only by a capability token
 * stored next to the staged plugin. The manifest must not be replayable: it
 * expires, and it is consumed before deploying.
 */
final class UpdaterManifestTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    public function testFreshManifestIsAccepted(): void
    {
        $path = $this->writeManifest(['token' => 'abc123', 'staged_path' => '/tmp/staged']);

        $manifest = updater_read_self_update_manifest($path);

        $this->assertIsArray($manifest);
        $this->assertSame('abc123', $manifest['token']);
    }

    public function testStaleManifestIsRejectedAndRemoved(): void
    {
        $path = $this->writeManifest(['token' => 'abc123', 'staged_path' => '/tmp/staged']);
        touch($path, time() - UPDATER_MANIFEST_MAX_AGE - 60);

        $this->assertNull(updater_read_self_update_manifest($path));
        $this->assertFileDoesNotExist($path);
    }

    public function testManifestWithoutRequiredFieldsIsRejectedAndRemoved(): void
    {
        $path = $this->writeManifest(['token' => 'abc123']);

        $this->assertNull(updater_read_self_update_manifest($path));
        $this->assertFileDoesNotExist($path);
    }

    public function testUnparsableManifestIsRejectedAndRemoved(): void
    {
        $path = $this->temporaryPath();
        file_put_contents($path, 'not-json');
        $this->temporaryFiles[] = $path;

        $this->assertNull(updater_read_self_update_manifest($path));
        $this->assertFileDoesNotExist($path);
    }

    public function testSessionBindingAcceptsThePreparingSession(): void
    {
        $secret = 'test-secret';

        $this->assertTrue(updater_session_binding_matches(
            $this->manifestWithBinding('sid-123', $secret),
            ['sess' => 'sid-123'],
            $secret
        ));
    }

    public function testSessionBindingRejectsAnotherSession(): void
    {
        $secret = 'test-secret';

        $this->assertFalse(updater_session_binding_matches(
            $this->manifestWithBinding('sid-123', $secret),
            ['sess' => 'sid-from-another-browser'],
            $secret
        ));
    }

    public function testSessionBindingRejectsAMissingCookie(): void
    {
        $this->assertFalse(updater_session_binding_matches(
            $this->manifestWithBinding('sid-123', 'test-secret'),
            [],
            'test-secret'
        ));
    }

    public function testSessionBindingRejectsWhenNoSecretIsAvailable(): void
    {
        $this->assertFalse(updater_session_binding_matches(
            $this->manifestWithBinding('sid-123', 'test-secret'),
            ['sess' => 'sid-123'],
            ''
        ));
    }

    /**
     * A manifest written by a plugin predating the binding must still finalize,
     * otherwise the updater could not upgrade itself from an old version.
     */
    public function testManifestWithoutBindingIsAcceptedForBackwardCompatibility(): void
    {
        $this->assertTrue(updater_session_binding_matches(
            ['token' => 'abc123', 'staged_path' => '/tmp/staged'],
            [],
            'test-secret'
        ));
    }

    /**
     * @return array<string, string>
     */
    private function manifestWithBinding(string $sessionId, string $secret): array
    {
        return [
            'token' => 'abc123',
            'staged_path' => '/tmp/staged',
            'session_name' => 'sess',
            'session_fingerprint' => hash_hmac('sha256', $sessionId, $secret),
        ];
    }

    private function writeManifest(array $data): string
    {
        $path = $this->temporaryPath();
        file_put_contents($path, json_encode($data));
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function temporaryPath(): string
    {
        return FS_FOLDER . '/tmp/' . uniqid('updater_manifest_', true) . '.json';
    }
}
