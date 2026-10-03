<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/model/idioma.php';

/**
 * Q6 / D1 — the `idioma` class name and `idiomas` table are core-reserved and
 * must not collide with any plugin-declared model or table.
 */
final class ContentI18nReservedNameTest extends TestCase
{
    public function test_idioma_resolves_to_the_core_model_file(): void
    {
        $reflection = new \ReflectionClass('idioma');

        $this->assertSame('idioma', $reflection->getShortName());
        $this->assertStringEndsWith(
            '/model/idioma.php',
            str_replace('\\', '/', (string) $reflection->getFileName()),
            'the idioma class must be the core model at model/idioma.php'
        );
    }

    public function test_idiomas_table_is_core_reserved(): void
    {
        $this->assertSame('idiomas', \idioma::TABLE);
        $this->assertSame('idioma', (new \ReflectionClass('idioma'))->getShortName());
    }

    public function test_no_plugin_declares_a_conflicting_idioma_class(): void
    {
        $collisions = [];
        foreach (glob(FS_FOLDER . '/plugins/*', GLOB_ONLYDIR) ?: [] as $pluginDir) {
            $plugin = basename($pluginDir);
            if (str_ends_with($plugin, '_back')) {
                continue;
            }

            foreach ($this->phpFiles($pluginDir) as $file) {
                $source = (string) file_get_contents($file);
                if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+idioma\b/m', $source)) {
                    $collisions[] = str_replace(FS_FOLDER . '/', '', $file);
                }
            }
        }

        $this->assertSame([], $collisions, 'no plugin may declare a global class idioma');
    }

    public function test_no_plugin_ships_a_conflicting_idiomas_table(): void
    {
        $collisions = [];
        foreach (glob(FS_FOLDER . '/plugins/*', GLOB_ONLYDIR) ?: [] as $pluginDir) {
            $plugin = basename($pluginDir);
            if (str_ends_with($plugin, '_back')) {
                continue;
            }

            if (is_file($pluginDir . '/model/table/idiomas.xml')) {
                $collisions[] = $plugin;
            }
        }

        $this->assertSame([], $collisions, 'no plugin may ship a core-idiomas table schema');
        $this->assertFileExists(FS_FOLDER . '/model/table/idiomas.xml', 'the core schema must exist');
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
