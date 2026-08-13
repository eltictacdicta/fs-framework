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

        $installProvider = $this->bootstrapInstallProvider();
        $dependencyResolver = new PluginDependencyResolver($installProvider);

        try {
            $plan = $dependencyResolver->buildActivationOrder($pluginName);
        } catch (CircularPluginDependencyException $exception) {
            $this->pluginManager->logPluginError($exception->getMessage());

            return false;
        }

        if ($plan === []) {
            $this->pluginManager->logPluginError('Nombre de plugin no válido.');

            return false;
        }

        if (!$this->installMissingDependencies($plan, $pluginName, $installProvider)) {
            return false;
        }

        return $this->enablePlannedPlugins($plan);
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

            if ($installProvider->installIfAvailable($plannedPlugin)) {
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
    private function enablePlannedPlugins(array $plan): bool
    {
        foreach ($plan as $plannedPlugin) {
            if ($this->pluginManager->is_plugin_enabled($plannedPlugin)) {
                continue;
            }

            if (!$this->pluginManager->enableWithoutDependencyResolution($plannedPlugin)) {
                return false;
            }
        }

        return true;
    }

    private function bootstrapInstallProvider(): PluginInstallProvider
    {
        $this->ensureSystemUpdaterPresent();

        return $this->resolveCatalogInstallProvider();
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
