<?php

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Core\Plugin\CircularPluginDependencyException;
use FSFramework\Core\Plugin\PluginDependencyResolver;
use FSFramework\Core\Plugin\PluginInstallProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PluginDependencyResolverTest extends TestCase
{
    #[Test]
    public function buildsTransitiveActivationOrder(): void
    {
        $provider = new ArrayPluginInstallProvider([
            'tpvmod' => ['clientes_facturacion', 'catalogo_core'],
            'clientes_facturacion' => ['clientes_core'],
            'clientes_core' => ['business_data'],
            'business_data' => [],
            'catalogo_core' => [],
        ]);

        $resolver = new PluginDependencyResolver($provider);
        $order = $resolver->buildActivationOrder('tpvmod');

        $this->assertSame('tpvmod', $order[array_key_last($order)]);
        $this->assertLessThan(
            array_search('clientes_core', $order, true),
            array_search('business_data', $order, true)
        );
        $this->assertLessThan(
            array_search('clientes_facturacion', $order, true),
            array_search('clientes_core', $order, true)
        );
        $this->assertLessThan(
            array_search('tpvmod', $order, true),
            array_search('clientes_facturacion', $order, true)
        );
        $this->assertLessThan(
            array_search('tpvmod', $order, true),
            array_search('catalogo_core', $order, true)
        );
        $this->assertCount(5, $order);
    }

    #[Test]
    public function buildsFacturaPdf1TransitiveActivationOrder(): void
    {
        $provider = new ArrayPluginInstallProvider([
            'factura_pdf1' => ['tpvmod'],
            'tpvmod' => ['clientes_facturacion', 'catalogo_core'],
            'clientes_facturacion' => ['clientes_core'],
            'clientes_core' => ['business_data'],
            'business_data' => [],
            'catalogo_core' => [],
        ]);

        $resolver = new PluginDependencyResolver($provider);
        $order = $resolver->buildActivationOrder('factura_pdf1');

        $this->assertSame(
            [
                'business_data',
                'clientes_core',
                'clientes_facturacion',
                'catalogo_core',
                'tpvmod',
                'factura_pdf1',
            ],
            $order
        );
    }

    #[Test]
    public function detectsCircularDependencies(): void
    {
        $provider = new ArrayPluginInstallProvider([
            'plugin_a' => ['plugin_b'],
            'plugin_b' => ['plugin_a'],
        ]);

        $resolver = new PluginDependencyResolver($provider);

        $this->expectException(CircularPluginDependencyException::class);
        $resolver->buildActivationOrder('plugin_a');
    }

    #[Test]
    public function singlePluginWithoutDependenciesReturnsItself(): void
    {
        $provider = new ArrayPluginInstallProvider([
            'catalogo_core' => [],
        ]);

        $resolver = new PluginDependencyResolver($provider);

        $this->assertSame(['catalogo_core'], $resolver->buildActivationOrder('catalogo_core'));
    }
}

/**
 * @internal
 *
 * @var array<string, list<string>> $requirements
 */
final class ArrayPluginInstallProvider implements PluginInstallProvider
{
    public function __construct(
        private array $requirements,
        private array $installed = [],
    ) {
    }

    public function isInstalled(string $pluginName): bool
    {
        return in_array($pluginName, $this->installed, true);
    }

    public function getDirectRequirements(string $pluginName): array
    {
        return $this->requirements[$pluginName] ?? [];
    }

    public function installIfAvailable(string $pluginName): bool
    {
        $this->installed[] = $pluginName;

        return true;
    }

    public function getLastError(): string
    {
        return '';
    }
}
