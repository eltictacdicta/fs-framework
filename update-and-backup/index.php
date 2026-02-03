<?php
/**
 * Módulo de Actualización y Backup para FSFramework
 * 
 * Este módulo maneja:
 * - Actualizaciones del núcleo de FSFramework
 * - Actualizaciones de los plugins por defecto
 * - Copias de seguridad (backups) de archivos y base de datos
 * - Auto-actualización del propio actualizador
 * 
 * SEGURIDAD: Este archivo SOLO debe ser accedido a través de updater.php
 * El acceso directo está bloqueado.
 * 
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 * @version 1.0.0
 */

// Bloquear acceso directo - solo permitir si viene de updater.php
if (!defined('FS_FOLDER')) {
    // Acceso directo detectado - bloquear
    header('HTTP/1.1 403 Forbidden');
    ?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Acceso Denegado - FSFramework</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background: #f5f5f5;
                margin: 0;
                padding: 20px;
            }

            .container {
                max-width: 500px;
                margin: 50px auto;
                background: #fff;
                border-radius: 4px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                padding: 30px;
            }

            h1 {
                color: #d9534f;
                margin-top: 0;
            }

            .alert {
                background: #f2dede;
                border: 1px solid #ebccd1;
                color: #a94442;
                padding: 15px;
                border-radius: 4px;
                margin-bottom: 20px;
            }

            .btn {
                display: inline-block;
                padding: 10px 20px;
                background: #337ab7;
                color: #fff;
                text-decoration: none;
                border-radius: 4px;
            }

            .btn:hover {
                background: #286090;
            }
        </style>
    </head>

    <body>
        <div class="container">
            <h1>🔒 Acceso Denegado</h1>
            <div class="alert">
                <strong>Acceso directo no permitido.</strong><br>
                Este módulo solo puede ser accedido a través del actualizador principal.
            </div>
            <p>Para acceder al actualizador, utiliza la ruta correcta:</p>
            <a href="../updater.php" class="btn">Ir al Actualizador</a>
        </div>
    </body>

    </html>
    <?php
    exit;
}

// La verificación de sesión ya se hizo en updater.php
// Aquí solo procesamos la lógica

// Cargar el controlador del actualizador (local, sin dependencias externas)
require_once __DIR__ . '/UpdaterController.php';

// Instanciar el controlador y ejecutar
$controller = new UpdaterController();
$controller->handle();
