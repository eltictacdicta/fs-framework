<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace FSFramework\Core\Plugin;

interface PluginInstallProvider
{
    public function isInstalled(string $pluginName): bool;

    /**
     * @return list<string>
     */
    public function getDirectRequirements(string $pluginName): array;

    public function installIfAvailable(string $pluginName): bool;

    public function getLastError(): string;
}
