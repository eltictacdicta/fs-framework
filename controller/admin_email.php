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

    public array $emailSystemInfo = [];

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
            } elseif ($action === 'use_mailpit') {
                $this->useMailpit();
            } elseif ($action === 'send_test') {
                $this->sendTestEmail();
            } else {
                $this->saveConfig();
            }
        }

        $this->emailSystemInfo = $this->getEmailSystemInfo();
    }

    /**
     * Configura Mailpit de DDEV para pruebas.
     */
    private function useMailpit(): void
    {
        $config = [
            'mail_mailer' => 'smtp',
            'mail_host' => 'localhost',
            'mail_port' => 1025,
            'mail_user' => '',
            'mail_password' => '',
            'mail_enc' => '',
            'mail_low_security' => false,
            'mail_from_email' => 'noreply@test.local',
            'mail_from_name' => 'Sistema de Pruebas',
        ];

        if ($this->mailService->saveConfig($config)) {
            $this->new_message('Configuración de Mailpit activada. Los emails se capturarán en Mailpit (puerto 8026).');
            $this->emailConfig = $this->mailService->getConfig();
            MailService::clearCache();
        } else {
            $this->new_error_msg('Error al configurar Mailpit.');
        }
    }

    /**
     * Envía un email de prueba.
     */
    private function sendTestEmail(): void
    {
        $testEmail = trim((string) $this->request->request->get('test_email', ''));
        
        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $this->new_error_msg('Por favor, introduce una dirección de email válida para la prueba.');
            return;
        }

        $config = $this->emailConfig;
        $subject = 'Email de prueba - ' . date('d/m/Y H:i:s');
        $body = $this->buildTestEmailBody($config);

        try {
            $result = $this->mailService->send($testEmail, $subject, $body);
            
            if ($result) {
                $this->new_message("Email de prueba enviado correctamente a {$testEmail}. Revisa tu bandeja de entrada (o Mailpit si usas DDEV).");
            } else {
                $detail = $this->mailService->getLastError();
                $message = "No se pudo enviar el email de prueba a {$testEmail}.";
                if ($detail !== '') {
                    $message .= " Detalle: {$detail}";
                }
                $this->new_error_msg($message);
            }
        } catch (\Throwable $e) {
            $this->new_error_msg("Error al enviar email de prueba: " . $e->getMessage());
        }
    }

    private function buildTestEmailBody(array $config): string
    {
        $host = $this->no_html($config['mail_host'] ?: '(no configurado)');
        $port = $this->no_html($config['mail_port'] ?: '(no configurado)');
        $mailer = $this->no_html($config['mail_mailer'] ?: 'smtp');
        $from = $this->no_html($config['mail_from_email'] ?: '(no configurado)');
        $date = $this->no_html(date('d/m/Y H:i:s'));

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; padding: 20px;">
    <h2 style="color: #0196d2;">Email de prueba</h2>
    <p>Este es un email de prueba enviado desde el sistema.</p>
    <hr style="border: 1px solid #eee;">
    <h3>Configuración actual:</h3>
    <ul>
        <li><strong>Mailer:</strong> {$mailer}</li>
        <li><strong>Host:</strong> {$host}</li>
        <li><strong>Puerto:</strong> {$port}</li>
        <li><strong>Remitente:</strong> {$from}</li>
    </ul>
    <p style="color: #666; font-size: 12px;">Enviado el {$date}</p>
</body>
</html>
HTML;
    }

    /**
     * Obtiene información del sistema de email actual para mostrar en la UI.
     */
    public function getEmailSystemInfo(): array
    {
        $config = $this->emailConfig;
        $mailer = $config['mail_mailer'] ?? 'smtp';
        $host = $config['mail_host'] ?? '';
        $port = $config['mail_port'] ?? 587;

        if ($mailer === 'smtp' && $host === 'localhost' && $port == 1025) {
            return [
                'name' => 'Mailpit (DDEV)',
                'icon' => 'flask',
                'class' => 'info',
                'description' => 'Los emails se capturan en Mailpit para pruebas.',
            ];
        }

        if ($mailer === 'smtp' && !empty($host)) {
            return [
                'name' => 'SMTP: ' . $host,
                'icon' => 'server',
                'class' => 'success',
                'description' => "Servidor SMTP en puerto {$port}.",
            ];
        }

        if ($mailer === 'mail') {
            return [
                'name' => 'PHP mail()',
                'icon' => 'envelope',
                'class' => 'warning',
                'description' => 'Usando función mail() nativa de PHP.',
            ];
        }

        return [
            'name' => 'No configurado',
            'icon' => 'exclamation-triangle',
            'class' => 'danger',
            'description' => 'El sistema de email no está configurado.',
        ];
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

        $submittedPassword = (string) $this->request->request->get('mail_password', '');
        if ($submittedPassword !== '') {
            $config['mail_password'] = $submittedPassword;
        }

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
