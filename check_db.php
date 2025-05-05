<?php
// This script checks if there's an entry for admin_empresa in the fs_pages table
// and removes it if found

require_once 'base/fs_db2.php';
require_once 'base/fs_model.php';
require_once 'model/fs_page.php';

$db = new fs_db2();
if ($db->connect()) {
    $result = $db->select("SELECT * FROM fs_pages WHERE name = 'admin_empresa'");
    if ($result) {
        echo "Entry found in fs_pages table for admin_empresa<br>";
        print_r($result);

        // Remove the entry
        $page = new fs_page();
        $page = $page->get('admin_empresa');
        if ($page && $page->delete()) {
            echo "<br><br>Successfully removed admin_empresa from fs_pages table";
        } else {
            echo "<br><br>Failed to remove admin_empresa from fs_pages table";
        }
    } else {
        echo "No entry found in fs_pages table for admin_empresa";
    }
} else {
    echo "Could not connect to database";
}
