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

/**
 * Autoloader de modelos bajo demanda
 * 
 * En lugar de cargar los 83+ modelos al inicio (require_all_models),
 * este autoloader los carga solo cuando se necesitan.
 * 
 * MEJORA DE RENDIMIENTO:
 * - Antes: ~7ms + 856KB para cargar 83 modelos en CADA petición
 * - Después: ~0.5ms para registrar autoloader, modelos cargados bajo demanda
 * 
 * Uso:
 *   // En lugar de require_all_models(), usar:
 *   fs_model_autoloader::register();
 *   
 *   // Los modelos se cargan automáticamente cuando se usan:
 *   $cliente = new cliente();  // Carga plugins/facturacion_base/model/cliente.php
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class fs_model_autoloader
{
    private const PLUGINS_PATH = '/plugins/';

    /**
     * @var bool Si el autoloader está registrado
     */
    private static bool $registered = false;

    /**
     * @var array Mapa de clase => ruta (cache)
     */
    private static array $classMap = [];

    /**
     * @var array Directorios de modelos a buscar
     */
    private static array $modelDirs = [];

    /**
     * @var string|null Ruta al archivo de cache del mapa
     */
    private static ?string $cacheFile = null;

    /**
     * Registra el autoloader
     * 
     * @param bool $useCache Usar cache del mapa de clases
     * @return void
     */
    public static function register(bool $useCache = true): void
    {
        if (self::$registered) {
            return;
        }

        $folder = defined('FS_FOLDER') ? FS_FOLDER : '.';
        $tmpName = defined('FS_TMP_NAME') ? FS_TMP_NAME : '';
        
        // Construir lista de directorios de modelos PRIMERO
        // (necesario para validar el cache)
        self::buildModelDirs($folder);
        
        // Configurar cache
        if ($useCache && $tmpName) {
            self::$cacheFile = $folder . '/tmp/' . $tmpName . 'model_class_map.php';
            self::loadCache();
            
            // Validar que el cache corresponde a los plugins actuales
            self::validateCache();
        }

        // Registrar autoloader con prioridad baja (después de Composer)
        spl_autoload_register([self::class, 'loadClass'], true, false);
        
        self::$registered = true;
    }
    
    /**
     * Valida que el cache corresponde a los plugins activos actuales
     * Si hay rutas a plugins no activos, limpia el cache
     */
    private static function validateCache(): void
    {
        if (empty(self::$classMap)) {
            return;
        }
        
        $activePlugins = $GLOBALS['plugins'] ?? [];
        
        foreach (self::$classMap as $path) {
            $pluginName = self::extractPluginNameFromPath($path);
            if ($pluginName !== null && !in_array($pluginName, $activePlugins)) {
                self::clearCacheFile();
                return;
            }
        }
    }

    private static function extractPluginNameFromPath(string $path): ?string
    {
        if (strpos($path, self::PLUGINS_PATH) === false) {
            return null;
        }

        if (preg_match('#/plugins/([^/]+)/#', $path, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private static function clearCacheFile(): void
    {
        self::$classMap = [];
        if (self::$cacheFile && file_exists(self::$cacheFile)) {
            @unlink(self::$cacheFile);
        }
    }

    /**
     * Construye la lista de directorios de modelos
     */
    private static function buildModelDirs(string $folder): void
    {
        // Modelos de plugins (en orden de prioridad)
        // Incluir tanto model/ como model/core/ de cada plugin
        if (isset($GLOBALS['plugins'])) {
            foreach ($GLOBALS['plugins'] as $plugin) {
                $dir = $folder . self::PLUGINS_PATH . $plugin . '/model';
                if (is_dir($dir)) {
                    self::$modelDirs[] = $dir;
                    
                    // Subdirectorio core/ del plugin
                    $coreDir = $dir . '/core';
                    if (is_dir($coreDir)) {
                        self::$modelDirs[] = $coreDir;
                    }
                }
            }
        }

        // Modelos del núcleo
        $coreDir = $folder . '/model';
        if (is_dir($coreDir)) {
            self::$modelDirs[] = $coreDir;
        }

        // Subdirectorios comunes (core, etc.)
        $coreSubDir = $folder . '/model/core';
        if (is_dir($coreSubDir)) {
            self::$modelDirs[] = $coreSubDir;
        }
    }

    /**
     * Carga una clase si es un modelo
     * 
     * @param string $class Nombre de la clase
     * @return bool
     */
    public static function loadClass(string $class): bool
    {
        // Manejar namespaces FacturaScripts\model\ClassName
        // Estos son modelos legacy que usan namespace pero están en plugins
        $originalClass = $class;
        if (strpos($class, 'FacturaScripts\\model\\') === 0) {
            // Extraer solo el nombre de la clase
            $class = substr($class, strlen('FacturaScripts\\model\\'));
        } elseif (strpos($class, '\\') !== false) {
            // Otros namespaces - dejar que Composer los maneje
            return false;
        }

        // Buscar en cache primero
        $cacheKey = $originalClass;
        if (isset(self::$classMap[$cacheKey])) {
            $path = self::$classMap[$cacheKey];
            if (file_exists($path)) {
                require_once $path;
                return true;
            }
            // Cache inválido, eliminar entrada
            unset(self::$classMap[$cacheKey]);
        }

        // Buscar en directorios de modelos
        $filename = $class . '.php';
        
        foreach (self::$modelDirs as $dir) {
            $path = $dir . '/' . $filename;
            if (file_exists($path)) {
                self::$classMap[$cacheKey] = $path;
                self::saveCache();
                require_once $path;
                return true;
            }
        }

        return false;
    }

    /**
     * Carga el cache del mapa de clases
     */
    private static function loadCache(): void
    {
        if (self::$cacheFile && file_exists(self::$cacheFile)) {
            $cached = include self::$cacheFile;
            if (is_array($cached)) {
                self::$classMap = $cached;
            }
        }
    }

    /**
     * Guarda el cache del mapa de clases
     */
    private static function saveCache(): void
    {
        if (!self::$cacheFile) {
            return;
        }

        // Solo guardar si hay cambios significativos
        static $lastSaveCount = 0;
        $currentCount = count(self::$classMap);
        
        // Guardar cada 10 nuevas clases o al final
        if ($currentCount - $lastSaveCount >= 10) {
            $content = "<?php\n// Model class map cache - Generated " . date('Y-m-d H:i:s') . "\n";
            $content .= "return " . var_export(self::$classMap, true) . ";\n";
            @file_put_contents(self::$cacheFile, $content);
            $lastSaveCount = $currentCount;
        }
    }

    /**
     * Limpia el cache
     */
    public static function clearCache(): void
    {
        self::$classMap = [];
        if (self::$cacheFile && file_exists(self::$cacheFile)) {
            @unlink(self::$cacheFile);
        }
    }

    /**
     * Precarga modelos específicos (para páginas que los necesitan todos)
     * 
     * @param array $models Lista de nombres de modelos a precargar
     */
    public static function preload(array $models): void
    {
        foreach ($models as $model) {
            if (!class_exists($model, false)) {
                self::loadClass($model);
            }
        }
    }

    /**
     * Obtiene estadísticas del autoloader
     * 
     * @return array
     */
    public static function getStats(): array
    {
        return [
            'registered' => self::$registered,
            'cached_classes' => count(self::$classMap),
            'model_dirs' => count(self::$modelDirs),
            'cache_file' => self::$cacheFile,
        ];
    }

    /**
     * Guarda el cache al finalizar (register_shutdown_function)
     */
    public static function shutdown(): void
    {
        if (self::$cacheFile && !empty(self::$classMap)) {
            $content = "<?php\n// Model class map cache - Generated " . date('Y-m-d H:i:s') . "\n";
            $content .= "return " . var_export(self::$classMap, true) . ";\n";
            @file_put_contents(self::$cacheFile, $content);
        }
    }
}

// Registrar función de shutdown para guardar cache
register_shutdown_function(['fs_model_autoloader', 'shutdown']);
