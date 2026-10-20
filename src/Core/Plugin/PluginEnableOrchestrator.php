<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace FSFramework\Core\Plugin;

final class PluginEnableOrchestrator
{
    public function __construct(
        private readonly \fs_plugin_manager $pluginManager,
    ) {
    }

    public function enable(string $pluginName): bool
    {
        $pluginName = $this->pluginManager->resolvePluginName($pluginName);

        if ($this->pluginManager->is_plugin_enabled($pluginName)) {
            return true;
        }

        $inspection = $this->inspectActivation($pluginName);
        if (!$inspection['success']) {
            foreach ($inspection['errors'] as $error) {
                $this->pluginManager->logPluginError($error);
            }

            return false;
        }

        $installProvider = $this->bootstrapInstallProvider();

        if (!$this->installMissingDependencies($inspection['plan'], $pluginName, $installProvider)) {
            return false;
        }

        return $this->enablePlannedPlugins($inspection['plan'], $pluginName);
    }

    /**
     * @return array{
     *     success: bool,
     *     target: string,
     *     plan: list<string>,
     *     missing: list<string>,
     *     pending_activation: list<string>,
     *     errors: list<string>
     * }
     */
    public function inspectActivation(string $pluginName): array
    {
        $pluginName = $this->pluginManager->resolvePluginName($pluginName);
        $empty = [
            'success' => false,
            'target' => $pluginName,
            'plan' => [],
            'missing' => [],
            'pending_activation' => [],
            'errors' => [],
        ];

        if ($pluginName === '') {
            $empty['errors'][] = 'Nombre de plugin no válido.';

            return $empty;
        }

        $installProvider = $this->bootstrapInstallProvider();
        $dependencyResolver = new PluginDependencyResolver($installProvider);

        try {
            $plan = $dependencyResolver->buildActivationOrder($pluginName);
        } catch (CircularPluginDependencyException $exception) {
            $empty['errors'][] = $exception->getMessage();

            return $empty;
        }

        if ($plan === []) {
            $empty['errors'][] = 'Nombre de plugin no válido.';

            return $empty;
        }

        $missing = [];
        $pendingActivation = [];
        foreach ($plan as $plannedPlugin) {
            if (!$installProvider->isInstalled($plannedPlugin)) {
                $missing[] = $plannedPlugin;
            }

            if (!$this->pluginManager->is_plugin_enabled($plannedPlugin)) {
                $pendingActivation[] = $plannedPlugin;
            }
        }

        return [
            'success' => true,
            'target' => $pluginName,
            'plan' => $plan,
            'missing' => $missing,
            'pending_activation' => $pendingActivation,
            'errors' => [],
        ];
    }

    public function downloadPlugin(string $pluginName): bool
    {
        $pluginName = $this->pluginManager->resolvePluginName($pluginName);
        if ($pluginName === '') {
            $this->pluginManager->logPluginError('Nombre de plugin no válido.');

            return false;
        }

        $installProvider = $this->bootstrapInstallProvider();
        if ($installProvider->isInstalled($pluginName)) {
            return true;
        }

        if ($this->installMissingPlugin($pluginName, $installProvider)) {
            return true;
        }

        $message = $installProvider->getLastError();
        if ($message === '') {
            $message = 'No se pudo descargar el plugin <b>' . htmlspecialchars($pluginName, ENT_QUOTES, 'UTF-8') . '</b>.';
        }

        $this->pluginManager->logPluginError($message);

        return false;
    }

    /**
     * Returns the wizard page name from the last enablePluginStep() call,
     * or null when the activated plugin had no wizard.
     */
    public function getLastEnableWizard(): ?string
    {
        return $this->pluginManager->getLastEnableWizard();
    }

    public function enablePluginStep(string $targetPlugin, string $pluginToEnable): bool
    {
        $targetPlugin = $this->pluginManager->resolvePluginName($targetPlugin);
        $pluginToEnable = $this->pluginManager->resolvePluginName($pluginToEnable);

        $inspection = $this->inspectActivation($targetPlugin);
        if (!$inspection['success']) {
            foreach ($inspection['errors'] as $error) {
                $this->pluginManager->logPluginError($error);
            }

            return false;
        }

        if (!in_array($pluginToEnable, $inspection['plan'], true)) {
            $this->pluginManager->logPluginError(
                'El plugin <b>' . htmlspecialchars($pluginToEnable, ENT_QUOTES, 'UTF-8') . '</b> no pertenece al plan de activación.'
            );

            return false;
        }

        if ($this->pluginManager->is_plugin_enabled($pluginToEnable)) {
            return true;
        }

        foreach ($inspection['plan'] as $plannedPlugin) {
            if ($plannedPlugin === $pluginToEnable) {
                break;
            }

            if (!$this->pluginManager->is_plugin_enabled($plannedPlugin)) {
                $this->pluginManager->logPluginError(
                    'Debe activarse primero el plugin <b>' . htmlspecialchars($plannedPlugin, ENT_QUOTES, 'UTF-8') . '</b>.'
                );

                return false;
            }
        }

        $installProvider = $this->bootstrapInstallProvider();

        if (!$installProvider->isInstalled($pluginToEnable)) {
            $this->pluginManager->logPluginError(
                'El plugin <b>' . htmlspecialchars($pluginToEnable, ENT_QUOTES, 'UTF-8') . '</b> no está instalado en disco.'
            );

            return false;
        }

        $runWizard = $pluginToEnable === $inspection['target'];

        return $this->pluginManager->enableWithoutDependencyResolution($pluginToEnable, $runWizard);
    }

    /**
     * @param list<string> $plan
     */
    private function installMissingDependencies(array $plan, string $pluginName, PluginInstallProvider $installProvider): bool
    {
        foreach ($plan as $plannedPlugin) {
            if ($installProvider->isInstalled($plannedPlugin)) {
                continue;
            }

            if ($this->installMissingPlugin($plannedPlugin, $installProvider)) {
                $installProvider = PluginInstallProviderRegistry::get();
                continue;
            }

            $message = $installProvider->getLastError();
            if ($message === '') {
                $message = 'No se pudo instalar la dependencia <b>' . $plannedPlugin . '</b>.';
            }

            $this->pluginManager->logPluginError($message);
            $this->pluginManager->logPluginError('Imposible activar el plugin <b>' . $pluginName . '</b>.');

            return false;
        }

        return true;
    }

    /**
     * @param list<string> $plan
     */
    private function enablePlannedPlugins(array $plan, string $targetPlugin): bool
    {
        foreach ($plan as $plannedPlugin) {
            if ($this->pluginManager->is_plugin_enabled($plannedPlugin)) {
                continue;
            }

            $runWizard = $plannedPlugin === $targetPlugin;
            if (!$this->pluginManager->enableWithoutDependencyResolution($plannedPlugin, $runWizard)) {
                return false;
            }
        }

        return true;
    }

    private function bootstrapInstallProvider(): PluginInstallProvider
    {
        return $this->resolveCatalogInstallProvider();
    }

    private function installMissingPlugin(string $pluginName, PluginInstallProvider $installProvider): bool
    {
        if ($installProvider->installIfAvailable($pluginName)) {
            return true;
        }

        if (!$this->shouldBootstrapCatalogFor($pluginName)) {
            return false;
        }

        $this->ensureSystemUpdaterPresent();
        $catalogProvider = $this->resolveCatalogInstallProvider();
        if ($catalogProvider === $installProvider) {
            return false;
        }

        PluginInstallProviderRegistry::register($catalogProvider);

        return $catalogProvider->installIfAvailable($pluginName);
    }

    private function shouldBootstrapCatalogFor(string $pluginName): bool
    {
        if (is_dir(FS_FOLDER . '/plugins/system_updater/controller')) {
            return true;
        }

        $lookupFile = FS_FOLDER . '/plugins/system_updater/lib/public_catalog_lookup.php';
        if (is_file($lookupFile)) {
            require_once $lookupFile;

            return system_updater_catalog_lists_plugin($pluginName);
        }

        // Sin system_updater instalado no hay catálogo que consultar: el
        // catálogo vive dentro del plugin (plugins/system_updater/data/
        // custom_plugins.json). Antes se consultaba además un repositorio
        // remoto que ya no existe; mantener la URL sólo dejaba una petición
        // condenada a 404 en cada intento de alta.
        return false;
    }

    private function ensureSystemUpdaterPresent(): void
    {
        if (is_dir(FS_FOLDER . '/plugins/system_updater/controller')) {
            return;
        }

        if (!class_exists(\FSFramework\Core\PluginInstaller::class)) {
            require_once FS_FOLDER . '/base/fs_plugin_manager.php';
        }

        if (!class_exists(\FSFramework\Core\PluginInstaller::class)) {
            return;
        }

        $installer = new \FSFramework\Core\PluginInstaller($this->pluginManager);
        $installer->installSystemUpdater();
    }

    private function resolveCatalogInstallProvider(): PluginInstallProvider
    {
        if (PluginInstallProviderRegistry::isLocked()) {
            return PluginInstallProviderRegistry::get();
        }

        $providerFile = FS_FOLDER . '/plugins/system_updater/lib/CatalogPluginInstallProvider.php';
        if (!is_file($providerFile)) {
            return PluginInstallProviderRegistry::get();
        }

        require_once FS_FOLDER . '/plugins/system_updater/lib/plugin_downloader.php';
        require_once $providerFile;

        if (!class_exists('CatalogPluginInstallProvider', false)) {
            return PluginInstallProviderRegistry::get();
        }

        $provider = new \CatalogPluginInstallProvider();
        PluginInstallProviderRegistry::register($provider);

        return $provider;
    }
}
