<?php
/**
 * Actualizador de FSFramework (Delegado a Symfony)
 */

use FSFramework\Controller\UpdaterController;

if (!file_exists('config.php')) {
    die('Archivo config.php no encontrado. No puedes actualizar sin instalar.');
}

define('FS_FOLDER', __DIR__);

/// Carga de dependencias y Kernel moderno
require_once __DIR__ . '/vendor/autoload.php';
$kernel = \FSFramework\Core\Kernel::boot();

/// ampliamos el límite de ejecución de PHP a 5 minutos
@set_time_limit(300);
ignore_user_abort(true);

// Instanciamos y ejecutamos el controlador
$controller = new UpdaterController();
$response = $controller->handle($kernel->getRequest());
$response->send();