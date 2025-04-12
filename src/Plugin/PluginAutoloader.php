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
