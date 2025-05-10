<?php
/**
 * Archivo de prueba para verificar la carga de controladores
 */

// Mostrar todos los errores de PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Definimos la constante FS_FOLDER si no está definida
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', __DIR__);
}

// Cargamos las dependencias
require_once 'config.php';

// Cargar el autoloader legacy
require_once 'base/fs_legacy_autoloader.php';

// Cargar el autoloader para controladores
require_once 'base/fs_controller_autoloader.php';

// Intentar cargar las clases
echo "<h1>Prueba de carga de controladores</h1>";

// Probar si fs_controller existe
echo "<h2>Carga de fs_controller</h2>";
if (class_exists('fs_controller')) {
    echo "✅ La clase fs_controller existe<br>";
} else {
    echo "❌ La clase fs_controller NO existe<br>";
}

// Probar si fs_app existe
echo "<h2>Carga de fs_app</h2>";
if (class_exists('fs_app')) {
    echo "✅ La clase fs_app existe<br>";
} else {
    echo "❌ La clase fs_app NO existe<br>";
}

// Probar si fs_db2 existe
echo "<h2>Carga de fs_db2</h2>";
if (class_exists('fs_db2')) {
    echo "✅ La clase fs_db2 existe<br>";
} else {
    echo "❌ La clase fs_db2 NO existe<br>";
}

// Verificar dependencias adicionales
echo "<h2>Verificación de dependencias adicionales</h2>";
$classes = [
    'fs_default_items',
    'fs_extended_model',
    'fs_login',
    'fs_divisa_tools',
    'empresa',
    'fs_user',
    'fs_model'
];

foreach ($classes as $class) {
    if (class_exists($class)) {
        echo "✅ La clase {$class} existe<br>";
    } else {
        echo "❌ La clase {$class} NO existe<br>";
    }
}

echo "<p>Fin de la prueba</p>"; 