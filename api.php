<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com> *
 * This program is free software: you can redistribute it and/
 * or modify
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
 * Punto de entrada para la API REST de FSFramework
 *
 * URLs soportadas:
 * - /api.php/v1/{plugin}/{resource}
 * - /api.php/v1/{plugin}/{resource}/{id}
 * - /api.php/v1/{plugin}/{resource}/{id}/{action}
 * - /api.php?api_path=/v1/{plugin}/{resource} (fallback)
 *
 * @author FacturaScripts Team
 */

// Establecer directorio de trabajo
define('FS_FOLDER', __DIR__);
define('FS_JSON_CONTENT_TYPE', 'Content-Type: application/json; charset=utf-8');
chdir(FS_FOLDER);

// Cargar configuración
if (file_exists('config.php')) {
    require_once 'config.php';
} else {
    header(FS_JSON_CONTENT_TYPE);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Sistema no configurado. Ejecute el instalador primero.'
    ]);
    exit;
}

// Cargar autoloader de Composer
if (file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
}

// Cargar clases base del framework
require_once 'base/fs_core_log.php';
require_once 'base/fs_cache.php';
require_once 'base/fs_db2.php';
require_once 'base/fs_model.php';

// Inicializar conexión a base de datos
$db = new fs_db2();
if (!$db->connect()) {
    header(FS_JSON_CONTENT_TYPE);
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'Error de conexión a la base de datos'
    ]);
    exit;
}

// Cargar plugins activos
require_once 'base/fs_plugin_manager.php';
$plugin_manager = new fs_plugin_manager();
$GLOBALS['plugins'] = $plugin_manager->enabled();

// Ejecutar API Kernel
use FSFramework\Api\ApiKernel;

try {
    ApiKernel::handle();
} catch (\Throwable $e) {
    header(FS_JSON_CONTENT_TYPE);
    http_response_code(500);
    
    $response = [
        'success' => false,
        'error' => 'Error interno del servidor'
    ];
    
    if (defined('FS_DEBUG') && FS_DEBUG) {
        $response['error'] = $e->getMessage();
        $response['trace'] = explode("\n", $e->getTraceAsString());
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
