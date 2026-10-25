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
use FSFramework\Core\Plugins;
use FSFramework\Core\StealthMode;
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
        $publicLoginRedirect = $this->resolveAnonymousPublicLoginRedirect();
        if ($publicLoginRedirect !== null) {
            $this->redirectToPublicLogin($publicLoginRedirect);
        }

        $defaultRedirectUrl = 'index.php';

        $this->restoreBufferedVariables();
        $this->switchDatabaseIfRequested();

        if ($this->request->query->get('logout') !== null) {
            $this->user->logout();
        }

        if ($this->user->logged_on) {
            $this->redirectToSafeUrl($defaultRedirectUrl);
        }

        // LC-06: el veredicto CSRF de fs_controller::log_in() es autoritativo y
        // la credencial se evalúa como máximo una vez por petición. Sin este
        // guard el mismo POST se entregaba a fs_login dos veces y la segunda
        // entrega no pasaba por ninguna validación de token (VULN-0003).
        // Vive acá, después del redirect de login público de los plugins y
        // antes de la rama de credenciales: un guard al comienzo de
        // public_core() bloquearía ese redirect.
        if ($this->shouldSkipCredentialLogin()) {
            return;
        }

        if ($this->handleCredentialLogin($defaultRedirectUrl)) {
            return;
        }

        if ($this->handleAutoLogin($defaultRedirectUrl)) {
            return;
        }
    }

    /**
     * LC-06: un POST cuya credencial ya fue evaluada, o cuyo token CSRF no es
     * válido, no vuelve a entrar en la rama de credenciales.
     *
     * El predicado lee isCsrfValid() y nunca requireCsrf(): `csrf_valid ===
     * false` sólo lo fija un validateCsrf() que ya corrió, mientras que
     * requireCsrf() validaría cuando nadie lo hizo todavía y rechazaría un POST
     * a page=login que legítimamente no lleva token ni credenciales.
     */
    protected function shouldSkipCredentialLogin(): bool
    {
        if (!$this->request->isMethod('POST')) {
            return false;
        }

        return !$this->isCsrfValid() || $this->credentialLoginEvaluated();
    }

    protected function resolveAnonymousPublicLoginRedirect(): ?string
    {
        if (isset($this->user) && !empty($this->user->logged_on)) {
            return null;
        }

        $stealth = $this->createStealthMode();
        if ($stealth->isEnabled() && $stealth->hasAccess()) {
            return null;
        }

        $redirectUrl = Plugins::getPublicLoginRedirect();
        if ($redirectUrl === null) {
            return null;
        }

        return SafeRedirect::validate($redirectUrl, 'index.php?page=login');
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

        $requestedDb = $this->request->request->get('cdb') ?? $this->request->query->get('cdb');
        if (!$requestedDb || $requestedDb == FS_DB_NAME) {
            return;
        }

        // El cambio de base de datos se heredó de FacturaScripts 2017 y nunca se
        // portó: select_db() y fs_user::load_from_session() no existen en este
        // código, y $multi_db está declarado en false sin que nadie lo active,
        // así que esta rama jamás se ejecutó. Se declara explícitamente para que
        // el fallo sea legible: activar la bandera moría antes con "Call to
        // undefined method" a mitad de camino, que se lee como un error de quien
        // llama y no como la funcionalidad que falta. Implementarlo implica
        // seleccionar la base y después recargar el usuario y revalidar la sesión
        // contra ella.
        throw new \LogicException('El cambio de base de datos no está implementado.');
    }

    private function handleCredentialLogin($defaultRedirectUrl)
    {
        $nick = $this->request->request->get('nick') ?? $this->request->request->get('user');
        $password = $this->request->request->get('password');

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

        $rememberMe = $this->request->request->get('remember_me') === '1';
        fs_session_manager::set('remember_me', $rememberMe);

        $this->redirectToSafeUrl($defaultRedirectUrl);
    }

    private function handleAutoLogin($defaultRedirectUrl)
    {
        $autologinToken = $this->request->query->get('autologin');
        if ($autologinToken === null || !$this->user->login_from_cookie($autologinToken)) {
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

    protected function redirectToPublicLogin(string $url): void
    {
        SafeRedirect::redirect($url, 'index.php?page=login');
    }

    protected function createStealthMode(): StealthMode
    {
        return new StealthMode($this->db instanceof \fs_db2 ? $this->db : new \fs_db2());
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
