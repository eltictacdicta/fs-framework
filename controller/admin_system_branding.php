<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
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

require_once 'base/fs_settings.php';

/**
 * Controlador para gestionar el branding del sistema (logo, nombre).
 * Solo accesible por administradores.
 */
class admin_system_branding extends fs_controller
{
    /**
     * @var fs_settings
     */
    public $settings;

    /**
     * @var string|null Current system logo URL
     */
    public $logo_url;

    /**
     * @var string System name for branding
     */
    public $system_name;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Marca del Sistema', 'admin', true, true);
    }

    protected function private_core()
    {
        $this->settings = new fs_settings();
        $this->load_branding();

        if (!$this->isCsrfValid()) {
            return;
        }

        if ($this->getRequest()->request->has('upload_logo')) {
            $this->handle_logo_upload();
        } elseif ($this->getRequest()->request->has('delete_logo')) {
            $this->handle_logo_delete();
        } elseif ($this->getRequest()->request->has('save_branding')) {
            $this->handle_save_branding();
        }

        $this->load_branding();
    }

    private function load_branding(): void
    {
        $this->logo_url = $this->settings->getSystemLogoUrl();
        $this->system_name = $this->settings->get('system_name', 'FSFramework');
    }

    private function handle_logo_upload(): void
    {
        if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
            $this->new_error_msg('Error al subir el archivo. Intente de nuevo.');
            return;
        }

        $result = $this->settings->saveSystemLogo($_FILES['logo_file']);

        if ($result['success']) {
            $this->new_message('Logo del sistema actualizado correctamente.');
        } else {
            $this->new_error_msg($result['error']);
        }
    }

    private function handle_logo_delete(): void
    {
        if ($this->settings->deleteSystemLogo()) {
            $this->new_message('Logo del sistema eliminado correctamente.');
        } else {
            $this->new_advice('No había ningún logo configurado para eliminar.');
        }
    }

    private function handle_save_branding(): void
    {
        $systemName = trim($this->getRequest()->request->get('system_name', ''));
        if ($systemName === '') {
            $this->new_message('El nombre del sistema no puede estar vacío.');
            return;
        }

        $this->settings->set('system_name', $this->no_html($systemName));
        $this->settings->save();
        $this->new_message('Configuración de marca guardada correctamente.');
    }

    /**
     * Check if there's a current logo configured
     */
    public function has_logo(): bool
    {
        return $this->logo_url !== null;
    }

    /**
     * Get the full logo URL for display
     */
    public function get_logo_display_url(): string
    {
        if ($this->logo_url === null) {
            return '';
        }

        return FS_PATH . $this->logo_url;
    }
}
