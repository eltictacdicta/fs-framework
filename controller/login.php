<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com> (lead developer of Facturascript)
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

// Importar clase para redirecciones seguras (prevención de Open Redirect)
use FSFramework\Security\SafeRedirect;

/**
 * Controlador de login.
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class login extends fs_controller
{

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Login', '', false, false);
    }

    protected function private_core()
    {
        // No hacer nada durante la activación de páginas desde admin_home
        if ($this->is_enable_page_context()) {
            return;
        }

        $this->process_login_logic();
    }

    /**
     * Verifica si estamos en el contexto de activación de páginas
     * @return bool
     */
    private function is_enable_page_context()
    {
        // Si se está llamando desde admin_home para activar páginas,
        // no debe ejecutarse la lógica de redirección
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($trace as $frame) {
            if (isset($frame['class']) && $frame['class'] === 'admin_home' && isset($frame['function']) && $frame['function'] === 'enable_page') {
                return true;
            }
        }
        return false;
    }

    /**
     * Procesa la lógica de login
     */
    private function process_login_logic()
    {
        // URL por defecto para redirecciones
        $defaultRedirectUrl = $this->url();

        // Comprobamos si hay variables en sesión, para restaurarlas o si se ha seleccionado otra db
        if (isset($_SESSION['variable_buffer'])) {
            foreach ($_SESSION['variable_buffer'] as $key => $value) {
                ${$key} = $value;
            }

            unset($_SESSION['variable_buffer']);
        } else if ($this->multi_db) {
            if ((isset($_POST['cdb']) && $_POST['cdb'] != FS_DB_NAME) || (isset($_GET['cdb']) && $_GET['cdb'] != FS_DB_NAME)) {
                $new_db = (isset($_POST['cdb']) ? $_POST['cdb'] : $_GET['cdb']);
                if ($this->select_db($new_db)) {
                    $this->user->load_from_session();
                }
            }
        }

        if ($this->user->logged_on) {
            // Redirección segura: valida que la URL sea interna antes de redirigir
            $safeUrl = SafeRedirect::getFromRequest($defaultRedirectUrl);
            header('Location: ' . $safeUrl);
            exit();
        } else if (isset($_POST['nick']) && isset($_POST['password'])) {
            if ($this->user->login($_POST['nick'], $_POST['password'])) {
                if (isset($_POST['keep_login_on']) && $_POST['keep_login_on'] == 'TRUE') {
                    $this->user->set_cookie();
                }

                // Redirección segura: valida que la URL sea interna antes de redirigir
                $safeUrl = SafeRedirect::getFromRequest($defaultRedirectUrl);
                header('Location: ' . $safeUrl);
                exit();
            } else {
                $this->mensaje_login = 'Nick o contraseña incorrectos.';
            }
        } elseif (isset($_GET['autologin'])) {
            if ($this->user->login_from_cookie($_GET['autologin'])) {
                // Redirección segura: valida que la URL sea interna antes de redirigir
                $safeUrl = SafeRedirect::getFromRequest($defaultRedirectUrl);
                header('Location: ' . $safeUrl);
                exit();
            }
        } elseif (isset($_GET['logout'])) {
            $this->user->logout();
        }
    }

    protected function public_core()
    {
        $this->template = 'login.html.twig';

        // No hacer nada durante la activación de páginas desde admin_home
        if ($this->is_enable_page_context()) {
            return;
        }

        $this->process_login_logic();
    }
}
