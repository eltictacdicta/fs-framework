<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/controller/admin_home.php';

final class AdminHomeUpdatesTest extends TestCase
{
    #[Test]
    public function pluginEntryReturnsFalseWhenRemoteVersionCannotBeFetched(): void
    {
        $home = $this->createTestableAdminHome();

        $this->assertFalse($home->exposesPluginEntryHasRemoteUpdate([
            'version_url' => 'https://invalid.invalid/version.ini',
            'update_url' => 'https://invalid.invalid/update.zip',
            'version' => 1,
            'idplugin' => '',
            'download2_url' => '',
        ]));
    }

    #[Test]
    public function pluginEntryDetectsPaidPluginWithDownloadUrl(): void
    {
        $home = $this->createTestableAdminHome();

        $this->assertTrue($home->exposesPluginEntryHasRemoteUpdate([
            'version_url' => '',
            'update_url' => '',
            'version' => 5,
            'idplugin' => 99,
            'download2_url' => 'https://example.test/paid.zip',
        ]));
    }

    #[Test]
    public function pluginEntryReturnsFalseWhenNoUpdateSignals(): void
    {
        $home = $this->createTestableAdminHome();

        $this->assertFalse($home->exposesPluginEntryHasRemoteUpdate([
            'version_url' => '',
            'update_url' => '',
            'version' => 1,
            'idplugin' => '',
            'download2_url' => '',
        ]));
    }

    #[Test]
    public function installedPluginsHaveUpdatesStopsAtFirstMatch(): void
    {
        $home = $this->createTestableAdminHome();
        $home->pluginEntries = [
            [
                'version_url' => '',
                'update_url' => '',
                'version' => 1,
                'idplugin' => '',
                'download2_url' => '',
            ],
            [
                'version_url' => '',
                'update_url' => '',
                'version' => 1,
                'idplugin' => 42,
                'download2_url' => 'https://example.test/update.zip',
            ],
        ];

        $this->assertTrue($home->exposesInstalledPluginsHaveUpdates());
    }

    private function createTestableAdminHome(): TestableAdminHome
    {
        return new TestableAdminHome();
    }
}

/**
 * @internal
 */
final class TestableAdminHome extends \admin_home
{
    /** @var list<array<string, mixed>> */
    public array $pluginEntries = [];

    public function __construct()
    {
        // Sin boot del controlador legacy.
    }

    /**
     * @param array<string, mixed> $plugin
     */
    public function exposesPluginEntryHasRemoteUpdate(array $plugin): bool
    {
        return $this->pluginEntryHasRemoteUpdate($plugin);
    }

    public function exposesInstalledPluginsHaveUpdates(): bool
    {
        $this->plugin_manager = new class($this->pluginEntries) {
            /** @param list<array<string, mixed>> $entries */
            public function __construct(private array $entries)
            {
            }

            public function installed(): array
            {
                return $this->entries;
            }
        };

        return $this->installedPluginsHaveUpdates();
    }
}
