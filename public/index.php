<?php

use FSFramework\Kernel;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/vendor/autoload.php';

// Definimos la constante FS_FOLDER si no está definida
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', dirname(__DIR__));
}

// Definimos el entorno
$env = $_SERVER['APP_ENV'] ?? 'prod';
$debug = (bool) ($_SERVER['APP_DEBUG'] ?? ($env !== 'prod'));

if ($debug) {
    umask(0000);
    Debug::enable();
}

// Creamos el kernel
$kernel = new Kernel($env, $debug);

// Creamos la petición
$request = Request::createFromGlobals();

// Manejamos la petición
$response = $kernel->handle($request);
$response->send();

// Terminamos la petición
$kernel->terminate($request, $response);
