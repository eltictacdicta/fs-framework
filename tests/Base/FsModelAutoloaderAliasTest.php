<?php

declare(strict_types=1);

namespace Tests\Base;

use PHPUnit\Framework\TestCase;

final class FsModelAutoloaderAliasTest extends TestCase
{
    protected function tearDown(): void
    {
        if (class_exists('fs_model_autoloader', false)) {
            \fs_model_autoloader::clearCache();
        }

        parent::tearDown();
    }

    public function testEnsureGlobalAliasCreatesLegacyNameFromNamespacedModel(): void
    {
        if (!is_dir(FS_FOLDER . '/plugins/catalogo_core/model/core')) {
            $this->markTestSkipped('catalogo_core not installed');
        }

        // Cuando toda la suite corre en un mismo proceso, los tests de plugin
        // pueden declarar el FQCN real como stub inline (InitUpgradeTest.php
        // declara FSFramework\model\impuesto) antes de que este test cargue el
        // fichero real del plugin: ese require fallaria con "Cannot declare
        // class". El test se omite en ese escenario y se ejecuta en corridas
        // aisladas (suite Base/Core o el phpunit.xml del propio plugin).
        if (class_exists('FSFramework\\model\\impuesto', false)) {
            $this->markTestSkipped(
                'FSFramework\model\impuesto is already declared in this process by a plugin test stub; the real plugin model file cannot be loaded here'
            );
        }

        if (!class_exists('fs_model_autoloader', false)) {
            require_once FS_FOLDER . '/base/fs_model_autoloader.php';
        }

        $plugins = $GLOBALS['plugins'] ?? [];
        if (!in_array('catalogo_core', $plugins, true)) {
            $GLOBALS['plugins'][] = 'catalogo_core';
        }

        \fs_model_autoloader::register(false);
        \fs_model_autoloader::refreshModelDirs();

        $this->assertFalse(class_exists('impuesto', false));

        $this->assertTrue(\fs_model_autoloader::loadClass('impuesto'));
        $this->assertTrue(class_exists('impuesto', false));
        $this->assertTrue(class_exists('FSFramework\\model\\impuesto', false));
        $this->assertSame('FSFramework\\model\\impuesto', get_class(new \impuesto()));
    }
}
