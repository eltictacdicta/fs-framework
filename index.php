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
if (version_compare(PHP_VERSION, '8.2', '<')) {
    die('FSFramework necesita PHP 8.2 o superior, y usted tiene PHP ' . PHP_VERSION);
}

if (!file_exists('config.php')) {
    /// si no hay config.php redirigimos al instalador
    header('Location: install.php');
    die('Redireccionando al instalador...');
}

define('FS_FOLDER', __DIR__);

require_once 'config.php';
require_once 'base/fs_maintenance_mode.php';

if (fs_maintenance_mode::isActive()) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Retry-After: ' . fs_maintenance_mode::retryAfter());
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Mantenimiento</title><style>body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f5f7fa;color:#1f2933;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}.card{max-width:640px;background:#fff;border:1px solid #d9e2ec;border-radius:16px;padding:32px;box-shadow:0 12px 30px rgba(15,23,42,.08)}h1{margin:0 0 12px;font-size:clamp(1.8rem,4vw,2.4rem)}p{margin:0;line-height:1.6;color:#52606d}</style></head><body><main class="card"><h1>Mantenimiento en curso</h1><p>'
        . htmlspecialchars(fs_maintenance_mode::message(), ENT_QUOTES, 'UTF-8')
        . '</p></main></body></html>';
    exit;
}

/// Carga de dependencias
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($composerAutoload)) {
    error_log('FSFramework bootstrap error: falta vendor/autoload.php. Ejecute composer install o restaure la carpeta vendor.');
    http_response_code(500);
    echo '<h1>Error de arranque</h1>'
        . '<p>Faltan las dependencias de Composer.</p>'
        . '<p>Restaure la carpeta vendor desde una copia valida o ejecute <code>composer install --no-dev --optimize-autoloader</code>.</p>';
    exit;
}

require_once $composerAutoload;

/// Compatibilidad temporal para plugins bajo el namespace FSFramework\Plugins\*
spl_autoload_register(function ($class) {
    $prefix = 'FSFramework\\Plugins\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativePath = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/plugins/' . $relativePath . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
}, true, true);

/// cargamos las constantes de configuración (DEBE ser antes de Kernel::boot para que $GLOBALS['plugins'] esté disponible)
require_once 'base/fs_secret_migrator.php';
fs_secret_migrator::ensure();
require_once 'base/config2.php';

/// --- Crawler Gate (host-wide, web-server independent) ---
/// Runs before the boot, the self-heal and the migrations, so a blocked crawler
/// costs almost nothing. It must not use Kernel::request(): the Kernel is not
/// booted yet and getInstance() throws. `.htaccess` is inert on nginx and on
/// Apache with AllowOverride None, so the policy cannot live only in server
/// configuration. Opt out with define('FS_BLOCK_AI_CRAWLERS', false).
$_crawlerResponse = (new \FSFramework\Security\CrawlerUserAgentGate())->intercept(
    \Symfony\Component\HttpFoundation\Request::createFromGlobals()
);
if ($_crawlerResponse) {
    $_crawlerResponse->send();
    exit;
}
unset($_crawlerResponse);

/// Self-heal: asegurar tablas core críticas en instalaciones incompletas
require_once 'base/fs_core_log.php';
require_once 'base/fs_db2.php';
require_once 'base/fs_schema.php';
/// y migraciones one-shot; se saltan mientras exista el sello de esta versión
\FSFramework\Core\Schema\CoreSchemaBootstrap::ensure(static function (string $msg): void {
    error_log($msg);
});

/// Cargar la clase base legacy de controladores ANTES del boot.
/// El Router se construye dentro de Kernel::boot() y refleja src/Controller/* y
/// plugins/*/Controller/* para leer sus atributos de ruta; clases como
/// HtmxCrudController extienden \fs_controller, así que la clase debe existir
/// en ese momento. El mapa legacy de base/fs_autoload.php no está cableado en
/// el entry path, por eso se requiere explícitamente acá.
require_once 'base/fs_controller.php';

/// Boot del Kernel moderno (inicializa plugins de FS2025)
\FSFramework\Core\Kernel::boot();

/// Inicializar DebugBar (solo se renderiza si FS_DEBUG está activo)
\FSFramework\Core\DebugBar::init();

/// --- Security Headers ---
\FSFramework\Security\SecurityHeaders::applyDefaultHeaders();

/// --- HttpKernel de Symfony ---
/// Listeners de kernel.request (FSFramework\Core\Http\FrontRequestSubscriber):
/// gate de acceso público (stealth), rutas #[FSRoute] y probes del navegador.
/// Lo que ninguna ruta reclama lo sirve el despacho por ?page= (PageDispatcher).
require_once FS_FOLDER . '/src/Core/StealthMode.php';
require_once FS_FOLDER . '/src/Core/PublicAccessGate.php';
$request = \FSFramework\Core\Kernel::request();
$httpKernel = \FSFramework\Core\Kernel::httpKernel();
$response = $httpKernel->handle($request);
$response->send();
$httpKernel->terminate($request, $response);
