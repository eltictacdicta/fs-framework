<?php
// Display all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Database Connection Test</h1>";

// Load configuration
if (file_exists(__DIR__ . '/config.php')) {
    include_once __DIR__ . '/config.php';
    
    echo "<h2>Database Configuration</h2>";
    echo "<ul>";
    echo "<li>DB Type: " . (defined('FS_DB_TYPE') ? FS_DB_TYPE : 'Not defined') . "</li>";
    echo "<li>DB Host: " . (defined('FS_DB_HOST') ? FS_DB_HOST : 'Not defined') . "</li>";
    echo "<li>DB Port: " . (defined('FS_DB_PORT') ? FS_DB_PORT : 'Not defined') . "</li>";
    echo "<li>DB Name: " . (defined('FS_DB_NAME') ? FS_DB_NAME : 'Not defined') . "</li>";
    echo "<li>DB User: " . (defined('FS_DB_USER') ? FS_DB_USER : 'Not defined') . "</li>";
    echo "<li>DB Pass: " . (defined('FS_DB_PASS') ? (FS_DB_PASS ? 'Set' : 'Empty') : 'Not defined') . "</li>";
    echo "</ul>";
    
    // Test database connection
    echo "<h2>Connection Test</h2>";
    try {
        if (defined('FS_DB_TYPE') && defined('FS_DB_HOST') && defined('FS_DB_NAME') && defined('FS_DB_USER')) {
            $dsn = strtolower(FS_DB_TYPE) === 'mysql' 
                ? "mysql:host=" . FS_DB_HOST . ";port=" . FS_DB_PORT . ";dbname=" . FS_DB_NAME 
                : "pgsql:host=" . FS_DB_HOST . ";port=" . FS_DB_PORT . ";dbname=" . FS_DB_NAME;
            
            $pdo = new PDO($dsn, FS_DB_USER, FS_DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            echo "<p style='color: green;'>Database connection successful!</p>";
            
            // Check if tables exist
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            echo "<p>Found " . count($tables) . " tables in the database:</p>";
            echo "<ul>";
            foreach ($tables as $table) {
                echo "<li>$table</li>";
            }
            echo "</ul>";
        } else {
            echo "<p style='color: red;'>Missing database configuration parameters.</p>";
        }
    } catch (PDOException $e) {
        echo "<p style='color: red;'>Database connection failed: " . $e->getMessage() . "</p>";
        
        // Check if database exists
        try {
            if (defined('FS_DB_TYPE') && defined('FS_DB_HOST') && defined('FS_DB_USER')) {
                $dsn = strtolower(FS_DB_TYPE) === 'mysql' 
                    ? "mysql:host=" . FS_DB_HOST . ";port=" . FS_DB_PORT 
                    : "pgsql:host=" . FS_DB_HOST . ";port=" . FS_DB_PORT;
                
                $pdo = new PDO($dsn, FS_DB_USER, FS_DB_PASS);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Check if database exists
                $stmt = $pdo->query("SHOW DATABASES LIKE '" . FS_DB_NAME . "'");
                $result = $stmt->fetchAll();
                
                if (count($result) > 0) {
                    echo "<p>Database '" . FS_DB_NAME . "' exists but connection failed. Check permissions.</p>";
                } else {
                    echo "<p>Database '" . FS_DB_NAME . "' does not exist. You may need to create it.</p>";
                    
                    // Offer to create the database
                    echo "<form method='post'>";
                    echo "<input type='hidden' name='create_db' value='1'>";
                    echo "<button type='submit'>Create Database</button>";
                    echo "</form>";
                }
            }
        } catch (PDOException $e2) {
            echo "<p style='color: red;'>Could not connect to database server: " . $e2->getMessage() . "</p>";
        }
    }
    
    // Handle database creation if requested
    if (isset($_POST['create_db']) && $_POST['create_db'] == 1) {
        try {
            if (defined('FS_DB_TYPE') && defined('FS_DB_HOST') && defined('FS_DB_USER')) {
                $dsn = strtolower(FS_DB_TYPE) === 'mysql' 
                    ? "mysql:host=" . FS_DB_HOST . ";port=" . FS_DB_PORT 
                    : "pgsql:host=" . FS_DB_HOST . ";port=" . FS_DB_PORT;
                
                $pdo = new PDO($dsn, FS_DB_USER, FS_DB_PASS);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Create database
                $pdo->exec("CREATE DATABASE `" . FS_DB_NAME . "`");
                echo "<p style='color: green;'>Database '" . FS_DB_NAME . "' created successfully!</p>";
                echo "<p>Please refresh this page to test the connection again.</p>";
            }
        } catch (PDOException $e) {
            echo "<p style='color: red;'>Failed to create database: " . $e->getMessage() . "</p>";
        }
    }
} else {
    echo "<p style='color: red;'>config.php does not exist</p>";
}
?>
