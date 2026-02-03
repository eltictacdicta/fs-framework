<?php
/**
 * Actualizador de FSFramework
 * 
 * Este archivo actúa como punto de entrada y redirecciona la lógica
 * al módulo update-and-backup que contiene toda la funcionalidad.
 * 
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 */

// Verificar que existe config.php
if (!file_exists('config.php')) {
    die('Archivo config.php no encontrado. No puedes actualizar sin instalar.');
}

define('FS_FOLDER', __DIR__);

// Cargar configuración
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/base/config2.php';

// Cargar autoloader de Composer
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Verificar autenticación de usuario administrador usando consulta SQL directa
 * Esto evita problemas de dependencias y namespaces
 */
function check_admin_auth() {
    // Obtener cookies de usuario
    $cookieUser = isset($_COOKIE['user']) ? filter_var($_COOKIE['user'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) : null;
    $cookieLogkey = isset($_COOKIE['logkey']) ? filter_var($_COOKIE['logkey'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) : null;
    
    if (!$cookieUser || !$cookieLogkey) {
        return false;
    }
    
    // Verificar directamente en la base de datos
    try {
        // Crear conexión PDO directa
        $dsn = '';
        
        if (strtolower(FS_DB_TYPE) === 'mysql') {
            $dsn = 'mysql:host=' . FS_DB_HOST . ';port=' . FS_DB_PORT . ';dbname=' . FS_DB_NAME . ';charset=utf8mb4';
        } else {
            $dsn = 'pgsql:host=' . FS_DB_HOST . ';port=' . FS_DB_PORT . ';dbname=' . FS_DB_NAME;
        }
        
        $pdo = new PDO($dsn, FS_DB_USER, FS_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        // Consultar usuario
        $stmt = $pdo->prepare("SELECT nick, log_key, admin, enabled FROM fs_users WHERE nick = :nick LIMIT 1");
        $stmt->execute(['nick' => $cookieUser]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Verificar que está habilitado, es admin y el logkey coincide
            $isEnabled = $user['enabled'] === true || $user['enabled'] === 't' || $user['enabled'] === 1 || $user['enabled'] === '1';
            $isAdmin = $user['admin'] === true || $user['admin'] === 't' || $user['admin'] === 1 || $user['admin'] === '1';
            $logkeyMatch = $user['log_key'] === $cookieLogkey;
            
            if ($isEnabled && $isAdmin && $logkeyMatch) {
                return true;
            }
        }
    } catch (PDOException $e) {
        error_log('Updater: Error conectando a la base de datos: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log('Updater: Error verificando auth: ' . $e->getMessage());
    }
    
    return false;
}

// Verificar que el usuario está logueado y es administrador
if (!check_admin_auth()) {
    // No autenticado o no es admin
    header('HTTP/1.1 403 Forbidden');
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Acceso Denegado - FSFramework</title>
        <link rel="stylesheet" href="view/css/bootstrap.min.css">
        <link rel="stylesheet" href="themes/AdminLTE/css/AdminLTE.min.css">
        <link rel="stylesheet" href="view/css/font-awesome.min.css">
    </head>
    <body class="hold-transition skin-blue" style="background: #ecf0f5;">
        <div class="container" style="margin-top: 50px;">
            <div class="row">
                <div class="col-md-6 col-md-offset-3">
                    <div class="box box-danger">
                        <div class="box-header with-border">
                            <h3 class="box-title"><i class="fa fa-lock"></i> Acceso Denegado</h3>
                        </div>
                        <div class="box-body">
                            <div class="alert alert-danger">
                                <h4><i class="fa fa-ban"></i> No autorizado</h4>
                                <p>Necesitas iniciar sesión como <strong>administrador</strong> para acceder al actualizador.</p>
                            </div>
                            <p>Por favor, inicia sesión en el panel de control y vuelve a intentarlo.</p>
                        </div>
                        <div class="box-footer">
                            <a href="index.php" class="btn btn-primary">
                                <i class="fa fa-sign-in"></i> Ir al Panel de Control
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Ampliar límite de ejecución a 5 minutos
@set_time_limit(300);
ignore_user_abort(true);

// Redirigir al módulo de actualización
require_once __DIR__ . '/update-and-backup/index.php';