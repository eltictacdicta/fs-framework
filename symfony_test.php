<?php
// Display all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set environment variables
$_SERVER['APP_ENV'] = 'dev';
$_SERVER['APP_DEBUG'] = '1';

// Try to load Symfony components
try {
    require_once 'vendor/autoload.php';
    
    echo "<h1>Symfony Components Test</h1>";
    
    // Test HttpFoundation
    if (class_exists('Symfony\Component\HttpFoundation\Request')) {
        echo "<p style='color: green;'>Symfony HttpFoundation is available</p>";
        $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        echo "<p>Request method: " . $request->getMethod() . "</p>";
    } else {
        echo "<p style='color: red;'>Symfony HttpFoundation is not available</p>";
    }
    
    // Test ErrorHandler
    if (class_exists('Symfony\Component\ErrorHandler\Debug')) {
        echo "<p style='color: green;'>Symfony ErrorHandler is available</p>";
    } else {
        echo "<p style='color: red;'>Symfony ErrorHandler is not available</p>";
    }
    
    // Test HttpKernel
    if (class_exists('Symfony\Component\HttpKernel\Kernel')) {
        echo "<p style='color: green;'>Symfony HttpKernel is available</p>";
    } else {
        echo "<p style='color: red;'>Symfony HttpKernel is not available</p>";
    }
    
    // Test if FSFramework\Kernel exists
    if (class_exists('FSFramework\Kernel')) {
        echo "<p style='color: green;'>FSFramework\Kernel is available</p>";
    } else {
        echo "<p style='color: red;'>FSFramework\Kernel is not available</p>";
    }
    
    // Test if PluginAutoloader exists
    if (class_exists('FSFramework\Plugin\PluginAutoloader')) {
        echo "<p style='color: green;'>FSFramework\Plugin\PluginAutoloader is available</p>";
    } else {
        echo "<p style='color: red;'>FSFramework\Plugin\PluginAutoloader is not available</p>";
    }
    
} catch (Exception $e) {
    echo "<h1>Error</h1>";
    echo "<p style='color: red;'>" . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
