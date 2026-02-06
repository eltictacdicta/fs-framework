<?php
/**
 * Actualizador de FSFramework - Redirect / Auto-installer
 * 
 * Si el plugin system_updater está instalado, redirige a su controlador.
 * Si no está instalado, redirige al panel de control para descargarlo
 * automáticamente desde GitHub e instalarlo.
 * 
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 */

// Verificar que existe config.php (sistema instalado)
if (!file_exists(__DIR__ . '/config.php')) {
    die('Archivo config.php no encontrado. No puedes actualizar sin instalar.');
}

// Verificar si el plugin system_updater está instalado
if (file_exists(__DIR__ . '/plugins/system_updater/controller/admin_updater.php')) {
    // El plugin existe, redirigir directamente al actualizador
    header('Location: index.php?page=admin_updater');
    exit;
}

// El plugin no existe, redirigir al panel de control para instalación automática
// La descarga se hace desde admin_home (contexto autenticado) para seguridad
header('Location: index.php?page=admin_home&install_system_updater=1');
exit;