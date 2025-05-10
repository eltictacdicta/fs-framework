<?php
/**
 * Funciones del plugin business_data
 *
 * Este plugin contiene las funcionalidades relacionadas con datos empresariales
 * que se han extraído del core del framework para hacerlo más ligero.
 *
 * Incluye:
 * - Empresas
 * - Países
 * - Series
 * - Almacenes
 * - Agentes
 */

// Cargar los modelos del plugin
if (!class_exists('empresa')) {
    require_once __DIR__ . '/Model/empresa.php';
}

if (!class_exists('pais')) {
    require_once __DIR__ . '/Model/pais.php';
}

if (!class_exists('serie')) {
    require_once __DIR__ . '/Model/serie.php';
}

if (!class_exists('almacen')) {
    require_once __DIR__ . '/Model/almacen.php';
}

if (!class_exists('agente')) {
    require_once __DIR__ . '/Model/agente.php';
}

// No cargamos los controladores aquí, se cargarán automáticamente cuando se necesiten
// a través del sistema de autoload de FacturaScripts

/**
 * Función para registrar el plugin en el sistema
 */
function register_business_data() {
    // Esta función se llama automáticamente cuando se activa el plugin
    // No necesita hacer nada, ya que los modelos se registran automáticamente
}

/**
 * Función que se ejecuta cuando se activa el plugin
 */
function enable_business_data() {
    // Asegurarse de que fs_model está cargado
    if (!class_exists('fs_model')) {
        require_once 'base/fs_model.php';
    }

    // Asegurarse de que fs_page está cargado
    if (!class_exists('fs_page')) {
        require_once 'model/fs_page.php';
    }

    // Registrar la página admin_empresa en el menú
    $fsPage = new fs_page();
    $fsPage->name = 'admin_empresa';
    $fsPage->title = 'Empresa / web';
    $fsPage->folder = 'admin';
    $fsPage->show_on_menu = true;
    $fsPage->save();
    
    // Registrar la página business_data en el menú
    $fsPage = new fs_page();
    $fsPage->name = 'business_data';
    $fsPage->title = 'Datos Empresariales';
    $fsPage->folder = 'admin';
    $fsPage->show_on_menu = true;
    $fsPage->save();
}

/**
 * Función que se ejecuta cuando se desactiva el plugin
 */
function disable_business_data() {
    // Asegurarse de que fs_model está cargado
    if (!class_exists('fs_model')) {
        require_once 'base/fs_model.php';
    }

    // Asegurarse de que fs_page está cargado
    if (!class_exists('fs_page')) {
        require_once 'model/fs_page.php';
    }

    // Eliminar las páginas del menú
    $fsPage = new fs_page();
    
    $page = $fsPage->get('admin_empresa');
    if ($page) {
        $page->delete();
    }
    
    $page = $fsPage->get('business_data');
    if ($page) {
        $page->delete();
    }
}
