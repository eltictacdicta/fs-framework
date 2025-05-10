<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2013-2018 Carlos Garcia Gomez <neorazorx@gmail.com>
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

/**
 * Redondeo bancario
 * @staticvar real $dFuzz
 * @param float $dVal
 * @param integer $iDec
 * @return float
 */
if (!function_exists('bround')) {
    function bround($dVal, $iDec = 2)
    {
        // banker's style rounding or round-half-even
        // (round down when even number is left of 5, otherwise round up)
        // $dVal is value to round
        // $iDec specifies number of decimal places to retain
        static $dFuzz = 0.00001; // to deal with floating-point precision loss

        $iSign = ($dVal != 0.0) ? intval($dVal / abs($dVal)) : 1;
        $dVal = abs($dVal);

        // get decimal digit in question and amount to right of it as a fraction
        $dWorking = $dVal * pow(10.0, $iDec + 1) - floor($dVal * pow(10.0, $iDec)) * 10.0;
        $iEvenOddDigit = floor($dVal * pow(10.0, $iDec)) - floor($dVal * pow(10.0, $iDec - 1)) * 10.0;

        if (abs($dWorking - 5.0) < $dFuzz) {
            $iRoundup = ($iEvenOddDigit & 1) ? 1 : 0;
        } else {
            $iRoundup = ($dWorking > 5.0) ? 1 : 0;
        }

        return $iSign * ((floor($dVal * pow(10.0, $iDec)) + $iRoundup) / pow(10.0, $iDec));
    }
}

/**
 * Muestra un mensaje de error en caso de error fatal, aunque php tenga
 * desactivados los errores.
 */
if (!function_exists('fatal_handler')) {
    function fatal_handler()
    {
        $error = error_get_last();
        if (isset($error) && in_array($error["type"], [1, 64])) {
            echo "<h1>Error fatal</h1>"
            . "<ul>"
            . "<li><b>Tipo:</b> " . $error["type"] . "</li>"
            . "<li><b>Archivo:</b> " . $error["file"] . "</li>"
            . "<li><b>Línea:</b> " . $error["line"] . "</li>"
            . "<li><b>Mensaje:</b> " . $error["message"] . "</li>"
            . "</ul>";
        }
    }
}

/**
 * Devuelve la ruta del controlador solicitado.
 * @param string $name
 * @return string
 */
if (!function_exists('find_controller')) {
    function find_controller($name)
    {
        foreach ($GLOBALS['plugins'] as $plugin) {
            if (file_exists(FS_FOLDER . '/plugins/' . $plugin . '/controller/' . $name . '.php')) {
                return 'plugins/' . $plugin . '/controller/' . $name . '.php';
            }
        }

        if (file_exists(FS_FOLDER . '/controller/' . $name . '.php')) {
            return 'controller/' . $name . '.php';
        }

        return 'base/fs_controller.php';
    }
}

/**
 * Función alternativa para cuando el followlocation falla.
 * @param resource $ch
 * @param integer $redirects
 * @param boolean $curlopt_header
 * @return string
 */
if (!function_exists('fs_curl_redirect_exec')) {
    function fs_curl_redirect_exec($ch, &$redirects, $curlopt_header = false)
    {
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($http_code == 301 || $http_code == 302) {
            list($header) = explode("\r\n\r\n", $data, 2);
            $matches = [];
            preg_match("/(Location:|URI:)[^(\n)]*/i", $header, $matches);
            $url = trim(str_replace($matches[1], "", $matches[0]));
            $url_parsed = parse_url($url);
            if (isset($url_parsed)) {
                curl_setopt($ch, CURLOPT_URL, $url);
                $redirects++;
                return fs_curl_redirect_exec($ch, $redirects, $curlopt_header);
            }
        }

        if ($curlopt_header) {
            curl_close($ch);
            return $data;
        }

        list(, $body) = explode("\r\n\r\n", $data, 2);
        curl_close($ch);
        return $body;
    }
}

/**
 * Descarga el archivo de la url especificada
 * @param string $url
 * @param string $filename
 * @param integer $timeout
 * @return boolean
 */
if (!function_exists('fs_file_download')) {
    function fs_file_download($url, $filename, $timeout = 30)
    {
        $ok = FALSE;

        try {
            $data = fs_file_get_contents($url, $timeout);
            if ($data && $data != 'ERROR' && file_put_contents($filename, $data) !== FALSE) {
                $ok = TRUE;
            }
        } catch (Exception $e) {
            /// nada
        }

        return $ok;
    }
}

/**
 * Descarga el contenido con curl o file_get_contents.
 * @param string $url
 * @param integer $timeout
 * @return string
 */
if (!function_exists('fs_file_get_contents')) {
    function fs_file_get_contents($url, $timeout = 10)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/56.0.2924.87 Safari/537.36');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            if (ini_get('open_basedir') === NULL) {
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            }

            /**
             * En algunas configuraciones de php es necesario desactivar estos flags,
             * en otras es necesario activarlos. habrá que buscar una solución mejor.
             */
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            if (defined('FS_PROXY_TYPE')) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, FS_PROXY_TYPE);
                curl_setopt($ch, CURLOPT_PROXY, FS_PROXY_HOST);
                curl_setopt($ch, CURLOPT_PROXYPORT, FS_PROXY_PORT);
            }
            $data = curl_exec($ch);
            $info = curl_getinfo($ch);

            if ($info['http_code'] == 200) {
                curl_close($ch);
                return $data;
            } else if ($info['http_code'] == 301 || $info['http_code'] == 302) {
                $redirs = 0;
                return fs_curl_redirect_exec($ch, $redirs);
            }

            /// guardamos en el log
            if (class_exists('fs_core_log') && $info['http_code'] != 404) {
                $error = curl_error($ch);
                if ($error == '') {
                    $error = 'ERROR ' . $info['http_code'];
                }

                $core_log = new fs_core_log();
                $core_log->new_error($error);
                $core_log->save($url . ' - ' . $error);
            }

            curl_close($ch);
            return 'ERROR';
        }

        return file_get_contents($url);
    }
}

/**
 * Devuelve el equivalente a $_POST[$name], pero pudiendo definicar un valor
 * por defecto si no encuentra nada.
 * @param string $name
 * @param mixed $default
 * @return mixed
 */
if (!function_exists('fs_filter_input_post')) {
    function fs_filter_input_post($name, $default = false)
    {
        return isset($_POST[$name]) ? $_POST[$name] : $default;
    }
}

/**
 * Devuelve el equivalente a $_REQUEST[$name], pero pudiendo definicar un valor
 * por defecto si no encuentra nada.
 * @param string $name
 * @param mixed $default
 * @return mixed
 */
if (!function_exists('fs_filter_input_req')) {
    function fs_filter_input_req($name, $default = false)
    {
        return isset($_REQUEST[$name]) ? $_REQUEST[$name] : $default;
    }
}

/**
 * Deshace las conversiones realizadas por fs_model::no_html()
 * @param string $txt
 * @return string
 */
if (!function_exists('fs_fix_html')) {
    function fs_fix_html($txt)
    {
        $txt = trim($txt);
        $txt = str_replace(['<', '>', '"'], ['&lt;', '&gt;', '&quot;'], $txt);
        return nl2br($txt);
    }
}

/**
 * Devuelve un array con todas las IP del usuario y el navegador.
 * @return array
 */
if (!function_exists('fs_get_ip')) {
    function fs_get_ip()
    {
        $ip_addr = [];

        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip_addr[] = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }

        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip_addr[] = $_SERVER['HTTP_CLIENT_IP'];
        }

        if (isset($_SERVER['REMOTE_ADDR'])) {
            $ip_addr[] = $_SERVER['REMOTE_ADDR'];
        }

        return $ip_addr;
    }
}

/**
 * Devuelve el tamaño máximo en bytes de archivo que podemos subir al servidor.
 * @return integer
 */
if (!function_exists('fs_get_max_file_upload')) {
    function fs_get_max_file_upload()
    {
        return min(
            $GLOBALS['config2']['memory_limit'] * 1024 * 1024, 
            $GLOBALS['config2']['post_max_size'] * 1024 * 1024, 
            $GLOBALS['config2']['upload_max_filesize'] * 1024 * 1024
        );
    }
}

/**
 * Devuelve el nombre de la clase de un objeto o el tipo de una variable.
 * @param mixed $object
 * @return string class name
 */
if (!function_exists('get_class_name')) {
    function get_class_name($object = NULL)
    {
        if (is_object($object)) {
            return get_class($object);
        }

        if (!empty($object)) {
            return gettype($object);
        }

        return 'empty';
    }
}

/**
 * Carga todos los modelos de la carpeta modelo/
 */
if (!function_exists('require_all_models')) {
    function require_all_models()
    {
        $done = array(0);
        if (is_dir('model')) {
            foreach (scandir('model') as $file_name) {
                if (strlen($file_name) > 4 && substr($file_name, -4) == '.php') {
                    if (in_array(substr($file_name, 0, -4), $done)) {
                        continue;
                    }

                    require_once('model/' . $file_name);
                    $done[] = substr($file_name, 0, -4);
                }
            }
        }

        if (isset($GLOBALS['plugins'])) {
            foreach ($GLOBALS['plugins'] as $plugin) {
                if (is_dir('plugins/' . $plugin . '/model')) {
                    foreach (scandir('plugins/' . $plugin . '/model') as $file_name) {
                        if (strlen($file_name) > 4 && substr($file_name, -4) == '.php') {
                            if (in_array(substr($file_name, 0, -4), $done)) {
                                continue;
                            }

                            require_once('plugins/' . $plugin . '/model/' . $file_name);
                            $done[] = substr($file_name, 0, -4);
                        }
                    }
                }
            }
        }
    }
}

/**
 * Devuelve el modelo solicitado.
 * @param string $name nombre del modelo a cargar
 * @param array $params parámetros adicionales a pasar al constructor
 * @return mixed
 */
if (!function_exists('require_model')) {
    function require_model($name, $params = array())
    {
        if (substr($name, 0, 8) == "\\FacturaScripts\\") {
            $class_name = $name;
        } else {
            $class_name = "\\FacturaScripts\\model\\" . $name;
            
            if (!class_exists($class_name)) {
                if (file_exists('model/' . $name . '.php')) {
                    require_once 'model/' . $name . '.php';
                    $class_name = $name;
                } else {
                    foreach ($GLOBALS['plugins'] as $plugin) {
                        if (file_exists('plugins/' . $plugin . '/model/' . $name . '.php')) {
                            require_once 'plugins/' . $plugin . '/model/' . $name . '.php';
                            
                            if (class_exists('\\FacturaScripts\\plugins\\' . $plugin . '\\model\\' . $name)) {
                                $class_name = '\\FacturaScripts\\plugins\\' . $plugin . '\\model\\' . $name;
                                break;
                            } else if (class_exists($name)) {
                                $class_name = $name;
                                break;
                            }
                        }
                    }
                }
            }
        }
        
        $class_name = str_replace('\\\\', '\\', $class_name);
        if (class_exists($class_name)) {
            switch (count($params)) {
                case 0:
                    return new $class_name();
                case 1:
                    return new $class_name($params[0]);
                case 2:
                    return new $class_name($params[0], $params[1]);
                case 3:
                    return new $class_name($params[0], $params[1], $params[2]);
                case 4:
                    return new $class_name($params[0], $params[1], $params[2], $params[3]);
                default:
                    $reflection = new ReflectionClass($class_name);
                    return $reflection->newInstanceArgs($params);
            }
        }
        
        return NULL;
    }
}

/**
 * Legacy plugin compatibility layer
 * These lines provide compatibility with older plugins
 */

// Register a general plugin compatibility autoloader
if (!function_exists('register_legacy_plugin_autoloader')) {
    /**
     * Registers an autoloader that will handle any class from plugins
     */
    function register_legacy_plugin_autoloader() {
        // Skip if already registered
        static $registered = false;
        if ($registered) {
            return;
        }
        
        $registered = true;
        
        spl_autoload_register(function($class) {
            // Only handle classes in the global namespace
            if (strpos($class, '\\') !== false) {
                return false;
            }
            
            // First try to find in the plugins directory directly
            if (is_dir(FS_FOLDER . '/plugins')) {
                // Try to guess the plugin directory by looking at the class name
                // Common patterns: class_name, plugin_class, extension_plugin, etc.
                $possiblePluginNames = [];
                
                // Check if the class name contains underscores (common for plugins)
                if (strpos($class, '_') !== false) {
                    $parts = explode('_', $class);
                    // Try various combinations of parts as potential plugin names
                    for ($i = 0; $i < count($parts); $i++) {
                        $possiblePluginNames[] = $parts[$i];
                        if ($i < count($parts) - 1) {
                            $possiblePluginNames[] = $parts[$i] . '_' . $parts[$i + 1];
                        }
                    }
                }
                
                // Add the full class name as a potential match
                $possiblePluginNames[] = $class;
                $possiblePluginNames = array_unique($possiblePluginNames);
                
                // First, try with specific potential plugin directories
                foreach ($possiblePluginNames as $pluginName) {
                    $pluginDir = FS_FOLDER . '/plugins/' . $pluginName;
                    if (is_dir($pluginDir)) {
                        $paths = [
                            $pluginDir . '/' . $class . '.php',
                            $pluginDir . '/lib/' . $class . '.php',
                            $pluginDir . '/model/' . $class . '.php',
                            $pluginDir . '/class/' . $class . '.php',
                            $pluginDir . '/classes/' . $class . '.php',
                        ];
                        
                        foreach ($paths as $path) {
                            if (file_exists($path)) {
                                require_once $path;
                                return class_exists($class, false);
                            }
                        }
                    }
                }
                
                // If not found, search in all plugin directories
                foreach (scandir(FS_FOLDER . '/plugins') as $dir) {
                    if ($dir === '.' || $dir === '..' || !is_dir(FS_FOLDER . '/plugins/' . $dir)) {
                        continue;
                    }
                    
                    $paths = [
                        FS_FOLDER . '/plugins/' . $dir . '/' . $class . '.php',
                        FS_FOLDER . '/plugins/' . $dir . '/lib/' . $class . '.php',
                        FS_FOLDER . '/plugins/' . $dir . '/model/' . $class . '.php',
                        FS_FOLDER . '/plugins/' . $dir . '/class/' . $class . '.php',
                        FS_FOLDER . '/plugins/' . $dir . '/classes/' . $class . '.php',
                    ];
                    
                    foreach ($paths as $path) {
                        if (file_exists($path)) {
                            require_once $path;
                            return class_exists($class, false);
                        }
                    }
                }
            }
            
            return false;
        });
    }
}

// Register the general legacy plugin autoloader
if (class_exists('FSFramework\\Plugin\\PluginAutoloader', false)) {
    register_legacy_plugin_autoloader();
}
