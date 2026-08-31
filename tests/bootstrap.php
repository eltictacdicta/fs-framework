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

// Symfony PHPUnit bridge: activa el reporting de deprecaciones honrando
// SYMFONY_DEPRECATIONS_HELPER (ver phpunit.xml).
require_once __DIR__ . '/../vendor/symfony/phpunit-bridge/bootstrap.php';

// Con PHPUnit >= 10 el bootstrap del puente retorna antes de registrar su
// DeprecationErrorHandler (y la SymfonyExtension tampoco lo registra), por lo
// que SYMFONY_DEPRECATIONS_HELPER se ignoraria. Registro explicito e
// idempotente para que weak / max[...] sigan siendo honrados por el puente.
if (class_exists(\Symfony\Bridge\PhpUnit\DeprecationErrorHandler::class)
    && 'disabled' !== getenv('SYMFONY_DEPRECATIONS_HELPER')
) {
    \Symfony\Bridge\PhpUnit\DeprecationErrorHandler::register(getenv('SYMFONY_DEPRECATIONS_HELPER') ?: 'weak');
}

// Vendors aislados de plugins (p. ej. OidcProvider firebase/php-jwt)
foreach (glob(dirname(__DIR__) . '/plugins/*/composer_autoload.php') ?: [] as $pluginComposerBootstrap) {
    require_once $pluginComposerBootstrap;
}

// Constantes del framework necesarias para las clases base.
// Se definen SIEMPRE con valores canónicos de testing: nunca se carga el
// config.php local de la máquina, para que la suite sea determinista.
define('FS_FOLDER', dirname(__DIR__));

define('FS_TMP_NAME', 'test_');
define('FS_DB_TYPE', 'MYSQL');
define('FS_DB_INTEGER', 'INT(11)');
define('FS_DB_HOST', 'db');
define('FS_DB_PORT', '3306');
define('FS_DB_NAME', 'db');
define('FS_DB_USER', 'db');
define('FS_DB_PASS', 'db');
define('FS_IP_WHITELIST', '*');
define('FS_MYDOCS', 'documentos');
define('FS_MAX_DECIMALS', 2);
define('FS_NF0', 2);
define('FS_NF1', ',');
define('FS_NF2', '.');
define('FS_POS_DIVISA', 'right');
define('FS_ITEM_LIMIT', 50);
define('FS_COOKIES_EXPIRE', 31536000);
define('FS_VENTAS_SIN_STOCK', false);
define('FS_DB_HISTORY', false);
define('FS_FOREIGN_KEYS', true);
define('FS_CHECK_DB_TYPES', true);
define('FS_PATH', '');
define('FS_DEMO', false);

// Secreto de testing determinista (64 hex chars): satisface la validación de
// SecretManager (>= 32 caracteres) sin recurrir al fallback de fichero.
define('FS_SECRET_KEY', '9f1c2d3e4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d');

if (!isset($GLOBALS['plugins']) || !is_array($GLOBALS['plugins'])) {
    $GLOBALS['plugins'] = [];
}

// Asegurar que el directorio tmp (y el subdirectorio FS_TMP_NAME) existen
$tmpDir = FS_FOLDER . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0777, true);
}
$tmpNameDir = $tmpDir . '/' . FS_TMP_NAME;
if (!is_dir($tmpNameDir)) {
    mkdir($tmpNameDir, 0777, true);
}

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/base/fs_model_autoloader.php';

fs_model_autoloader::register();
