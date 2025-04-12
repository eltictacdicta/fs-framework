<?php
// Display all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Manual Autoload Test</h1>";

// Define the base path to the vendor directory
$vendorDir = __DIR__ . '/vendor';

// Function to recursively scan a directory for PHP files
function scanDirectory($dir, &$files) {
    if (!is_dir($dir)) {
        return;
    }
    
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            scanDirectory($path, $files);
        } elseif (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            $files[] = $path;
        }
    }
}

// Manually include the Composer autoloader
if (file_exists($vendorDir . '/autoload.php')) {
    echo "<p>Including vendor/autoload.php...</p>";
    require_once $vendorDir . '/autoload.php';
    echo "<p style='color: green;'>Autoloader included successfully.</p>";
} else {
    echo "<p style='color: red;'>vendor/autoload.php does not exist.</p>";
}

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
        
        // Try to manually include the file
        $classPath = str_replace('\\', DIRECTORY_SEPARATOR, $component) . '.php';
        $fullPath = $vendorDir . DIRECTORY_SEPARATOR . $classPath;
        
        if (file_exists($fullPath)) {
            echo "<p>Trying to include $fullPath...</p>";
            require_once $fullPath;
            
            if (class_exists($component)) {
                echo "<p style='color: green;'>Successfully included $component manually.</p>";
            } else {
                echo "<p style='color: red;'>Failed to include $component manually.</p>";
            }
        } else {
            echo "<p>File not found: $fullPath</p>";
            
            // Try to find the file in the vendor directory
            $files = [];
            scanDirectory($vendorDir, $files);
            
            $className = substr($component, strrpos($component, '\\') + 1);
            $matchingFiles = [];
            
            foreach ($files as $file) {
                if (basename($file) === $className . '.php') {
                    $matchingFiles[] = $file;
                }
            }
            
            if (!empty($matchingFiles)) {
                echo "<p>Found potential matches:</p>";
                echo "<ul>";
                foreach ($matchingFiles as $file) {
                    echo "<li>$file</li>";
                }
                echo "</ul>";
            } else {
                echo "<p>No matching files found for $className.</p>";
            }
        }
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
        
        // Try to find the file in the src directory
        $classPath = str_replace('FSFramework\\', '', $class);
        $classPath = str_replace('\\', DIRECTORY_SEPARATOR, $classPath) . '.php';
        $fullPath = __DIR__ . '/src/' . $classPath;
        
        if (file_exists($fullPath)) {
            echo "<p>Found file at $fullPath</p>";
            
            // Display the file content
            echo "<pre>" . htmlspecialchars(file_get_contents($fullPath)) . "</pre>";
        } else {
            echo "<p>File not found: $fullPath</p>";
        }
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
?>
