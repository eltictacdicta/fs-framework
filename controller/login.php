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

    private bool $skipLoginLogic = false;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Login', '', false, false);
    }

    protected function private_core()
    {
        if ($this->skipLoginLogic) {
            return;
        }

        $this->process_login_logic();
    }

    public function skipLoginLogic(bool $skip = true): void
    {
        $this->skipLoginLogic = $skip;
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

        $this->showInitialCredentialsIfAvailable();
    }

    /**
     * Muestra las credenciales iniciales en la página de login si es la primera vez.
     */
    private function showInitialCredentialsIfAvailable(): void
    {
        $credentials = $this->getInitialCredentialsFromFile();
        if ($credentials === null) {
            return;
        }

        $this->core_log->new_message(self::buildInitialCredentialsMessage($credentials));
    }

    protected static function buildInitialCredentialsMessage(array $credentials): string
    {
        $nick = htmlspecialchars((string) ($credentials['nick'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $password = htmlspecialchars((string) ($credentials['password'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<strong>¡Instalación completada!</strong><br>' .
            'Usuario: <code>' . $nick . '</code><br>' .
            'Contraseña temporal: <code>' . $password . '</code><br>' .
            '<small class="text-warning"><i class="fa fa-exclamation-triangle"></i> ' .
            'Esta contraseña se muestra solo hasta el primer acceso correcto. ' .
            'Cámbiala inmediatamente después de iniciar sesión.</small>';
    }

    /**
     * Lee las credenciales iniciales directamente del archivo.
     * Delega al método estático de fs_user que maneja el descifrado.
     */
    private function getInitialCredentialsFromFile(): ?array
    {
        return \fs_user::getInitialCredentials();
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
        $nick = $_POST['nick'] ?? ($_POST['user'] ?? null);
        $password = $_POST['password'] ?? null;

        if ($nick === null || $password === null) {
            return false;
        }

        $nick = trim((string) $nick);
        $password = trim((string) $password);

        if ($nick === '' || $password === '') {
            $this->mensaje_login = 'Nick o contraseña incorrectos.';
            return true;
        }

        if (!$this->user->login($nick, $password)) {
            $this->mensaje_login = 'Nick o contraseña incorrectos.';
            return true;
        }

        \fs_user::clearInitialCredentials();

        $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
        fs_session_manager::set('remember_me', $rememberMe);

        $this->redirectToSafeUrl($defaultRedirectUrl);
    }

    private function handleAutoLogin($defaultRedirectUrl)
    {
        if (!isset($_GET['autologin']) || !$this->user->login_from_cookie($_GET['autologin'])) {
            return false;
        }

        \fs_user::clearInitialCredentials();

        $this->redirectToSafeUrl($defaultRedirectUrl);
    }

    private function redirectToSafeUrl($defaultRedirectUrl)
    {
        $safeUrl = SafeRedirect::getFromRequest($defaultRedirectUrl);
        header(self::LOCATION_HEADER . $safeUrl);
        exit();
    }

    public function loginActionUrl(): string
    {
        $query = $this->request->query->all();
        unset($query['page']);
        $query['nlogin'] = $query['nlogin'] ?? '';

        return 'index.php?' . http_build_query($query);
    }

    public function shouldShowPasswordResetLink(): bool
    {
        require_once FS_FOLDER . '/src/Core/StealthMode.php';

        return !(new \FSFramework\Core\StealthMode())->isEnabled();
    }

    protected function public_core()
    {
        $this->template = 'login.html.twig';

        if ($this->skipLoginLogic) {
            return;
        }

        $this->process_login_logic();
    }
}
