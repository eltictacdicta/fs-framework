<?php

namespace FSFramework\Plugin;

/**
 * Clase para cargar automáticamente las clases de los plugins
 */
class PluginAutoloader
{
    /**
     * Registra el autoloader para los plugins
     */
    public static function register(): void
    {
        spl_autoload_register([self::class, 'loadClass']);
        
        // Add a global namespace legacy autoloader
        spl_autoload_register([self::class, 'loadLegacyClass'], true, true);
    }

    /**
     * Loads a legacy class from global namespace with extension_* prefix
     * This is for backwards compatibility with old plugins
     */
    public static function loadLegacyClass(string $class): bool
    {
        // Only handle classes in the global namespace
        if (strpos($class, '\\') !== false) {
            return false;
        }
        
        // First try to find in plugins directly related to the class name
        $possiblePluginNames = [];
        
        // Check if the class name contains underscores (common for plugins)
        if (strpos($class, '_') !== false) {
            $parts = explode('_', $class);
            // Try various combinations of parts as potential plugin names
            for ($i = 0; $i < count($parts); $i++) {
                $possiblePluginNames[] = $parts[$i];
                if ($i < count($parts) - 1) {
                    $possiblePluginNames[] = $parts[$i] . '_' . $parts[$i + 1];
                }
            }
        }
        
        // Add the full class name as a potential match
        $possiblePluginNames[] = $class;
        $possiblePluginNames = array_unique($possiblePluginNames);
        
        // First, try with specific potential plugin directories
        foreach ($possiblePluginNames as $pluginName) {
            $pluginDir = FS_FOLDER . '/plugins/' . $pluginName;
            if (is_dir($pluginDir)) {
                $paths = [
                    $pluginDir . '/' . $class . '.php',
                    $pluginDir . '/lib/' . $class . '.php',
                    $pluginDir . '/model/' . $class . '.php',
                    $pluginDir . '/class/' . $class . '.php',
                    $pluginDir . '/classes/' . $class . '.php',
                ];
                
                foreach ($paths as $path) {
                    if (file_exists($path)) {
                        require_once $path;
                        return class_exists($class, false);
                    }
                }
            }
        }
        
        // If not found, search in all plugin directories
        if (is_dir(FS_FOLDER . '/plugins')) {
            foreach (scandir(FS_FOLDER . '/plugins') as $dir) {
                if ($dir === '.' || $dir === '..' || !is_dir(FS_FOLDER . '/plugins/' . $dir)) {
                    continue;
                }
                
                $paths = [
                    FS_FOLDER . '/plugins/' . $dir . '/' . $class . '.php',
                    FS_FOLDER . '/plugins/' . $dir . '/lib/' . $class . '.php',
                    FS_FOLDER . '/plugins/' . $dir . '/model/' . $class . '.php',
                    FS_FOLDER . '/plugins/' . $dir . '/class/' . $class . '.php',
                    FS_FOLDER . '/plugins/' . $dir . '/classes/' . $class . '.php',
                ];
                
                foreach ($paths as $path) {
                    if (file_exists($path)) {
                        require_once $path;
                        return class_exists($class, false);
                    }
                }
            }
        }
        
        return false;
    }

    /**
     * Carga una clase de un plugin
     */
    public static function loadClass(string $class): bool
    {
        // Verificar si la clase pertenece al namespace de un plugin
        if (strpos($class, 'FSFramework\\Plugin\\') !== 0) {
            return false;
        }

        // Extraer el nombre del plugin y la ruta de la clase
        $parts = explode('\\', $class);

        if (count($parts) < 3) {
            return false;
        }

        // El nombre del plugin es la tercera parte del namespace
        $pluginName = $parts[2];

        // Convertir el namespace a una ruta de archivo
        $relativePath = implode('/', array_slice($parts, 3));

        // Intentar diferentes variantes del nombre del plugin
        $pluginVariants = [
            lcfirst($pluginName),  // example_twig
            strtolower($pluginName), // exampletwig
            $pluginName,           // ExampleTwig
        ];

        $file = null;
        foreach ($pluginVariants as $variant) {
            $testFile = FS_FOLDER . '/plugins/' . $variant . '/' . $relativePath . '.php';
            if (file_exists($testFile)) {
                $file = $testFile;
                break;
            }
        }

        // Si no encontramos el archivo, intentar buscar en todos los plugins
        if ($file === null && is_dir(FS_FOLDER . '/plugins')) {
            $plugins = scandir(FS_FOLDER . '/plugins');
            foreach ($plugins as $plugin) {
                if ($plugin === '.' || $plugin === '..') {
                    continue;
                }

                $testFile = FS_FOLDER . '/plugins/' . $plugin . '/' . $relativePath . '.php';
                if (file_exists($testFile)) {
                    $file = $testFile;
                    break;
                }
            }
        }

        // Debug: Imprimir información sobre la carga de clases
        error_log("Trying to load class: {$class}");
        error_log("Plugin name: {$pluginName}");
        error_log("Relative path: {$relativePath}");
        error_log("File path: {$file}");
        error_log("File exists: " . ($file && file_exists($file) ? 'yes' : 'no'));

        if ($file && file_exists($file)) {
            require_once $file;
            return true;
        }

        return false;
    }
}
