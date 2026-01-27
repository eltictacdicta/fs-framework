<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FacturaScripts\Plugins\facturascripts_support;

/**
 * Initialization class for facturascripts_support plugin.
 * Provides FacturaScripts 2025 compatibility layer:
 * - Autoloader for FacturaScripts\Core namespace
 */
class Init
{
    public function init(): void
    {
        // Register autoloader for FacturaScripts\Core namespace
        $this->registerAutoloader();


    }

    /**
     * Register autoloader for FacturaScripts\Core classes
     */
    private function registerAutoloader(): void
    {
        spl_autoload_register(function ($class) {
            // Only handle FacturaScripts\Core namespace
            if (strpos($class, 'FacturaScripts\\Core\\') !== 0) {
                return;
            }

            // Convert namespace to path
            // FacturaScripts\Core\Tools -> plugins/facturascripts_support/Core/Tools.php
            $relativePath = str_replace('FacturaScripts\\Core\\', '', $class);
            $relativePath = str_replace('\\', '/', $relativePath);
            $file = __DIR__ . '/Core/' . $relativePath . '.php';

            if (file_exists($file)) {
                require_once $file;
            }
        });

        // Autoloader for FacturaScripts\Plugins namespace
        // FacturaScripts\Plugins\Backup\Init -> plugins/backup/Init.php
        spl_autoload_register(function ($class) {
            if (strpos($class, 'FacturaScripts\\Plugins\\') !== 0) {
                return;
            }

            // Remove prefix
            $relative = str_replace('FacturaScripts\\Plugins\\', '', $class);
            // $relative is now Backup\Init

            $parts = explode('\\', $relative);
            $pluginName = strtolower(array_shift($parts)); // backup
            $remainingPath = implode('/', $parts); // Init

            // Check fs_folder/plugins/pluginName/remainingPath.php
            $file = FS_FOLDER . '/plugins/' . $pluginName . '/' . $remainingPath . '.php';

            if (file_exists($file)) {
                require_once $file;
            }
        });
    }
}


