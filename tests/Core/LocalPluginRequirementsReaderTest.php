<?php

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Core\Plugin\LocalPluginRequirementsReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocalPluginRequirementsReaderTest extends TestCase
{
    private string $pluginsRoot;

    protected function setUp(): void
    {
        $this->pluginsRoot = sys_get_temp_dir() . '/fs_req_reader_' . uniqid('', true);
        mkdir($this->pluginsRoot . '/catalogo_core', 0777, true);
        mkdir($this->pluginsRoot . '/business_data', 0777, true);

        file_put_contents(
            $this->pluginsRoot . '/catalogo_core/fsframework.ini',
            "version = 1\nrequire = \n"
        );
        file_put_contents(
            $this->pluginsRoot . '/business_data/fsframework.ini',
            "version = 1\nrequire = catalogo_core\n"
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->pluginsRoot);
    }

    #[Test]
    public function readsRequireFieldFromLocalIni(): void
    {
        $reader = new LocalPluginRequirementsReader($this->pluginsRoot);

        $this->assertSame([], $reader->read('catalogo_core'));
        $this->assertSame(['catalogo_core'], $reader->read('business_data'));
    }

    #[Test]
    public function detectsInstalledPluginDirectory(): void
    {
        $reader = new LocalPluginRequirementsReader($this->pluginsRoot);

        $this->assertTrue($reader->isInstalled('business_data'));
        $this->assertFalse($reader->isInstalled('tpvmod'));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
