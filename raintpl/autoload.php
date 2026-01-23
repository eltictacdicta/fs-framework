<?php
/**
 * Autoloader para RainTPL 4.0 adaptado a FSFramework.
 * Carga las clases del namespace Rain\Tpl desde la carpeta raintpl/Rain/
 */

if (!defined("RAINTPL_DIR")) {
    define("RAINTPL_DIR", __DIR__);
}

spl_autoload_register(function ($class) {
    // Solo autoload clases del namespace Rain
    if (strpos($class, 'Rain\\') !== 0) {
        return;
    }

    // Convertir namespace a path
    $path = str_replace("\\", DIRECTORY_SEPARATOR, $class);
    $filepath = RAINTPL_DIR . DIRECTORY_SEPARATOR . $path . ".php";

    if (file_exists($filepath)) {
        require_once $filepath;
    }
});
