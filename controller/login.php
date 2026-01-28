<?php
// Fix Legacy
if (isset($GLOBALS['config2']['site_url'])) {
    header('Location: ' . $GLOBALS['config2']['site_url'] . 'index.php?page=admin_home');
    exit();
}

// Importar clase para redirecciones seguras (prevención de Open Redirect)
use FSFramework\Security\SafeRedirect;

$fsc->template = 'login.html.twig';

// URL por defecto para redirecciones
$defaultRedirectUrl = $fsc->url();

// Comprobamos si hay variables en sesión, para restaurarlas o si se ha seleccionado otra db
if (isset($_SESSION['variable_buffer'])) {
    foreach ($_SESSION['variable_buffer'] as $key => $value) {
        ${$key} = $value;
    }

    unset($_SESSION['variable_buffer']);
} else if ($fsc->multi_db) {
    if ((isset($_POST['cdb']) && $_POST['cdb'] != FS_DB_NAME) || (isset($_GET['cdb']) && $_GET['cdb'] != FS_DB_NAME)) {
        $new_db = (isset($_POST['cdb']) ? $_POST['cdb'] : $_GET['cdb']);
        if ($fsc->select_db($new_db)) {
            $fsc->user->load_from_session();
        }
    }
}

if ($fsc->user->logged_on) {
    // Redirección segura: valida que la URL sea interna antes de redirigir
    $safeUrl = SafeRedirect::getFromRequest($defaultRedirectUrl);
    header('Location: ' . $safeUrl);
    exit();
} else if (isset($_POST['nick']) && isset($_POST['password'])) {
    if ($fsc->user->login($_POST['nick'], $_POST['password'])) {
        if (isset($_POST['keep_login_on']) && $_POST['keep_login_on'] == 'TRUE') {
            $fsc->user->set_cookie();
        }

        // Redirección segura: valida que la URL sea interna antes de redirigir
        $safeUrl = SafeRedirect::getFromRequest($defaultRedirectUrl);
        header('Location: ' . $safeUrl);
        exit();
    } else {
        $fsc->mensaje_login = 'Nick o contraseña incorrectos.';
    }
} elseif (isset($_GET['autologin'])) {
    if ($fsc->user->login_from_cookie($_GET['autologin'])) {
        // Redirección segura: valida que la URL sea interna antes de redirigir
        $safeUrl = SafeRedirect::getFromRequest($defaultRedirectUrl);
        header('Location: ' . $safeUrl);
        exit();
    }
} elseif (isset($_GET['logout'])) {
    $fsc->user->logout();
}

// Preparamos la lista de bases de datos
$dbs = array();
if ($fsc->multi_db) {
    $dbs = $fsc->get_db_list();
}

// Si la base de datos es la predeterminada y no hay usuario, ocultamos el selector
if (FS_DB_NAME == 'facturascripts' && isset($dbs[0]) && $dbs[0] == 'facturascripts' && count($dbs) == 1) {
    $dbs = array();
}
