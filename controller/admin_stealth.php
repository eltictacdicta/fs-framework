<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * Controlador de administración del Modo Stealth.
 * Permite activar/desactivar el modo, configurar el parámetro secreto
 * y editar la homepage pública con un editor WYSIWYG.
 */
class admin_stealth extends fs_controller
{
    /** @var \FSFramework\Core\StealthMode */
    public $stealth;

    /** @var bool */
    public $stealth_enabled;

    /** @var string */
    public $stealth_param_name;

    /** @var string */
    public $stealth_param_value;

    /** @var string */
    public $stealth_access_url;

    /** @var string */
    public $stealth_homepage_html;

    /** @var string */
    public $stealth_custom_css;

    /** @var string */
    public $stealth_page_title;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Modo Stealth', 'admin', FALSE, TRUE);
    }

    protected function private_core()
    {
        if (!$this->user->admin) {
            $this->new_error_msg('Solo los administradores pueden acceder a esta página.');
            return;
        }

        $this->initializeStealth();

        $action = (string) ($this->request->request->get('action') ?? '');

        if ($action === 'save_settings') {
            if ($this->enforcePostAction('save_settings')) {
                $this->handleSaveSettings();
            }
        } elseif ($action === 'save_homepage') {
            if ($this->enforcePostAction('save_homepage')) {
                $this->handleSaveHomepage();
            }
        } elseif ($action === 'regenerate_secret') {
            if ($this->enforcePostAction('regenerate_secret')) {
                $this->handleRegenerateSecret();
            }
        } elseif ($this->request->query->get('action') === 'regenerate_secret') {
            $this->new_error_msg('La regeneración del token requiere una petición POST válida.');
        }

        $this->ensureStealthSecret();
        $this->loadViewData();
    }

    private function initializeStealth(): void
    {
        require_once FS_FOLDER . '/src/Core/StealthMode.php';
        $this->stealth = new \FSFramework\Core\StealthMode();
    }

    private function enforcePostAction(string $action): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !$this->request->isMethod('POST')) {
            $this->new_error_msg('La acción ' . $action . ' solo acepta peticiones POST.');
            return false;
        }

        $token = (string) ($this->request->request->get(\FSFramework\Security\CsrfManager::FIELD_NAME)
            ?? $this->request->request->get('_token')
            ?? '');

        if (!$this->verifyCsrfToken($token)) {
            $this->new_error_msg('Token de seguridad inválido o ausente. Recarga la página e inténtalo de nuevo.');
            return false;
        }

        return true;
    }

    private function verifyCsrfToken(string $token): bool
    {
        return $token !== '' && \FSFramework\Security\CsrfManager::isValid($token);
    }

    private function handleSaveSettings(): void
    {
        $enabled = $this->request->request->get('stealth_enabled') === '1';
        $paramName = trim((string) $this->request->request->get('stealth_param_name'));
        $pageTitle = trim((string) $this->request->request->get('stealth_page_title'));

        $this->stealth->saveEnabled($enabled);

        if (!empty($paramName)) {
            $this->stealth->saveParamName($paramName);
        }

        if (!empty($pageTitle)) {
            $this->stealth->savePageTitle($pageTitle);
        }

        $this->new_message('Configuración del modo stealth guardada correctamente.');
    }

    private function handleSaveHomepage(): void
    {
        $html = (string) $this->request->request->get('stealth_homepage_html');
        $css = (string) $this->request->request->get('stealth_custom_css');

        $okCss = $this->stealth->saveCustomCss($css);
        $okHtml = $okCss && $this->stealth->saveHomepageHtml($html);

        if ($okHtml && $okCss) {
            $this->new_message('Homepage pública guardada correctamente.');
        } elseif (!$okCss) {
            $this->new_error_msg('El CSS personalizado contiene reglas o valores no permitidos.');
        } else {
            $this->new_error_msg('Error al guardar la homepage pública.');
        }
    }

    private function handleRegenerateSecret(): void
    {
        if ($this->stealth->regenerateSecret() === '') {
            $this->new_error_msg('No se pudo regenerar el token secreto.');
            return;
        }

        $this->new_message('Token secreto regenerado. Recuerda actualizar tus marcadores.');
    }

    private function ensureStealthSecret(): void
    {
        $currentSecret = $this->stealth->getParamValue();
        if ($currentSecret !== '') {
            return;
        }

        if ($this->stealth->regenerateSecret() === '') {
            $this->new_error_msg('No se pudo inicializar el token secreto del modo stealth.');
        }
    }

    private function loadViewData(): void
    {
        $this->stealth_enabled = $this->stealth->isEnabled();
        $this->stealth_param_name = $this->stealth->getParamName();
        $this->stealth_param_value = $this->stealth->getParamValue();
        $this->stealth_access_url = $this->stealth->getAccessUrl();
        $this->stealth_homepage_html = $this->stealth->getHomepageHtml();
        $this->stealth_custom_css = $this->stealth->getCustomCss();
        $this->stealth_page_title = $this->stealth->getPageTitle();
    }
}
