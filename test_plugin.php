<?php
// This script tests the activation and deactivation of the business_data plugin

require_once 'base/fs_db2.php';
require_once 'base/fs_model.php';
require_once 'model/fs_page.php';
require_once 'plugins/business_data/functions.php';

echo "<h1>Testing Business Data Plugin</h1>";

// Check if admin_empresa exists in fs_pages
$db = new fs_db2();
if ($db->connect()) {
    $result = $db->select("SELECT * FROM fs_pages WHERE name = 'admin_empresa'");
    if ($result) {
        echo "<p>Entry found in fs_pages table for admin_empresa</p>";
    } else {
        echo "<p>No entry found in fs_pages table for admin_empresa</p>";
    }
} else {
    echo "<p>Could not connect to database</p>";
}

// Test activation
echo "<h2>Testing Plugin Activation</h2>";
enable_business_data();
echo "<p>Plugin activation function called</p>";

// Check if admin_empresa exists in fs_pages after activation
if ($db->connect()) {
    $result = $db->select("SELECT * FROM fs_pages WHERE name = 'admin_empresa'");
    if ($result) {
        echo "<p>Entry found in fs_pages table for admin_empresa after activation</p>";
    } else {
        echo "<p>No entry found in fs_pages table for admin_empresa after activation</p>";
    }
} else {
    echo "<p>Could not connect to database</p>";
}

// Test deactivation
echo "<h2>Testing Plugin Deactivation</h2>";
disable_business_data();
echo "<p>Plugin deactivation function called</p>";

// Check if admin_empresa exists in fs_pages after deactivation
if ($db->connect()) {
    $result = $db->select("SELECT * FROM fs_pages WHERE name = 'admin_empresa'");
    if ($result) {
        echo "<p>Entry found in fs_pages table for admin_empresa after deactivation</p>";
    } else {
        echo "<p>No entry found in fs_pages table for admin_empresa after deactivation</p>";
    }
} else {
    echo "<p>Could not connect to database</p>";
}
