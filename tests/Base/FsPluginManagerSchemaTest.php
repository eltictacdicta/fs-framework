<?php

declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_plugin_manager.php';

final class FsPluginManagerSchemaTest extends TestCase
{
    public function testApplyPluginSchemaUpdatesReturnsSuccessWhenPluginHasNoTableDefinitions(): void
    {
        $manager = new \fs_plugin_manager();
        $result = $manager->applyPluginSchemaUpdates('plugin_inexistente_schema_test_xyz');

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['changes']);
        $this->assertSame([], $result['errors']);
    }
}
