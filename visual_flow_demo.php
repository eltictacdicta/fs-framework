<?php
/**
 * Demostración Visual del Flujo de Instalación con Sistema de Temas
 * 
 * Este script muestra visualmente cómo funciona el sistema de temas
 * durante y después de la instalación.
 */

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║         FLUJO DE INSTALACIÓN CON SISTEMA DE TEMAS               ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// Simulación del flujo
$steps = [
    [
        'title' => '1. USUARIO ACCEDE A install.php',
        'file' => 'install.php',
        'action' => 'Detecta tema AdminLTE',
        'code' => '$theme_available = file_exists(__DIR__ . \'/plugins/AdminLTE\');',
        'result' => 'AdminLTE detectado ✓'
    ],
    [
        'title' => '2. CARGA DE RECURSOS',
        'file' => 'install.php (HEAD)',
        'action' => 'Carga CSS/JS condicionalmente',
        'code' => 'if (file_exists(\'plugins/AdminLTE/view/css/AdminLTE.min.css\')) { ... }',
        'result' => 'Recursos cargados ✓'
    ],
    [
        'title' => '3. INTERFAZ DE INSTALACIÓN',
        'file' => 'install.php (BODY)',
        'action' => 'Muestra alerta informativa',
        'code' => '<div class="alert alert-info">Tema AdminLTE detectado...</div>',
        'result' => 'Usuario informado ✓'
    ],
    [
        'title' => '4. USUARIO COMPLETA FORMULARIO',
        'file' => 'install.php (POST)',
        'action' => 'Procesa datos de BD',
        'code' => 'test_mysql($errors, $errors2);',
        'result' => 'Conexión exitosa ✓'
    ],
    [
        'title' => '5. GENERACIÓN DE config.php',
        'file' => 'install.php::guarda_config()',
        'action' => 'Escribe FS_DEFAULT_THEME',
        'code' => 'fwrite($archivo, "define(\'FS_DEFAULT_THEME\', \'AdminLTE\');\\n");',
        'result' => 'config.php creado ✓'
    ],
    [
        'title' => '6. REDIRECCIÓN A index.php',
        'file' => 'index.php',
        'action' => 'Carga base/config2.php',
        'code' => 'require_once \'base/config2.php\';',
        'result' => 'Sistema iniciado ✓'
    ],
    [
        'title' => '7. AUTO-ACTIVACIÓN DEL TEMA',
        'file' => 'base/config2.php',
        'action' => 'Activa AdminLTE automáticamente',
        'code' => 'if (empty($GLOBALS[\'plugins\'])) { $GLOBALS[\'plugins\'][] = \'AdminLTE\'; }',
        'result' => 'AdminLTE activado ✓'
    ],
    [
        'title' => '8. CARGA DE FUNCIONES',
        'file' => 'base/config2.php',
        'action' => 'Carga functions.php del tema',
        'code' => 'require_once \'plugins/AdminLTE/functions.php\';',
        'result' => 'Funciones cargadas ✓'
    ],
    [
        'title' => '9. SISTEMA DE PLANTILLAS',
        'file' => 'raintpl/rain.tpl.class.php',
        'action' => 'Busca vistas en plugins',
        'code' => 'foreach ($GLOBALS[\'plugins\'] as $plugin_dir) { ... }',
        'result' => 'Vistas de AdminLTE ✓'
    ],
    [
        'title' => '10. RENDERIZADO FINAL',
        'file' => 'index.php',
        'action' => 'Muestra interfaz con AdminLTE',
        'code' => '$tpl->draw($fsc->template);',
        'result' => '🎨 Interfaz moderna ✓'
    ]
];

foreach ($steps as $i => $step) {
    echo "\n";
    echo "┌─────────────────────────────────────────────────────────────────┐\n";
    echo "│ " . str_pad($step['title'], 63) . " │\n";
    echo "├─────────────────────────────────────────────────────────────────┤\n";
    echo "│ 📁 Archivo: " . str_pad($step['file'], 50) . " │\n";
    echo "│ ⚙️  Acción: " . str_pad($step['action'], 50) . " │\n";
    echo "├─────────────────────────────────────────────────────────────────┤\n";
    
    // Wrap code if too long
    $code = $step['code'];
    if (strlen($code) > 60) {
        $code = substr($code, 0, 57) . '...';
    }
    echo "│ 💻 Código: " . str_pad($code, 51) . " │\n";
    
    echo "├─────────────────────────────────────────────────────────────────┤\n";
    echo "│ ✅ " . str_pad($step['result'], 60) . " │\n";
    echo "└─────────────────────────────────────────────────────────────────┘\n";
    
    usleep(100000); // Pausa visual de 0.1s
}

echo "\n\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║                    VERIFICACIÓN DEL SISTEMA                      ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// Verificaciones reales
$checks = [];

// 1. AdminLTE existe
$checks['AdminLTE presente'] = file_exists('plugins/AdminLTE');

// 2. Archivos clave
$checks['functions.php'] = file_exists('plugins/AdminLTE/functions.php');
$checks['fsframework.ini'] = file_exists('plugins/AdminLTE/fsframework.ini');
$checks['header.html'] = file_exists('plugins/AdminLTE/view/header.html');
$checks['footer.html'] = file_exists('plugins/AdminLTE/view/footer.html');

// 3. CSS de AdminLTE
$checks['AdminLTE.min.css'] = file_exists('plugins/AdminLTE/view/css/AdminLTE.min.css');
$checks['skins/_all-skins.min.css'] = file_exists('plugins/AdminLTE/view/css/skins/_all-skins.min.css');

// 4. JS de AdminLTE
$checks['app.min.js'] = file_exists('plugins/AdminLTE/view/js/app.min.js');
$checks['jquery.slimscroll.min.js'] = file_exists('plugins/AdminLTE/view/js/jquery.slimscroll.min.js');

// 5. Archivos del sistema
$checks['install.php adaptado'] = (
    file_exists('install.php') && 
    strpos(file_get_contents('install.php'), '$theme_available') !== false
);
$checks['config2.php con auto-activación'] = (
    file_exists('base/config2.php') && 
    strpos(file_get_contents('base/config2.php'), 'SISTEMA DE TEMAS') !== false
);
$checks['RainTPL busca en plugins'] = (
    file_exists('raintpl/rain.tpl.class.php') && 
    strpos(file_get_contents('raintpl/rain.tpl.class.php'), '$GLOBALS[\'plugins\']') !== false
);

// Mostrar resultados
$max_length = max(array_map('strlen', array_keys($checks)));
foreach ($checks as $name => $status) {
    $icon = $status ? '✅' : '❌';
    $status_text = $status ? 'OK' : 'FALTA';
    echo $icon . ' ' . str_pad($name, $max_length + 2) . ' ' . $status_text . "\n";
}

// Resumen
$total = count($checks);
$passed = count(array_filter($checks));
$percentage = round(($passed / $total) * 100, 1);

echo "\n";
echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│                         RESUMEN FINAL                            │\n";
echo "├─────────────────────────────────────────────────────────────────┤\n";
echo "│ Checks totales:    " . str_pad($total, 44) . " │\n";
echo "│ Checks pasados:    " . str_pad($passed, 44) . " │\n";
echo "│ Checks fallidos:   " . str_pad($total - $passed, 44) . " │\n";
echo "│ Porcentaje:        " . str_pad($percentage . '%', 44) . " │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n";

if ($percentage == 100) {
    echo "\n🎉 ¡SISTEMA COMPLETAMENTE FUNCIONAL! 🎉\n\n";
    exit(0);
} elseif ($percentage >= 80) {
    echo "\n⚠️  Sistema mayormente funcional, pero con algunos problemas.\n\n";
    exit(1);
} else {
    echo "\n❌ Sistema con problemas significativos. Revisar componentes faltantes.\n\n";
    exit(2);
}

