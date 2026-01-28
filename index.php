<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com> (lead developer of Facturascript)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
if ((float) substr(phpversion(), 0, 3) < 5.6) {
    /// comprobamos la versión de PHP
    die('FacturaScripts necesita PHP 5.6 o superior, y usted tiene PHP ' . phpversion());
}

if (!file_exists('config.php')) {
    /// si no hay config.php redirigimos al instalador
    header('Location: install.php');
    die('Redireccionando al instalador...');
}

define('FS_FOLDER', __DIR__);

/// Carga de dependencias
require_once __DIR__ . '/vendor/autoload.php';

/// cargamos las constantes de configuración (DEBE ser antes de Kernel::boot para que $GLOBALS['plugins'] esté disponible)
require_once 'config.php';
require_once 'base/config2.php';

/// Boot del Kernel moderno (inicializa plugins de FS2025)
\FSFramework\Core\Kernel::boot();

/// --- Symfony Routing Bridge ---
try {
    $response = \FSFramework\Core\Kernel::handleRequest();
    if ($response) {
        $response->send();
        exit;
    }
} catch (\Throwable $e) {
    error_log("Global Symfony Router Bridge Error: " . $e->getMessage());
}
// ------------------------------


/// ampliamos el límite de ejecución de PHP a 5 minutos
@set_time_limit(300);


/// Definir URL base del sistema si no está definida
if (!defined('FS_BASE_URL')) {
    $protocol = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $protocol = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        $protocol = 'https';
    } elseif (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
        $protocol = 'https';
    }

    $host = 'localhost';
    if (!empty($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
    } elseif (!empty($_SERVER['SERVER_NAME'])) {
        $host = $_SERVER['SERVER_NAME'];
    }

    $base_path = (defined('FS_PATH') && FS_PATH !== '') ? FS_PATH : '';
    if (empty($base_path) && !empty($_SERVER['SCRIPT_NAME'])) {
        $script_dir = dirname($_SERVER['SCRIPT_NAME']);
        if ($script_dir !== '/' && $script_dir !== '\\') {
            $base_path = $script_dir;
        }
    }

    define('FS_BASE_URL', $protocol . '://' . $host . $base_path);
}
require_once 'base/fs_controller.php';
require_once 'base/fs_edit_controller.php';
require_once 'base/fs_list_controller.php';
require_once 'base/fs_log_manager.php';


/**
 * Registramos la función para capturar los fatal error.
 * Información importante a la hora de depurar errores.
 */
register_shutdown_function("fatal_handler");

/// ¿Qué controlador usar?
$pagename = '';
if (filter_input(INPUT_GET, 'page')) {
    $pagename = filter_input(INPUT_GET, 'page');
} elseif (defined('FS_HOMEPAGE')) {
    $pagename = FS_HOMEPAGE;
}

// Support for Modern Controllers (Bridge) - High Priority to avoid legacy 404 headers
// FS2025 plugins use Controller/ folder with PascalCase class names
if (!empty($pagename)) {
    if (isset($GLOBALS['plugins'])) {
        foreach ($GLOBALS['plugins'] as $plugin) {
            $modernDir = __DIR__ . '/plugins/' . $plugin . '/Controller';
            if (is_dir($modernDir)) {
                foreach (scandir($modernDir) as $file) {
                    if (substr($file, -4) === '.php') {
                        $className = substr($file, 0, -4);
                        $fullClass = "FacturaScripts\\Plugins\\$plugin\\Controller\\$className";
                        
                        // Skip if class doesn't exist (autoloader didn't find it)
                        if (!class_exists($fullClass)) {
                            continue;
                        }
                        
                        // Check if this controller matches the requested page
                        // FS2025 controllers can define 'name' in getPageData() or use class name
                        $cName = $className;
                        
                        // Use reflection to check getPageData without instantiating
                        $reflection = new \ReflectionClass($fullClass);
                        if ($reflection->hasMethod('getPageData')) {
                            // Instantiate to get page data
                            try {
                                // Create without running constructor logic by using newInstanceWithoutConstructor
                                // then manually call getPageData
                                $tempInstance = $reflection->newInstanceWithoutConstructor();
                                if (method_exists($tempInstance, 'getPageData')) {
                                    $pd = $tempInstance->getPageData();
                                    if (isset($pd['name']) && !empty($pd['name'])) {
                                        $cName = $pd['name'];
                                    }
                                }
                            } catch (\Throwable $e) {
                                // If reflection fails, just use class name
                            }
                        }

                        if ($cName === $pagename) {
                            // FOUND IT! Create proper instance and run
                            try {
                                $controller = new $fullClass();
                                
                                if (method_exists($controller, 'handle')) {
                                    $resp = $controller->handle(\FSFramework\Core\Kernel::request());
                                    if ($resp) {
                                        $resp->send();
                                    }
                                    exit;
                                } elseif (method_exists($controller, 'run')) {
                                    $controller->run();
                                    exit;
                                }
                            } catch (\Throwable $e) {
                                error_log("Error loading modern controller $fullClass: " . $e->getMessage());
                                // Continue to try legacy controller
                            }
                        }
                    }
                }
            }
        }
    }
}

$fsc_error = FALSE;
if ($pagename == '') {
    $fsc = new fs_controller();
} else {
    // First check if it's a FS2025 modern controller
    $modernController = find_modern_controller($pagename);
    
    if ($modernController) {
        // FS2025 controller - use full namespace class
        try {
            $fsc = new $modernController['class']();
            if (method_exists($fsc, 'run')) {
                $fsc->run();
                exit;
            }
        } catch (\Throwable $exc) {
            echo "<h1>Error fatal (FS2025)</h1>"
                . "<ul>"
                . "<li><b>Clase:</b> " . $modernController['class'] . "</li>"
                . "<li><b>Código:</b> " . $exc->getCode() . "</li>"
                . "<li><b>Mensaje:</b> " . $exc->getMessage() . "</li>"
                . "<li><b>Archivo:</b> " . $exc->getFile() . ":" . $exc->getLine() . "</li>"
                . "</ul>";
            $fsc_error = TRUE;
        }
    } else {
        // Legacy controller handling
        $class_path = find_controller($pagename);
        require_once $class_path;

        try {
            /// ¿No se ha encontrado el controlador?
            if ('base/fs_controller.php' === $class_path) {
                header("HTTP/1.0 404 Not Found");
                $fsc = new fs_controller();
            } else {
                $fsc = new $pagename();
            }
        } catch (Exception $exc) {
            echo "<h1>Error fatal</h1>"
                . "<ul>"
                . "<li><b>Código:</b> " . $exc->getCode() . "</li>"
                . "<li><b>Mensaje:</b> " . $exc->getMessage() . "</li>"
                . "</ul>";
            $fsc_error = TRUE;
        }
    }
}

/// guardamos los errores en el log
$log_manager = new fs_log_manager();
$log_manager->save();

/// redireccionamos a la página definida por el usuario
if (is_null(filter_input(INPUT_GET, 'page'))) {
    $fsc->select_default_page();
}

if ($fsc_error) {
    die();
}


if ($fsc->template) {
    $renderParams = [
        'fsc' => $fsc,
        'nlogin' => filter_input(INPUT_POST, 'user') ?? filter_input(INPUT_COOKIE, 'user') ?? ''
    ];
    
    // Use AJAX render mode for AJAX requests (returns content only, no header/footer)
    if ($fsc->isAjax()) {
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        echo \FacturaScripts\Core\Html::renderAjax($fsc->template, $renderParams);
    } else {
        echo \FacturaScripts\Core\Html::render($fsc->template, $renderParams);
    }
}

/// guardamos los errores en el log (los producidos durante la carga del template)
$log_manager->save();

/// cerramos las conexiones
$fsc->close();
