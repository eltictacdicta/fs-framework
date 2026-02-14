<?php

const FS_PLUGIN_LEGACY_CONTROLLER_PATH = '/controller/';
const FS_PLUGIN_MODERN_CONTROLLER_PATH = '/Controller/';
const FS_HTTP_SEPARATOR = "\r\n\r\n";
const FS_GITHUB_ACCEPT_HEADER = 'Accept: application/vnd.github.v3.raw';
const FS_GITHUB_AUTH_HEADER_PREFIX = 'Authorization: token ';
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

/**
 * Redondeo bancario
 * @staticvar real $dFuzz
 * @param float $dVal
 * @param integer $iDec
 * @return float
 */
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

/**
 * Muestra un mensaje de error en caso de error fatal, aunque php tenga
 * desactivados los errores.
 */
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

/**
 * Devuelve la ruta del controlador solicitado.
 * Soporta tanto plugins legacy (controller/) como FS2025 (Controller/).
 * @param string $name
 * @return string
 */
function find_controller($name)
{
    foreach ($GLOBALS['plugins'] as $plugin) {
        $legacyPath = 'plugins/' . $plugin . FS_PLUGIN_LEGACY_CONTROLLER_PATH . $name . '.php';
        if (file_exists(FS_FOLDER . '/' . $legacyPath)) {
            return $legacyPath;
        }

        $modernPath = 'plugins/' . $plugin . FS_PLUGIN_MODERN_CONTROLLER_PATH . $name . '.php';
        if (file_exists(FS_FOLDER . '/' . $modernPath)) {
            return $modernPath;
        }

        $matchedModern = fs_find_controller_by_page_data($plugin, $name);
        if ($matchedModern !== null) {
            return $matchedModern;
        }
    }

    if (file_exists(FS_FOLDER . '/controller/' . $name . '.php')) {
        return 'controller/' . $name . '.php';
    }

    return 'base/fs_controller.php';
}

/**
 * Busca información de un controlador FS2025 por nombre de página.
 * @param string $pageName
 * @return array|false Array con 'plugin', 'class', 'file' o false si no encontrado
 */
function find_modern_controller($pageName)
{
    foreach ($GLOBALS['plugins'] as $plugin) {
        $modernDir = FS_FOLDER . '/plugins/' . $plugin . '/Controller';
        if (!is_dir($modernDir)) {
            continue;
        }

        $entries = scandir($modernDir);
        if (!is_array($entries)) {
            continue;
        }

        foreach ($entries as $file) {
            if (substr($file, -4) !== '.php') {
                continue;
            }

            $className = substr($file, 0, -4);
            $fullClass = "FacturaScripts\\Plugins\\{$plugin}\\Controller\\{$className}";

            if (!class_exists($fullClass)) {
                continue;
            }

            $detectedName = fs_detect_controller_page_name($fullClass, $className);
            if ($detectedName === null || $detectedName !== $pageName) {
                continue;
            }

            return [
                'plugin' => $plugin,
                'class' => $fullClass,
                'className' => $className,
                'file' => $modernDir . '/' . $file
            ];
        }
    }

    return false;
}

/**
 * Función alternativa para cuando el followlocation falla.
 * @param resource $ch
 * @param integer $redirects
 * @param boolean $curlopt_header
 * @return string
 */
function fs_curl_redirect_exec($ch, &$redirects, $curlopt_header = false)
{
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($http_code == 301 || $http_code == 302) {
        list($header) = explode(FS_HTTP_SEPARATOR, $data, 2);
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

    list(, $body) = explode(FS_HTTP_SEPARATOR, $data, 2);
    curl_close($ch);
    return $body;
}

/**
 * Descarga el archivo de la url especificada
 * @param string $url
 * @param string $filename
 * @param integer $timeout
 * @return boolean
 */
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

/**
 * Detecta y devuelve la ruta al archivo CA bundle del sistema para cURL.
 * Busca en múltiples ubicaciones comunes, acepta configuración manual
 * vía FS_CURL_CA_BUNDLE en config.php, y como último recurso consulta
 * la configuración de OpenSSL de PHP.
 *
 * @return string Ruta al CA bundle, o cadena vacía si no se encuentra
 */
function fs_curl_ca_info()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // 1. Configuración manual del usuario (prioridad máxima)
    if (defined('FS_CURL_CA_BUNDLE') && FS_CURL_CA_BUNDLE && file_exists(FS_CURL_CA_BUNDLE)) {
        $cached = FS_CURL_CA_BUNDLE;
        return $cached;
    }

    // 2. Buscar en rutas comunes de distintos sistemas/hostings
    $common_paths = [
        '/etc/ssl/certs/ca-certificates.crt',       // Debian/Ubuntu
        '/etc/pki/tls/certs/ca-bundle.crt',          // RedHat/CentOS/Fedora
        '/etc/ssl/ca-bundle.pem',                     // OpenSUSE
        '/etc/pki/tls/cacert.pem',                    // Algunos RedHat
        '/etc/ssl/cert.pem',                          // macOS / Alpine / FreeBSD
        '/usr/local/share/certs/ca-root-nss.crt',    // FreeBSD
        '/usr/local/etc/openssl/cert.pem',            // macOS Homebrew OpenSSL
        '/etc/ca-certificates/extracted/tls-ca-bundle.pem', // Arch
    ];

    foreach ($common_paths as $path) {
        if (file_exists($path)) {
            $cached = $path;
            return $cached;
        }
    }

    // 3. Consultar la configuración de OpenSSL de PHP
    if (function_exists('openssl_get_cert_locations')) {
        $locations = openssl_get_cert_locations();
        if (!empty($locations['default_cert_file']) && file_exists($locations['default_cert_file'])) {
            $cached = $locations['default_cert_file'];
            return $cached;
        }
    }

    // 4. CA bundle incluido en el proyecto como último recurso
    $localCert = (defined('FS_FOLDER') ? FS_FOLDER : '.') . '/base/cacert.pem';
    if (file_exists($localCert)) {
        $cached = $localCert;
        return $cached;
    }

    $cached = '';
    return $cached;
}

/**
 * Comprueba si el archivo cacert.pem local necesita actualizarse y, si es así,
 * descarga una copia nueva desde curl.se/ca/cacert.pem.
 *
 * Se ejecuta como máximo una vez por sesión y solo intenta la descarga si el
 * archivo tiene más de $maxAgeDays días (por defecto 90).
 *
 * @param int $maxAgeDays Días máximos antes de renovar (defecto: 90)
 * @return bool TRUE si se actualizó correctamente, FALSE en caso contrario
 */
function fs_curl_update_ca_bundle($maxAgeDays = 90)
{
    // Evitar múltiples intentos en la misma sesión
    if (!empty($_SESSION['cacert_checked'])) {
        return false;
    }
    $_SESSION['cacert_checked'] = true;

    $localCert = (defined('FS_FOLDER') ? FS_FOLDER : '.') . '/base/cacert.pem';
    $sourceUrl = 'https://curl.se/ca/cacert.pem';

    // Si el archivo existe y no es lo suficientemente viejo, no hacer nada
    if (file_exists($localCert)) {
        $fileAge = time() - filemtime($localCert);
        if ($fileAge < ($maxAgeDays * 86400)) {
            return false; // Aún vigente, no necesita actualización
        }
    }

    // Verificar permisos de escritura
    $baseDir = dirname($localCert);
    if (!is_writable($baseDir)) {
        return false;
    }

    $tmpFile = $localCert . '.tmp';
    $downloaded = false;

    // Intento 1: cURL (usa el cacert.pem existente para validar SSL)
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $sourceUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FSFramework-CA-Updater');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // Usar el cacert.pem actual (aunque viejo, sigue siendo válido para esta descarga)
        $currentCa = fs_curl_ca_info();
        if ($currentCa) {
            curl_setopt($ch, CURLOPT_CAINFO, $currentCa);
        }

        $data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200 && $data && strlen($data) > 1000) {
            $downloaded = @file_put_contents($tmpFile, $data) !== false;
        }
    }

    // Intento 2: file_get_contents como fallback
    if (!$downloaded) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 30, 'user_agent' => 'FSFramework-CA-Updater'],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $currentCa = fs_curl_ca_info();
        if ($currentCa) {
            stream_context_set_option($ctx, 'ssl', 'cafile', $currentCa);
        }
        $data = @file_get_contents($sourceUrl, false, $ctx);
        if ($data && strlen($data) > 1000) {
            $downloaded = @file_put_contents($tmpFile, $data) !== false;
        }
    }

    if (!$downloaded) {
        @unlink($tmpFile);
        return false;
    }

    // Validación básica: debe contener certificados PEM
    $content = @file_get_contents($tmpFile, false, null, 0, 512);
    if (!$content || strpos($content, '-----BEGIN CERTIFICATE-----') === false) {
        @unlink($tmpFile);
        return false;
    }

    // Reemplazar atómicamente: renombrar es atómico en la mayoría de filesystems
    if (!@rename($tmpFile, $localCert)) {
        // Fallback: copiar y eliminar temporal
        if (@copy($tmpFile, $localCert)) {
            @unlink($tmpFile);
        } else {
            @unlink($tmpFile);
            return false;
        }
    }

    return true;
}

/**
 * Aplica la configuración SSL y CA bundle a un handle cURL.
 *
 * La verificación SSL siempre permanece activa por seguridad.
 *
 * Si se define FS_CURL_SSL_VERIFY como false en config.php, el valor se
 * ignora para evitar conexiones TLS inseguras.
 *
 * @param resource $ch Handle cURL
 * @param bool|null $forceVerify Forzar verificación (ignora config). NULL = usar config.
 * @return void
 */
function fs_curl_set_ssl($ch, $forceVerify = null)
{
    static $ssl_warning_emitted = false;
    $verify = $forceVerify ?? (defined('FS_CURL_SSL_VERIFY') ? (bool) FS_CURL_SSL_VERIFY : true);

    if (!$verify && !$ssl_warning_emitted && class_exists('fs_core_log')) {
        $ssl_warning_emitted = true;
        $core_log = new fs_core_log();
        $core_log->new_advice('FS_CURL_SSL_VERIFY=false se ignora por seguridad: TLS verification remains enabled.');
    }

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $caInfo = fs_curl_ca_info();
    if ($caInfo) {
        curl_setopt($ch, CURLOPT_CAINFO, $caInfo);
    }
}

/**
 * Descarga el contenido con curl o file_get_contents.
 * @param string $url
 * @param integer $timeout
 * @return string
 */
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

        // Verificación SSL con auto-detección de CA bundle
        fs_curl_set_ssl($ch);

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
        } elseif ($info['http_code'] == 301 || $info['http_code'] == 302) {
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

function fs_filter_input_post($name, $default = false)
{
    try {
        if (\FSFramework\Core\Kernel::getInstance()->getRequest()->request->has($name)) {
            return \FSFramework\Core\Kernel::request()->request->get($name);
        }
    } catch (\Exception $e) {
        // Fallback for when Kernel is not booted or other issues
        return isset($_POST[$name]) ? $_POST[$name] : $default;
    }
    return $default;
}

function fs_filter_input_req($name, $default = false)
{
    try {
        $request = \FSFramework\Core\Kernel::request();
        // Check query (GET) first, then request (POST) - mimicking $_REQUEST behavior partially or check all
        // Symfony $request->get() checks query, request (POST), attributes.
        $value = $request->get($name, $default);

        // If value is default, explicit check if parameter exists to avoid false positives if default is returned naturally?
        // $request->get returns default if key not found.

    } catch (\Exception $e) {
        $value = isset($_REQUEST[$name]) ? $_REQUEST[$name] : $default;
    }

    if ($value !== $default && $value !== null) {
        return filter_var($value, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    }
    return $value;
}

/**
 * Deshace las conversiones realizadas por fs_model::no_html()
 * @param string $txt
 * @return string
 */
function fs_fix_html($txt)
{
    $original = array('&lt;', '&gt;', '&quot;', '&#39;');
    $final = array('<', '>', "'", "'");
    return trim(str_replace($original, $final, $txt));
}

/**
 * Devuelve la IP del usuario.
 * @return string
 */
function fs_get_ip()
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $field) {
        if (isset($_SERVER[$field])) {
            // Si hay varias IPs en X-Forwarded-For, cogemos la primera
            $ips = explode(',', $_SERVER[$field]);
            return trim($ips[0]);
        }
    }

    return '';
}

/**
 * Devuelve TRUE si la IP es local o privada.
 * @param string $ip
 * @return boolean
 */
function fs_is_local_ip($ip)
{
    if (in_array($ip, ['127.0.0.1', '::1', 'localhost'])) {
        return TRUE;
    }

    // Rangos privados: 10.x.x.x, 172.16.x.x-172.31.x.x, 192.168.x.x
    return (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip);
}

/**
 * Devuelve el tamaño máximo de archivo que soporta el servidor para las subidas por formulario.
 * @return int
 */
function fs_get_max_file_upload()
{
    $max = intval(ini_get('post_max_size'));
    if (intval(ini_get('upload_max_filesize')) < $max) {
        $max = intval(ini_get('upload_max_filesize'));
    }

    return $max;
}

/**
 * Devuelve el nombre de la clase del objeto, pero sin el namespace.
 * @param mixed $object
 * @return string
 */
function get_class_name($object = NULL)
{
    $name = get_class($object);
    $pos = strrpos($name, '\\');
    if ($pos !== FALSE) {
        $name = substr($name, $pos + 1);
    }

    return $name;
}

/**
 * Carga todos los modelos disponibles en los pugins activados y el núcleo.
 */
function require_all_models()
{
    if (!isset($GLOBALS['models'])) {
        $GLOBALS['models'] = [];
    }

    foreach ($GLOBALS['plugins'] as $plugin) {
        if (!file_exists('plugins/' . $plugin . '/model')) {
            continue;
        }

        foreach (scandir('plugins/' . $plugin . '/model') as $file_name) {
            if ($file_name != '.' && $file_name != '..' && substr($file_name, -4) == '.php' && !in_array($file_name, $GLOBALS['models'])) {
                require_once 'plugins/' . $plugin . '/model/' . $file_name;
                $GLOBALS['models'][] = $file_name;
            }
        }
    }

    /// ahora cargamos los del núcleo
    foreach (scandir('model') as $file_name) {
        if ($file_name != '.' && $file_name != '..' && substr($file_name, -4) == '.php' && !in_array($file_name, $GLOBALS['models'])) {
            require_once 'model/' . $file_name;
            $GLOBALS['models'][] = $file_name;
        }
    }
}

/**
 * Función obsoleta para cargar un modelo concreto.
 * @deprecated since version 2017.025
 * @param string $name
 */
function require_model($name)
{
    if (FS_DB_HISTORY) {
        $core_log = new fs_core_log();
        $core_log->new_error("require_model('" . $name . "') es innecesario desde FSFramework 2017.025.");
    }
}

/**
 * Descarga contenido con autenticación (para repositorios privados de GitHub).
 * @param string $url
 * @param string $token Token de acceso (GitHub Personal Access Token)
 * @param integer $timeout
 * @return string
 */
function fs_file_get_contents_auth($url, $token, $timeout = 10)
{
    if (!function_exists('curl_init')) {
        if (class_exists('fs_core_log')) {
            $core_log = new fs_core_log();
            $core_log->new_error('cURL no está disponible. Se requiere para acceso a repositorios privados.');
        }
        return 'ERROR';
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'FSFramework-Plugin-Manager');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    curl_setopt($ch, CURLOPT_HTTPHEADER, fs_github_headers($token));

    if (ini_get('open_basedir') === NULL) {
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    }

    // Verificación SSL con auto-detección de CA bundle
    fs_curl_set_ssl($ch);

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
    } elseif ($info['http_code'] == 301 || $info['http_code'] == 302) {
        $redirs = 0;
        return fs_curl_redirect_exec_auth($ch, $redirs, $token);
    }

    // Log de errores
    if (class_exists('fs_core_log') && $info['http_code'] != 404) {
        $error = curl_error($ch);
        if ($error == '') {
            $error = 'ERROR ' . $info['http_code'];
            if ($info['http_code'] == 401) {
                $error .= ' - Token de GitHub inválido o expirado';
            } elseif ($info['http_code'] == 403) {
                $error .= ' - Acceso denegado. Verifica los permisos del token';
            }
        }

        $core_log = new fs_core_log();
        $core_log->new_error($error);
        $core_log->save($url . ' - ' . $error);
    }

    curl_close($ch);
    return 'ERROR';
}

/**
 * Función alternativa para redirecciones con autenticación.
 * @param resource $ch
 * @param integer $redirects
 * @param string $token
 * @param boolean $curlopt_header
 * @return string
 */
function fs_curl_redirect_exec_auth($ch, &$redirects, $token, $curlopt_header = false)
{
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Mantener la autenticación en redirects
    curl_setopt($ch, CURLOPT_HTTPHEADER, fs_github_headers($token));

    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($http_code == 301 || $http_code == 302) {
        list($header) = explode(FS_HTTP_SEPARATOR, $data, 2);
        $matches = [];
        preg_match("/(Location:|URI:)[^(\n)]*/i", $header, $matches);
        $url = trim(str_replace($matches[1], "", $matches[0]));
        $url_parsed = parse_url($url);
        if (isset($url_parsed)) {
            curl_setopt($ch, CURLOPT_URL, $url);
            $redirects++;
            return fs_curl_redirect_exec_auth($ch, $redirects, $token, $curlopt_header);
        }
    }

    if ($curlopt_header) {
        curl_close($ch);
        return $data;
    }

    list(, $body) = explode(FS_HTTP_SEPARATOR, $data, 2);
    curl_close($ch);
    return $body;
}

function fs_github_headers($token)
{
    return [
        FS_GITHUB_ACCEPT_HEADER,
        FS_GITHUB_AUTH_HEADER_PREFIX . $token,
    ];
}

function fs_find_controller_by_page_data($plugin, $name)
{
    $modernDir = FS_FOLDER . '/plugins/' . $plugin . '/Controller';
    if (!is_dir($modernDir)) {
        return null;
    }

    $files = scandir($modernDir);
    if (!is_array($files)) {
        return null;
    }

    foreach ($files as $file) {
        if (substr($file, -4) !== '.php') {
            continue;
        }

        $className = substr($file, 0, -4);
        $fullClass = "FacturaScripts\\Plugins\\{$plugin}\\Controller\\{$className}";
        $detectedName = fs_detect_controller_page_name($fullClass, $className);
        if ($detectedName === $name) {
            return 'plugins/' . $plugin . FS_PLUGIN_MODERN_CONTROLLER_PATH . $file;
        }
    }

    return null;
}

function fs_detect_controller_page_name($fullClass, $default)
{
    if (!class_exists($fullClass)) {
        return null;
    }

    try {
        $reflection = new \ReflectionClass($fullClass);
        $tempInstance = $reflection->newInstanceWithoutConstructor();
        if (!method_exists($tempInstance, 'getPageData')) {
            return $default;
        }

        $pd = $tempInstance->getPageData();
        if (isset($pd['name']) && !empty($pd['name'])) {
            return $pd['name'];
        }
    } catch (\Throwable $e) {
        return null;
    }

    return $default;
}

/**
 * Descarga archivo con autenticación (para repositorios privados de GitHub).
 * @param string $url
 * @param string $filename
 * @param string $token Token de acceso de GitHub
 * @param integer $timeout
 * @return boolean
 */
function fs_file_download_auth($url, $filename, $token, $timeout = 60)
{
    $ok = false;

    try {
        // Para descargas de ZIP desde GitHub, usar la API de contenidos
        $data = fs_file_get_contents_auth($url, $token, $timeout);
        if ($data && $data != 'ERROR' && file_put_contents($filename, $data) !== false) {
            $ok = true;
        }
    } catch (Exception $e) {
        if (class_exists('fs_core_log')) {
            $core_log = new fs_core_log();
            $core_log->new_error('Error al descargar archivo: ' . $e->getMessage());
        }
    }

    return $ok;
}

/**
 * Obtiene el contenido de un archivo usando la API de GitHub (para repositorios privados).
 * @param string $api_url URL de la API de GitHub (https://api.github.com/repos/owner/repo/contents/path)
 * @param string $token Token de acceso de GitHub
 * @param integer $timeout
 * @return string|false Contenido del archivo o 'ERROR' si falla
 */
function fs_file_get_contents_github_api($api_url, $token, $timeout = 10)
{
    if (!function_exists('curl_init')) {
        return 'ERROR';
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'FSFramework-Plugin-Manager');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    // Headers de autenticación para GitHub API
    // Usamos el header Accept para obtener el contenido raw directamente
    $headers = [
        'Accept: application/vnd.github.v3.raw',
        'Authorization: token ' . $token,
        'X-GitHub-Api-Version: 2022-11-28'
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if (ini_get('open_basedir') === NULL) {
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    }

    // Verificación SSL con auto-detección de CA bundle
    fs_curl_set_ssl($ch);

    if (defined('FS_PROXY_TYPE')) {
        curl_setopt($ch, CURLOPT_PROXYTYPE, FS_PROXY_TYPE);
        curl_setopt($ch, CURLOPT_PROXY, FS_PROXY_HOST);
        curl_setopt($ch, CURLOPT_PROXYPORT, FS_PROXY_PORT);
    }

    $data = curl_exec($ch);
    $info = curl_getinfo($ch);

    curl_close($ch);

    if ($info['http_code'] == 200) {
        return $data;
    }

    // Si falla con raw, intentar obtener JSON y decodificar base64
    if ($info['http_code'] == 404) {
        return 'ERROR';
    }

    // Log de error para depuración
    if (class_exists('fs_core_log') && $info['http_code'] != 404) {
        $core_log = new fs_core_log();
        $error_msg = 'GitHub API error: ' . $info['http_code'];
        if ($info['http_code'] == 401) {
            $error_msg .= ' - Token inválido';
        } elseif ($info['http_code'] == 403) {
            $error_msg .= ' - Acceso denegado o rate limit';
        }
        $core_log->save($api_url . ' - ' . $error_msg);
    }

    return 'ERROR';
}
