<?php

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Core\Plugin\PluginSchemaSynchronizer;
use FSFramework\Core\Template\InitClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../base/fs_model.php';

final class PluginSchemaSynchronizerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/fs_plugin_sync_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
        TestInitWithUpdate::$updateCalled = false;
        TestInitWithUpgrade::$upgradeCalled = false;
        TestInitClassPlugin::$updateCalled = false;
    }

    #[Test]
    public function callsInitUpdateWhenPluginDefinesIt(): void
    {
        $pluginName = 'test_init_update_plugin';
        $this->registerInitClass($pluginName, TestInitWithUpdate::class);

        $synchronizer = new PluginSchemaSynchronizer();
        $result = $synchronizer->synchronize($pluginName, $this->tempDir);

        $this->assertTrue($result['success']);
        $this->assertTrue(TestInitWithUpdate::$updateCalled);
        $this->assertContains('Init::update() ejecutado', $result['changes']);
    }

    #[Test]
    public function callsLegacyInitUpgradeWhenDefined(): void
    {
        $pluginName = 'test_init_upgrade_plugin';
        $this->registerInitClass($pluginName, TestInitWithUpgrade::class);

        $synchronizer = new PluginSchemaSynchronizer();
        $result = $synchronizer->synchronize($pluginName, $this->tempDir);

        $this->assertTrue($result['success']);
        $this->assertTrue(TestInitWithUpgrade::$upgradeCalled);
        $this->assertContains('Init::upgrade() ejecutado', $result['changes']);
    }

    #[Test]
    public function callsInitClassUpdateForModernPlugins(): void
    {
        $pluginName = 'test_init_class_plugin';
        $this->registerInitClass($pluginName, TestInitClassPlugin::class);

        $synchronizer = new PluginSchemaSynchronizer();
        $result = $synchronizer->synchronize($pluginName, $this->tempDir);

        $this->assertTrue($result['success']);
        $this->assertTrue(TestInitClassPlugin::$updateCalled);
        $this->assertContains('Init::update() ejecutado', $result['changes']);
    }

    #[Test]
    public function returnsSuccessWhenPluginHasNoDefinitions(): void
    {
        $synchronizer = new PluginSchemaSynchronizer();
        $result = $synchronizer->synchronize('plugin_without_anything_xyz', $this->tempDir);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['changes']);
        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function forgetCheckedTablesRemovesOnlyRequestedTables(): void
    {
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('checked_tables');
        $prop->setAccessible(true);
        $prop->setValue(null, ['clientes', 'articulos', 'fs_users']);

        \fs_model::forgetCheckedTables(['clientes', 'fs_users']);

        $remaining = $prop->getValue();
        $this->assertSame(['articulos'], $remaining);
    }

    private function registerInitClass(string $pluginName, string $className): void
    {
        if (!class_exists($className, false)) {
            $this->fail('Missing test Init class: ' . $className);
        }

        $fqcn = 'FSFramework\\Plugins\\' . $pluginName . '\\Init';
        class_alias($className, $fqcn);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
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

final class TestInitWithUpdate
{
    public static bool $updateCalled = false;

    public function init(): void
    {
        // Hook vacío intencional: el test verifica solo update().
    }

    public function update(): void
    {
        self::$updateCalled = true;
    }
}

final class TestInitWithUpgrade
{
    public static bool $upgradeCalled = false;

    public function init(): void
    {
        // Hook vacío intencional: el test verifica solo upgrade().
    }

    public static function upgrade(): void
    {
        self::$upgradeCalled = true;
    }
}

final class TestInitClassPlugin extends InitClass
{
    public static bool $updateCalled = false;

    public function init(): void
    {
        // Hook vacío intencional: el test verifica solo update().
    }

    public function uninstall(): void
    {
        // Hook vacío intencional: el test verifica solo update().
    }

    public function update(): void
    {
        self::$updateCalled = true;
    }
}
