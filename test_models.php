<?php
/**
 * Archivo de prueba para verificar la carga de clases
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

// Intentar cargar las clases
echo "<h1>Prueba de carga de clases</h1>";

// Probar si fs_model existe
echo "<h2>Carga de fs_model</h2>";
if (class_exists('fs_model')) {
    echo "✅ La clase fs_model existe<br>";
} else {
    echo "❌ La clase fs_model NO existe<br>";
}

// Probar si la clase empresa está disponible
echo "<h2>Carga de modelo empresa</h2>";
require_once 'model/empresa.php';
if (class_exists('empresa')) {
    echo "✅ La clase empresa existe<br>";
    
    // Intentar instanciar la clase
    try {
        $emp = new empresa();
        echo "✅ La clase empresa se ha instanciado correctamente<br>";
    } catch (Throwable $e) {
        echo "❌ Error al instanciar la clase empresa: " . $e->getMessage() . "<br>";
    }
} else {
    echo "❌ La clase empresa NO existe<br>";
}

// Verificar las clases padre
echo "<h2>Verificación de herencia</h2>";
if (class_exists('FacturaScripts\\model\\empresa')) {
    echo "✅ La clase FacturaScripts\\model\\empresa existe<br>";
} else {
    echo "❌ La clase FacturaScripts\\model\\empresa NO existe<br>";
}

// Prueba con país
echo "<h2>Carga de modelo país</h2>";
require_once 'model/pais.php';
if (class_exists('pais')) {
    echo "✅ La clase pais existe<br>";
} else {
    echo "❌ La clase pais NO existe<br>";
}

echo "<p>Fin de la prueba</p>"; 