<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
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

// Inicio del SSO web (OIDC Authorization Code + PKCE): genera el state y el
// code_verifier, los guarda en la sesión y redirige al provider. El usuario
// autenticado en el provider entra al backend por /auth/callback.php.

define('FS_FOLDER', dirname(__DIR__));

// FS_PATH: subdirectorio público real del framework. Este entrypoint vive en
// /auth/, así que base/config2.php calcularía FS_PATH=/auth/ y rompería las
// URLs. Lo fijamos a la base pública real del proyecto.
if (!defined('FS_PATH')) {
    $scriptDir = '';
    if (!empty($_SERVER['SCRIPT_NAME'])) {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $scriptDir = preg_replace('#/auth/?$#', '', $scriptDir);
        if ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '\\') {
            $scriptDir = '';
        }
    }

    // Se usa '/' (no '') para que el SessionManager derive el cookie-path como
    // '/': con '' lo toma de SCRIPT_NAME (dirname = '/auth') y la cookie quedaría
    // limitada a /auth, sin compartirse con index.php.
    define('FS_PATH', $scriptDir === '' ? '/' : rtrim($scriptDir, '/') . '/');
}

chdir(FS_FOLDER);

require_once 'config.php';
require_once 'base/config2.php';

// Autoloader de Composer: SessionManager (FSFramework\Security\*), HttpClient,
// oidc-core y demás clases PSR-4 no pasan por index.php en este entrypoint.
require_once 'vendor/autoload.php';

require_once 'base/fs_core_log.php';
require_once 'base/fs_db2.php';
require_once 'base/fs_model.php';
require_once 'base/fs_extended_model.php';
require_once 'base/fs_log_manager.php';
require_once 'base/fs_functions.php';

require_once 'model/fs_user.php';
require_once 'model/agente.php';

require_once 'plugins/api_auth/model/api_security_setting.php';
require_once 'plugins/api_auth/model/oidc_user_correlator.php';
require_once 'plugins/api_auth/model/oidc_login_service.php';

if (!function_exists('infrico_sso_fail')) {
    /**
     * Muestra un error claro y termina la petición sin crear sesión.
     */
    function infrico_sso_fail(string $message, int $status = 500): void
    {
        $base = (defined('FS_PATH') && trim((string) FS_PATH) !== '') ? rtrim((string) FS_PATH, '/') : '';
        $home = htmlspecialchars($base . '/index.php', ENT_QUOTES, 'UTF-8');
        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<title>Inicio de sesión no disponible</title></head><body>'
            . '<h1>Inicio de sesión no disponible</h1><p>' . $safe . '</p>'
            . '<p><a href="' . $home . '">Volver al inicio</a></p>'
            . '</body></html>';
        exit;
    }
}

$db = new fs_db2();
$db->connect();

if (!$db->connected()) {
    infrico_sso_fail('No se pudo conectar con la base de datos.');
}

$service = new oidc_login_service($db);

if (!$service->isWebConfigured()) {
    $db->close();
    infrico_sso_fail('El proveedor OIDC no está configurado para el SSO web.');
}

$state = oidc_login_service::generateState();
$codeVerifier = oidc_login_service::generatePkceVerifier();

$session = \FSFramework\Security\SessionManager::getInstance();
$session->set('oidc_sso_state', $state);
$session->set('oidc_sso_code_verifier', $codeVerifier);
$session->getSymfonySession()->save();

$callbackUrl = $service->getCallbackUrl($_SERVER, defined('FS_PATH') ? (string) FS_PATH : '');
$authorizeUrl = $service->buildAuthorizeUrl($callbackUrl, $state, $codeVerifier);

$db->close();

header('Location: ' . $authorizeUrl, true, 302);
exit;
