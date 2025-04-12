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
require_once 'vendor/autoload.php';

// Definimos la constante FS_FOLDER si no está definida
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', __DIR__);
}

// Definimos el entorno
$_SERVER['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'dev';
$_SERVER['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? ($_SERVER['APP_ENV'] !== 'prod');

// Inicializamos los plugins
$GLOBALS['plugins'] = [];

// Cargamos la lista de plugins activos
$enabledPluginsFile = __DIR__ . '/tmp/' . FS_TMP_NAME . 'enabled_plugins.list';
if (file_exists($enabledPluginsFile)) {
    $enabledPlugins = file_get_contents($enabledPluginsFile);
    $plugins = explode(',', $enabledPlugins);
    
    foreach ($plugins as $plugin) {
        $plugin = trim($plugin);
        if (!empty($plugin) && is_dir(__DIR__ . '/plugins/' . $plugin)) {
            $GLOBALS['plugins'][] = $plugin;
        }
    }
}

// Si no hay plugins activos, activamos el plugin de ejemplo
if (empty($GLOBALS['plugins']) && is_dir(__DIR__ . '/plugins/example_twig')) {
    $GLOBALS['plugins'][] = 'example_twig';
    
    // Guardamos la lista de plugins activos
    file_put_contents($enabledPluginsFile, 'example_twig');
}

// Registramos el autoloader de plugins
require_once 'src/Plugin/PluginAutoloader.php';
\FSFramework\Plugin\PluginAutoloader::register();

// Cargamos las funciones de los plugins
foreach ($GLOBALS['plugins'] as $plugin) {
    $functionsFile = __DIR__ . '/plugins/' . $plugin . '/functions.php';
    if (file_exists($functionsFile)) {
        require_once $functionsFile;
    }
}

// Creamos un entorno Twig simple
$loader = new \Twig\Loader\FilesystemLoader([
    __DIR__ . '/templates',
    __DIR__ . '/src/Template',
    __DIR__ . '/plugins/example_twig/Template'
]);

$twig = new \Twig\Environment($loader, [
    'debug' => true,
    'cache' => false
]);

// Procesamos la URL para determinar qué controlador y acción ejecutar
$uri = $_SERVER['REQUEST_URI'];
$basePath = '/fs-framework/';
$path = substr($uri, strlen($basePath));

// Si la ruta está vacía, mostramos la página de inicio
if (empty($path) || $path === 'index.php') {
    echo $twig->render('base.html.twig', [
        'title' => 'FS-Framework',
        'message' => 'Bienvenido a FS-Framework',
        'items' => [
            'Modular',
            'Basado en Symfony',
            'Soporte para plugins',
            'Soporte para Twig',
            'Fácil de usar'
        ]
    ]);
    exit;
}

// Si la ruta es 'example', ejecutamos el controlador de ejemplo
if ($path === 'example') {
    require_once 'src/Controller/BaseController.php';
    require_once 'plugins/example_twig/Controller/ExampleController.php';
    
    $controller = new \FSFramework\Plugin\ExampleTwig\Controller\ExampleController();
    $response = $controller->index();
    $response->send();
    exit;
}

// Si no encontramos ninguna ruta válida, mostramos un error 404
header('HTTP/1.0 404 Not Found');
echo $twig->render('base.html.twig', [
    'title' => 'Error 404',
    'message' => 'Página no encontrada',
    'items' => [
        'La página que está buscando no existe',
        'Verifique la URL e inténtelo de nuevo',
        '<a href="' . $basePath . '">Volver al inicio</a>'
    ]
]);
?>
