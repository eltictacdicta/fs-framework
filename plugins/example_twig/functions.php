<?php
/**
 * Funciones del plugin example_twig
 */

// Asegurarnos de que la clase BaseController esté cargada
if (!class_exists('FSFramework\Controller\BaseController')) {
    require_once __DIR__ . '/../../src/Controller/BaseController.php';
}

// Definimos el controlador en un archivo separado
require_once __DIR__ . '/Controller/ExampleController.php';

// Función para registrar el controlador en el sistema
function register_example_twig_controller() {
    // Esta función se llama automáticamente cuando se activa el plugin
    // No necesita hacer nada, ya que el controlador se registra automáticamente
    // a través del namespace
}
