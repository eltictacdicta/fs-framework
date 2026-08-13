<?php

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Core\PluginActionHandler;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../base/fs_plugin_manager.php';

class PluginActionHandlerTest extends TestCase
{
    public function testHandleReturnsEmptyResultWhenNoActionRequested(): void
    {
        $manager = $this->createMock(\fs_plugin_manager::class);
        $handler = new PluginActionHandler($manager);

        $result = $handler->handle();

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['messages']);
        $this->assertSame([], $result['advices']);
    }
}
