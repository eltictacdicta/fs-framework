<?php
define('FS_FOLDER', getcwd());
require_once 'vendor/autoload.php';

use Twig\Loader\FilesystemLoader;

echo "Checking view/admin_agentes.html...\n";
if (!file_exists(FS_FOLDER . '/view/admin_agentes.html')) {
    echo "File DOES NOT exist on disk.\n";
} else {
    echo "File exists on disk.\n";
}

$loader = new FilesystemLoader(FS_FOLDER . '/view');
echo "Loader paths: " . implode(', ', $loader->getPaths()) . "\n";

if ($loader->exists('admin_agentes.html')) {
    echo "Loader FOUND admin_agentes.html\n";
} else {
    echo "Loader FAILED to find admin_agentes.html\n";
}

// Check Legacy Loader logic simulation
$name = 'admin_agentes.html.twig';
$htmlName = substr($name, 0, -5);
if ($loader->exists($htmlName)) {
    echo "Fallback logic WOULD succeed: $htmlName found.\n";
} else {
    echo "Fallback logic WOULD FAIL.\n";
}
