<?php
// Display all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Clearing Cache</h1>";

// Function to recursively delete directory contents
function deleteDirectoryContents($dir) {
    if (!is_dir($dir)) {
        echo "<p>Directory does not exist: $dir</p>";
        return false;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        
        if (is_dir($path)) {
            deleteDirectoryContents($path);
            if (rmdir($path)) {
                echo "<p>Removed directory: $path</p>";
            } else {
                echo "<p style='color: red;'>Failed to remove directory: $path</p>";
            }
        } else {
            if (unlink($path)) {
                echo "<p>Removed file: $path</p>";
            } else {
                echo "<p style='color: red;'>Failed to remove file: $path</p>";
            }
        }
    }
    
    return true;
}

// Clear var/cache directory
echo "<h2>Clearing var/cache directory</h2>";
if (is_dir(__DIR__ . '/var/cache')) {
    if (deleteDirectoryContents(__DIR__ . '/var/cache')) {
        echo "<p style='color: green;'>Successfully cleared var/cache directory</p>";
    } else {
        echo "<p style='color: red;'>Failed to clear var/cache directory</p>";
    }
} else {
    echo "<p>var/cache directory does not exist</p>";
}

// Clear tmp directory if it exists
echo "<h2>Clearing tmp directory</h2>";
if (is_dir(__DIR__ . '/tmp')) {
    if (deleteDirectoryContents(__DIR__ . '/tmp')) {
        echo "<p style='color: green;'>Successfully cleared tmp directory</p>";
    } else {
        echo "<p style='color: red;'>Failed to clear tmp directory</p>";
    }
} else {
    echo "<p>tmp directory does not exist</p>";
}

// Create necessary directories if they don't exist
echo "<h2>Creating necessary directories</h2>";
$directories = [
    'var/cache',
    'var/log',
    'tmp'
];

foreach ($directories as $dir) {
    $fullPath = __DIR__ . '/' . $dir;
    if (!is_dir($fullPath)) {
        if (mkdir($fullPath, 0777, true)) {
            echo "<p style='color: green;'>Created directory: $dir</p>";
        } else {
            echo "<p style='color: red;'>Failed to create directory: $dir</p>";
        }
    } else {
        echo "<p>Directory already exists: $dir</p>";
    }
}

echo "<p>Cache clearing complete.</p>";
?>
