<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Core;

use FSFramework\Core\Base\ControllerPermissions;
use PHPUnit\Framework\TestCase;

/**
 * Effective permissions must fail closed: a controller that reads a flag
 * without deriving it from the user's role should not get permission by
 * default.
 */
final class ControllerPermissionsTest extends TestCase
{
    public function testEveryPermissionDefaultsToDenied(): void
    {
        $permissions = new ControllerPermissions();

        $this->assertFalse($permissions->allowAccess);
        $this->assertFalse($permissions->allowDelete);
        $this->assertFalse($permissions->allowUpdate);
        $this->assertFalse($permissions->allowImport);
        $this->assertFalse($permissions->allowExport);
    }
}
