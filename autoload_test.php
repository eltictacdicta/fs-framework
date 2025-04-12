<?php
// Display all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Autoloader Test</h1>";

try {
    // Test if autoload.php exists
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        echo "<p style='color: green;'>vendor/autoload.php exists</p>";
    } else {
        echo "<p style='color: red;'>vendor/autoload.php does not exist</p>";
        die("Cannot continue without autoloader");
    }
    
    // Try to load the autoloader
    require_once __DIR__ . '/vendor/autoload.php';
    echo "<p style='color: green;'>Autoloader loaded successfully</p>";
    
    // Test if Symfony components are available
    $components = [
        'Symfony\Component\HttpFoundation\Request',
        'Symfony\Component\HttpKernel\Kernel',
        'Symfony\Component\Routing\Router',
        'Symfony\Component\DependencyInjection\ContainerBuilder',
        'Symfony\Component\Config\FileLocator',
        'Symfony\Component\Yaml\Yaml',
        'Twig\Environment'
    ];
    
    echo "<h2>Symfony Components</h2>";
    echo "<ul>";
    foreach ($components as $component) {
        if (class_exists($component)) {
            echo "<li style='color: green;'>$component: Available</li>";
        } else {
            echo "<li style='color: red;'>$component: Not available</li>";
        }
    }
    echo "</ul>";
    
    // Test if FSFramework classes are available
    $fsClasses = [
        'FSFramework\Kernel',
        'FSFramework\Plugin\PluginAutoloader'
    ];
    
    echo "<h2>FSFramework Classes</h2>";
    echo "<ul>";
    foreach ($fsClasses as $class) {
        if (class_exists($class)) {
            echo "<li style='color: green;'>$class: Available</li>";
        } else {
            echo "<li style='color: red;'>$class: Not available</li>";
        }
    }
    echo "</ul>";
    
    // Print the include path
    echo "<h2>Include Path</h2>";
    echo "<p>" . get_include_path() . "</p>";
    
    // Print the autoloader stack
    echo "<h2>Autoloader Stack</h2>";
    $autoloaders = spl_autoload_functions();
    if ($autoloaders) {
        echo "<ul>";
        foreach ($autoloaders as $autoloader) {
            if (is_array($autoloader)) {
                if (is_object($autoloader[0])) {
                    echo "<li>" . get_class($autoloader[0]) . "->" . $autoloader[1] . "()</li>";
                } else {
                    echo "<li>" . $autoloader[0] . "::" . $autoloader[1] . "()</li>";
                }
            } else {
                echo "<li>" . $autoloader . "()</li>";
            }
        }
        echo "</ul>";
    } else {
        echo "<p>No autoloaders registered</p>";
    }
    
} catch (Exception $e) {
    echo "<h2>Error</h2>";
    echo "<p style='color: red;'>" . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
