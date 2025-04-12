<?php
// Display all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Simplified Index</h1>";

// Set environment variables
$_SERVER['APP_ENV'] = 'dev';
$_SERVER['APP_DEBUG'] = '1';

try {
    // Load configuration
    if (file_exists(__DIR__ . '/config.php')) {
        echo "<p>Loading config.php...</p>";
        require_once __DIR__ . '/config.php';
        echo "<p style='color: green;'>config.php loaded successfully.</p>";
    } else {
        echo "<p style='color: red;'>config.php does not exist.</p>";
        die();
    }
    
    // Load autoloader
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        echo "<p>Loading vendor/autoload.php...</p>";
        require_once __DIR__ . '/vendor/autoload.php';
        echo "<p style='color: green;'>vendor/autoload.php loaded successfully.</p>";
    } else {
        echo "<p style='color: red;'>vendor/autoload.php does not exist.</p>";
        die();
    }
    
    // Define FS_FOLDER if not defined
    if (!defined('FS_FOLDER')) {
        echo "<p>Defining FS_FOLDER...</p>";
        define('FS_FOLDER', __DIR__);
        echo "<p style='color: green;'>FS_FOLDER defined as: " . FS_FOLDER . "</p>";
    }
    
    // Try to register the plugin autoloader
    if (class_exists('FSFramework\Plugin\PluginAutoloader')) {
        echo "<p>Registering plugin autoloader...</p>";
        \FSFramework\Plugin\PluginAutoloader::register();
        echo "<p style='color: green;'>Plugin autoloader registered successfully.</p>";
    } else {
        echo "<p style='color: red;'>FSFramework\Plugin\PluginAutoloader class not found.</p>";
    }
    
    // Try to create a kernel
    if (class_exists('FSFramework\Kernel')) {
        echo "<p>Creating kernel...</p>";
        $kernel = new \FSFramework\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
        echo "<p style='color: green;'>Kernel created successfully.</p>";
        
        // Try to create a request
        if (class_exists('Symfony\Component\HttpFoundation\Request')) {
            echo "<p>Creating request...</p>";
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            echo "<p style='color: green;'>Request created successfully.</p>";
            
            // Try to handle the request
            echo "<p>Handling request...</p>";
            $response = $kernel->handle($request);
            echo "<p style='color: green;'>Request handled successfully.</p>";
            
            // Send the response
            echo "<p>Sending response...</p>";
            $response->send();
            echo "<p style='color: green;'>Response sent successfully.</p>";
            
            // Terminate the kernel
            echo "<p>Terminating kernel...</p>";
            $kernel->terminate($request, $response);
            echo "<p style='color: green;'>Kernel terminated successfully.</p>";
        } else {
            echo "<p style='color: red;'>Symfony\Component\HttpFoundation\Request class not found.</p>";
        }
    } else {
        echo "<p style='color: red;'>FSFramework\Kernel class not found.</p>";
    }
} catch (Exception $e) {
    echo "<h2>Error</h2>";
    echo "<p style='color: red;'>" . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
