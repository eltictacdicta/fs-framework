<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2013-2022 Carlos Garcia Gomez <neorazorx@gmail.com>
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
error_reporting(E_ALL);
date_default_timezone_set('Europe/Madrid');
define('FS_COMMUNITY_URL', 'https://github.com/eltictacdicta/fs-framework');

$errors = [];
$errors2 = [];
$db_type = 'MYSQL';
$db_host = 'localhost';
$db_port = '3306';
$db_name = 'fsframework';
$db_user = '';

// Verificar que el tema por defecto existe
$default_theme = 'AdminLTE';
$theme_available = file_exists(__DIR__ . '/plugins/' . $default_theme);

function guarda_config(&$errors, $nombre_archivo = 'config.php')
{
    $archivo = fopen(__DIR__ . '/' . $nombre_archivo, "w");
    if ($archivo) {
        fwrite($archivo, "<?php\n");
        fwrite($archivo, "/**\n");
        fwrite($archivo, " * Configuración de FSFramework\n");
        fwrite($archivo, " * Generado automáticamente el " . date('Y-m-d H:i:s') . "\n");
        fwrite($archivo, " */\n\n");

        // Configuración de base de datos
        fwrite($archivo, "// Configuración de base de datos\n");
        $fields = ['DB_TYPE', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'CACHE_HOST', 'CACHE_PORT', 'CACHE_PREFIX'];
        foreach ($fields as $name) {
            fwrite($archivo, "define('FS_" . $name . "', '" . filter_input(INPUT_POST, strtolower($name)) . "');\n");
        }

        if (filter_input(INPUT_POST, 'db_type') == 'MYSQL' && filter_input(INPUT_POST, 'mysql_socket') != '') {
            fwrite($archivo, "ini_set('mysqli.default_socket', '" . filter_input(INPUT_POST, 'mysql_socket') . "');\n");
        }

        fwrite($archivo, "\n// Configuración general\n");
        fwrite($archivo, "define('FS_TMP_NAME', '" . random_string(20) . "/');\n");
        fwrite($archivo, "define('FS_COOKIES_EXPIRE', 604800);\n");
        fwrite($archivo, "define('FS_ITEM_LIMIT', 50);\n");
        
        // Sistema de temas: Definir tema por defecto
        fwrite($archivo, "\n// Sistema de temas\n");
        fwrite($archivo, "// El tema por defecto se activa automáticamente en config2.php\n");
        fwrite($archivo, "// Si el tema no existe, el sistema usará las vistas del core\n");
        
        global $default_theme, $theme_available;
        if ($theme_available) {
            fwrite($archivo, "define('FS_DEFAULT_THEME', '" . $default_theme . "');\n");
        } else {
            fwrite($archivo, "// define('FS_DEFAULT_THEME', 'AdminLTE'); // Tema no encontrado, usando vistas del core\n");
        }

        $fieldsFalse = ['DB_HISTORY', 'DEMO', 'DISABLE_MOD_PLUGINS', 'DISABLE_ADD_PLUGINS', 'DISABLE_RM_PLUGINS'];
        foreach ($fieldsFalse as $name) {
            fwrite($archivo, "define('FS_" . $name . "', FALSE);\n");
        }

        if (filter_input(INPUT_POST, 'proxy_type')) {
            fwrite($archivo, "define('FS_PROXY_TYPE', '" . filter_input(INPUT_POST, 'proxy_type') . "');\n");
            fwrite($archivo, "define('FS_PROXY_HOST', '" . filter_input(INPUT_POST, 'proxy_host') . "');\n");
            fwrite($archivo, "define('FS_PROXY_PORT', '" . filter_input(INPUT_POST, 'proxy_port') . "');\n");
        }

        fclose($archivo);

        header("Location: index.php");
        exit();
    }

    $errors[] = "permisos";
}

function test_mysql(&$errors, &$errors2)
{
    if (!class_exists('mysqli')) {
        $errors[] = "db_mysql";
        $errors2[] = 'No tienes instalada la extensión de PHP para MySQL.';
        return;
    }

    if (filter_input(INPUT_POST, 'mysql_socket') != '') {
        ini_set('mysqli.default_socket', filter_input(INPUT_POST, 'mysql_socket'));
    }

    // Omitimos el valor del nombre de la BD porque lo comprobaremos más tarde
    $connection = @new mysqli(
        filter_input(INPUT_POST, 'db_host'), filter_input(INPUT_POST, 'db_user'), filter_input(INPUT_POST, 'db_pass'), '', intval(filter_input(INPUT_POST, 'db_port'))
    );
    if ($connection->connect_error) {
        $errors[] = "db_mysql";
        $errors2[] = $connection->connect_error;
        return;
    }

    // Verificamos si la base de datos existe
    $db_name = filter_input(INPUT_POST, 'db_name', FILTER_SANITIZE_SPECIAL_CHARS);
    
    // Comprobamos que el nombre de la base de datos sea válido
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $db_name)) {
        $errors[] = "db_mysql";
        $errors2[] = "Nombre de base de datos inválido. Solo se permiten letras, números y guiones bajos.";
        return;
    }
    
    // Consulta para verificar si la base de datos existe
    $query = "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?";
    $stmt = mysqli_prepare($connection, $query);
    mysqli_stmt_bind_param($stmt, "s", $db_name);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    
    if (mysqli_stmt_num_rows($stmt) == 0) {
        // La base de datos no existe, intentamos crearla
        mysqli_stmt_close($stmt);
        
        // Creamos la base de datos usando consulta preparada
        $query = "CREATE DATABASE `" . str_replace('`', '', $db_name) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        if (!mysqli_query($connection, $query)) {
            $errors[] = "db_mysql";
            $errors2[] = "Error al crear la base de datos: " . mysqli_error($connection);
            $errors2[] = "Por favor, crea manualmente la base de datos '" . htmlspecialchars($db_name) . "' o proporciona un usuario con privilegios para crear bases de datos.";
            $errors2[] = "Comando SQL: CREATE DATABASE `" . htmlspecialchars($db_name) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
            return;
        }
    } else {
        mysqli_stmt_close($stmt);
    }

    // Seleccionamos la base de datos
    if (!mysqli_select_db($connection, $db_name)) {
        $errors[] = "db_mysql";
        $errors2[] = "Error al seleccionar la base de datos: " . mysqli_error($connection);
        $errors2[] = "Verifica que el usuario tenga permisos sobre la base de datos '" . htmlspecialchars($db_name) . "'.";
        return;
    }
    
    guarda_config($errors);

    $errors[] = "db_mysql";
    $errors2[] = mysqli_error($connection);
}

function test_postgresql(&$errors, &$errors2)
{
    if (!function_exists('pg_connect')) {
        $errors[] = "db_postgresql";
        $errors2[] = 'No tienes instalada la extensión de PHP para PostgreSQL.';
        return;
    }

    // Sanitizamos y validamos los datos de entrada
    $db_host = filter_input(INPUT_POST, 'db_host', FILTER_SANITIZE_SPECIAL_CHARS);
    $db_port = filter_input(INPUT_POST, 'db_port', FILTER_SANITIZE_NUMBER_INT);
    $db_user = filter_input(INPUT_POST, 'db_user', FILTER_SANITIZE_SPECIAL_CHARS);
    $db_pass = filter_input(INPUT_POST, 'db_pass');
    $db_name = filter_input(INPUT_POST, 'db_name', FILTER_SANITIZE_SPECIAL_CHARS);
    
    // Validamos el nombre de la base de datos
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $db_name)) {
        $errors[] = "db_postgresql";
        $errors2[] = "Nombre de base de datos inválido. Solo se permiten letras, números y guiones bajos.";
        return;
    }

    $connection = @pg_connect('host=' . $db_host . ' port=' . $db_port . ' user=' . $db_user . ' password=' . $db_pass);

    if (!$connection) {
        $errors[] = "db_postgresql";
        $errors2[] = 'No se puede conectar a la base de datos. Revisa los datos de usuario y contraseña.';
        return;
    }

    // Comprobamos que la BD exista, de lo contrario la creamos
    $connection2 = @pg_connect('host=' . $db_host . ' port=' . $db_port . ' dbname=' . $db_name . ' user=' . $db_user . ' password=' . $db_pass);

    if ($connection2) {
        guarda_config($errors);
        return;
    }

    // Creamos la base de datos de forma segura
    $db_name_escaped = pg_escape_string($connection, $db_name);
    $sqlCrearBD = 'CREATE DATABASE "' . $db_name_escaped . '";';
    if (pg_query($connection, $sqlCrearBD)) {
        guarda_config($errors);
        return;
    }

    $errors[] = "db_postgresql";
    $errors2[] = 'Error al crear la base de datos.';
}

function random_string($length = 20)
{
    return substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, $length);
}
/**
 * Buscamos errores
 */
if (file_exists('config.php')) {
    header('Location: index.php');
} else if (floatval(substr(phpversion(), 0, 3)) < 5.6) {
    $errors[] = 'php';
} else if (floatval('3,1') >= floatval('3.1')) {
    $errors[] = "floatval";
    $errors2[] = 'El separador de decimales de esta versión de PHP no es el punto,'
        . ' como sucede en las instalaciones estándar. Debes corregirlo.';
} else if (!function_exists('mb_substr')) {
    $errors[] = "mb_substr";
} else if (!extension_loaded('simplexml')) {
    $errors[] = "simplexml";
    $errors2[] = 'No se encuentra la extensión simplexml en tu instalación de PHP.'
        . ' Debes instalarla o activarla.';
    $errors2[] = 'Linux: instala el paquete <b>php-xml</b> y reinicia el Apache.';
} else if (!extension_loaded('openssl')) {
    $errors[] = "openssl";
} else if (!extension_loaded('zip')) {
    $errors[] = "ziparchive";
} else if (!is_writable(__DIR__)) {
    $errors[] = "permisos";
} else if (filter_input(INPUT_POST, 'db_type')) {
    if (filter_input(INPUT_POST, 'db_type') == 'MYSQL') {
        test_mysql($errors, $errors2);
    } else if (filter_input(INPUT_POST, 'db_type') == 'POSTGRESQL') {
        test_postgresql($errors, $errors2);
    }

    $db_type = filter_input(INPUT_POST, 'db_type');
    $db_host = filter_input(INPUT_POST, 'db_host');
    $db_port = filter_input(INPUT_POST, 'db_port');
    $db_name = filter_input(INPUT_POST, 'db_name');
    $db_user = filter_input(INPUT_POST, 'db_user');
}

$system_info = 'facturascripts: ' . file_get_contents('VERSION') . "\n";
$system_info .= 'os: ' . php_uname() . "\n";
$system_info .= 'php: ' . phpversion() . "\n";

if (isset($_SERVER['REQUEST_URI'])) {
    $system_info .= 'url: ' . $_SERVER['REQUEST_URI'] . "\n------";
}
foreach ($errors as $e) {
    $system_info .= "\n" . $e;
}

$system_info = str_replace('"', "'", $system_info);

?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="es" xml:lang="es" >
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <title>FSFramework - Instalador</title>
        <meta name="description" content="FSFramework es un software de facturación y contabilidad para pymes. Es software libre bajo licencia GNU/LGPL." />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex" />
        <link rel="shortcut icon" href="view/img/favicon.ico" />
        <!-- Bootstrap y estilos core -->
        <link rel="stylesheet" href="view/css/bootstrap-yeti.min.css" />
        <link rel="stylesheet" href="view/css/font-awesome.min.css" />
        <?php
        // Cargar estilos de AdminLTE si está disponible (tema por defecto)
        if (file_exists('plugins/AdminLTE/view/css/AdminLTE.min.css')) {
            echo '<link rel="stylesheet" href="plugins/AdminLTE/view/css/AdminLTE.min.css" />' . "\n        ";
        }
        if (file_exists('plugins/AdminLTE/view/css/skins/_all-skins.min.css')) {
            echo '<link rel="stylesheet" href="plugins/AdminLTE/view/css/skins/_all-skins.min.css" />' . "\n        ";
        }
        ?>
        <link rel="stylesheet" href="view/css/datepicker.css" />
        <link rel="stylesheet" href="view/css/custom.css" />
        <?php
        // Estilos adicionales de AdminLTE
        if (file_exists('plugins/AdminLTE/view/css/estilo.css')) {
            echo '<link rel="stylesheet" href="plugins/AdminLTE/view/css/estilo.css" />' . "\n        ";
        }
        ?>
        <!-- Scripts JavaScript -->
        <script type="text/javascript" src="view/js/jquery.min.js"></script>
        <script type="text/javascript" src="view/js/bootstrap.min.js"></script>
        <script type="text/javascript" src="view/js/bootstrap-datepicker.js" charset="UTF-8"></script>
        <script type="text/javascript" src="view/js/jquery.autocomplete.min.js"></script>
        <?php
        // Scripts de AdminLTE si están disponibles
        if (file_exists('plugins/AdminLTE/view/js/jquery.slimscroll.min.js')) {
            echo '<script type="text/javascript" src="plugins/AdminLTE/view/js/jquery.slimscroll.min.js"></script>' . "\n        ";
        }
        if (file_exists('plugins/AdminLTE/view/js/app.min.js')) {
            echo '<script type="text/javascript" src="plugins/AdminLTE/view/js/app.min.js"></script>' . "\n        ";
        }
        ?>
        <script type="text/javascript" src="view/js/base.js"></script>
        <script type="text/javascript" src="view/js/jquery.validate.min.js"></script>
    </head>
    <?php if ($theme_available) { ?>
    <!-- Estructura AdminLTE para instalador -->
    <body class="hold-transition skin-blue layout-top-nav">
        <div class="wrapper">
            <header class="main-header">
                <nav class="navbar navbar-static-top">
                    <div class="container">
                        <div class="navbar-header">
                            <a href="index.php" class="navbar-brand">
                                <b>FS</b>Framework <small>Instalador</small>
                            </a>
                        </div>
                        <!-- Menú de navegación deshabilitado - no necesario en instalador
                        <div class="collapse navbar-collapse pull-left" id="navbar-collapse">
                            <ul class="nav navbar-nav">
                                <li class="active"><a href="#"><i class="fa fa-cloud-upload"></i> Instalación</a></li>
                            </ul>
                        </div>
                        -->
                        <!-- Menú de ayuda deshabilitado temporalmente
                        <div class="navbar-custom-menu">
                            <ul class="nav navbar-nav">
                                <li class="dropdown">
                                    <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                                        <i class="fa fa-question-circle"></i>
                                        <span class="hidden-xs">Ayuda</span>
                                    </a>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <a href="<?php echo FS_COMMUNITY_URL; ?>/ayuda" rel="nofollow" target="_blank">
                                                <i class="fa fa-book"></i> Documentación
                                            </a>
                                        </li>
                                        <li>
                                            <a href="<?php echo FS_COMMUNITY_URL; ?>/contacto" rel="nofollow" target="_blank">
                                                <i class="fa fa-shield"></i> Soporte oficial
                                            </a>
                                        </li>
                                        <li class="divider"></li>
                                        <li>
                                            <a href="#" id="b_feedback">
                                                <i class="fa fa-edit"></i> Informar de error...
                                            </a>
                                        </li>
                                    </ul>
                                </li>
                            </ul>
                        </div>
                        -->
                    </div>
                </nav>
            </header>
            <div class="content-wrapper" style="min-height: 100vh; background-color: #ecf0f5;">
                <div class="container" style="padding-top: 20px;">
    <?php } else { ?>
    <!-- Estructura Bootstrap básica cuando no hay AdminLTE -->
    <body>
        <nav class="navbar navbar-default" role="navigation" style="margin: 0px;">
            <div class="container-fluid">
                <div class="navbar-header">
                    <a class="navbar-brand" href="index.php">FSFramework</a>
                </div>
                <!-- Menú de ayuda deshabilitado temporalmente
                <div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
                    <ul class="nav navbar-nav navbar-right">
                        <li>
                            <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                                <span class="hidden-xs">
                                    <i class="fa fa-question-circle fa-fw" aria-hidden="true"></i> Ayuda
                                </span>
                                <span class="visible-xs">Ayuda</span>
                            </a>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?php echo FS_COMMUNITY_URL; ?>/ayuda" rel="nofollow" target="_blank">
                                        <i class="fa fa-book fa-fw" aria-hidden="true"></i> Documentación
                                    </a>
                                </li>
                                <li>
                                    <a href="<?php echo FS_COMMUNITY_URL; ?>/contacto" rel="nofollow" target="_blank">
                                        <i class="fa fa-shield fa-fw" aria-hidden="true"></i> Soporte oficial
                                    </a>
                                </li>
                                <li class="divider"></li>
                                <li>
                                    <a href="#" id="b_feedback">
                                        <i class="fa fa-edit fa-fw" aria-hidden="true"></i> Informar de error...
                                    </a>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </div>
                -->
            </div>
        </nav>
        <div class="container">
    <?php } ?>
        <!-- Modal de feedback deshabilitado temporalmente (enlaces no disponibles)
        <form name="f_feedback" action="<?php echo FS_COMMUNITY_URL; ?>/feedback" method="post" target="_blank" class="form" role="form">
            <input type="hidden" name="feedback_info" value="<?php echo $system_info; ?>"/>
            <input type="hidden" name="feedback_type" value="error"/>
            <div class="modal" id="modal_feedback">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                            <h4 class="modal-title">
                                <i class="fa fa-edit" aria-hidden="true"></i> Informar de error...
                            </h4>
                            <p class="help-block">
                                Usa este formulario para informarnos de cualquier error o duda que hayas encontrado.
                                Para facilitarnos el trabajo este formulario también nos informa de la versión de
                                FSFramework que usas, versión de php, etc...
                            </p>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <textarea class="form-control" name="feedback_text" rows="6" placeholder="Detalla tu duda o problema..."></textarea>
                            </div>
                            <div class="form-group">
                                <div class="input-group">
                                    <span class="input-group-addon">
                                        <i class="fa fa-envelope" aria-hidden="true"></i>
                                    </span>
                                    <input type="email" class="form-control" name="feedback_email" placeholder="Introduce tu email"/>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="fa fa-send" aria-hidden="true"></i>&nbsp; Enviar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        -->
        <script type="text/javascript">
            function change_db_type() {
                if (document.f_configuracion_inicial.db_type.value == 'POSTGRESQL') {
                    document.f_configuracion_inicial.db_port.value = '5432';
                    if (document.f_configuracion_inicial.db_user.value == '')
                    {
                        document.f_configuracion_inicial.db_user.value = 'postgres';
                    }
                    $("#mysql_socket").hide();
                } else {
                    document.f_configuracion_inicial.db_port.value = '3306';
                    $("#mysql_socket").show();
                }
            }
            $(document).ready(function () {
                $("#f_configuracion_inicial").validate({
                    rules: {
                        db_type: {required: false},
                        db_host: {required: true, minlength: 2},
                        db_port: {required: true, minlength: 2},
                        db_name: {required: true, minlength: 2},
                        db_user: {required: true, minlength: 2},
                        db_pass: {required: false},
                        cache_host: {required: true, minlength: 2},
                        cache_port: {required: true, minlength: 2},
                        cache_prefix: {required: false, minlength: 2}
                    },
                    messages: {
                        db_host: {
                            required: "El campo es obligatorio.",
                            minlength: $.validator.format("Requiere mínimo {0} carácteres!")
                        },
                        db_port: {
                            required: "El campo es obligatorio.",
                            minlength: $.validator.format("Requiere mínimo {0} carácteres!")
                        },
                        db_name: {
                            required: "El campo es obligatorio.",
                            minlength: $.validator.format("Requiere mínimo {0} carácteres!")
                        },
                        db_user: {
                            required: "El campo es obligatorio.",
                            minlength: $.validator.format("Requiere mínimo {0} carácteres!")
                        },
                        cache_host: {
                            required: "El campo es obligatorio.",
                            minlength: $.validator.format("Requiere mínimo {0} carácteres!")
                        },
                        cache_port: {
                            required: "El campo es obligatorio.",
                            minlength: $.validator.format("Requiere mínimo {0} carácteres!")
                        }
                    }
                });
            });
        </script>
            <div class="row">
                <div class="col-sm-12">
                    <?php if ($theme_available) { ?>
                    <section class="content-header">
                        <h1>
                            <i class="fa fa-cloud-upload" aria-hidden="true"></i>
                            Instalador de FSFramework
                            <small><?php echo file_get_contents('VERSION'); ?></small>
                        </h1>
                        <ol class="breadcrumb">
                            <li><a href="#"><i class="fa fa-dashboard"></i> Inicio</a></li>
                            <li class="active">Instalación</li>
                        </ol>
                    </section>
                    <?php } else { ?>
                    <div class="page-header">
                        <h1>
                            <i class="fa fa-cloud-upload" aria-hidden="true"></i>
                            Bienvenido al instalador de FSFramework
                            <small><?php echo file_get_contents('VERSION'); ?></small>
                        </h1>
                    </div>
                    <?php } ?>
                </div>
            </div>
            <?php
            // Mostrar información sobre el tema que se instalará
            if ($theme_available) {
                echo '<div class="row">' . "\n";
                echo '    <div class="col-sm-12">' . "\n";
                echo '        <div class="alert alert-info">' . "\n";
                echo '            <i class="fa fa-paint-brush" aria-hidden="true"></i> ';
                echo '            <strong>Tema AdminLTE detectado:</strong> ';
                echo '            Se instalará automáticamente el tema <strong>' . $default_theme . '</strong> ';
                echo '            para proporcionar una interfaz moderna y profesional.' . "\n";
                echo '        </div>' . "\n";
                echo '    </div>' . "\n";
                echo '</div>' . "\n";
            } else {
                echo '<div class="row">' . "\n";
                echo '    <div class="col-sm-12">' . "\n";
                echo '        <div class="alert alert-warning">' . "\n";
                echo '            <i class="fa fa-exclamation-triangle" aria-hidden="true"></i> ';
                echo '            <strong>Tema no encontrado:</strong> ';
                echo '            No se encontró el tema <strong>' . $default_theme . '</strong>. ';
                echo '            El sistema usará las vistas básicas del core. ';
                echo '            Puedes instalar el tema más tarde desde el panel de administración.' . "\n";
                echo '        </div>' . "\n";
                echo '    </div>' . "\n";
                echo '</div>' . "\n";
            }
            ?>
            <div class="row">
                <div class="col-sm-12">
                    <?php
                    foreach ($errors as $err) {
                        if ($err == 'permisos') {

                            ?>
                            <div class="panel panel-danger">
                                <div class="panel-heading">
                                    Permisos de escritura:
                                </div>
                                <div class="panel-body">
                                    <p>
                                        La carpeta de FSFramework no tiene permisos de escritura.
                                        Estos permisos son necesarios para el sistema de plantillas,
                                        instalar plugins, actualizaciones, etc...
                                    </p>
                                    <h3>
                                        <i class="fa fa-linux" aria-hidden="true"></i> Linux
                                    </h3>
                                    <pre>sudo chmod -R o+w <?php echo dirname(__FILE__); ?></pre>
                                    <p class="help-block">
                                        Este comando soluciona el problema en el 95% de los casos, pero
                                        puedes optar por una solución más restrictiva, simplemente es necesario
                                        que Apache (o PHP) pueda leer y escribir en la carpeta.
                                    </p>
                                    <h3>
                                        <i class="fa fa-lock" aria-hidden="true"></i> Fedora / CentOS / Red Hat
                                    </h3>
                                    <p class="help-block">
                                        La configuración por defecto de estas distribuciones, en concreto SELinux,
                                        bloquea cualquier intento de comprobar si la carpeta tiene permisos de escritura.
                                        Desactiva o modifica la configuración de SELinux para el correcto funcionamiento
                                        de FSFramework.
                                    </p>
                                    <h3>
                                        <i class="fa fa-globe" aria-hidden="true"></i> Hosting
                                    </h3>
                                    <p class="help-block">
                                        Intenta dar permisos de escritura desde el cliente <b>FTP</b> o desde el <b>cPanel</b>.
                                    </p>
                                </div>
                            </div>
                            <?php
                        } else if ($err == 'php') {

                            ?>
                            <div class="panel panel-danger">
                                <div class="panel-heading">
                                    Versión de PHP obsoleta:
                                </div>
                                <div class="panel-body">
                                    <p>
                                        FSFramework necesita PHP <b>5.6</b> o superior.
                                        Tú estás usando la versión <b><?php echo phpversion() ?></b>.
                                    </p>
                                    <h3>Soluciones:</h3>
                                    <ul>
                                        <li>
                                            <p class="help-block">
                                                Muchos hostings ofrecen <b>varias versiones de PHP</b>. Ve al panel de control
                                                de tu hosting y selecciona la versión de PHP más alta.
                                            </p>
                                        </li>
                                        <li>
                                            <p class="help-block">
                                                Busca un proveedor de hosting más completo, que son la mayoría. Mira en nuestra sección de
                                                <a href="<?php echo FS_COMMUNITY_URL; ?>/descargar" rel="nofollow" target="_blank">Hostings recomendados</a>.
                                            </p>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <?php
                        } else if ($err == 'mb_substr') {

                            ?>
                            <div class="panel panel-danger">
                                <div class="panel-heading">
                                    No se encuentra la función mb_substr():
                                </div>
                                <div class="panel-body">
                                    <p>
                                        FSFramework necesita la extensión mbstring para poder trabajar con caracteres
                                        no europeos (chinos, coreanos, japonenes y rusos).
                                    </p>
                                    <h3>
                                        <i class="fa fa-linux" aria-hidden="true"></i> Linux
                                    </h3>
                                    <p class="help-block">
                                        Instala el paquete <b>php-mbstring</b> y reinicia el Apache.
                                    </p>
                                    <h3>
                                        <i class="fa fa-globe" aria-hidden="true"></i> Hosting
                                    </h3>
                                    <p class="help-block">
                                        Algunos proveedores de hosting ofrecen versiones de PHP demasiado recortadas.
                                        Es mejor que busques un proveedor de hosting más completo, que son la mayoría.
                                        Mira en nuestra sección de
                                        <a href="<?php echo FS_COMMUNITY_URL; ?>/descargar" rel="nofollow" target="_blank">Hostings recomendados</a>.
                                    </p>
                                </div>
                            </div>
                            <?php
                        } else if ($err == 'openssl') {

                            ?>
                            <div class="panel panel-danger">
                                <div class="panel-heading">
                                    No se encuentra la extensión OpenSSL:
                                </div>
                                <div class="panel-body">
                                    <p>
                                        FSFramework necesita la extensión OpenSSL para poder descargar plugins,
                                        actualizaciones y enviar emails.
                                    </p>
                                    <h3>
                                        <i class="fa fa-globe" aria-hidden="true"></i> Hosting
                                    </h3>
                                    <p class="help-block">
                                        Algunos proveedores de hosting ofrecen versiones de PHP demasiado recortadas.
                                        Es mejor que busques un proveedor de hosting más completo, que son la mayoría.
                                        Mira en nuestra sección de
                                        <a href="<?php echo FS_COMMUNITY_URL; ?>/descargar" rel="nofollow" target="_blank">Hostings recomendados</a>.
                                    </p>
                                    <h3>
                                        <i class="fa fa-windows" aria-hidden="true"></i> Windows
                                    </h3>
                                    <p class="help-block">
                                        Ofrecemos una versión de FSFramework para Windows <b>con todo</b> el software necesario
                                        (como OpenSSL) ya incluido de serie. Puedes encontrala en nuestra sección de
                                        <a href="<?php echo FS_COMMUNITY_URL; ?>/descargar" rel="nofollow" target="_blank">descargas</a>.
                                        Si decides utilizar <b>un empaquetado distinto</b>, y este no incluye lo necesario, deberás
                                        buscar ayuda en los foros o el soporte de los creadores de ese empaquetado.
                                    </p>
                                    <h3>
                                        <i class="fa fa-linux" aria-hidden="true"></i> Linux
                                    </h3>
                                    <p class="help-block">
                                        Es muy raro que una instalación propia de PHP en Linux no incluya OpenSSL.
                                        Intenta instalar el paquete <b>php-openssl</b> con tu gestor de paquetes
                                        y reinicia el Apache. Para más información consulta la ayuda o los foros
                                        de la distribución Linux que utilices.
                                    </p>
                                    <h3>
                                        <i class="fa fa-apple" aria-hidden="true"></i> Mac
                                    </h3>
                                    <p class="help-block">
                                        Es raro que un empaquetado Apache+PHP+MySQL para Mac no incluya OpenSSL.
                                        Nosotros ofrecemos varios empaquetados con todo lo necesario en nuestra sección de
                                        <a href="<?php echo FS_COMMUNITY_URL; ?>/descargar" rel="nofollow" target="_blank">descargas</a>.
                                    </p>
                                </div>
                            </div>
                            <?php
                        } else if ($err == 'ziparchive') {

                            ?>
                            <div class="panel panel-danger">
                                <div class="panel-heading">
                                    No se encuentra la extensión ZipArchive:
                                </div>
                                <div class="panel-body">
                                    <p>
                                        FSFramework necesita la extensión ZipArchive para poder
                                        descomprimir plugins y actualizaciones.
                                    </p>
                                    <h3>
                                        <i class="fa fa-linux" aria-hidden="true"></i> Linux
                                    </h3>
                                    <p class="help-block">Instala el paquete <b>php-zip</b> y reinicia el Apache.</p>
                                    <h3>
                                        <i class="fa fa-globe" aria-hidden="true"></i> Hosting
                                    </h3>
                                    <p class="help-block">
                                        Algunos proveedores de hosting ofrecen versiones de PHP demasiado recortadas.
                                        Es mejor que busques un proveedor de hosting más completo, que son la mayoría.
                                        Mira en nuestra sección de
                                        <a href="<?php echo FS_COMMUNITY_URL; ?>/descargar" rel="nofollow" target="_blank">Hostings recomendados</a>.
                                    </p>
                                </div>
                            </div>
                            <?php
                        } else if ($err == 'db_mysql') {

                            ?>
                            <div class="panel panel-danger">
                                <div class="panel-heading">
                                    Acceso a base de datos MySQL:
                                </div>
                                <div class="panel-body">
                                    <ul>
                                        <?php
                                        foreach ($errors2 as $err2)
                                            echo "<li>" . $err2 . "</li>";

                                        ?>
                                    </ul>
                                </div>
                            </div>
                            <?php
                        } else if ($err == 'db_postgresql') {

                            ?>
                            <div class="panel panel-danger">
                                <div class="panel-heading">
                                    Acceso a base de datos PostgreSQL:
                                </div>
                                <div class="panel-body">
                                    <ul>
                                        <?php
                                        foreach ($errors2 as $err2)
                                            echo "<li>" . $err2 . "</li>";

                                        ?>
                                    </ul>
                                </div>
                            </div>
                            <?php
                        } else {

                            ?>
                            <div class="panel panel-danger">
                                <div class="panel-heading">
                                    Error:
                                </div>
                                <div class="panel-body">
                                    <ul>
                                        <?php
                                        if (!empty($errors2)) {
                                            foreach ($errors2 as $err2) {
                                                echo "<li>" . $err2 . "</li>";
                                            }
                                        } else {
                                            echo "<li>Error desconocido.</li>";
                                        }

                                        ?>
                                    </ul>
                                </div>
                            </div>
                            <?php
                        }
                    }

                    ?>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-12">
                    <b>Antes de empezar...</b>
                    <p class="help-block">
                        Recuerda que tienes el <b>menú de ayuda</b> arriba a la derecha. Si encuentras cualquier problema,
                        haz clic en <b>informar de error...</b> y describe tu duda, sugerencia o el error que has encontrado.
                        No sabemos hacer software perfecto, pero con tu ayuda nos podemos acercar cada vez más ;-)
                        <br/><br/>
                        Y recuerda que tienes una sección especialmente dedicada a la <b>instalación</b> en nuestra
                        documentación oficial:
                    </p>
                    <a href="<?php echo FS_COMMUNITY_URL; ?>/ayuda" rel="nofollow" target="_blank" class="btn btn-sm btn-info">
                        <i class="fa fa-book"></i>&nbsp; Ayuda
                    </a>
                    <?php if ($theme_available) { ?>
                    <button type="button" class="btn btn-sm btn-default" data-toggle="modal" data-target="#modal_theme_info">
                        <i class="fa fa-paint-brush"></i>&nbsp; Info del Tema
                    </button>
                    <?php } ?>
                    <br/>
                    <br/>
                </div>
            </div>
            
            <!-- Modal con información del tema -->
            <?php if ($theme_available) { ?>
            <div class="modal fade" id="modal_theme_info" tabindex="-1" role="dialog">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">
                                <i class="fa fa-paint-brush"></i> Sistema de Temas - <?php echo $default_theme; ?>
                            </h4>
                        </div>
                        <div class="modal-body">
                            <p>
                                <strong>FSFramework</strong> incluye un sistema de temas basado en plugins que permite
                                personalizar completamente la interfaz de usuario.
                            </p>
                            <h5><i class="fa fa-check-circle"></i> Características de AdminLTE</h5>
                            <ul>
                                <li>Interfaz moderna y profesional</li>
                                <li>Menú lateral responsive</li>
                                <li>Múltiples skins de color</li>
                                <li>Iconos mejorados con Font Awesome</li>
                                <li>Optimizado para dispositivos móviles</li>
                            </ul>
                            <h5><i class="fa fa-cog"></i> Cómo funciona</h5>
                            <p class="help-block">
                                El tema se activa automáticamente después de la instalación mediante el sistema de plugins.
                                Las vistas del tema sobrescriben las vistas básicas del sistema, proporcionando una
                                experiencia visual mejorada sin modificar el código del core.
                            </p>
                            <p class="help-block">
                                Puedes cambiar de tema en cualquier momento desde el panel de administración, en la
                                sección de <strong>Plugins</strong>.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-sm btn-default" data-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>
            <form name="f_configuracion_inicial" id="f_configuracion_inicial" action="install.php" class="form" role="form" method="post">
                <div class="row">
                    <div class="col-sm-12">
                        <ul class="nav nav-tabs" role="tablist">
                            <li role="presentation" class="active">
                                <a href="#db" aria-controls="db" role="tab" data-toggle="tab">
                                    <i class="fa fa-database"></i>&nbsp;
                                    Base de datos
                                </a>
                            </li>
                            <li role="presentation">
                                <a href="#cache" aria-controls="cache" role="tab" data-toggle="tab">
                                    <i class="fa fa-wrench"></i>&nbsp;
                                    Avanzado
                                </a>
                            </li>
                            <li role="presentation">
                                <a href="#licencia" aria-controls="licencia" role="tab" data-toggle="tab">
                                    <i class="fa fa-file-text-o"></i>&nbsp;
                                    Licencia
                                </a>
                            </li>
                        </ul>
                        <br/>
                    </div>
                </div>
                <div class="tab-content">
                    <div role="tabpanel" class="tab-pane active" id="db">
                        <div class="row">
                            <div class="col-sm-4">
                                <div class="form-group">
                                    Tipo de servidor SQL:
                                    <select name="db_type" class="form-control" onchange="change_db_type()">
                                        <option value="MYSQL"<?php echo ($db_type == 'MYSQL') ? ' selected=""' : ''; ?>>MySQL</option>
                                        <option value="POSTGRESQL"<?php echo ($db_type == 'POSTGRESQL') ? ' selected=""' : ''; ?>>PostgreSQL</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group">
                                    Servidor:
                                    <input class="form-control" type="text" name="db_host" value="<?php echo $db_host; ?>" autocomplete="off"/>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group">
                                    Puerto:
                                    <input class="form-control" type="number" name="db_port" value="<?php echo $db_port; ?>" autocomplete="off"/>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4">
                                <div class="form-group">
                                    Nombre base de datos:
                                    <div class="input-group">
                                        <span class="input-group-addon">
                                            <i class="fa fa-database fa-fw"></i>
                                        </span>
                                        <input class="form-control" type="text" name="db_name" value="<?php echo $db_name; ?>" autocomplete="off"/>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group">
                                    Usuario:
                                    <div class="input-group">
                                        <span class="input-group-addon">
                                            <i class="fa fa-user fa-fw"></i>
                                        </span>
                                        <input class="form-control" type="text" name="db_user" value="<?php echo $db_user; ?>" autocomplete="off"/>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group">
                                    Contraseña:
                                    <div class="input-group">
                                        <span class="input-group-addon">
                                            <i class="fa fa-key fa-fw"></i>
                                        </span>
                                        <input class="form-control" type="password" name="db_pass" value="" autocomplete="off"/>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4">
                                <div id="mysql_socket" class="form-group">
                                    Socket:
                                    <input class="form-control" type="text" name="mysql_socket" value="" placeholder="opcional" autocomplete="off"/>
                                    <p class="help-block">
                                        Solamente en algunos hostings es necesario especificar el socket de MySQL.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div role="tabpanel" class="tab-pane" id="cache">
                        <div class="row">
                            <div class="col-sm-12">
                                <div class="panel panel-default">
                                    <div class="panel-heading">
                                        <h3 class="panel-title">Mencached</h3>
                                    </div>
                                    <div class="panel-body">
                                        <p class="help-block">
                                            Este apartado es totalmente <b>opcional</b>. Si tienes instalado memcached,
                                            puedes especificar aquí la ruta, puerto y prefijo a utilizar. Si no,
                                            déjalo como está.
                                        </p>
                                        <div class="row">
                                            <div class="col-sm-4">
                                                <div class="form-group">
                                                    Servidor:
                                                    <input class="form-control" type="text" name="cache_host" value="localhost" autocomplete="off"/>
                                                </div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="form-group">
                                                    Puerto:
                                                    <input class="form-control" type="number" name="cache_port" value="11211" autocomplete="off"/>
                                                </div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="form-group">
                                                    Prefijo:
                                                    <input class="form-control" type="text" name="cache_prefix" value="<?php echo random_string(8); ?>_" autocomplete="off"/>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="panel panel-default">
                                    <div class="panel-heading">
                                        <h3 class="panel-title">Proxy</h3>
                                    </div>
                                    <div class="panel-body">
                                        <div class="row">
                                            <div class="col-sm-4">
                                                <div class="form-group">
                                                    Tipo de Proxy:
                                                    <select class='form-control' name="proxy_type">
                                                        <option value="">Sin proxy</option>
                                                        <option value="">------</option>
                                                        <option value="HTTP">HTTP</option>
                                                        <option value="HTTPS">HTTPS</option>
                                                        <option value="SOCKS5">SOCKS5</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="form-group">
                                                    Servidor:
                                                    <input class="form-control" type="text" name="proxy_host" placeholder="192.168.1.1" autocomplete="off"/>
                                                </div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="form-group">
                                                    Puerto:
                                                    <input class="form-control" type="number" name="proxy_port" placeholder="8080" autocomplete="off"/>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div role="tabpanel" class="tab-pane" id="licencia">
                        <div class="row">
                            <div class="col-sm-12">
                                <div class="form-group">
                                    <pre><?php echo file_get_contents('COPYING'); ?></pre>
                                    <p>
                                        FacturaScripts también incluye una versión modificada de
                                        <a href="https://github.com/feulf/raintpl/tree/65493157073ff0f313a67fe2ee42139b3eaa7f5a">RainTPL</a>
                                        que también tiene licencia <a href="raintpl/LICENSE.txt">LGPL</a>, así como
                                        <a href="https://github.com/PHPMailer/PHPMailer/">phpmailer</a> con la misma licencia
                                        <a href="extras/phpmailer/LICENSE">LGPL</a>.
                                        <br/>
                                        Para la parte gráfica se incluye el framewrowk <a href="http://getbootstrap.com">Bootstrap</a>, con licencia
                                        <a href="https://github.com/twbs/bootstrap/blob/master/LICENSE">MIT</a> y
                                        <a href="http://fontawesome.io">font-awesome</a> también con licencia <a href="http://fontawesome.io/license">MIT</a>.
                                        <br/>
                                        Y por último, pero no menos importante, también incluye <a href="https://github.com/jquery/jquery">jQuery</a>,
                                        con licencia <a href="https://github.com/jquery/jquery/blob/master/LICENSE.txt">MIT</a>.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-12 text-right">
                        <button id="submit_button" class="btn btn-sm btn-primary" type="submit">
                            <i class="fa fa-check" aria-hidden="true"></i>&nbsp; Aceptar
                        </button>
                    </div>
                </div>
            </form>
            <div class="row" style="margin-bottom: 20px;">
                <div class="col-sm-12 text-center">
                    <hr/>
                    <small>
                        &COPY; 2013-<?php echo date('Y'); ?>
                        <a target="_blank" href="<?php echo FS_COMMUNITY_URL; ?>" rel="nofollow">FSFramework</a>
                    </small>
                </div>
            </div>
        <?php if ($theme_available) { ?>
                </div><!-- /.container -->
            </div><!-- /.content-wrapper -->
        </div><!-- /.wrapper -->
        <?php } else { ?>
        </div><!-- /.container -->
        <?php } ?>
    </body>
</html>
