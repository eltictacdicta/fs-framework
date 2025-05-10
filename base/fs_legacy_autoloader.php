<?php
/**
 * Autoloader para clases legacy del framework
 */

class FSLegacyAutoloader
{
    /**
     * Lista de archivos ya incluidos para evitar redeclaraciones
     */
    private static $included_files = [];

    /**
     * Registra el autoloader
     */
    public static function register()
    {
        spl_autoload_register([self::class, 'loadClass'], true, true);
    }

    /**
     * Carga una clase
     * 
     * @param string $class El nombre de la clase a cargar
     * @return bool True si la clase fue cargada, false en caso contrario
     */
    public static function loadClass($class)
    {
        // Si es una clase con namespace, verificamos si es del namespace FacturaScripts\model
        if (strpos($class, '\\') !== false) {
            list($namespace, $className) = explode('\\', $class, 2);
            
            if ($namespace === 'FacturaScripts' && strpos($className, 'model\\') === 0) {
                $modelName = substr($className, 6); // Quitar 'model\'
                $file = FS_FOLDER . '/model/core/' . $modelName . '.php';
                
                if (file_exists($file)) {
                    self::requireOnce($file);
                    return true;
                }
            }
            return false;
        }
        
        // Para clases sin namespace, intentamos cargarlas directamente
        $classMap = [
            'fs_model' => 'base/fs_model.php',
            'fs_cache' => 'base/fs_cache.php',
            'fs_db2' => 'base/fs_db2.php',
            'fs_core_log' => 'base/fs_core_log.php',
            'fs_functions' => 'base/fs_functions.php',
            'fs_default_items' => 'base/fs_default_items.php',
            'fs_var' => 'base/fs_var.php',
            // Añadir más clases según sea necesario
        ];
        
        if (isset($classMap[$class])) {
            $file = FS_FOLDER . '/' . $classMap[$class];
            if (file_exists($file)) {
                self::requireOnce($file);
                return true;
            }
        }
        
        return false;
    }

    /**
     * Incluye un archivo solo si no ha sido ya incluido
     * 
     * @param string $file Ruta del archivo a incluir
     */
    private static function requireOnce($file)
    {
        $realpath = realpath($file);
        if (!isset(self::$included_files[$realpath])) {
            self::$included_files[$realpath] = true;
            require_once $file;
        }
    }
}

// Registrar el autoloader
FSLegacyAutoloader::register();

// Precargar fs_model ya que es la más usada
if (!class_exists('fs_model', false)) {
    FSLegacyAutoloader::loadClass('fs_model');
} 