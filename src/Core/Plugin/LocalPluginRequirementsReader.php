<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace FSFramework\Core\Plugin;

final class LocalPluginRequirementsReader
{
    public function __construct(
        private readonly string $pluginsRoot,
    ) {
    }

    /**
     * @return list<string>
     */
    public function read(string $pluginName): array
    {
        $pluginName = $this->normalizeName($pluginName);
        if ($pluginName === '') {
            return [];
        }

        $ini = $this->loadIni($pluginName);
        if ($ini === null) {
            return [];
        }

        $require = $ini['require'] ?? '';
        if (trim($require) === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $require));

        return array_values(array_filter($parts, static fn (string $item): bool => $item !== ''));
    }

    public function isInstalled(string $pluginName): bool
    {
        $pluginName = $this->normalizeName($pluginName);

        return $pluginName !== ''
            && is_dir($this->pluginsRoot . '/' . $pluginName);
    }

    /**
     * @return array<string, string>|null
     */
    private function loadIni(string $pluginName): ?array
    {
        foreach (['fsframework.ini', 'facturascripts.ini'] as $iniFile) {
            $path = $this->pluginsRoot . '/' . $pluginName . '/' . $iniFile;
            if (!is_file($path)) {
                continue;
            }

            $parsed = parse_ini_file($path);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        return null;
    }

    private function normalizeName(string $pluginName): string
    {
        $normalized = basename(str_replace('\\', '/', trim($pluginName)));
        $normalized = preg_replace('/-(master|main)$/i', '', $normalized) ?? '';
        $normalized = preg_replace('/[^A-Za-z0-9_-]/', '', $normalized) ?? '';

        return trim($normalized);
    }
}
