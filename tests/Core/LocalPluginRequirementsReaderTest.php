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

    #[Test]
    public function readsFacturaPdf1DependencyChainFromLocalIni(): void
    {
        $pluginsRoot = sys_get_temp_dir() . '/fs_req_chain_' . uniqid('', true);

        $chain = [
            'business_data' => "version = 1\nrequire = \n",
            'catalogo_core' => "version = 1\nrequire = \n",
            'clientes_core' => "version = 1\nrequire = business_data\n",
            'clientes_facturacion' => "version = 1\nrequire = clientes_core\n",
            'tpvmod' => "version = 1\nrequire = clientes_facturacion,catalogo_core\n",
            'factura_pdf1' => "version = 1\nrequire = tpvmod\n",
        ];

        try {
            foreach ($chain as $plugin => $ini) {
                mkdir($pluginsRoot . '/' . $plugin, 0777, true);
                file_put_contents($pluginsRoot . '/' . $plugin . '/fsframework.ini', $ini);
            }

            $reader = new LocalPluginRequirementsReader($pluginsRoot);

            $this->assertSame([], $reader->read('business_data'));
            $this->assertSame(['business_data'], $reader->read('clientes_core'));
            $this->assertSame(['clientes_core'], $reader->read('clientes_facturacion'));
            $this->assertSame(['clientes_facturacion', 'catalogo_core'], $reader->read('tpvmod'));
            $this->assertSame(['tpvmod'], $reader->read('factura_pdf1'));
        } finally {
            $this->removeTree($pluginsRoot);
        }
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
