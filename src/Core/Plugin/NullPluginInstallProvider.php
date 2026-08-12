<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace FSFramework\Core\Plugin;

final class NullPluginInstallProvider implements PluginInstallProvider
{
    private string $lastError = '';

    public function __construct(
        private readonly ?LocalPluginRequirementsReader $requirementsReader = null,
    ) {
    }

    public function isInstalled(string $pluginName): bool
    {
        return $this->reader()->isInstalled($pluginName);
    }

    public function getDirectRequirements(string $pluginName): array
    {
        return $this->reader()->read($pluginName);
    }

    public function installIfAvailable(string $pluginName): bool
    {
        if ($this->isInstalled($pluginName)) {
            return true;
        }

        $this->lastError = 'Plugin ' . $pluginName . ' no instalado y no hay proveedor de catálogo registrado.';

        return false;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    private function reader(): LocalPluginRequirementsReader
    {
        if ($this->requirementsReader !== null) {
            return $this->requirementsReader;
        }

        $root = defined('FS_FOLDER') ? FS_FOLDER . '/plugins' : dirname(__DIR__, 3) . '/plugins';

        return new LocalPluginRequirementsReader($root);
    }
}
