<?php

declare(strict_types=1);

namespace Tests\Core;

use FSFramework\Core\Plugin\PluginEnableOrchestrator;
use FSFramework\Core\Plugin\PluginInstallProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../base/fs_plugin_manager.php';
require_once __DIR__ . '/PluginEnableOrchestratorTest.php';

/**
 * Verifies that the AJAX guard in fs_plugin_manager::enableWithoutDependencyResolution()
 * suppresses HTTP redirects during AJAX activation and exposes wizard info.
 *
 * Unlike the previous version, this test exercises the REAL production code path:
 * only isAjaxRequest() and infrastructure methods (save, cache, schema, redirect)
 * are doubled. The guard logic itself runs unmodified.
 *
 * @see openspec/changes/plugin-enable-ajax-wizard/specs/plugin-enable-ajax-safety/spec.md
 */
final class PluginEnableAjaxSafetyTest extends TestCase
{
    /** @var string[] Backup of $GLOBALS['plugins'] before each test */
    private array $originalPlugins;

    protected function setUp(): void
    {
        PluginInstallProviderRegistry::reset();
        $this->originalPlugins = $GLOBALS['plugins'] ?? [];
    }

    protected function tearDown(): void
    {
        PluginInstallProviderRegistry::reset();
        $GLOBALS['plugins'] = $this->originalPlugins;
    }

    // ── T1: wizard plugin via AJAX → no redirect, wizard exposed ──

    #[Test]
    public function wizardPluginViaAjaxExposesWizardWithoutRedirect(): void
    {
        $manager = new AjaxGuardTestPluginManager(
            pluginWizards: ['factura_pdf1' => 'admin_factura_pdf1'],
            ajax: true,
        );
        $provider = new RecordingPluginInstallProvider(
            ['factura_pdf1' => []],
            installed: ['factura_pdf1'],
        );
        PluginInstallProviderRegistry::register($provider);

        $orchestrator = new PluginEnableOrchestrator($manager);

        $result = $orchestrator->enablePluginStep('factura_pdf1', 'factura_pdf1');

        $this->assertTrue($result, 'Activation must succeed');
        $this->assertSame(
            'admin_factura_pdf1',
            $manager->getLastEnableWizard(),
            'Wizard page name must be exposed after activation',
        );
        $this->assertSame(
            [],
            $manager->redirectsCaptured,
            'AJAX request must not emit a Location header',
        );
    }

    // ── T2: wizard plugin via normal request → redirect preserved ──

    #[Test]
    public function wizardPluginViaNormalRequestPreservesRedirect(): void
    {
        $manager = new AjaxGuardTestPluginManager(
            pluginWizards: ['factura_pdf1' => 'admin_factura_pdf1'],
            ajax: false,
        );
        $provider = new RecordingPluginInstallProvider(
            ['factura_pdf1' => []],
            installed: ['factura_pdf1'],
        );
        PluginInstallProviderRegistry::register($provider);

        $orchestrator = new PluginEnableOrchestrator($manager);

        $result = $orchestrator->enablePluginStep('factura_pdf1', 'factura_pdf1');

        $this->assertTrue($result, 'Activation must succeed');
        $this->assertSame('admin_factura_pdf1', $manager->getLastEnableWizard());
        $this->assertSame(
            ['index.php?page=admin_factura_pdf1'],
            $manager->redirectsCaptured,
            'Non-AJAX request with wizard must emit a redirect',
        );
    }

    // ── T3: plugin without wizard via AJAX → no wizard, no redirect ──

    #[Test]
    public function pluginWithoutWizardViaAjaxHasNoWizardAndNoRedirect(): void
    {
        $manager = new AjaxGuardTestPluginManager(
            pluginWizards: [],
            ajax: true,
        );
        $provider = new RecordingPluginInstallProvider(
            ['catalogo_core' => []],
            installed: ['catalogo_core'],
        );
        PluginInstallProviderRegistry::register($provider);

        $orchestrator = new PluginEnableOrchestrator($manager);

        $result = $orchestrator->enablePluginStep('catalogo_core', 'catalogo_core');

        $this->assertTrue($result, 'Activation must succeed');
        $this->assertNull(
            $manager->getLastEnableWizard(),
            'Plugin without wizard must not expose wizard info',
        );
        $this->assertSame([], $manager->redirectsCaptured);
    }

    // ── T4: Orchestrator propagates wizard to caller ──

    #[Test]
    public function orchestratorExposesWizardFromLastStep(): void
    {
        $manager = new AjaxGuardTestPluginManager(
            pluginWizards: ['factura_pdf1' => 'admin_factura_pdf1'],
            ajax: true,
        );
        $requirements = [
            'factura_pdf1' => ['catalogo_core'],
            'catalogo_core' => [],
        ];
        PluginInstallProviderRegistry::register(
            new RecordingPluginInstallProvider($requirements, installed: array_keys($requirements)),
        );

        $orchestrator = new PluginEnableOrchestrator($manager);

        $orchestrator->enablePluginStep('factura_pdf1', 'catalogo_core');
        $this->assertNull($orchestrator->getLastEnableWizard());

        $orchestrator->enablePluginStep('factura_pdf1', 'factura_pdf1');
        $this->assertSame('admin_factura_pdf1', $orchestrator->getLastEnableWizard());
    }

    // ── T5: dependency plugin in chain never redirects even with wizard ──

    #[Test]
    public function dependencyPluginRunWizardFalseNeverRedirects(): void
    {
        $manager = new AjaxGuardTestPluginManager(
            pluginWizards: ['catalogo_core' => 'admin_catalogo_wizard'],
            ajax: false,
        );
        $requirements = [
            'factura_pdf1' => ['catalogo_core'],
            'catalogo_core' => [],
        ];
        PluginInstallProviderRegistry::register(
            new RecordingPluginInstallProvider($requirements, installed: array_keys($requirements)),
        );

        $orchestrator = new PluginEnableOrchestrator($manager);

        // catalogo_core is a dependency → runWizard=false
        $orchestrator->enablePluginStep('factura_pdf1', 'catalogo_core');

        $this->assertNull(
            $manager->getLastEnableWizard(),
            'Dependency activation (runWizard=false) must not expose wizard',
        );
        $this->assertSame(
            [],
            $manager->redirectsCaptured,
            'Dependency activation must never redirect',
        );
    }

    // ── T6: guard branches — AJAX suppresses, non-AJAX emits, same plugin ──

    #[Test]
    public function samePluginDifferentContextYieldsDifferentRedirectBehaviour(): void
    {
        // AJAX context
        $ajaxManager = new AjaxGuardTestPluginManager(
            pluginWizards: ['factura_pdf1' => 'admin_factura_pdf1'],
            ajax: true,
        );
        PluginInstallProviderRegistry::register(
            new RecordingPluginInstallProvider(['factura_pdf1' => []], installed: ['factura_pdf1']),
        );
        $orchestrator = new PluginEnableOrchestrator($ajaxManager);
        $orchestrator->enablePluginStep('factura_pdf1', 'factura_pdf1');

        $this->assertSame([], $ajaxManager->redirectsCaptured, 'AJAX must suppress redirect');
        $this->assertNotNull($ajaxManager->getLastEnableWizard(), 'Wizard must still be exposed');

        // Non-AJAX context — fresh manager and globals to reset state
        PluginInstallProviderRegistry::reset();
        $GLOBALS['plugins'] = $this->originalPlugins;
        $normalManager = new AjaxGuardTestPluginManager(
            pluginWizards: ['factura_pdf1' => 'admin_factura_pdf1'],
            ajax: false,
        );
        PluginInstallProviderRegistry::register(
            new RecordingPluginInstallProvider(['factura_pdf1' => []], installed: ['factura_pdf1']),
        );
        $orchestrator2 = new PluginEnableOrchestrator($normalManager);
        $orchestrator2->enablePluginStep('factura_pdf1', 'factura_pdf1');

        $this->assertCount(1, $normalManager->redirectsCaptured, 'Non-AJAX must emit redirect');
        $this->assertNotNull($normalManager->getLastEnableWizard());
    }
}

/**
 * Test double that runs the REAL enableWithoutDependencyResolution() logic
 * while stubbing only infrastructure concerns (filesystem, cache, schema)
 * and making isAjaxRequest() controllable.
 *
 * @internal
 */
final class AjaxGuardTestPluginManager extends \fs_plugin_manager
{
    /** @var list<string> URLs passed to emitRedirect() */
    public array $redirectsCaptured = [];

    /**
     * @param array<string, string> $pluginWizards Plugin name → wizard page name
     * @param bool $ajax Whether isAjaxRequest() returns true
     */
    public function __construct(
        private array $pluginWizards = [],
        private bool $ajax = true,
    ) {
        // Skip parent constructor (avoids cache/core_log/VERSION filesystem access).
        // Manually set what enableWithoutDependencyResolution() needs.
        $ref = new \ReflectionClass(\fs_plugin_manager::class);

        $coreProp = $ref->getProperty('core_log');
        $coreProp->setAccessible(true);
        $coreProp->setValue($this, new \fs_core_log());

        $cacheProp = $ref->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this, new \fs_cache());
    }

    /**
     * Return the controllable AJAX flag — this is what the production guard reads.
     */
    protected function isAjaxRequest(): bool
    {
        return $this->ajax;
    }

    /**
     * Capture redirects instead of emitting a real header().
     */
    protected function emitRedirect(string $url): void
    {
        $this->redirectsCaptured[] = $url;
    }

    /**
     * Return plugin metadata with wizard info, without scanning the filesystem.
     */
    public function installed(): array
    {
        $result = [];
        foreach ($this->pluginWizards as $name => $wizard) {
            $result[] = [
                'name' => $name,
                'wizard' => $wizard,
                'description' => '',
                'version' => 1,
                'min_version' => '0.1',
                'compatible' => true,
                'enabled' => false,
                'has_backup' => false,
            ];
        }

        return $result;
    }

    public function resolvePluginName($plugin_name): string
    {
        return (string) $plugin_name;
    }

    protected function save(): bool
    {
        return true;
    }

    public function applyPluginSchemaUpdates(string $plugin_name): array
    {
        return ['success' => true, 'errors' => []];
    }

    protected function clean_cache(): void
    {
    }

    protected function auditLog(string $action, string $pluginName, array $context = []): void
    {
    }
}
