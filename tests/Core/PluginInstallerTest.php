<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
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
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Core\PluginInstaller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../base/fs_file_manager.php';
require_once __DIR__ . '/../../base/fs_plugin_manager.php';

#[CoversClass(PluginInstaller::class)]
class PluginInstallerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive extension not available');
        }

        $this->tempDir = sys_get_temp_dir() . '/fs_test_installer_' . uniqid();
        mkdir($this->tempDir . '/plugins', 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $this->removeTree($this->tempDir);
        }
    }

    #[Test]
    public function cleansUpZipWhenExtractionFails(): void
    {
        $zipPath = $this->tempDir . '/download_updater.zip';

        $installer = new class($this->createPluginManagerMock()) extends PluginInstaller {
            protected function downloadSystemUpdater(string $githubUrl, string $downloadPath): bool
            {
                file_put_contents($downloadPath, 'not-a-valid-zip-content');
                return true;
            }
        };
        $result = $installer->installSystemUpdaterIn($this->tempDir);

        $this->assertNotEmpty($result['errors']);
        $this->assertFileDoesNotExist($zipPath);
    }

    #[Test]
    public function cleansUpZipWhenPluginAlreadyInstalledAndEnabled(): void
    {
        mkdir($this->tempDir . '/plugins/system_updater/controller', 0777, true);
        file_put_contents(
            $this->tempDir . '/plugins/system_updater/controller/admin_updater.php',
            '<?php'
        );

        $zipPath = $this->tempDir . '/download_updater.zip';
        file_put_contents($zipPath, 'leftover-zip-from-previous-run');

        $installer = new PluginInstaller($this->createPluginManagerMock(['system_updater']));
        $result = $installer->installSystemUpdaterIn($this->tempDir);

        $this->assertEmpty($result['errors']);
        $this->assertFileDoesNotExist($zipPath);
    }

    #[Test]
    public function cleansUpZipWhenExtractionSucceedsButEnableFails(): void
    {
        $zipPath = $this->tempDir . '/download_updater.zip';

        $pluginManager = $this->createMock(\fs_plugin_manager::class);
        $pluginManager->method('enabled')->willReturn([]);
        $pluginManager->method('enable')->willReturn(false);

        $installer = new class($pluginManager) extends PluginInstaller {
            protected function downloadSystemUpdater(string $githubUrl, string $downloadPath): bool
            {
                $zip = new \ZipArchive();
                $zip->open($downloadPath, \ZipArchive::CREATE);
                $zip->addEmptyDir('system_updater-master');
                $zip->addEmptyDir('system_updater-master/controller');
                $zip->addFromString('system_updater-master/controller/admin_updater.php', '<?php // stub');
                $zip->addFromString('system_updater-master/fsframework.ini', 'version=1');
                $zip->close();
                return true;
            }
        };

        $result = $installer->installSystemUpdaterIn($this->tempDir);

        $this->assertFileDoesNotExist($zipPath);
    }

    private function createPluginManagerMock(array $enabled = []): \fs_plugin_manager
    {
        $mock = $this->createMock(\fs_plugin_manager::class);
        $mock->method('enabled')->willReturn($enabled);
        $mock->method('enable')->willReturn(true);
        return $mock;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
