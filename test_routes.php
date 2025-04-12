<?php
// Mostrar todos los errores de PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Cargar el autoloader de Composer
require_once __DIR__ . '/vendor/autoload.php';

// Definir la constante FS_FOLDER si no está definida
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', __DIR__);
}

// Cargar el controlador del plugin
require_once __DIR__ . '/plugins/example_twig/Controller/ExampleController.php';

// Verificar si la clase existe
if (class_exists('FSFramework\Plugin\ExampleTwig\Controller\ExampleController')) {
    echo '<h1>La clase ExampleController existe</h1>';
    
    // Crear una instancia del controlador
    try {
        $controller = new \FSFramework\Plugin\ExampleTwig\Controller\ExampleController();
        echo '<p>Se ha creado una instancia del controlador correctamente.</p>';
    } catch (Exception $e) {
        echo '<h1>Error al crear una instancia del controlador</h1>';
        echo '<p>' . $e->getMessage() . '</p>';
        echo '<pre>' . $e->getTraceAsString() . '</pre>';
    }
} else {
    echo '<h1>La clase ExampleController no existe</h1>';
}
