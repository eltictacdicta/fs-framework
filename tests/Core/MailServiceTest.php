<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
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
 *
 * Tests para MailService.
 */

namespace Tests\Core;

require_once __DIR__ . '/../../model/fs_var.php';

use FSFramework\Core\MailService;
use PHPUnit\Framework\TestCase;

class MailServiceTest extends TestCase
{
    protected function setUp(): void
    {
        MailService::clearCache();
    }

    public function testSaveConfigSkipsPasswordEncryptionWhenPasswordIsNotSubmitted(): void
    {
        $fsVar = new class() extends \fs_var {
            public array $saved = [];
            public int $encryptedSaves = 0;

            public function __construct()
            {
                // Skip fs_var DB initialization; this double only records save calls.
            }

            public function simple_save($name, $value)
            {
                $this->saved[$name] = $value;
                return true;
            }

            public function simple_save_encrypted($name, $value)
            {
                $this->encryptedSaves++;
                return false;
            }
        };

        $service = new MailService($fsVar);

        $result = $service->saveConfig([
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.com',
            'mail_port' => 587,
            'mail_user' => 'user@example.com',
            'mail_enc' => 'tls',
            'mail_low_security' => false,
            'mail_from_email' => 'noreply@example.com',
            'mail_from_name' => 'Example',
        ]);

        $this->assertTrue($result);
        $this->assertSame(0, $fsVar->encryptedSaves);
        $this->assertSame('smtp.example.com', $fsVar->saved['mail_host']);
        $this->assertArrayNotHasKey('mail_password', $fsVar->saved);
    }

    public function testSaveConfigClearsPasswordWhenEmptyPasswordIsSubmitted(): void
    {
        $fsVar = new class() extends \fs_var {
            public array $saved = [];
            public int $encryptedSaves = 0;

            public function __construct()
            {
                // Skip fs_var DB initialization; this double only records save calls.
            }

            public function simple_save($name, $value)
            {
                $this->saved[$name] = $value;
                return true;
            }

            public function simple_save_encrypted($name, $value)
            {
                $this->encryptedSaves++;
                return true;
            }
        };

        $service = new MailService($fsVar);

        $result = $service->saveConfig([
            'mail_password' => '',
        ]);

        $this->assertTrue($result);
        $this->assertSame('', $fsVar->saved['mail_password']);
        $this->assertSame(0, $fsVar->encryptedSaves);
    }

    public function testCanSendMailRejectsAuthenticatedSmtpWithoutUser(): void
    {
        $service = new MailService($this->createFsVarDouble([
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.com',
            'mail_port' => '465',
            'mail_user' => '',
            'mail_password' => 'secret',
            'mail_enc' => 'ssl',
            'mail_low_security' => '0',
            'mail_from_email' => 'noreply@example.com',
            'mail_from_name' => 'Example',
        ]));

        $this->assertFalse($service->canSendMail());
        $this->assertSame(
            'La configuración SMTP está incompleta: indica usuario y contraseña SMTP.',
            $service->testConnection()['message']
        );
    }

    public function testCanSendMailAcceptsAuthenticatedSmtpWithCredentials(): void
    {
        $service = new MailService($this->createFsVarDouble([
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.com',
            'mail_port' => '465',
            'mail_user' => 'user@example.com',
            'mail_password' => 'secret',
            'mail_enc' => 'ssl',
            'mail_low_security' => '0',
            'mail_from_email' => 'noreply@example.com',
            'mail_from_name' => 'Example',
        ]));

        $this->assertTrue($service->canSendMail());
    }

    public function testCanSendMailAllowsLocalMailpitWithoutCredentials(): void
    {
        $service = new MailService($this->createFsVarDouble([
            'mail_mailer' => 'smtp',
            'mail_host' => 'localhost',
            'mail_port' => '1025',
            'mail_user' => '',
            'mail_password' => '',
            'mail_enc' => '',
            'mail_low_security' => '0',
            'mail_from_email' => 'noreply@test.local',
            'mail_from_name' => 'Sistema de Pruebas',
        ]));

        $this->assertTrue($service->canSendMail());
    }

    public function testSaveConfigNormalizesTlsOnPort465ToSsl(): void
    {
        $fsVar = new class() extends \fs_var {
            public array $saved = [];

            public function __construct()
            {
                // Skip fs_var DB initialization; this double only records save calls.
            }

            public function simple_save($name, $value)
            {
                $this->saved[$name] = $value;
                return true;
            }
        };

        $service = new MailService($fsVar);
        $result = $service->saveConfig([
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.com',
            'mail_port' => 465,
            'mail_user' => 'user@example.com',
            'mail_password' => 'secret',
            'mail_enc' => 'tls',
            'mail_low_security' => false,
            'mail_from_email' => 'noreply@example.com',
            'mail_from_name' => 'Example',
        ]);

        $this->assertTrue($result);
        $this->assertSame('ssl', $fsVar->saved['mail_enc']);
    }

    public function testCreateMailerNormalizesTlsOnPort465ToSsl(): void
    {
        $service = new MailService($this->createFsVarDouble([
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.com',
            'mail_port' => '465',
            'mail_user' => 'user@example.com',
            'mail_password' => 'secret',
            'mail_enc' => 'tls',
            'mail_low_security' => '0',
            'mail_from_email' => 'noreply@example.com',
            'mail_from_name' => 'Example',
        ]));

        $this->assertSame('ssl', $service->createMailer()->SMTPSecure);
    }

    private function createFsVarDouble(array $values): \fs_var
    {
        return new class($values) extends \fs_var {
            public function __construct(private array $values)
            {
                // Skip fs_var DB initialization; this double reads from memory.
            }

            public function simple_get($name)
            {
                return $this->values[$name] ?? false;
            }

            public function simple_get_decrypted($name)
            {
                return $this->simple_get($name);
            }
        };
    }
}
