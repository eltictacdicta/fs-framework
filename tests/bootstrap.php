<?php
/**
 * Bootstrap para pruebas unitarias de FSFramework
 * 
 * Define las constantes mínimas necesarias para que las clases del
 * framework se puedan instanciar en un entorno de testing aislado,
 * sin conexión a base de datos.
 */

// Autoloader de Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Constantes del framework necesarias para las clases base
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', dirname(__DIR__));
}

if (!defined('FS_TMP_NAME')) {
    define('FS_TMP_NAME', 'test_');
}

if (!defined('FS_DB_TYPE')) {
    define('FS_DB_TYPE', 'MYSQL');
}

if (!defined('FS_IP_WHITELIST')) {
    define('FS_IP_WHITELIST', '*');
}

if (!defined('FS_MYDOCS')) {
    define('FS_MYDOCS', 'documentos');
}

if (!defined('FS_MAX_DECIMALS')) {
    define('FS_MAX_DECIMALS', 2);
}

if (!defined('FS_NF0')) {
    define('FS_NF0', 2);
}

// Asegurar que el directorio tmp existe para tests que lo necesiten
$tmpDir = FS_FOLDER . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0777, true);
}
