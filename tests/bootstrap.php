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

// Reutilizar la configuracion real cuando exista, por ejemplo en ddev.
$appConfig = dirname(__DIR__) . '/config.php';
if (file_exists($appConfig)) {
    require_once $appConfig;
}

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

if (!defined('FS_DB_INTEGER')) {
    define('FS_DB_INTEGER', 'INT(11)');
}

if (!defined('FS_DB_HOST')) {
    define('FS_DB_HOST', 'localhost');
}

if (!defined('FS_DB_PORT')) {
    define('FS_DB_PORT', '3306');
}

if (!defined('FS_DB_NAME')) {
    define('FS_DB_NAME', 'fsframework_test');
}

if (!defined('FS_DB_USER')) {
    define('FS_DB_USER', 'root');
}

if (!defined('FS_DB_PASS')) {
    define('FS_DB_PASS', '');
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

if (!defined('FS_NF1')) {
    define('FS_NF1', ',');
}

if (!defined('FS_NF2')) {
    define('FS_NF2', '.');
}

if (!defined('FS_POS_DIVISA')) {
    define('FS_POS_DIVISA', 'right');
}

if (!defined('FS_ITEM_LIMIT')) {
    define('FS_ITEM_LIMIT', 50);
}

if (!defined('FS_COOKIES_EXPIRE')) {
    define('FS_COOKIES_EXPIRE', 31536000);
}

if (!defined('FS_DB_HISTORY')) {
    define('FS_DB_HISTORY', false);
}

if (!defined('FS_FOREIGN_KEYS')) {
    define('FS_FOREIGN_KEYS', true);
}

if (!defined('FS_CHECK_DB_TYPES')) {
    define('FS_CHECK_DB_TYPES', true);
}

if (!defined('FS_PATH')) {
    define('FS_PATH', '');
}

if (!defined('FS_DEMO')) {
    define('FS_DEMO', false);
}

if (!defined('FS_SECRET_KEY')) {
    define('FS_SECRET_KEY', 'phpunit-test-secret-key');
}

if (!isset($GLOBALS['plugins']) || !is_array($GLOBALS['plugins'])) {
    $GLOBALS['plugins'] = [];
}

// Asegurar que el directorio tmp existe para tests que lo necesiten
$tmpDir = FS_FOLDER . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0777, true);
}

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/base/fs_model_autoloader.php';

fs_model_autoloader::register();
