<?php
// Display all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Plugin Autoloader Test</h1>";

// Define FS_FOLDER
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', __DIR__);
    echo "<p>Defined FS_FOLDER as: " . FS_FOLDER . "</p>";
}

// Load autoloader
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    echo "<p style='color: green;'>Autoloader loaded successfully</p>";
} else {
    echo "<p style='color: red;'>Autoloader not found</p>";
    die();
}

// Load the plugin autoloader
if (class_exists('FSFramework\Plugin\PluginAutoloader')) {
    echo "<p style='color: green;'>PluginAutoloader class found</p>";
    
    // Register the plugin autoloader
    FSFramework\Plugin\PluginAutoloader::register();
    echo "<p style='color: green;'>Plugin autoloader registered</p>";
} else {
    echo "<p style='color: red;'>PluginAutoloader class not found</p>";
    die();
}

// Try to load the example controller
$controllerClass = 'FSFramework\Plugin\ExampleTwig\Controller\ExampleController';
echo "<h2>Testing class: $controllerClass</h2>";

if (class_exists($controllerClass)) {
    echo "<p style='color: green;'>Class $controllerClass loaded successfully</p>";
    
    // Create an instance of the controller
    $controller = new $controllerClass();
    echo "<p style='color: green;'>Controller instance created</p>";
    
    // Print the class methods
    $methods = get_class_methods($controller);
    echo "<p>Controller methods:</p>";
    echo "<ul>";
    foreach ($methods as $method) {
        echo "<li>$method</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color: red;'>Class $controllerClass not found</p>";
    
    // Check if the file exists
    $file = __DIR__ . '/plugins/example_twig/Controller/ExampleController.php';
    if (file_exists($file)) {
        echo "<p>File exists at: $file</p>";
        
        // Display the file content
        echo "<h3>File content:</h3>";
        echo "<pre>" . htmlspecialchars(file_get_contents($file)) . "</pre>";
    } else {
        echo "<p>File not found at: $file</p>";
    }
}

// List all plugins
echo "<h2>Available Plugins</h2>";
if (is_dir(__DIR__ . '/plugins')) {
    $plugins = scandir(__DIR__ . '/plugins');
    $plugins = array_diff($plugins, ['.', '..']);
    
    echo "<ul>";
    foreach ($plugins as $plugin) {
        if (is_dir(__DIR__ . '/plugins/' . $plugin)) {
            echo "<li>$plugin</li>";
        }
    }
    echo "</ul>";
} else {
    echo "<p>No plugins directory found</p>";
}

// Check if the global plugins array is defined
echo "<h2>Global Plugins Array</h2>";
if (isset($GLOBALS['plugins']) && is_array($GLOBALS['plugins'])) {
    echo "<p>Global plugins array is defined with " . count($GLOBALS['plugins']) . " plugins:</p>";
    echo "<ul>";
    foreach ($GLOBALS['plugins'] as $plugin) {
        echo "<li>$plugin</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color: red;'>Global plugins array is not defined</p>";
    
    // Define the global plugins array
    $GLOBALS['plugins'] = ['example_twig'];
    echo "<p>Created global plugins array with: example_twig</p>";
}

// Test loading the controller again
echo "<h2>Testing class again: $controllerClass</h2>";
if (class_exists($controllerClass)) {
    echo "<p style='color: green;'>Class $controllerClass loaded successfully</p>";
} else {
    echo "<p style='color: red;'>Class $controllerClass still not found</p>";
}
?>
