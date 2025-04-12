<?php
// Display all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define FS_FOLDER if not defined
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', __DIR__);
}

// Load configuration
require_once 'config.php';

// Load autoloader
require_once 'vendor/autoload.php';

// Load the BaseController
require_once 'src/Controller/BaseController.php';

// Load the ExampleController
require_once 'plugins/example_twig/Controller/ExampleController.php';

// Create an instance of the controller
$controller = new \FSFramework\Plugin\ExampleTwig\Controller\ExampleController();

// Call the index method
$response = $controller->index();

// Send the response
$response->send();
?>
