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
    private const LOCATION_HEADER = 'Location: ';

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
        $defaultRedirectUrl = $this->url();

        $this->restoreBufferedVariables();
        $this->switchDatabaseIfRequested();

        if ($this->user->logged_on) {
            $this->redirectToSafeUrl($defaultRedirectUrl);
        }

        if ($this->handleCredentialLogin($defaultRedirectUrl)) {
            return;
        }

        if ($this->handleAutoLogin($defaultRedirectUrl)) {
            return;
        }

        if (isset($_GET['logout'])) {
            $this->user->logout();
        }
    }

    private function restoreBufferedVariables()
    {
        if (!isset($_SESSION['variable_buffer'])) {
            return;
        }

        foreach ($_SESSION['variable_buffer'] as $key => $value) {
            $this->{$key} = $value;
        }

        unset($_SESSION['variable_buffer']);
    }

    private function switchDatabaseIfRequested()
    {
        if (!$this->multi_db) {
            return;
        }

        $requestedDb = $_POST['cdb'] ?? ($_GET['cdb'] ?? null);
        if (!$requestedDb || $requestedDb == FS_DB_NAME) {
            return;
        }

        if ($this->select_db($requestedDb)) {
            $this->user->load_from_session();
        }
    }

    private function handleCredentialLogin($defaultRedirectUrl)
    {
        if (!isset($_POST['nick'], $_POST['password'])) {
            return false;
        }

        if (!$this->user->login($_POST['nick'], $_POST['password'])) {
            $this->mensaje_login = 'Nick o contraseña incorrectos.';
            return true;
        }

        if (isset($_POST['keep_login_on']) && $_POST['keep_login_on'] === 'TRUE') {
            $this->user->set_cookie();
        }

        $this->redirectToSafeUrl($defaultRedirectUrl);
    }

    private function handleAutoLogin($defaultRedirectUrl)
    {
        if (!isset($_GET['autologin']) || !$this->user->login_from_cookie($_GET['autologin'])) {
            return false;
        }

        $this->redirectToSafeUrl($defaultRedirectUrl);
    }

    private function redirectToSafeUrl($defaultRedirectUrl)
    {
        $safeUrl = SafeRedirect::getFromRequest($defaultRedirectUrl);
        header(self::LOCATION_HEADER . $safeUrl);
        exit();
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
