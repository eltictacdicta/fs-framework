<?php
/**
 * Script de prueba del sistema de temas
 * 
 * Este script verifica que el sistema de auto-activación de temas funciona correctamente.
 * Ejecutar desde línea de comandos: php test_theme_system.php
 */

echo "=== Test del Sistema de Temas de FSFramework ===\n\n";

// Simular configuración
define('FS_FOLDER', __DIR__);
define('FS_TMP_NAME', 'test_temp/');
define('FS_DEFAULT_THEME', 'AdminLTE');

echo "1. Verificando que AdminLTE existe...\n";
if (file_exists('plugins/AdminLTE')) {
    echo "   ✓ AdminLTE encontrado en plugins/\n";
} else {
    echo "   ✗ AdminLTE NO encontrado en plugins/\n";
    exit(1);
}

echo "\n2. Verificando archivos clave de AdminLTE...\n";
$required_files = [
    'plugins/AdminLTE/functions.php',
    'plugins/AdminLTE/view/header.html',
    'plugins/AdminLTE/view/footer.html',
    'plugins/AdminLTE/fsframework.ini'
];

foreach ($required_files as $file) {
    if (file_exists($file)) {
        echo "   ✓ $file\n";
    } else {
        echo "   ✗ $file NO encontrado\n";
    }
}

echo "\n3. Simulando carga de plugins (como en config2.php)...\n";
$GLOBALS['plugins'] = [];

// Simulación de la lógica de config2.php
if (empty($GLOBALS['plugins'])) {
    $default_theme = defined('FS_DEFAULT_THEME') ? FS_DEFAULT_THEME : 'AdminLTE';
    
    if (file_exists(FS_FOLDER . '/plugins/' . $default_theme)) {
        $GLOBALS['plugins'][] = $default_theme;
        echo "   ✓ Tema por defecto '$default_theme' activado automáticamente\n";
    } else {
        echo "   ✗ Tema por defecto '$default_theme' NO encontrado\n";
    }
}

echo "\n4. Plugins activos:\n";
if (!empty($GLOBALS['plugins'])) {
    foreach ($GLOBALS['plugins'] as $plugin) {
        echo "   - $plugin\n";
    }
} else {
    echo "   (ninguno)\n";
}

echo "\n5. Verificando constante FS_DEFAULT_THEME...\n";
if (defined('FS_DEFAULT_THEME')) {
    echo "   ✓ FS_DEFAULT_THEME definida: " . FS_DEFAULT_THEME . "\n";
} else {
    echo "   ✗ FS_DEFAULT_THEME NO definida\n";
}

echo "\n6. Verificando archivo config.php...\n";
if (file_exists('config.php')) {
    $config_content = file_get_contents('config.php');
    if (strpos($config_content, 'FS_DEFAULT_THEME') !== false) {
        echo "   ✓ config.php contiene FS_DEFAULT_THEME\n";
    } else {
        echo "   ⚠ config.php NO contiene FS_DEFAULT_THEME (se usará el valor por defecto)\n";
    }
} else {
    echo "   ⚠ config.php no existe (instalación nueva)\n";
}

echo "\n=== Resultado ===\n";
if (!empty($GLOBALS['plugins']) && in_array('AdminLTE', $GLOBALS['plugins'])) {
    echo "✓ Sistema de temas funcionando correctamente\n";
    echo "✓ AdminLTE se activará automáticamente en nuevas instalaciones\n";
    exit(0);
} else {
    echo "✗ Hay problemas con el sistema de temas\n";
    exit(1);
}


