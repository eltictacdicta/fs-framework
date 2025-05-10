<?php

namespace FSFramework\Plugin;

/**
 * Autoloader for legacy plugin classes that are in the global namespace
 */
class LegacyPluginAutoloader
{
    /**
     * List of already included files to avoid redeclarations
     */
    private static $included_files = [];

    /**
     * Register the autoloader
     */
    public static function register(): void
    {
        spl_autoload_register([self::class, 'loadClass'], true, true);
    }

    /**
     * Load a class
     * 
     * @param string $class The name of the class to load
     * @return bool True if the class was loaded, false otherwise
     */
    public static function loadClass(string $class): bool
    {
        // Only handle classes in the global namespace (no backslash)
        if (strpos($class, '\\') !== false) {
            return false;
        }
        
        // For classes with "extension_" prefix, look in all plugin directories
        if (strpos($class, 'extension_') === 0) {
            $pluginName = substr($class, 10); // Remove 'extension_'
            
            // Check if the plugin directory exists
            if (is_dir(FS_FOLDER . '/plugins/' . $pluginName)) {
                // Try to load from plugin's main directory first
                $classFile = FS_FOLDER . '/plugins/' . $pluginName . '/' . $class . '.php';
                if (file_exists($classFile)) {
                    self::requireOnce($classFile);
                    return true;
                }
                
                // Try to load from lib directory
                $classFile = FS_FOLDER . '/plugins/' . $pluginName . '/lib/' . $class . '.php';
                if (file_exists($classFile)) {
                    self::requireOnce($classFile);
                    return true;
                }
                
                // Try to load from model directory
                $classFile = FS_FOLDER . '/plugins/' . $pluginName . '/model/' . $class . '.php';
                if (file_exists($classFile)) {
                    self::requireOnce($classFile);
                    return true;
                }
            }
            
            // If not found in specific plugin, search in all plugins
            if (is_dir(FS_FOLDER . '/plugins')) {
                foreach (scandir(FS_FOLDER . '/plugins') as $plugin) {
                    if ($plugin === '.' || $plugin === '..') {
                        continue;
                    }
                    
                    $classFile = FS_FOLDER . '/plugins/' . $plugin . '/' . $class . '.php';
                    if (file_exists($classFile)) {
                        self::requireOnce($classFile);
                        return true;
                    }
                    
                    $classFile = FS_FOLDER . '/plugins/' . $plugin . '/lib/' . $class . '.php';
                    if (file_exists($classFile)) {
                        self::requireOnce($classFile);
                        return true;
                    }
                    
                    $classFile = FS_FOLDER . '/plugins/' . $plugin . '/model/' . $class . '.php';
                    if (file_exists($classFile)) {
                        self::requireOnce($classFile);
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Include a file only if it has not already been included
     * 
     * @param string $file Path of the file to include
     */
    private static function requireOnce(string $file): void
    {
        $realpath = realpath($file);
        if (!isset(self::$included_files[$realpath])) {
            self::$included_files[$realpath] = true;
            require_once $file;
        }
    }
} 