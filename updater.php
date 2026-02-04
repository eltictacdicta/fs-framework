<?php
/**
 * Actualizador de FSFramework - Redirect
 * 
 * Este archivo redirige al plugin system_updater que contiene
 * toda la funcionalidad de actualizaciones y backups.
 * 
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 * @deprecated Usar index.php?page=admin_updater
 */

// Verificar que existe config.php (sistema instalado)
if (!file_exists(__DIR__ . '/config.php')) {
    die('Archivo config.php no encontrado. No puedes actualizar sin instalar.');
}

// Redirigir al nuevo controlador del plugin system_updater
header('Location: index.php?page=admin_updater');
exit;