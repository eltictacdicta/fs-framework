<?php
// Display all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define FS_FOLDER if not defined
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', __DIR__);
}

// Define FS_TMP_NAME if not defined
if (!defined('FS_TMP_NAME')) {
    define('FS_TMP_NAME', 'KnXu6L0bsQCJ1D2SmAUe/');
}

// Create tmp directory if it doesn't exist
if (!is_dir(__DIR__ . '/tmp')) {
    mkdir(__DIR__ . '/tmp');
    chmod(__DIR__ . '/tmp', 0777);
}

// Create tmp/FS_TMP_NAME directory if it doesn't exist
if (!is_dir(__DIR__ . '/tmp/' . FS_TMP_NAME)) {
    mkdir(__DIR__ . '/tmp/' . FS_TMP_NAME);
    chmod(__DIR__ . '/tmp/' . FS_TMP_NAME, 0777);
}

// Initialize the global plugins array
$GLOBALS['plugins'] = [];

// Check if the enabled_plugins.list file exists
$enabledPluginsFile = __DIR__ . '/tmp/' . FS_TMP_NAME . 'enabled_plugins.list';
if (file_exists($enabledPluginsFile)) {
    $enabledPlugins = file_get_contents($enabledPluginsFile);
    $plugins = explode(',', $enabledPlugins);
    
    foreach ($plugins as $plugin) {
        $plugin = trim($plugin);
        if (!empty($plugin) && is_dir(__DIR__ . '/plugins/' . $plugin)) {
            $GLOBALS['plugins'][] = $plugin;
        }
    }
}

// If no plugins are enabled, enable the example_twig plugin
if (empty($GLOBALS['plugins']) && is_dir(__DIR__ . '/plugins/example_twig')) {
    $GLOBALS['plugins'][] = 'example_twig';
    
    // Save the enabled plugins list
    file_put_contents($enabledPluginsFile, 'example_twig');
    
    echo "<p>Enabled the example_twig plugin</p>";
}

// Display the enabled plugins
echo "<h1>Enabled Plugins</h1>";
echo "<ul>";
foreach ($GLOBALS['plugins'] as $plugin) {
    echo "<li>$plugin</li>";
}
echo "</ul>";

// Load the plugin functions
foreach ($GLOBALS['plugins'] as $plugin) {
    $functionsFile = __DIR__ . '/plugins/' . $plugin . '/functions.php';
    if (file_exists($functionsFile)) {
        require_once $functionsFile;
        echo "<p>Loaded functions for plugin: $plugin</p>";
    }
}

// Redirect to index.php
echo "<p>Redirecting to index.php in 3 seconds...</p>";
echo "<script>setTimeout(function() { window.location.href = 'index.php'; }, 3000);</script>";
?>
