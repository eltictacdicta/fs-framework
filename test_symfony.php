<?php
require_once __DIR__ . '/vendor/autoload.php';

use FSFramework\Core\Kernel;
use Symfony\Component\HttpFoundation\Request;

try {
    echo "Booting Kernel...\n";
    Kernel::boot();

    $request = Kernel::request();
    echo "Request captured successfully.\n";
    echo "Method: " . $request->getMethod() . "\n";
    echo "Class: " . get_class($request) . "\n";
    echo "Symfony HttpFoundation is active.\n";

    echo "✅ Verification successful!\n";
} catch (\Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
