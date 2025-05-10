<?php
/**
 * This file is part of FS-Framework
 * Copyright (C) 2013-2025 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

// Mostrar todos los errores de PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use FSFramework\Kernel;
use FSFramework\Plugin\PluginAutoloader;
use FSFramework\Plugin\LegacyPluginAutoloader;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\HttpFoundation\Request;

// Comprobamos la versión de PHP
if ((float) substr(phpversion(), 0, 3) < 8.1) {
    die('FS-Framework necesita PHP 8.1 o superior, y usted tiene PHP ' . phpversion());
}

// Si no hay config.php redirigimos al instalador
if (!file_exists('config.php')) {
    header('Location: install.php');
    die('Redireccionando al instalador...');
}

// Cargamos las dependencias
require_once 'config.php';

// Definimos la constante FS_FOLDER si no está definida
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', __DIR__);
}

// Cargar el autoloader legacy para compatibilidad con clases antiguas
require_once 'base/fs_legacy_autoloader.php';

// Cargar el autoloader para controladores legacy
require_once 'base/fs_controller_autoloader.php';

// Cargar funciones de compatibilidad para plugins antiguos
require_once 'base/fs_functions.php';

// Cargar el autoloader para el plugin business_data
if (file_exists('plugins/business_data/fs_business_data_autoloader.php')) {
    require_once 'plugins/business_data/fs_business_data_autoloader.php';
}

// Cargar el autoloader de Composer
require_once 'vendor/autoload.php';

// Registramos el autoloader de plugins
PluginAutoloader::register();
LegacyPluginAutoloader::register();

// Registrar el autoloader de compatibilidad para plugins antiguos
if (function_exists('register_legacy_plugin_autoloader')) {
    register_legacy_plugin_autoloader();
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
