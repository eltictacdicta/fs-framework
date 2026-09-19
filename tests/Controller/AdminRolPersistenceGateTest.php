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

use PHPUnit\Framework\Attributes\CoversClass;
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
#[CoversClass(\admin_rol::class)]
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

    /**
     * A partial POST that omits a section must NOT be read as "nothing checked".
     *
     * `modify()` deletes every grant of a section when the corresponding array is
     * absent (`!$enabled` / `!$idusers`). Without a per-section marker, a POST
     * carrying only `descripcion` would therefore wipe every page permission and
     * every user assignment of the role.
     */
    #[Test]
    public function eachSectionIsGatedOnItsOwnFormMarker(): void
    {
        $this->assertFileExists(self::TARGET_FILE);

        $source = (string) file_get_contents(self::TARGET_FILE);
        $body = $this->methodBody($source, 'modify');

        $this->assertStringContainsString(
            "has('pages_form_present')",
            $body,
            'The page-permission section must only run when pages_form_present travelled in the POST.'
        );
        $this->assertStringContainsString(
            "has('users_form_present')",
            $body,
            'The user-assignment section must only run when users_form_present travelled in the POST.'
        );
    }

    /**
     * The template must actually send both markers, or the guards would make the
     * form a no-op from the real UI.
     */
    #[Test]
    public function theRoleTemplateSendsBothSectionMarkers(): void
    {
        $template = dirname(__DIR__, 2) . '/themes/AdminLTE/view/admin_rol.html.twig';
        $this->assertFileExists($template);

        $source = (string) file_get_contents($template);

        $this->assertStringContainsString('name="pages_form_present"', $source);
        $this->assertStringContainsString('name="users_form_present"', $source);
    }
}
