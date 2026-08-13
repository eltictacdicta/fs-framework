<?php

declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

class UpdaterPathValidationTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        require_once FS_FOLDER . '/updater.php';

        $this->tmpRoot = sys_get_temp_dir() . '/updater-path-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/tmp/valid-plugin/controller', 0777, true);
        file_put_contents($this->tmpRoot . '/tmp/valid-plugin/fsframework.ini', "version = 1\n");
        file_put_contents($this->tmpRoot . '/tmp/valid-plugin/controller/admin_updater.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
    }

    public function testPathIsWithinAcceptsValidChildDirectory(): void
    {
        $child = realpath($this->tmpRoot . '/tmp/valid-plugin');

        $this->assertNotFalse($child);
        $this->assertTrue(updater_path_is_within($child, realpath($this->tmpRoot . '/tmp')));
    }

    public function testPathIsWithinRejectsSiblingWithSharedPrefix(): void
    {
        mkdir($this->tmpRoot . '/tmp-evil', 0777, true);
        $sibling = realpath($this->tmpRoot . '/tmp-evil');

        $this->assertNotFalse($sibling);
        $this->assertFalse(updater_path_is_within($sibling, realpath($this->tmpRoot . '/tmp')));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . '/' . $item;
            if (is_dir($fullPath)) {
                $this->removeTree($fullPath);
                continue;
            }

            unlink($fullPath);
        }

        rmdir($path);
    }
}
