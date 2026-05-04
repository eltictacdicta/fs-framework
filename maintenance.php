<?php
/**
 * Minimal maintenance mode endpoint for environments without shell access.
 */

define('FS_FOLDER', __DIR__);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!file_exists(FS_FOLDER . '/config.php')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Sistema no configurado.',
    ]);
    exit;
}

require_once FS_FOLDER . '/config.php';
require_once FS_FOLDER . '/base/fs_maintenance_mode.php';

$adminToken = defined('FS_MAINTENANCE_ADMIN_TOKEN') ? trim((string) FS_MAINTENANCE_ADMIN_TOKEN) : '';
$providedToken = trim((string) ($_POST['token'] ?? ''));

if ($adminToken === '' || $providedToken === '' || !hash_equals($adminToken, $providedToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Token de administración no válido.',
    ]);
    exit;
}

$action = trim((string) ($_REQUEST['action'] ?? 'status'));

switch ($action) {
    case 'enable':
        $stealthStatus = fs_maintenance_mode::stealthAccessStatus();
        if (empty($stealthStatus['ready'])) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => 'Activa primero el modo stealth desde admin_stealth para mantener una ruta de acceso del administrador durante el mantenimiento.',
            ]);
            exit;
        }

        $message = trim((string) ($_REQUEST['message'] ?? 'Mantenimiento activado manualmente.'));
        $retryAfter = (int) ($_REQUEST['retry_after'] ?? 300);
        $success = fs_maintenance_mode::writeLock([
            'message' => $message,
            'source' => 'maintenance.php',
            'retry_after' => $retryAfter > 0 ? $retryAfter : 300,
        ]);

        if (!$success) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'No se pudo activar el modo mantenimiento.',
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'active' => true,
            'message' => fs_maintenance_mode::message(),
            'lock_file' => fs_maintenance_mode::lockFileName(),
        ]);
        exit;

    case 'disable':
        $success = fs_maintenance_mode::clearLock();
        if (!$success) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'No se pudo desactivar el modo mantenimiento.',
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'active' => false,
            'lock_file' => fs_maintenance_mode::lockFileName(),
        ]);
        exit;

    case 'status':
        echo json_encode([
            'success' => true,
            'active' => fs_maintenance_mode::isEnabled(),
            'forced' => fs_maintenance_mode::isForced(),
            'message' => fs_maintenance_mode::message(),
            'lock_file' => fs_maintenance_mode::lockFileName(),
            'state' => fs_maintenance_mode::readLockState(),
            'stealth' => fs_maintenance_mode::stealthAccessStatus(),
        ]);
        exit;

    default:
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Acción no válida. Use status, enable o disable.',
        ]);
        exit;
}
