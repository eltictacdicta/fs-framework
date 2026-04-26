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

namespace FSFramework\Core;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Servicio de email del núcleo de FSFramework.
 * Permite enviar emails usando configuración SMTP almacenada en fs_vars,
 * con fallback a mail() nativo de PHP si no hay SMTP configurado.
 */
class MailService
{
    private const CONFIG_KEYS = [
        'mail_mailer' => 'smtp',
        'mail_host' => '',
        'mail_port' => 587,
        'mail_user' => '',
        'mail_password' => '',
        'mail_enc' => 'tls',
        'mail_low_security' => false,
        'mail_from_email' => '',
        'mail_from_name' => '',
    ];

    /** @var array|null Configuración cacheada */
    private static ?array $configCache = null;

    private string $lastError = '';

    public function __construct(private ?\fs_var $fsVar = null)
    {
        if ($this->fsVar === null && class_exists('fs_var')) {
            $this->fsVar = new \fs_var();
        }
    }

    /**
     * Obtiene la configuración de email actual.
    *
     * @return array
     */
    public function getConfig(): array
    {
        if (self::$configCache !== null) {
            return self::$configCache;
        }

        $config = self::CONFIG_KEYS;

        if ($this->fsVar === null) {
            return $config;
        }

        foreach ($config as $key => $default) {
            if ($key === 'mail_password') {
                $value = $this->fsVar->simple_get_decrypted($key);
            } else {
                $value = $this->fsVar->simple_get($key);
            }

            if ($value !== false) {
                if ($key === 'mail_port') {
                    $config[$key] = (int) $value;
                } elseif ($key === 'mail_low_security') {
                    $config[$key] = (bool) $value;
                } else {
                    $config[$key] = $value;
                }
            }
        }

        self::$configCache = $config;
        return $config;
    }

    /**
     * Guarda la configuración de email.
    *
     * @param array $config
     * @return bool
     */
    public function saveConfig(array $config): bool
    {
        if ($this->fsVar === null) {
            return false;
        }

        $success = true;
        foreach (self::CONFIG_KEYS as $key => $default) {
            if (!isset($config[$key])) {
                continue;
            }

            $value = $config[$key];

            if ($key === 'mail_password') {
                if (!$this->fsVar->simple_save_encrypted($key, $value)) {
                    $success = false;
                }
            } elseif ($key === 'mail_low_security') {
                if (!$this->fsVar->simple_save($key, $value ? '1' : '0')) {
                    $success = false;
                }
            } else {
                if (!$this->fsVar->simple_save($key, (string) $value)) {
                    $success = false;
                }
            }
        }

        self::$configCache = null;
        return $success;
    }

    /**
     * Verifica si hay configuración SMTP válida.
    *
     * @return bool
     */
    public function canSendMail(): bool
    {
        $config = $this->getConfig();
        $mailer = (string) ($config['mail_mailer'] ?? 'smtp');

        if (in_array($mailer, ['mail', 'sendmail'], true)) {
            return true;
        }

        if ($mailer !== 'smtp' || !$this->hasSmtpConfig()) {
            return false;
        }

        return $this->allowsSmtpWithoutAuth($config)
            || ($config['mail_user'] !== '' && $config['mail_password'] !== '');
    }

    /**
     * Verifica si hay configuración SMTP (no usa mail() nativo).
    *
     * @return bool
     */
    public function hasSmtpConfig(): bool
    {
        $config = $this->getConfig();
        return !empty($config['mail_host'])
            && $config['mail_mailer'] === 'smtp'
            && (int) $config['mail_port'] > 0;
    }

    /**
     * Crea una instancia de PHPMailer configurada según la configuración del sistema.
     * Si no hay SMTP configurado, usa mail() nativo como fallback.
    *
     * @param string|null $fromEmail Email del remitente (opcional, usa config si no se especifica)
     * @param string|null $fromName Nombre del remitente (opcional, usa config si no se especifica)
     * @return PHPMailer
     */
    public function createMailer(?string $fromEmail = null, ?string $fromName = null): PHPMailer
    {
        $config = $this->getConfig();
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->WordWrap = 50;

        if ($this->hasSmtpConfig()) {
            $mail->Mailer = 'smtp';
            $mail->Host = $config['mail_host'];
            $mail->Port = $config['mail_port'];

            $hasCredentials = $config['mail_user'] !== '' && $config['mail_password'] !== '';
            $mail->SMTPAuth = $hasCredentials;
            if ($hasCredentials) {
                $mail->Username = $config['mail_user'];
                $mail->Password = $config['mail_password'];
            }

            if (!empty($config['mail_enc'])) {
                $mail->SMTPSecure = $config['mail_enc'];
            }

            if ($config['mail_low_security']) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ];
            }
        } else {
            $mail->Mailer = $config['mail_mailer'] ?: 'mail';
        }

        $mail->From = $fromEmail ?: $config['mail_from_email'] ?: $this->getDefaultFromEmail();
        $mail->FromName = $fromName ?: $config['mail_from_name'] ?: $this->getDefaultFromName();

        return $mail;
    }

    /**
     * Envía un email de forma sencilla.
    *
     * @param string $to Destinatario
     * @param string $subject Asunto
     * @param string $body Cuerpo HTML del mensaje
     * @param string|null $toName Nombre del destinatario (opcional)
     * @param string|null $fromEmail Email del remitente (opcional)
     * @param string|null $fromName Nombre del remitente (opcional)
     * @return bool
     */
    public function send(
        string $to,
        string $subject,
        string $body,
        ?string $toName = null,
        ?string $fromEmail = null,
        ?string $fromName = null
    ): bool {
        $this->lastError = '';

        if (!$this->canSendMail()) {
            $this->lastError = $this->getMissingConfigurationMessage();
            error_log('FSFramework MailService: ' . $this->lastError);
            return false;
        }

        try {
            $mail = $this->createMailer($fromEmail, $fromName);
            $mail->addAddress($to, $toName ?? '');
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));

            return $mail->send();
        } catch (PHPMailerException $e) {
            $this->lastError = $e->getMessage();
            error_log('FSFramework MailService: Error enviando email: ' . $this->lastError);
            return false;
        }
    }

    /**
     * Prueba la conexión SMTP.
    *
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConnection(): array
    {
        if (!$this->canSendMail()) {
            return [
                'success' => false,
                'message' => $this->getMissingConfigurationMessage()
            ];
        }

        if (!$this->hasSmtpConfig()) {
            if ($this->getConfig()['mail_mailer'] === 'mail') {
                return [
                    'success' => true,
                    'message' => 'Usando mail() nativo de PHP. No se puede probar la conexión.'
                ];
            }
            return [
                'success' => false,
                'message' => 'No hay configuración SMTP definida.'
            ];
        }

        if (!extension_loaded('openssl')) {
            return [
                'success' => false,
                'message' => 'La extensión OpenSSL no está disponible. Es necesaria para conexiones SMTP seguras.'
            ];
        }

        try {
            $mail = $this->createMailer();
            $mail->Timeout = 5;
            $mail->SMTPDebug = 0;

            if ($mail->smtpConnect()) {
                $mail->smtpClose();
                return [
                    'success' => true,
                    'message' => 'Conexión SMTP exitosa.'
                ];
            }

            return [
                'success' => false,
                'message' => 'No se pudo conectar al servidor SMTP. Verifica los datos de configuración.'
            ];
        } catch (PHPMailerException $e) {
            return [
                'success' => false,
                'message' => 'Error de conexión: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene el email de remitente por defecto.
    *
     * @return string
     */
    private function getDefaultFromEmail(): string
    {
        $domain = $this->getTrustedDomain();
        return 'no-reply@' . $domain;
    }

    /**
     * Obtiene el nombre de remitente por defecto.
    *
     * @return string
     */
    private function getDefaultFromName(): string
    {
        return 'FSFramework';
    }

    /**
     * Obtiene el dominio de confianza para construir emails.
    *
     * @return string
     */
    private function getTrustedDomain(): string
    {
        if (defined('FS_DOMAIN') && !empty(FS_DOMAIN)) {
            return FS_DOMAIN;
        }

        if (!empty($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] !== '_') {
            $domain = $this->sanitizeTrustedDomain((string) $_SERVER['SERVER_NAME']);
            if ($domain !== null) {
                return $domain;
            }
        }

        return 'localhost';
    }

    private function sanitizeTrustedDomain(string $domain): ?string
    {
        $normalized = strtolower(trim($domain));
        if ($normalized === '' || $normalized === '_') {
            return null;
        }

        if (str_contains($normalized, '/')) {
            return null;
        }

        $normalized = preg_replace('/:\d+$/', '', $normalized) ?? '';
        $normalized = trim($normalized, '.');
        if ($normalized === '' || $normalized === 'localhost') {
            return $normalized === '' ? null : 'localhost';
        }

        if (!preg_match('/^[a-z0-9.-]+$/', $normalized)) {
            return null;
        }

        return filter_var($normalized, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            ? $normalized
            : null;
    }

    /**
     * Limpia la caché de configuración.
     */
    public static function clearCache(): void
    {
        self::$configCache = null;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    private function allowsSmtpWithoutAuth(array $config): bool
    {
        $host = strtolower(trim((string) ($config['mail_host'] ?? '')));
        $port = (int) ($config['mail_port'] ?? 0);

        return $port === 1025 && in_array($host, ['localhost', '127.0.0.1', 'mailpit'], true);
    }

    private function getMissingConfigurationMessage(): string
    {
        $config = $this->getConfig();
        $mailer = (string) ($config['mail_mailer'] ?? 'smtp');

        if ($mailer !== 'smtp') {
            return 'El método de envío seleccionado no está configurado correctamente.';
        }

        if (!$this->hasSmtpConfig()) {
            return 'La configuración SMTP está incompleta: indica servidor y puerto.';
        }

        if (!$this->allowsSmtpWithoutAuth($config)
            && ($config['mail_user'] === '' || $config['mail_password'] === '')
        ) {
            return 'La configuración SMTP está incompleta: indica usuario y contraseña SMTP.';
        }

        return 'La configuración de email está incompleta.';
    }

    /**
     * Opciones de encriptación disponibles.
    *
     * @return array
     */
    public static function getEncryptionOptions(): array
    {
        return [
            'ssl' => 'SSL',
            'tls' => 'TLS',
            '' => 'Ninguna'
        ];
    }

    /**
     * Opciones de mailer disponibles.
    *
     * @return array
     */
    public static function getMailerOptions(): array
    {
        return [
            'smtp' => 'SMTP',
            'mail' => 'Mail (PHP nativo)',
            'sendmail' => 'SendMail'
        ];
    }
}
