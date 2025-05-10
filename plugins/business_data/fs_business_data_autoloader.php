<?php
/**
 * Business Data Plugin autoloader
 * 
 * This file provides an autoloader for business_data plugin controllers
 */

// Controller autoloading function
function fs_business_data_autoload($class_name) {
    // Check if the class is a controller from this plugin
    if ($class_name === 'AdminEmpresaController' || $class_name === 'BusinessDataController') {
        $file = __DIR__ . '/Controller/' . $class_name . '.php';
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    
    return false;
}

// Register the autoloader
spl_autoload_register('fs_business_data_autoload'); 