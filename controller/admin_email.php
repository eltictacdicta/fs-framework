<?php
/**
 * This file is part of FSFramework
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

use FSFramework\Core\MailService;
use FSFramework\Translation\FSTranslator;

/**
 * Controlador de administración de configuración de email SMTP.
 */
class admin_email extends fs_controller
{
    public MailService $mailService;

    public array $emailConfig = [];

    public ?bool $testResult = null;

    public string $testMessage = '';

    public function __construct()
    {
        parent::__construct(__CLASS__, FSTranslator::trans('email-config'), 'admin', true, true);
    }

    protected function private_core()
    {
        $this->mailService = new MailService();
        $this->emailConfig = $this->mailService->getConfig();
        $this->testResult = null;
        $this->testMessage = '';

        if ($this->request->getMethod() === 'POST') {
            if (!$this->isCsrfValid()) {
                $this->new_error_msg(FSTranslator::trans('invalid-csrf-token'));
                return;
            }

            $action = $this->request->request->get('action', 'save');

            if ($action === 'test') {
                $this->testConnection();
            } else {
                $this->saveConfig();
            }
        }
    }

    /**
     * Guarda la configuración de email.
     */
    private function saveConfig(): void
    {
        $port = (int) fs_filter_input_req('mail_port', '587');
        if ($port < 1 || $port > 65535) {
            $this->new_error_msg('El puerto SMTP debe estar entre 1 y 65535. Se usará 587 por defecto.');
            $port = 587;
        }

        $config = [
            'mail_mailer' => (string) fs_filter_input_req('mail_mailer', 'smtp'),
            'mail_host' => $this->no_html(fs_fix_html(trim((string) fs_filter_input_req('mail_host', '')))),
            'mail_port' => $port,
            'mail_user' => $this->no_html(fs_fix_html(trim((string) fs_filter_input_req('mail_user', '')))),
            'mail_enc' => (string) fs_filter_input_req('mail_enc', 'tls'),
            'mail_low_security' => in_array((string) fs_filter_input_req('mail_low_security', '0'), ['1', 'true', 'on'], true),
            'mail_from_email' => $this->no_html(fs_fix_html(trim((string) fs_filter_input_req('mail_from_email', '')))),
            'mail_from_name' => $this->no_html(fs_fix_html(trim((string) fs_filter_input_req('mail_from_name', '')))),
        ];

        $config['mail_password'] = (string) $this->request->request->get('mail_password', '');

        if ($this->mailService->saveConfig($config)) {
            $this->new_message(FSTranslator::trans('email-config-saved'));
            $this->emailConfig = $this->mailService->getConfig();

            if ($this->request->request->get('test_after_save')) {
                $this->testConnection();
            }
        } else {
            $this->new_error_msg(FSTranslator::trans('email-config-save-error'));
        }
    }

    /**
     * Prueba la conexión SMTP.
     */
    private function testConnection(): void
    {
        $result = $this->mailService->testConnection();
        $this->testResult = $result['success'];
        $this->testMessage = $result['message'];

        if ($result['success']) {
            $this->new_message($result['message']);
        } else {
            $this->new_error_msg($result['message']);
        }
    }

    /**
     * Opciones de encriptación para el formulario.
    *
     * @return array
     */
    public function encriptaciones(): array
    {
        return MailService::getEncryptionOptions();
    }

    /**
     * Opciones de mailer para el formulario.
    *
     * @return array
     */
    public function mailers(): array
    {
        return MailService::getMailerOptions();
    }

    /**
     * Verifica si el sistema puede enviar emails.
    *
     * @return bool
     */
    public function canSendMail(): bool
    {
        return $this->mailService->canSendMail();
    }

    /**
     * Verifica si hay configuración SMTP.
    *
     * @return bool
     */
    public function hasSmtpConfig(): bool
    {
        return $this->mailService->hasSmtpConfig();
    }

    /**
     * @deprecated Use canSendMail().
     */
    public function can_send_mail(): bool
    {
        return $this->canSendMail();
    }

    /**
     * @deprecated Use hasSmtpConfig().
     */
    public function has_smtp_config(): bool
    {
        return $this->hasSmtpConfig();
    }
}
