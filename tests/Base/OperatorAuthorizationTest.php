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

require_once FS_FOLDER . '/base/fs_operator_guard.php';

/**
 * Operator scripts (cron, diagnostics, emergency helpers) must not decide web
 * access from REMOTE_ADDR alone: behind a reverse proxy that address is the
 * proxy, so every client looks local. For cron.php this meant anyone could
 * trigger plugin crons, the table self-heal and the migrations.
 */
final class OperatorAuthorizationTest extends TestCase
{
    public function testCliIsAlwaysAllowed(): void
    {
        $this->assertTrue(fs_operator_authorize('cli', ['REMOTE_ADDR' => '203.0.113.9'], [], null));
    }

    public function testLoopbackWithoutTokenIsAllowed(): void
    {
        $this->assertTrue(fs_operator_authorize('fpm-fcgi', ['REMOTE_ADDR' => '127.0.0.1'], [], null));
        $this->assertTrue(fs_operator_authorize('fpm-fcgi', ['REMOTE_ADDR' => '::1'], [], null));
    }

    public function testPublicAddressWithoutTokenIsDenied(): void
    {
        $this->assertFalse(fs_operator_authorize('fpm-fcgi', ['REMOTE_ADDR' => '203.0.113.9'], [], null));
    }

    /**
     * The regression this guard exists for: a same-host reverse proxy makes
     * every client look like loopback.
     */
    public function testForwardedRequestFromLoopbackIsDenied(): void
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ];

        $this->assertFalse(fs_operator_authorize('fpm-fcgi', $server, [], null));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forwardingHeaders(): array
    {
        return [
            'x-forwarded-for' => ['HTTP_X_FORWARDED_FOR'],
            'x-real-ip' => ['HTTP_X_REAL_IP'],
            'forwarded' => ['HTTP_FORWARDED'],
            'cf-connecting-ip' => ['HTTP_CF_CONNECTING_IP'],
            'true-client-ip' => ['HTTP_TRUE_CLIENT_IP'],
        ];
    }

    #[DataProvider('forwardingHeaders')]
    public function testAnyForwardingHeaderDisqualifiesLoopback(string $header): void
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1', $header => '203.0.113.9'];

        $this->assertFalse(fs_operator_authorize('fpm-fcgi', $server, [], null));
    }

    public function testConfiguredTokenAcceptsTheHeader(): void
    {
        $server = [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_OPERATOR_TOKEN' => 'a-long-random-secret',
        ];

        $this->assertTrue(fs_operator_authorize('fpm-fcgi', $server, [], 'a-long-random-secret'));
    }

    public function testConfiguredTokenAcceptsTheQueryParameter(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.9'];

        $this->assertTrue(fs_operator_authorize('fpm-fcgi', $server, ['token' => 'a-long-random-secret'], 'a-long-random-secret'));
    }

    public function testConfiguredTokenRejectsAWrongValue(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_OPERATOR_TOKEN' => 'wrong'];

        $this->assertFalse(fs_operator_authorize('fpm-fcgi', $server, [], 'a-long-random-secret'));
    }

    public function testConfiguredTokenRejectsAMissingValue(): void
    {
        $this->assertFalse(fs_operator_authorize('fpm-fcgi', ['REMOTE_ADDR' => '127.0.0.1'], [], 'a-long-random-secret'));
    }

    public function testConfiguredTokenIsTrimmed(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_OPERATOR_TOKEN' => '  a-long-random-secret  '];

        $this->assertTrue(fs_operator_authorize('fpm-fcgi', $server, [], " a-long-random-secret\n"));
    }

    /**
     * A blank constant must not behave like a configured token; it falls back
     * to the local-caller rule.
     */
    public function testBlankTokenFallsBackToTheLocalCallerRule(): void
    {
        $this->assertTrue(fs_operator_authorize('fpm-fcgi', ['REMOTE_ADDR' => '127.0.0.1'], [], '   '));
        $this->assertFalse(fs_operator_authorize('fpm-fcgi', ['REMOTE_ADDR' => '203.0.113.9'], [], '   '));
    }

    /**
     * @return array<string, array{string, 'cli-only'|'guard'}>
     */
    public static function operatorScripts(): array
    {
        return [
            'debug_loader' => ['/debug_loader.php', 'cli-only'],
            'visual_flow_demo' => ['/visual_flow_demo.php', 'cli-only'],
            'reset_admin_password' => ['/scripts/reset-admin-password.php', 'cli-only'],
        ];
    }

    /**
     * No operator script may keep the old REMOTE_ADDR-only web guard.
     */
    #[DataProvider('operatorScripts')]
    public function testScriptDoesNotTrustRemoteAddrAlone(string $relativePath, string $mode): void
    {
        $source = $this->source($relativePath);

        $this->assertStringNotContainsString(
            '$remoteAddr',
            $source,
            basename($relativePath) . ' must not decide web access from REMOTE_ADDR alone.'
        );
    }

    #[DataProvider('operatorScripts')]
    public function testScriptDeniesWebAccess(string $relativePath, string $mode): void
    {
        $source = $this->source($relativePath);

        if ($mode === 'cli-only') {
            $this->assertStringContainsString(
                "if (PHP_SAPI !== 'cli')",
                $source,
                basename($relativePath) . ' is a CLI-only tool and must reject every web request.'
            );

            return;
        }

        $this->assertStringContainsString(
            'fs_operator_authorize(PHP_SAPI',
            $source,
            basename($relativePath) . ' must authorize web access through the shared guard.'
        );
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
