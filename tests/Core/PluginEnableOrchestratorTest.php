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
        $this->assertSame(['catalogo_core', 'business_data'], $manager->enabledCalls);
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
        $this->assertSame(['business_data'], $manager->enabledCalls);
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
    /** @var list<string> */
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

    public function enableWithoutDependencyResolution($plugin_name): bool
    {
        $this->enabled[] = (string) $plugin_name;
        $this->enabledCalls[] = (string) $plugin_name;

        return true;
    }

    public function logPluginError(string $message): void
    {
        $this->errors[] = $message;
    }
}
