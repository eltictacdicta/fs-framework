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
 * Autoloader mejorado para FSFramework
 * 
 * Carga clases del core, legacy y plugins sin depender exclusivamente de Composer.
 * Compatible con el sistema existente y añade soporte para namespaces FSFramework\*.
 * 
 * Características:
 * - Carga clases legacy sin namespace (fs_user, fs_model, etc.)
 * - Soporte para namespaces FSFramework\*
 * - Carga automática de modelos de plugins activos
 * - Mapeo explícito de clases para compatibilidad
 * - Cache de rutas para mejor rendimiento
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class fs_autoload
{
    /**
     * @var bool
     */
    private static $registered = false;
    
    /**
     * @var array Cache de rutas de clases
     */
    private static $classMap = [];
    
    /**
     * @var string
     */
    private static $baseFolder = '';

    /**
     * Registra el autoloader
     * 
     * @param string|null $baseFolder Carpeta base del framework
     * @return void
     */
    public static function register($baseFolder = null)
    {
        if (self::$registered) {
            return;
        }

        self::$baseFolder = $baseFolder ?: (defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__));
        
        spl_autoload_register([__CLASS__, 'loadClass'], true, true);
        self::$registered = true;
    }

    /**
     * Carga una clase
     * 
     * @param string $class Nombre de la clase
     * @return bool
     */
    public static function loadClass($class)
    {
        // Verificar cache primero
        if (isset(self::$classMap[$class])) {
            $file = self::$classMap[$class];
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }

        // Intentar cargar por diferentes métodos
        $file = self::findClassFile($class);
        
        if ($file !== null && file_exists($file)) {
            self::$classMap[$class] = $file;
            require_once $file;
            return true;
        }

        return false;
    }

    /**
     * Busca el archivo de una clase
     * 
     * @param string $class Nombre de la clase
     * @return string|null
     */
    private static function findClassFile($class)
    {
        $folder = self::$baseFolder;
        
        // 1. Clases en namespace FSFramework\Security
        if (strpos($class, 'FSFramework\\Security\\') === 0) {
            $relative = str_replace('FSFramework\\Security\\', '', $class);
            $file = $folder . '/base/fs_' . self::camelToSnake($relative) . '.php';
            if (file_exists($file)) {
                return $file;
            }
        }

        // 2. Clases en namespace FSFramework\Database
        if (strpos($class, 'FSFramework\\Database\\') === 0) {
            $relative = str_replace('FSFramework\\Database\\', '', $class);
            $file = $folder . '/base/fs_' . self::camelToSnake($relative) . '.php';
            if (file_exists($file)) {
                return $file;
            }
        }

        // 3. Clases en namespace FSFramework\Core
        if (strpos($class, 'FSFramework\\Core\\') === 0) {
            $relative = str_replace('FSFramework\\Core\\', '', $class);
            $file = $folder . '/core/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                return $file;
            }
        }

        // 4. Clases en namespace FSFramework\Model
        if (strpos($class, 'FSFramework\\Model\\') === 0) {
            $relative = str_replace('FSFramework\\Model\\', '', $class);
            $file = $folder . '/model/core/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                return $file;
            }
        }

        // 5. Clases en namespace FSFramework\Traits
        if (strpos($class, 'FSFramework\\Traits\\') === 0) {
            $relative = str_replace('FSFramework\\Traits\\', '', $class);
            $file = $folder . '/core/Traits/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                return $file;
            }
        }

        // 6. Clases legacy sin namespace (fs_user, fs_model, etc.)
        $legacyClasses = self::getLegacyClassMap();
        if (isset($legacyClasses[$class])) {
            $file = $folder . $legacyClasses[$class];
            if (file_exists($file)) {
                return $file;
            }
        }

        // 7. Buscar en directorio base/ (clases fs_*)
        if (strpos($class, 'fs_') === 0) {
            $file = $folder . '/base/' . $class . '.php';
            if (file_exists($file)) {
                return $file;
            }
        }

        // 8. Buscar modelos en plugins activos
        if (isset($GLOBALS['plugins']) && is_array($GLOBALS['plugins'])) {
            foreach ($GLOBALS['plugins'] as $plugin) {
                // Legacy models (model/)
                $file = $folder . '/plugins/' . $plugin . '/model/' . $class . '.php';
                if (file_exists($file)) {
                    return $file;
                }
                
                // FS2025 models (Model/)
                $file = $folder . '/plugins/' . $plugin . '/Model/' . $class . '.php';
                if (file_exists($file)) {
                    return $file;
                }
            }
        }

        // 9. Buscar en model/core
        $file = $folder . '/model/core/' . $class . '.php';
        if (file_exists($file)) {
            return $file;
        }

        // 10. Buscar en model/
        $file = $folder . '/model/' . $class . '.php';
        if (file_exists($file)) {
            return $file;
        }

        return null;
    }

    /**
     * Obtiene el mapeo de clases legacy
     * 
     * @return array
     */
    private static function getLegacyClassMap()
    {
        return [
            // Base
            'fs_app' => '/base/fs_app.php',
            'fs_api' => '/base/fs_api.php',
            'fs_cache' => '/base/fs_cache.php',
            'fs_controller' => '/base/fs_controller.php',
            'fs_core_log' => '/base/fs_core_log.php',
            'fs_db2' => '/base/fs_db2.php',
            'fs_db_engine' => '/base/fs_db_engine.php',
            'fs_default_items' => '/base/fs_default_items.php',
            'fs_divisa_tools' => '/base/fs_divisa_tools.php',
            'fs_edit_controller' => '/base/fs_edit_controller.php',
            'fs_edit_form' => '/base/fs_edit_form.php',
            'fs_excel' => '/base/fs_excel.php',
            'fs_extended_model' => '/base/fs_extended_model.php',
            'fs_file_manager' => '/base/fs_file_manager.php',
            'fs_ip_filter' => '/base/fs_ip_filter.php',
            'fs_list_controller' => '/base/fs_list_controller.php',
            'fs_list_decoration' => '/base/fs_list_decoration.php',
            'fs_list_filter' => '/base/fs_list_filter.php',
            'fs_list_filter_checkbox' => '/base/fs_list_filter_checkbox.php',
            'fs_list_filter_date' => '/base/fs_list_filter_date.php',
            'fs_list_filter_select' => '/base/fs_list_filter_select.php',
            'fs_log_manager' => '/base/fs_log_manager.php',
            'fs_login' => '/base/fs_login.php',
            'fs_model' => '/base/fs_model.php',
            'fs_mysql' => '/base/fs_mysql.php',
            'fs_plugin_manager' => '/base/fs_plugin_manager.php',
            'fs_postgresql' => '/base/fs_postgresql.php',
            'fs_settings' => '/base/fs_settings.php',
            'fs_updater' => '/base/fs_updater.php',
            'fs_chunked_upload' => '/base/fs_chunked_upload.php',
            'php_file_cache' => '/base/php_file_cache.php',
            
            // Nuevas clases
            'fs_session_manager' => '/base/fs_session_manager.php',
            'fs_auth' => '/base/fs_auth.php',
            'fs_query_builder' => '/base/fs_query_builder.php',
            'fs_schema' => '/base/fs_schema.php',
            
            // Core models
            'fs_var' => '/model/core/fs_var.php',
            'fs_user' => '/model/core/fs_user.php',
            'fs_page' => '/model/core/fs_page.php',
            'fs_access' => '/model/core/fs_access.php',
        ];
    }

    /**
     * Convierte CamelCase a snake_case
     * 
     * @param string $input
     * @return string
     */
    private static function camelToSnake($input)
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }

    /**
     * Añade una clase al mapeo
     * 
     * @param string $class Nombre de la clase
     * @param string $file Ruta del archivo
     * @return void
     */
    public static function addClass($class, $file)
    {
        self::$classMap[$class] = $file;
    }

    /**
     * Añade múltiples clases al mapeo
     * 
     * @param array $classes Array asociativo clase => archivo
     * @return void
     */
    public static function addClasses($classes)
    {
        self::$classMap = array_merge(self::$classMap, $classes);
    }

    /**
     * Obtiene el mapeo de clases actual
     * 
     * @return array
     */
    public static function getClassMap()
    {
        return self::$classMap;
    }

    /**
     * Limpia el cache de clases
     * 
     * @return void
     */
    public static function clearCache()
    {
        self::$classMap = [];
    }

    /**
     * Precarga las clases base esenciales
     * 
     * @return void
     */
    public static function preloadEssentials()
    {
        $folder = self::$baseFolder;
        
        $essentials = [
            'fs_cache',
            'fs_core_log',
            'fs_db2',
            'fs_model',
        ];

        foreach ($essentials as $class) {
            if (!class_exists($class, false)) {
                $file = $folder . '/base/' . $class . '.php';
                if (file_exists($file)) {
                    require_once $file;
                }
            }
        }
    }

    /**
     * Carga todos los modelos de un plugin
     * 
     * @param string $plugin Nombre del plugin
     * @return array Lista de clases cargadas
     */
    public static function loadPluginModels($plugin)
    {
        $folder = self::$baseFolder;
        $loaded = [];
        
        $modelDir = $folder . '/plugins/' . $plugin . '/model';
        if (is_dir($modelDir)) {
            foreach (scandir($modelDir) as $file) {
                if (substr($file, -4) === '.php') {
                    $class = substr($file, 0, -4);
                    if (!class_exists($class, false)) {
                        require_once $modelDir . '/' . $file;
                        $loaded[] = $class;
                    }
                }
            }
        }
        
        return $loaded;
    }

    /**
     * Verifica si el autoloader está registrado
     * 
     * @return bool
     */
    public static function isRegistered()
    {
        return self::$registered;
    }
}

// Registrar automáticamente si FS_FOLDER está definido
if (defined('FS_FOLDER')) {
    fs_autoload::register(FS_FOLDER);
}
