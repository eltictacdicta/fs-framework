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

// Crear un entorno Twig
$loader = new \Twig\Loader\FilesystemLoader([
    __DIR__ . '/templates',
    __DIR__ . '/src/Template',
    __DIR__ . '/view',
    __DIR__ . '/plugins/example_twig/Template'
]);
$twig = new \Twig\Environment($loader, [
    'debug' => true,
    'cache' => false
]);

// Renderizar una plantilla
try {
    echo $twig->render('base.html.twig', [
        'title' => 'Prueba de Twig',
        'message' => 'Esta es una prueba de Twig',
        'items' => [
            'Item 1',
            'Item 2',
            'Item 3'
        ]
    ]);
} catch (Exception $e) {
    echo '<h1>Error al renderizar la plantilla</h1>';
    echo '<p>' . $e->getMessage() . '</p>';
    echo '<pre>' . $e->getTraceAsString() . '</pre>';
}
