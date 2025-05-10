<?php
/**
 * Autoloader para controladores legacy del framework
 */

class FSControllerAutoloader
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
     * Carga una clase de controlador
     * 
     * @param string $class El nombre de la clase a cargar
     * @return bool True si la clase fue cargada, false en caso contrario
     */
    public static function loadClass($class)
    {
        // Mapa de clases legacy
        $classMap = [
            'fs_controller' => 'base/fs_controller.php',
            'fs_list_controller' => 'base/fs_list_controller.php',
            'fs_edit_controller' => 'base/fs_edit_controller.php',
            'fs_app' => 'base/fs_app.php',
            'fs_login' => 'base/fs_login.php',
            'fs_divisa_tools' => 'base/fs_divisa_tools.php',
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
FSControllerAutoloader::register();

// Precargar fs_controller ya que es la más usada
if (!class_exists('fs_controller', false)) {
    FSControllerAutoloader::loadClass('fs_controller');
} 