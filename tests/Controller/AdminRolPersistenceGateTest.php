<?php
declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Controller\Concerns\ExtractsMethodBody;

/**
 * Regression guard for the role edit form.
 *
 * admin_rol::private_core() used to gate the save on a NON-EMPTY description:
 *
 *     if (filter_input(INPUT_POST, 'descripcion')) { $this->modify(); }
 *
 * The description input in admin_rol.html.twig is not `required`, so clearing
 * it silently discarded every page permission (enabled[] / allow_delete[]) and
 * every user assignment (iuser[]) of the role, with no error shown. The gate
 * must be the request method, never a field value.
 *
 * Static source analysis is used for the same reason as
 * AdminUserInputAccessTest: instantiating fs_controller boots a database
 * connection plus user, menu, extensions and plugins.
 */
final class AdminRolPersistenceGateTest extends TestCase
{
    use ExtractsMethodBody;

    private const TARGET_FILE = __DIR__ . '/../../controller/admin_rol.php';

    #[Test]
    public function roleFormPostIsGatedOnTheRequestMethodNotOnTheDescription(): void
    {
        $this->assertFileExists(self::TARGET_FILE);

        $body = $this->methodBody((string) file_get_contents(self::TARGET_FILE), 'private_core');

        $this->assertStringContainsString(
            "isMethod('POST')",
            $body,
            'admin_rol::private_core() must process the role form on POST.'
        );

        $this->assertStringNotContainsString(
            'descripcion',
            $body,
            'admin_rol::private_core() must NOT gate the save on the description value: '
            . 'an empty description silently discarded page permissions and user assignments.'
        );
    }
}
