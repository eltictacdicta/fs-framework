<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2013-2018 Carlos Garcia Gomez <neorazorx@gmail.com>
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

require_once 'base/fs_ip_filter.php';

use FSFramework\Translation\FSTranslator;

/**
 * Controlador de recuperación de contraseña por email.
 * No requiere autenticación, accesible desde la pantalla de login.
 */
class password_reset extends fs_controller
{

    /** @var string Acción actual: 'request' o 'reset' */
    public $action;

    /** @var bool Si se envió el email correctamente */
    public $email_sent;

    /** @var bool Si el token es válido (para mostrar formulario de nueva contraseña) */
    public $token_valid;

    /** @var string Nick del usuario (para el formulario de reset) */
    public $reset_nick;

    /** @var string Token recibido por URL */
    public $reset_token;

    /** @var string Firma de URL recibida */
    public $reset_signature;

    /** @var string Expiración de URL recibida */
    public $reset_expires;

    /** @var bool Si la contraseña fue cambiada correctamente */
    public $password_changed;

    /** @var fs_ip_filter */
    private $ip_filter;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Recuperar contraseña', '', FALSE, FALSE);
    }

    protected function private_core()
    {
        // Si el usuario ya está logueado, redirigir a inicio
        header('Location: index.php');
        exit();
    }

    protected function public_core()
    {
        $this->template = 'password_reset';
        $this->ip_filter = new fs_ip_filter();
        $this->email_sent = FALSE;
        $this->token_valid = FALSE;
        $this->password_changed = FALSE;
        $this->reset_nick = '';
        $this->reset_token = '';
        $this->reset_signature = '';
        $this->reset_expires = '';

        // Determinar la acción
        $token = $this->request->query->get('token', '');
        $nick = $this->request->query->get('nick', '');

        if (!empty($token) && !empty($nick)) {
            // Llegamos desde el enlace del email
            $this->action = 'reset';
            $this->reset_nick = $nick;
            $this->reset_token = $token;
            $this->reset_signature = $this->request->query->get('_hash', '');
            $this->reset_expires = $this->request->query->get('_expiration', '');

            if ($this->request->getMethod() === 'POST') {
                $this->reset_signature = $this->request->request->get('_hash', $this->reset_signature);
                $this->reset_expires = $this->request->request->get('_expiration', $this->reset_expires);
            }

            if ($this->request->getMethod() === 'POST') {
                $this->process_reset();
            } else {
                $this->validate_token();
            }
        } else {
            // Formulario de solicitud de recuperación
            $this->action = 'request';

            if ($this->request->getMethod() === 'POST') {
                $this->process_request();
            }
        }
    }

    /**
     * Procesa la solicitud de recuperación: busca al usuario y envía el email.
     */
    private function process_request()
    {
        $ip = fs_get_ip();

        // Rate limiting
        if ($this->ip_filter->is_banned($ip)) {
            $this->new_error_msg(FSTranslator::trans('password-reset-too-many-attempts'));
            return;
        }

        $this->ip_filter->set_attempt($ip);

        $nick_or_email = trim($this->request->request->get('nick_or_email', ''));
        if (empty($nick_or_email)) {
            $this->new_error_msg(FSTranslator::trans('password-reset-enter-nick-or-email'));
            return;
        }

        // Buscar usuario por nick o email
        $user_model = new fs_user();
        $user = $user_model->get($nick_or_email);

        if (!$user) {
            $user = $user_model->get_by_email($nick_or_email);
        }

        // Siempre mostrar el mismo mensaje (prevenir enumeración de usuarios)
        $this->email_sent = TRUE;
        $this->new_message(FSTranslator::trans('password-reset-email-sent'));

        // Solo enviar si encontramos usuario con email válido y habilitado
        if ($user && $user->enabled && !empty($user->email)) {
            $plain_token = $user->set_reset_token();
            if ($user->save()) {
                $this->send_reset_email($user, $plain_token);
            }
        }
    }

    /**
     * Valida el token recibido por URL antes de mostrar el formulario.
     */
    private function validate_token()
    {
        if (!$this->validate_signed_reset_url()) {
            $this->token_valid = FALSE;
            $this->new_error_msg(FSTranslator::trans('password-reset-invalid-or-expired'));
            return;
        }

        $user_model = new fs_user();
        $user = $user_model->get($this->reset_nick);

        if ($user && $user->enabled && $user->validate_reset_token($this->reset_token)) {
            $this->token_valid = TRUE;
        } else {
            $this->token_valid = FALSE;
            $this->new_error_msg(FSTranslator::trans('password-reset-invalid-or-expired'));
        }
    }

    /**
     * Procesa el cambio de contraseña con el token válido.
     */
    private function process_reset()
    {
        if (!$this->validate_signed_reset_url()) {
            $this->new_error_msg(FSTranslator::trans('password-reset-invalid-or-expired'));
            return;
        }

        $ip = fs_get_ip();

        if ($this->ip_filter->is_banned($ip)) {
            $this->new_error_msg(FSTranslator::trans('password-reset-too-many-attempts'));
            return;
        }

        $new_password = $this->request->request->get('new_password', '');
        $new_password2 = $this->request->request->get('new_password2', '');
        $token = $this->request->request->get('token', $this->reset_token);
        $nick = $this->request->request->get('nick', $this->reset_nick);

        if ($new_password !== $new_password2) {
            $this->new_error_msg(FSTranslator::trans('password-reset-passwords-dont-match'));
            $this->token_valid = TRUE;
            return;
        }

        if (empty($new_password) || mb_strlen($new_password) < 8 || mb_strlen($new_password) > 32) {
            $this->new_error_msg(FSTranslator::trans('password-reset-invalid-length'));
            $this->token_valid = TRUE;
            return;
        }

        $user_model = new fs_user();
        $user = $user_model->get($nick);

        if (!$user || !$user->enabled || !$user->validate_reset_token($token)) {
            $this->ip_filter->set_attempt($ip);
            $this->new_error_msg(FSTranslator::trans('password-reset-invalid-or-expired'));
            return;
        }

        // Cambiar la contraseña
        $user->set_password($new_password);
        $user->clear_reset_token();

        if ($user->save()) {
            $this->password_changed = TRUE;
            $this->new_message(FSTranslator::trans('password-reset-success'));
        } else {
            $this->new_error_msg(FSTranslator::trans('password-reset-error'));
            $this->token_valid = TRUE;
        }
    }

    /**
     * Envía el email de recuperación de contraseña.
     * Usa PHPMailer en modo mail() nativo (como WordPress), sin configuración SMTP.
     * Remitente: fsf@dominio.com (dominio calculado dinámicamente).
     * 
     * @param fs_user $user el usuario destinatario
     * @param string $plain_token el token en texto plano
     */
    private function send_reset_email($user, $plain_token)
    {
        $domain = $this->get_trusted_domain();
        $from_email = 'fsf@' . $domain;

        // Nombre de la empresa si está disponible
        $from_name = 'FSFramework';
        if (isset($this->empresa) && $this->empresa && !empty($this->empresa->nombre)) {
            $from_name = $this->empresa->nombre;
        }

        // Construir URL de reset usando dominio de confianza
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base_url = $protocol . '://' . $domain;
        $path = str_replace('/index.php', '', $_SERVER['SCRIPT_NAME'] ?? '');
        $reset_url = $base_url . $path . '/index.php?page=password_reset'
            . '&nick=' . urlencode($user->nick)
            . '&token=' . urlencode($plain_token);
        $reset_url = \FSFramework\Security\SignedUrlService::sign($reset_url, time() + 3600);

        $subject = FSTranslator::trans('password-reset-email-subject', ['%company%' => $from_name]);

        $body = $this->build_email_body($user, $reset_url, $from_name);

        try {
            $mail = new \PHPMailer();
            $mail->CharSet = 'UTF-8';
            $mail->Mailer = 'mail'; // Usar mail() nativo de PHP, como WordPress
            $mail->From = $from_email;
            $mail->FromName = $from_name;
            $mail->addAddress($user->email, $user->nick);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));

            if (!$mail->send()) {
                error_log('FSFramework password_reset: Error enviando email al usuario ' . $user->nick . ': ' . $mail->ErrorInfo);
            }
        } catch (\Exception $e) {
            error_log('FSFramework password_reset: Excepción enviando email: ' . $e->getMessage());
        }
    }

    /**
     * Construye el cuerpo HTML del email de recuperación.
     * 
     * @param fs_user $user
     * @param string $reset_url
     * @param string $company_name
     * @return string
     */
    private function build_email_body($user, $reset_url, $company_name)
    {
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; color: #333;">';
        $html .= '<div style="background-color: #f7f7f7; border-radius: 5px; padding: 30px;">';
        $html .= '<h2 style="color: #3c8dbc; margin-top: 0;">' . htmlspecialchars($company_name) . '</h2>';
        $html .= '<p>' . FSTranslator::trans('password-reset-email-greeting', ['%nick%' => htmlspecialchars($user->nick)]) . '</p>';
        $html .= '<p>' . FSTranslator::trans('password-reset-email-body') . '</p>';
        $html .= '<p style="text-align: center; margin: 30px 0;">';
        $html .= '<a href="' . htmlspecialchars($reset_url) . '" style="background-color: #3c8dbc; color: #ffffff; padding: 12px 30px; text-decoration: none; border-radius: 4px; display: inline-block; font-weight: bold;">';
        $html .= FSTranslator::trans('password-reset-email-button');
        $html .= '</a></p>';
        $html .= '<p style="font-size: 12px; color: #999;">' . FSTranslator::trans('password-reset-email-expiry') . '</p>';
        $html .= '<p style="font-size: 12px; color: #999;">' . FSTranslator::trans('password-reset-email-ignore') . '</p>';
        $html .= '<hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">';
        $html .= '<p style="font-size: 11px; color: #aaa;">' . FSTranslator::trans('password-reset-email-footer', ['%url%' => htmlspecialchars($reset_url)]) . '</p>';
        $html .= '</div></body></html>';

        return $html;
    }

    /**
     * Obtiene el dominio de confianza para construir URLs.
     * Prioridad: FS_DOMAIN (configurado) > SERVER_NAME > localhost
     * 
     * Nunca usa HTTP_HOST directamente para evitar host header injection.
     * 
     * @return string
     */
    private function get_trusted_domain()
    {
        // Usar dominio configurado explícitamente (más seguro)
        if (defined('FS_DOMAIN') && !empty(FS_DOMAIN)) {
            return FS_DOMAIN;
        }

        // Fallback a SERVER_NAME (configurado en el servidor, no manipulable por el cliente)
        if (!empty($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] !== '_') {
            return $_SERVER['SERVER_NAME'];
        }

        return 'localhost';
    }

    private function validate_signed_reset_url()
    {
        return \FSFramework\Security\SignedUrlService::check($this->request->getUri());
    }
}
