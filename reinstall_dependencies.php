<?php
// Display all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Reinstalling Dependencies</h1>";

// Function to recursively delete a directory
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

// Check if PHP is available
echo "<h2>Checking PHP</h2>";
$phpPath = null;
$possiblePaths = ['php', 'C:\php\php.exe', 'C:\laragon\bin\php\php-8.1\php.exe'];

foreach ($possiblePaths as $path) {
    $output = [];
    $returnVar = 0;
    exec("$path -v 2>&1", $output, $returnVar);
    
    if ($returnVar === 0 && !empty($output) && strpos($output[0], 'PHP') !== false) {
        $phpPath = $path;
        echo "<p style='color: green;'>PHP found at: $phpPath</p>";
        echo "<p>Version: " . $output[0] . "</p>";
        break;
    }
}

if ($phpPath === null) {
    echo "<p style='color: red;'>PHP not found in PATH. Please install PHP or add it to your PATH.</p>";
    die();
}

// Check if Composer is available
echo "<h2>Checking Composer</h2>";
$composerPath = null;
$possibleComposerPaths = ['composer', 'composer.phar', 'C:\laragon\bin\composer\composer.bat'];

foreach ($possibleComposerPaths as $path) {
    $output = [];
    $returnVar = 0;
    exec("$path --version 2>&1", $output, $returnVar);
    
    if ($returnVar === 0 && !empty($output) && strpos($output[0], 'Composer') !== false) {
        $composerPath = $path;
        echo "<p style='color: green;'>Composer found at: $composerPath</p>";
        echo "<p>Version: " . $output[0] . "</p>";
        break;
    }
}

if ($composerPath === null) {
    echo "<p style='color: red;'>Composer not found. Downloading Composer installer...</p>";
    
    // Download Composer installer
    $installerUrl = 'https://getcomposer.org/installer';
    $installerPath = __DIR__ . '/composer-setup.php';
    
    if (file_put_contents($installerPath, file_get_contents($installerUrl))) {
        echo "<p style='color: green;'>Composer installer downloaded successfully.</p>";
        
        // Run the installer
        $output = [];
        $returnVar = 0;
        exec("$phpPath $installerPath 2>&1", $output, $returnVar);
        
        if ($returnVar === 0 && file_exists(__DIR__ . '/composer.phar')) {
            echo "<p style='color: green;'>Composer installed successfully.</p>";
            $composerPath = "$phpPath " . __DIR__ . '/composer.phar';
        } else {
            echo "<p style='color: red;'>Failed to install Composer:</p>";
            echo "<pre>" . implode("\n", $output) . "</pre>";
            die();
        }
        
        // Clean up
        unlink($installerPath);
    } else {
        echo "<p style='color: red;'>Failed to download Composer installer.</p>";
        die();
    }
}

// Check if we need to delete the vendor directory
if (isset($_GET['delete_vendor']) && $_GET['delete_vendor'] === '1') {
    echo "<h2>Deleting Vendor Directory</h2>";
    
    if (is_dir(__DIR__ . '/vendor')) {
        echo "<p>Deleting vendor directory...</p>";
        deleteDirectory(__DIR__ . '/vendor');
        echo "<p style='color: green;'>Vendor directory deleted successfully.</p>";
    } else {
        echo "<p>Vendor directory does not exist.</p>";
    }
}

// Install dependencies
echo "<h2>Installing Dependencies</h2>";

if ($composerPath) {
    echo "<p>Running composer install...</p>";
    
    $output = [];
    $returnVar = 0;
    $command = "$composerPath install --no-interaction 2>&1";
    
    echo "<p>Command: $command</p>";
    exec($command, $output, $returnVar);
    
    if ($returnVar === 0) {
        echo "<p style='color: green;'>Dependencies installed successfully.</p>";
    } else {
        echo "<p style='color: red;'>Failed to install dependencies:</p>";
        echo "<pre>" . implode("\n", $output) . "</pre>";
    }
} else {
    echo "<p style='color: red;'>Composer not available. Cannot install dependencies.</p>";
}

echo "<h2>Next Steps</h2>";
echo "<p>If you want to delete the vendor directory and reinstall all dependencies, <a href='?delete_vendor=1'>click here</a>.</p>";
echo "<p>After reinstalling dependencies, <a href='autoload_test.php'>test the autoloader</a>.</p>";
?>
