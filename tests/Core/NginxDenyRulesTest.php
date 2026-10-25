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
 * DSH-02 (VULN-0005) — the edge deny layer covers the non-runtime surface.
 *
 * The document root is the project root, and `nginx-sample.conf` is an inactive
 * sample: nothing in this repository executes it. So this test is a TEXT-LEVEL
 * contract over the sample, not a behavioural one. Two things are owed by the
 * operator and are deliberately not claimed here (DSH-05):
 *
 *   - `nginx -t` exits 0 after the directives are copied into the live server
 *     block;
 *   - `GET /openspec/changes/ventas-clientes-controller-dedup/verify-report.md`
 *     returns `403` and `GET /config.php` returns `404`.
 *
 * `^~` prefix locations are required rather than regex locations: they are
 * matched on the normalised, decoded URI and take precedence over the generic
 * `location ~ \.php$` handler, so they close the whole directory class instead
 * of one document.
 *
 * `themes/` MUST NOT be denied: it holds the CSS/JS the browser fetches.
 */
final class NginxDenyRulesTest extends TestCase
{
    /** Directories that exist in the web root and hold no runtime assets. */
    private const NON_RUNTIME_DIRECTORIES = [
        'openspec',
        'docs',
        'tools',
        'extras',
        'odd',
        'dev-tools',
        'managed_context',
        'test_suite_analysis',
    ];

    /** Prefix denies that must survive the change untouched. */
    private const PRE_EXISTING_PREFIX_DENIES = [
        'base',
        'model',
        'src',
        'controller',
        'scripts',
        'tests',
        'vendor',
        'tmp',
        'backups',
        '.git',
    ];

    /** Operator scripts that must keep returning 404. */
    private const PRE_EXISTING_EXACT_FILE_DENIES = [
        '/config.php',
        '/cron.php',
        '/debug_loader.php',
        '/visual_flow_demo.php',
        '/emergency_admin_reset.php',
        '/reset_admin_password.php',
    ];

    #[Test]
    public function testEveryNonRuntimeDirectoryHasAPrefixDeny(): void
    {
        $locations = self::parseLocations($this->config());

        foreach (self::NON_RUNTIME_DIRECTORIES as $directory) {
            $this->assertContains(
                '^~|/' . $directory . '/|deny all;',
                self::summarise($locations),
                sprintf(
                    'nginx-sample.conf must deny /%s/ with a `^~` prefix location (deny all;). '
                    . 'It exists in the web root and holds no runtime asset.',
                    $directory
                )
            );
        }
    }

    #[Test]
    public function testThemeAssetsAreNotDenied(): void
    {
        foreach (self::parseLocations($this->config()) as $location) {
            $this->assertStringNotContainsString(
                'themes',
                $location['path'],
                sprintf(
                    'nginx-sample.conf line %d denies a theme path. themes/ holds browser-fetched '
                    . 'CSS/JS and must stay served.',
                    $location['line']
                )
            );
        }
    }

    #[Test]
    public function testThePreExistingExactFileDeniesAreUnchanged(): void
    {
        $locations = self::parseLocations($this->config());

        foreach (self::PRE_EXISTING_EXACT_FILE_DENIES as $file) {
            $this->assertContains(
                '=|' . $file . '|return 404;',
                self::summarise($locations),
                $file . ' must keep its exact-file 404 deny.'
            );
        }
    }

    #[Test]
    public function testThePreExistingPrefixDeniesAreUnchanged(): void
    {
        $locations = self::parseLocations($this->config());

        foreach (self::PRE_EXISTING_PREFIX_DENIES as $directory) {
            $this->assertContains(
                '^~|/' . $directory . '/|deny all;',
                self::summarise($locations),
                '/' . $directory . '/ must keep its `^~` deny.'
            );
        }
    }

    #[Test]
    public function testThePrefixDenySetIsExactlyTheExpectedOne(): void
    {
        $denied = [];
        foreach (self::parseLocations($this->config()) as $location) {
            if ($location['modifier'] === '^~' && $location['action'] === 'deny all;') {
                $denied[] = trim($location['path'], '/');
            }
        }

        sort($denied);

        $expected = array_merge(self::PRE_EXISTING_PREFIX_DENIES, self::NON_RUNTIME_DIRECTORIES);
        sort($expected);

        $this->assertSame(
            $expected,
            $denied,
            'The `^~` deny set must be exactly the pre-existing directories plus the eight '
            . 'non-runtime ones: an unlisted denial can take a runtime asset down with it.'
        );
    }

    #[Test]
    public function testTheNewDeniesAreGroupedWithTheSensitiveDirectoryBlock(): void
    {
        $lines = preg_split('/\R/', $this->config()) ?: [];

        $blockStart = null;
        $blockEnd = count($lines);
        foreach ($lines as $index => $line) {
            if ($blockStart === null && str_contains($line, '# --- Sensitive directories')) {
                $blockStart = $index;
                continue;
            }
            if ($blockStart !== null && str_contains($line, '# --- Plugins')) {
                $blockEnd = $index;
                break;
            }
        }

        $this->assertNotNull($blockStart, 'nginx-sample.conf must keep its sensitive-directory block.');

        foreach (self::parseLocations($this->config()) as $location) {
            if ($location['modifier'] !== '^~') {
                continue;
            }

            $directory = trim($location['path'], '/');
            if (!in_array($directory, self::NON_RUNTIME_DIRECTORIES, true)) {
                continue;
            }

            $this->assertGreaterThan($blockStart, $location['line'] - 1);
            $this->assertLessThan(
                $blockEnd,
                $location['line'] - 1,
                sprintf('/%s/ must be grouped with the sensitive-directory block.', $directory)
            );
        }
    }

    #[Test]
    public function testThePluginDenyLayerIsPreserved(): void
    {
        $config = $this->config();

        $this->assertStringContainsString('location ~ ^/plugins/.+\.php$ {', $config);
        $this->assertStringContainsString('location ~ ^/plugins/system_updater/', $config);
    }

    private function config(): string
    {
        $path = FS_FOLDER . '/nginx-sample.conf';
        $config = file_get_contents($path);
        if ($config === false) {
            $this->fail($path . ' must be readable.');
        }

        return $config;
    }

    /**
     * @return array<int, array{modifier: string, path: string, action: string, line: int}>
     */
    private static function parseLocations(string $config): array
    {
        $locations = [];

        foreach (preg_split('/\R/', $config) ?: [] as $index => $line) {
            $matched = preg_match(
                '/^\s*location\s+(?<modifier>\^~|=|~\*|~)\s*(?<path>\S+)\s*\{(?<action>[^}]*)\}\s*$/',
                $line,
                $matches
            );

            if ($matched !== 1) {
                continue;
            }

            $locations[] = [
                'modifier' => $matches['modifier'],
                'path' => $matches['path'],
                'action' => trim($matches['action']),
                'line' => $index + 1,
            ];
        }

        return $locations;
    }

    /**
     * Flattens a parsed location into a comparable `modifier|path|action` token so a
     * missing rule is reported by assertion diff instead of by index.
     *
     * @param array<int, array{modifier: string, path: string, action: string, line: int}> $locations
     * @return array<int, string>
     */
    private static function summarise(array $locations): array
    {
        return array_map(
            static fn(array $location): string => sprintf(
                '%s|%s|%s',
                $location['modifier'],
                $location['path'],
                $location['action']
            ),
            $locations
        );
    }
}
