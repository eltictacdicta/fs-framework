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
define('FS_FOLDER', __DIR__);

/// cargamos las constantes de configuración
require_once 'config.php';
require_once 'base/config2.php';

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
require_once 'base/fs_core_log.php';
require_once 'base/fs_db2.php';
$db = new fs_db2();

require_once 'base/fs_extended_model.php';
require_once 'base/fs_log_manager.php';
require_once 'base/fs_api.php';
require_all_models();

// Cargar la clase base fs_controller antes de cargar cualquier controlador que la extienda
require_once 'base/fs_controller.php';

// Inicializar plugin API Auth si existe - DESPUÉS de cargar modelos
if (file_exists('plugins/api_auth/init.php')) {
    require_once 'plugins/api_auth/init.php';
}

// Cargar las funciones API del controlador para asegurar disponibilidad
if (file_exists('plugins/api_auth/controller/api_controller.php')) {
    require_once 'plugins/api_auth/controller/api_controller.php';
}

// También cargar las funciones de modelo API para registro
if (file_exists('plugins/api_auth/model/api_functions.php')) {
    require_once 'plugins/api_auth/model/api_functions.php';

    // Registrar las funciones API inmediatamente después de cargarlas
    if (function_exists('register_api_auth_functions')) {
        register_api_auth_functions();
    }
}

// ACCESO DESHABILITADO POR SEGURIDAD
// Este archivo ha sido deprecado. Use los puntos de entrada específicos de cada plugin.
// Para autenticación API, use: /plugins/api_auth/api.php

header('HTTP/1.1 403 Forbidden');
header('Content-Type: application/json');

echo json_encode([
    'success' => false,
    'error' => 'Acceso directo a la API general está deshabilitado por seguridad',
    'message' => 'Por favor, utilice los endpoints específicos de cada plugin',
    'api_auth_endpoint' => '/plugins/api_auth/api.php'
]);

exit;
