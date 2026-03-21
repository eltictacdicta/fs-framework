<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FSFramework\Plugins\facturascripts_support;

/**
 * Version validator for FacturaScripts 2025 compatibility.
 * Manages the VERSION-FS2025 file and validates plugin compatibility.
 */
class VersionValidator
{
    /**
     * Cached version value
     * @var float|null
     */
    private static $version = null;

    /**
     * Default version if file doesn't exist
     */
    private const DEFAULT_VERSION = 2025.000;

    /**
     * Get the FS2025 compatibility version.
     * 
     * @return float
     */
    public static function getVersion(): float
    {
        if (self::$version === null) {
            $file = __DIR__ . '/VERSION-FS2025';
            if (file_exists($file)) {
                self::$version = (float) trim(file_get_contents($file));
            } else {
                self::$version = self::DEFAULT_VERSION;
            }
        }
        return self::$version;
    }

    /**
     * Check if a plugin requiring the given min_version is compatible.
     * 
     * @param float $minVersion The minimum version required by the plugin
     * @return bool True if compatible, false otherwise
     */
    public static function isCompatible(float $minVersion): bool
    {
        return self::getVersion() >= $minVersion;
    }

    /**
     * Reset the cached version (useful for testing).
     */
    public static function resetCache(): void
    {
        self::$version = null;
    }

    /**
     * Get list of dependent plugins that require facturascripts_support.
     * These are plugins with facturascripts.ini and min_version >= 2025.
     * 
     * @return array List of plugin names that depend on facturascripts_support
     */
    public static function getDependentPlugins(): array
    {
        $pluginsDir = FS_FOLDER . '/plugins';
        if (!is_dir($pluginsDir)) {
            return [];
        }

        $dependents = [];
        foreach (scandir($pluginsDir) as $pluginName) {
            if (self::isFs2025Dependent($pluginsDir, $pluginName)) {
                $dependents[] = $pluginName;
            }
        }

        return $dependents;
    }

    private static function isFs2025Dependent(string $pluginsDir, string $pluginName): bool
    {
        if ($pluginName === '.' || $pluginName === '..' || $pluginName === 'facturascripts_support') {
            return false;
        }

        $pluginPath = $pluginsDir . '/' . $pluginName;
        if (!is_dir($pluginPath)) {
            return false;
        }

        if (file_exists($pluginPath . '/fsframework.ini')) {
            return false;
        }

        $fsIni = $pluginPath . '/facturascripts.ini';
        if (!file_exists($fsIni)) {
            return false;
        }

        $iniData = parse_ini_file($fsIni);
        $minVersion = isset($iniData['min_version']) ? (float) $iniData['min_version'] : 0;

        return $minVersion >= 2025;
    }
}
