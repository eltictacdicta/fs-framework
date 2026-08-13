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

        return $this->isPluginListedInRemoteCatalog($pluginName);
    }

    private function isPluginListedInRemoteCatalog(string $pluginName): bool
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header' => "User-Agent: FSFramework-CatalogLookup/1.0\r\n",
            ],
        ]);

        foreach ([
            'https://raw.githubusercontent.com/eltictacdicta/fs-cusmtom-plugins/main/custom_plugins.json',
            'https://raw.githubusercontent.com/eltictacdicta/fs-cusmtom-plugins/master/custom_plugins.json',
        ] as $url) {
            $json = @file_get_contents($url, false, $context);
            if ($json === false) {
                continue;
            }

            $entries = json_decode($json, true);
            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (is_array($entry) && isset($entry['nombre']) && (string) $entry['nombre'] === $pluginName) {
                    return true;
                }
            }
        }

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
