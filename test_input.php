<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/base/fs_functions.php';

use FSFramework\Core\Kernel;
use Symfony\Component\HttpFoundation\Request;

// Simular entorno antes de arrancar el Kernel
$_GET['test_get'] = 'valor_get_legacy';
$_POST['test_post'] = 'valor_post_legacy';
$_REQUEST['test_req'] = 'valor_req_legacy';

// Arrancar Kernel
Kernel::boot();

echo "--- Probando integración de Request ---\n";

// Inyectar valores en el Request de Symfony (simulando lo que haría el navegador/web server)
// Nota: Kernel::boot() ya crea el request desde globals, pero aquí verificamos si podemos influir o leer.

$val_req = fs_filter_input_req('test_get');
echo "fs_filter_input_req('test_get'): " . ($val_req === 'valor_get_legacy' ? 'OK' : 'FAIL') . " -> $val_req\n";

$val_post = fs_filter_input_post('test_post');
echo "fs_filter_input_post('test_post'): " . ($val_post === 'valor_post_legacy' ? 'OK' : 'FAIL') . " -> $val_post\n";

// Ahora vamos a intentar modificar el Request de Symfony y ver si las funciones legacy lo leen
// Esto confirmará que estamos usando el objeto Request de Symfony y no $_POST directo
// (Despues de modificar fs_functions.php)

// Hack para modificar el request actual protegido en el Kernel (solo para test)
// En realidad, para testear esto correctamente despues del cambio, 
// necesitariamos una forma de "reemplazar" el request o inyectar valores.
// Como fs_functions leerá del Kernel, si modificamos el request del Kernel, fs_functions debería verlo.

echo "\n--- Prueba post-migración (esperada) ---\n";
// Estas lineas fallarán o no tendrán efecto hasta que modifiquemos fs_functions.php
// Vamos a usar esto para validar DESPUES de los cambios.
