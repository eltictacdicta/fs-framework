<?php
// Display all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define FS_FOLDER if not defined
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', __DIR__);
}

// Load configuration
require_once 'config.php';

// Load autoloader
require_once 'vendor/autoload.php';

// Initialize the global plugins array
$GLOBALS['plugins'] = ['example_twig'];

// Load the plugin functions
foreach ($GLOBALS['plugins'] as $plugin) {
    $functionsFile = __DIR__ . '/plugins/' . $plugin . '/functions.php';
    if (file_exists($functionsFile)) {
        require_once $functionsFile;
    }
}

// Create a simple Twig environment
$loader = new \Twig\Loader\FilesystemLoader([
    __DIR__ . '/templates',
    __DIR__ . '/src/Template',
    __DIR__ . '/plugins/example_twig/Template'
]);

$twig = new \Twig\Environment($loader, [
    'debug' => true,
    'cache' => false
]);

// Render the template
try {
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
} catch (Exception $e) {
    echo '<h1>Error al renderizar la plantilla</h1>';
    echo '<p>' . $e->getMessage() . '</p>';
    echo '<pre>' . $e->getTraceAsString() . '</pre>';
}
?>
