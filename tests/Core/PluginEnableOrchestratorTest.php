<?php

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Core\Plugin\PluginEnableOrchestrator;
use FSFramework\Core\Plugin\PluginInstallProvider;
use FSFramework\Core\Plugin\PluginInstallProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../base/fs_plugin_manager.php';

final class PluginEnableOrchestratorTest extends TestCase
{
    protected function setUp(): void
    {
        PluginInstallProviderRegistry::reset();
    }

    protected function tearDown(): void
    {
        PluginInstallProviderRegistry::reset();
    }

    #[Test]
    public function installsMissingDependenciesBeforeEnablingTarget(): void
    {
        $provider = new RecordingPluginInstallProvider([
            'business_data' => ['catalogo_core'],
        ], installed: ['catalogo_core']);

        PluginInstallProviderRegistry::register($provider);

        $manager = new TestPluginManager();
        $orchestrator = new PluginEnableOrchestrator($manager);

        $this->assertTrue($orchestrator->enable('business_data'));
        $this->assertSame(
            [
                ['plugin' => 'catalogo_core', 'runWizard' => false],
                ['plugin' => 'business_data', 'runWizard' => true],
            ],
            $manager->enabledCalls
        );
        $this->assertSame(['business_data'], $provider->installedCalls);
    }

    #[Test]
    public function failsWhenMissingDependencyCannotBeInstalled(): void
    {
        $provider = new RecordingPluginInstallProvider([
            'tpvmod' => ['api_base'],
        ]);

        PluginInstallProviderRegistry::register($provider);

        $manager = new TestPluginManager();
        $orchestrator = new PluginEnableOrchestrator($manager);

        $this->assertFalse($orchestrator->enable('tpvmod'));
        $this->assertSame([], $manager->enabledCalls);
        $this->assertNotEmpty($manager->errors);
    }

    #[Test]
    public function skipsAlreadyEnabledDependencies(): void
    {
        $provider = new RecordingPluginInstallProvider([
            'business_data' => ['catalogo_core'],
        ], installed: ['catalogo_core']);

        PluginInstallProviderRegistry::register($provider);

        $manager = new TestPluginManager(['catalogo_core']);
        $orchestrator = new PluginEnableOrchestrator($manager);

        $this->assertTrue($orchestrator->enable('business_data'));
        $this->assertSame([['plugin' => 'business_data', 'runWizard' => true]], $manager->enabledCalls);
    }

    #[Test]
    public function enablesFullFacturaPdf1DependencyChainWhenInstalledLocally(): void
    {
        $requirements = [
            'factura_pdf1' => ['tpvmod'],
            'tpvmod' => ['clientes_facturacion', 'catalogo_core'],
            'clientes_facturacion' => ['clientes_core'],
            'clientes_core' => ['business_data'],
            'business_data' => [],
            'catalogo_core' => [],
        ];
        $installed = array_keys($requirements);

        PluginInstallProviderRegistry::register(new RecordingPluginInstallProvider($requirements, installed: $installed));

        $manager = new TestPluginManager();
        $orchestrator = new PluginEnableOrchestrator($manager);

        $this->assertTrue($orchestrator->enable('factura_pdf1'));
        $this->assertSame(
            [
                ['plugin' => 'business_data', 'runWizard' => false],
                ['plugin' => 'clientes_core', 'runWizard' => false],
                ['plugin' => 'clientes_facturacion', 'runWizard' => false],
                ['plugin' => 'catalogo_core', 'runWizard' => false],
                ['plugin' => 'tpvmod', 'runWizard' => false],
                ['plugin' => 'factura_pdf1', 'runWizard' => true],
            ],
            $manager->enabledCalls
        );
        $this->assertSame([], $manager->errors);
    }

    #[Test]
    public function enablesOnlyMissingPluginsWhenPartialChainAlreadyActive(): void
    {
        $requirements = [
            'factura_pdf1' => ['tpvmod'],
            'tpvmod' => ['clientes_facturacion', 'catalogo_core'],
            'clientes_facturacion' => ['clientes_core'],
            'clientes_core' => ['business_data'],
            'business_data' => [],
            'catalogo_core' => [],
        ];
        $installed = array_keys($requirements);
        $alreadyEnabled = ['business_data', 'catalogo_core', 'clientes_core'];

        PluginInstallProviderRegistry::register(new RecordingPluginInstallProvider($requirements, installed: $installed));

        $manager = new TestPluginManager($alreadyEnabled);
        $orchestrator = new PluginEnableOrchestrator($manager);

        $this->assertTrue($orchestrator->enable('factura_pdf1'));
        $this->assertSame(
            [
                ['plugin' => 'clientes_facturacion', 'runWizard' => false],
                ['plugin' => 'tpvmod', 'runWizard' => false],
                ['plugin' => 'factura_pdf1', 'runWizard' => true],
            ],
            $manager->enabledCalls
        );
    }

    #[Test]
    public function inspectActivationListsMissingPluginsInPlanOrder(): void
    {
        $requirements = [
            'factura_pdf1' => ['tpvmod'],
            'tpvmod' => ['catalogo_core'],
            'catalogo_core' => [],
        ];

        PluginInstallProviderRegistry::register(new RecordingPluginInstallProvider(
            $requirements,
            installed: ['catalogo_core']
        ));

        $orchestrator = new PluginEnableOrchestrator(new TestPluginManager());

        $inspection = $orchestrator->inspectActivation('factura_pdf1');

        $this->assertTrue($inspection['success']);
        $this->assertSame('factura_pdf1', $inspection['target']);
        $this->assertSame(['catalogo_core', 'tpvmod', 'factura_pdf1'], $inspection['plan']);
        $this->assertSame(['tpvmod', 'factura_pdf1'], $inspection['missing']);
        $this->assertSame(['catalogo_core', 'tpvmod', 'factura_pdf1'], $inspection['pending_activation']);
    }

    #[Test]
    public function downloadPluginInstallsOnlyRequestedPlugin(): void
    {
        $provider = new RecordingPluginInstallProvider([
            'tpvmod' => ['catalogo_core'],
            'catalogo_core' => [],
        ]);

        PluginInstallProviderRegistry::register($provider);

        $orchestrator = new PluginEnableOrchestrator(new TestPluginManager());

        $this->assertTrue($orchestrator->downloadPlugin('tpvmod'));
        $this->assertSame(['tpvmod'], $provider->installedCalls);
        $this->assertTrue($provider->isInstalled('tpvmod'));
    }

    #[Test]
    public function enablePluginStepActivatesDependenciesInOrder(): void
    {
        $requirements = [
            'factura_pdf1' => ['tpvmod'],
            'tpvmod' => ['catalogo_core'],
            'catalogo_core' => [],
        ];

        PluginInstallProviderRegistry::register(new RecordingPluginInstallProvider(
            $requirements,
            installed: array_keys($requirements)
        ));

        $manager = new TestPluginManager();
        $orchestrator = new PluginEnableOrchestrator($manager);

        $this->assertTrue($orchestrator->enablePluginStep('factura_pdf1', 'catalogo_core'));
        $this->assertTrue($orchestrator->enablePluginStep('factura_pdf1', 'tpvmod'));
        $this->assertTrue($orchestrator->enablePluginStep('factura_pdf1', 'factura_pdf1'));
        $this->assertSame(
            [
                ['plugin' => 'catalogo_core', 'runWizard' => false],
                ['plugin' => 'tpvmod', 'runWizard' => false],
                ['plugin' => 'factura_pdf1', 'runWizard' => true],
            ],
            $manager->enabledCalls
        );
    }

    #[Test]
    public function enablePluginStepRejectsOutOfOrderActivation(): void
    {
        PluginInstallProviderRegistry::register(new RecordingPluginInstallProvider(
            [
                'factura_pdf1' => ['tpvmod'],
                'tpvmod' => [],
            ],
            installed: ['factura_pdf1', 'tpvmod']
        ));

        $manager = new TestPluginManager();
        $orchestrator = new PluginEnableOrchestrator($manager);

        $this->assertFalse($orchestrator->enablePluginStep('factura_pdf1', 'factura_pdf1'));
        $this->assertNotEmpty($manager->errors);
    }

    #[Test]
    public function enablePluginStepRejectsAlreadyEnabledPluginOutsidePlan(): void
    {
        PluginInstallProviderRegistry::register(new RecordingPluginInstallProvider(
            [
                'factura_pdf1' => ['tpvmod'],
                'tpvmod' => [],
            ],
            installed: ['factura_pdf1', 'tpvmod', 'catalogo_core']
        ));

        $manager = new TestPluginManager(['catalogo_core']);
        $orchestrator = new PluginEnableOrchestrator($manager);

        $this->assertFalse($orchestrator->enablePluginStep('factura_pdf1', 'catalogo_core'));
        $this->assertNotEmpty($manager->errors);
    }
}

/**
 * @internal
 */
final class RecordingPluginInstallProvider implements PluginInstallProvider
{
    /** @var list<string> */
    public array $installedCalls = [];

    private string $lastError = '';

    /**
     * @param array<string, list<string>> $requirements
     * @param list<string> $installed
     */
    public function __construct(
        private array $requirements,
        private array $installed = [],
        private array $installable = [],
    ) {
        if ($installable === []) {
            $this->installable = array_keys($requirements);
        }
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
        if ($this->isInstalled($pluginName)) {
            return true;
        }

        if (!in_array($pluginName, $this->installable, true)) {
            $this->lastError = 'Plugin ' . $pluginName . ' no está en el catálogo público.';

            return false;
        }

        $this->installed[] = $pluginName;
        $this->installedCalls[] = $pluginName;

        return true;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }
}

/**
 * @internal
 */
final class TestPluginManager extends \fs_plugin_manager
{
    /** @var list<array{plugin: string, runWizard: bool}> */
    public array $enabledCalls = [];

    /** @var list<string> */
    public array $errors = [];

    /**
     * @param list<string> $enabled
     */
    public function __construct(private array $enabled = [])
    {
    }

    public function resolvePluginName($plugin_name): string
    {
        return (string) $plugin_name;
    }

    public function is_plugin_enabled($pluginName): bool
    {
        return in_array($pluginName, $this->enabled, true);
    }

    public function enableWithoutDependencyResolution($plugin_name, $runWizard = true): bool
    {
        $this->enabled[] = (string) $plugin_name;
        $this->enabledCalls[] = ['plugin' => (string) $plugin_name, 'runWizard' => (bool) $runWizard];

        return true;
    }

    public function logPluginError(string $message): void
    {
        $this->errors[] = $message;
    }
}
