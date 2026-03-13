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

namespace FSFramework\Plugins\facturascripts_support;

/**
 * Initialization class for facturascripts_support plugin.
 * Provides FacturaScripts 2025 compatibility layer:
 * - Autoloader for FSFramework namespace
 */
class Init
{
    public function init(): void
    {
        // Register autoloader for FSFramework namespace
        $this->registerAutoloader();


    }

    /**
     * Register autoloader for FSFramework classes
     */
    private function registerAutoloader(): void
    {
        // Autoloader for FSFramework\Plugins namespace
        // FSFramework\Plugins\Backup\Init -> plugins/backup/Init.php
        spl_autoload_register(function ($class) {
            if (strpos($class, 'FSFramework\\Plugins\\') !== 0) {
                return;
            }

            // Remove prefix
            $relative = str_replace('FSFramework\\Plugins\\', '', $class);
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


