<?php
// Display all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Create a custom error handler
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    echo "<div style='background-color: #ffdddd; padding: 10px; margin: 5px; border: 1px solid #ff0000;'>";
    echo "<h3>Error Detected:</h3>";
    echo "<p><strong>Error Type:</strong> $errno</p>";
    echo "<p><strong>Error Message:</strong> $errstr</p>";
    echo "<p><strong>File:</strong> $errfile</p>";
    echo "<p><strong>Line:</strong> $errline</p>";
    echo "</div>";
    
    // Log to file
    error_log("Error [$errno]: $errstr in $errfile on line $errline", 3, __DIR__ . '/error.log');
    
    // Don't execute PHP's internal error handler
    return true;
}

// Set the custom error handler
set_error_handler("customErrorHandler");

// Set exception handler
function customExceptionHandler($exception) {
    echo "<div style='background-color: #ffdddd; padding: 10px; margin: 5px; border: 1px solid #ff0000;'>";
    echo "<h3>Exception Caught:</h3>";
    echo "<p><strong>Message:</strong> " . $exception->getMessage() . "</p>";
    echo "<p><strong>File:</strong> " . $exception->getFile() . "</p>";
    echo "<p><strong>Line:</strong> " . $exception->getLine() . "</p>";
    echo "<p><strong>Trace:</strong> <pre>" . $exception->getTraceAsString() . "</pre></p>";
    echo "</div>";
    
    // Log to file
    error_log("Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine(), 3, __DIR__ . '/error.log');
}

// Set the custom exception handler
set_exception_handler("customExceptionHandler");

echo "<h1>FS-Framework Debug Information</h1>";

// Check PHP version
echo "<h2>PHP Version</h2>";
echo "<p>Current PHP version: " . phpversion() . "</p>";
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    echo "<p style='color: red;'>Warning: FS-Framework requires PHP 8.1 or higher.</p>";
}

// Check required extensions
echo "<h2>PHP Extensions</h2>";
$required_extensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'xml', 'zip'];
echo "<ul>";
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "<li style='color: green;'>$ext: Loaded</li>";
    } else {
        echo "<li style='color: red;'>$ext: Not loaded</li>";
    }
}
echo "</ul>";

// Check config.php
echo "<h2>Configuration Files</h2>";
if (file_exists(__DIR__ . '/config.php')) {
    echo "<p style='color: green;'>config.php exists</p>";
    include_once __DIR__ . '/config.php';
    
    // Check database configuration
    echo "<h3>Database Configuration</h3>";
    echo "<ul>";
    echo "<li>DB Type: " . (defined('FS_DB_TYPE') ? FS_DB_TYPE : 'Not defined') . "</li>";
    echo "<li>DB Host: " . (defined('FS_DB_HOST') ? FS_DB_HOST : 'Not defined') . "</li>";
    echo "<li>DB Port: " . (defined('FS_DB_PORT') ? FS_DB_PORT : 'Not defined') . "</li>";
    echo "<li>DB Name: " . (defined('FS_DB_NAME') ? FS_DB_NAME : 'Not defined') . "</li>";
    echo "<li>DB User: " . (defined('FS_DB_USER') ? FS_DB_USER : 'Not defined') . "</li>";
    echo "<li>DB Pass: " . (defined('FS_DB_PASS') ? (FS_DB_PASS ? 'Set' : 'Empty') : 'Not defined') . "</li>";
    echo "</ul>";
    
    // Test database connection
    echo "<h3>Database Connection Test</h3>";
    try {
        if (defined('FS_DB_TYPE') && defined('FS_DB_HOST') && defined('FS_DB_NAME') && defined('FS_DB_USER')) {
            $dsn = strtolower(FS_DB_TYPE) === 'mysql' 
                ? "mysql:host=" . FS_DB_HOST . ";port=" . FS_DB_PORT . ";dbname=" . FS_DB_NAME 
                : "pgsql:host=" . FS_DB_HOST . ";port=" . FS_DB_PORT . ";dbname=" . FS_DB_NAME;
            
            $pdo = new PDO($dsn, FS_DB_USER, FS_DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            echo "<p style='color: green;'>Database connection successful!</p>";
        } else {
            echo "<p style='color: red;'>Missing database configuration parameters.</p>";
        }
    } catch (PDOException $e) {
        echo "<p style='color: red;'>Database connection failed: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'>config.php does not exist</p>";
}

// Check vendor directory
echo "<h2>Vendor Directory</h2>";
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "<p style='color: green;'>vendor/autoload.php exists</p>";
} else {
    echo "<p style='color: red;'>vendor/autoload.php does not exist. Did you run composer install?</p>";
}

// Check for Symfony components
echo "<h2>Symfony Components</h2>";
$symfony_classes = [
    'Symfony\Component\HttpFoundation\Request',
    'Symfony\Component\ErrorHandler\Debug',
    'Symfony\Component\HttpKernel\Kernel'
];
echo "<ul>";
foreach ($symfony_classes as $class) {
    if (class_exists($class)) {
        echo "<li style='color: green;'>$class: Available</li>";
    } else {
        echo "<li style='color: red;'>$class: Not available</li>";
    }
}
echo "</ul>";

// Check for PluginAutoloader
echo "<h2>Plugin System</h2>";
if (file_exists(__DIR__ . '/src/Plugin/PluginAutoloader.php')) {
    echo "<p style='color: green;'>PluginAutoloader.php exists</p>";
    
    // Check plugins directory
    if (is_dir(__DIR__ . '/plugins')) {
        echo "<p style='color: green;'>plugins directory exists</p>";
        
        // List plugins
        $plugins = scandir(__DIR__ . '/plugins');
        $plugins = array_diff($plugins, ['.', '..']);
        echo "<p>Found " . count($plugins) . " plugins:</p>";
        echo "<ul>";
        foreach ($plugins as $plugin) {
            if (is_dir(__DIR__ . '/plugins/' . $plugin)) {
                echo "<li>$plugin</li>";
            }
        }
        echo "</ul>";
    } else {
        echo "<p style='color: red;'>plugins directory does not exist</p>";
    }
} else {
    echo "<p style='color: red;'>PluginAutoloader.php does not exist</p>";
}

// Check directory permissions
echo "<h2>Directory Permissions</h2>";
$directories = [
    'var/cache',
    'var/log',
    'plugins',
    'tmp'
];
echo "<ul>";
foreach ($directories as $dir) {
    $fullPath = __DIR__ . '/' . $dir;
    if (is_dir($fullPath)) {
        $writable = is_writable($fullPath) ? 'Writable' : 'Not writable';
        $color = is_writable($fullPath) ? 'green' : 'red';
        echo "<li style='color: $color;'>$dir: $writable</li>";
    } else {
        echo "<li style='color: red;'>$dir: Directory does not exist</li>";
    }
}
echo "</ul>";

// Check environment variables
echo "<h2>Environment Variables</h2>";
echo "<ul>";
echo "<li>APP_ENV: " . (getenv('APP_ENV') ?: 'Not set') . "</li>";
echo "<li>APP_DEBUG: " . (getenv('APP_DEBUG') ?: 'Not set') . "</li>";
echo "</ul>";

// Check for .env file
if (file_exists(__DIR__ . '/.env')) {
    echo "<p style='color: green;'>.env file exists</p>";
} else {
    echo "<p style='color: orange;'>.env file does not exist (may not be required)</p>";
}

// Try to include the main application file without executing it
echo "<h2>Application Bootstrap Analysis</h2>";
try {
    // Define a function to capture the included file content without executing it
    function includeFileContent($file) {
        $content = file_get_contents($file);
        return $content;
    }
    
    $indexContent = includeFileContent(__DIR__ . '/index.php');
    echo "<p>Successfully read index.php</p>";
    
    // Check for critical components in index.php
    $criticalComponents = [
        'use FSFramework\Kernel' => strpos($indexContent, 'use FSFramework\Kernel') !== false,
        'use FSFramework\Plugin\PluginAutoloader' => strpos($indexContent, 'use FSFramework\Plugin\PluginAutoloader') !== false,
        'use Symfony\Component\ErrorHandler\Debug' => strpos($indexContent, 'use Symfony\Component\ErrorHandler\Debug') !== false,
        'use Symfony\Component\HttpFoundation\Request' => strpos($indexContent, 'use Symfony\Component\HttpFoundation\Request') !== false,
        'require_once \'config.php\'' => strpos($indexContent, 'require_once \'config.php\'') !== false,
        'require_once \'vendor/autoload.php\'' => strpos($indexContent, 'require_once \'vendor/autoload.php\'') !== false,
        'PluginAutoloader::register()' => strpos($indexContent, 'PluginAutoloader::register()') !== false,
        'define(\'FS_FOLDER\', __DIR__)' => strpos($indexContent, 'define(\'FS_FOLDER\', __DIR__)') !== false,
        '$kernel = new Kernel' => strpos($indexContent, '$kernel = new Kernel') !== false,
        '$request = Request::createFromGlobals()' => strpos($indexContent, '$request = Request::createFromGlobals()') !== false,
        '$response = $kernel->handle($request)' => strpos($indexContent, '$response = $kernel->handle($request)') !== false
    ];
    
    echo "<p>Critical components check:</p>";
    echo "<ul>";
    foreach ($criticalComponents as $component => $exists) {
        $color = $exists ? 'green' : 'red';
        $status = $exists ? 'Found' : 'Not found';
        echo "<li style='color: $color;'>$component: $status</li>";
    }
    echo "</ul>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error analyzing index.php: " . $e->getMessage() . "</p>";
}

// Create a .env file if it doesn't exist
if (!file_exists(__DIR__ . '/.env')) {
    echo "<h2>Creating .env file</h2>";
    try {
        $envContent = "APP_ENV=dev\nAPP_DEBUG=1\nAPP_SECRET=" . bin2hex(random_bytes(16)) . "\n";
        file_put_contents(__DIR__ . '/.env', $envContent);
        echo "<p style='color: green;'>Created .env file with development settings</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>Failed to create .env file: " . $e->getMessage() . "</p>";
    }
}

// Check for Kernel.php
echo "<h2>Kernel Class</h2>";
if (file_exists(__DIR__ . '/src/Kernel.php')) {
    echo "<p style='color: green;'>src/Kernel.php exists</p>";
} else {
    echo "<p style='color: red;'>src/Kernel.php does not exist</p>";
}

echo "<p>Debug information complete. Check above for any errors or warnings.</p>";
?>
