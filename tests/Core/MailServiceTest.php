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
}
