<?php
// Mostrar todos los errores de PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Información sobre PHP
echo "<h1>Información de PHP</h1>";
echo "<p>Versión de PHP: " . phpversion() . "</p>";
echo "<p>Extensiones cargadas:</p>";
echo "<ul>";
foreach (get_loaded_extensions() as $ext) {
    echo "<li>" . $ext . "</li>";
}
echo "</ul>";

// Verificar la configuración de Twig
echo "<h1>Verificación de Twig</h1>";
if (class_exists('Twig\Environment')) {
    echo "<p>Twig está instalado correctamente.</p>";
} else {
    echo "<p>Twig no está instalado correctamente.</p>";
}

// Verificar la configuración de Symfony
echo "<h1>Verificación de Symfony</h1>";
if (class_exists('Symfony\Component\HttpKernel\Kernel')) {
    echo "<p>Symfony está instalado correctamente.</p>";
} else {
    echo "<p>Symfony no está instalado correctamente.</p>";
}

// Verificar la estructura de directorios
echo "<h1>Verificación de directorios</h1>";
$directories = [
    'templates',
    'plugins/example_twig',
    'plugins/example_twig/Template',
    'plugins/example_twig/Controller',
    'src/Template',
    'view'
];

echo "<ul>";
foreach ($directories as $dir) {
    if (is_dir($dir)) {
        echo "<li>" . $dir . " - Existe</li>";
    } else {
        echo "<li>" . $dir . " - No existe</li>";
    }
}
echo "</ul>";

// Verificar los archivos de configuración
echo "<h1>Verificación de archivos de configuración</h1>";
$files = [
    'config/packages/twig.yaml',
    'templates/base.html.twig',
    'plugins/example_twig/Template/index.html.twig',
    'plugins/example_twig/Controller/ExampleController.php'
];

echo "<ul>";
foreach ($files as $file) {
    if (file_exists($file)) {
        echo "<li>" . $file . " - Existe</li>";
    } else {
        echo "<li>" . $file . " - No existe</li>";
    }
}
echo "</ul>";

// Verificar la variable global de plugins
echo "<h1>Verificación de plugins activos</h1>";
if (isset($GLOBALS['plugins']) && is_array($GLOBALS['plugins'])) {
    echo "<p>Plugins activos:</p>";
    echo "<ul>";
    foreach ($GLOBALS['plugins'] as $plugin) {
        echo "<li>" . $plugin . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No hay plugins activos o la variable global no está definida.</p>";
}

// Intentar cargar el controlador del plugin
echo "<h1>Verificación del controlador del plugin</h1>";
$controllerFile = 'plugins/example_twig/Controller/ExampleController.php';
if (file_exists($controllerFile)) {
    echo "<p>El archivo del controlador existe.</p>";
    
    // Intentar incluir el archivo
    try {
        require_once $controllerFile;
        echo "<p>El archivo del controlador se ha cargado correctamente.</p>";
        
        if (class_exists('FSFramework\Plugin\ExampleTwig\Controller\ExampleController')) {
            echo "<p>La clase del controlador existe.</p>";
        } else {
            echo "<p>La clase del controlador no existe.</p>";
        }
    } catch (Exception $e) {
        echo "<p>Error al cargar el archivo del controlador: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p>El archivo del controlador no existe.</p>";
}
