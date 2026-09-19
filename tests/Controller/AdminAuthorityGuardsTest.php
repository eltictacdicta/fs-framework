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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Controller\Concerns\ExtractsMethodBody;

/**
 * Guards for authority-mutating admin actions.
 *
 * fs_controller's `$admin` constructor flag is OBSOLETO and ignored
 * (base/fs_controller.php:187): it is NOT a declaration source. Admin-only
 * pages are declared with the `#[AdminOnly]` class attribute and enforced by
 * the listing filters, `fs_rol_access::save()` and `fs_user::get_menu()`.
 *
 * That enforcement is defense in depth, not a substitute for per-controller
 * authorization: a page is still only as protected as the code that runs in
 * it, so every method below — which mutates authority: users, roles, role
 * permissions, or the global menu order — must re-check the admin flag at its
 * own entry point.
 *
 * Static source analysis is used for the same reason as
 * AdminUserInputAccessTest: instantiating fs_controller boots a database
 * connection plus user, menu, extensions and plugins.
 */
#[CoversClass(\admin_users::class)]
#[CoversClass(\admin_user::class)]
#[CoversClass(\admin_rol::class)]
#[CoversClass(\admin_orden_menu::class)]
final class AdminAuthorityGuardsTest extends TestCase
{
    use ExtractsMethodBody;

    /**
     * @return array<string, array{string, string}>
     */
    public static function authorityMutatingMethods(): array
    {
        return [
            // User management.
            'admin_users::add_user' => ['/controller/admin_users.php', 'add_user'],
            'admin_users::delete_user' => ['/controller/admin_users.php', 'delete_user'],
            'admin_user::desactivar_usuario' => ['/controller/admin_user.php', 'desactivar_usuario'],
            // Role management: this is what grants permissions to everyone else.
            'admin_users::add_rol' => ['/controller/admin_users.php', 'add_rol'],
            'admin_users::delete_rol' => ['/controller/admin_users.php', 'delete_rol'],
            'admin_rol::modify' => ['/controller/admin_rol.php', 'modify'],
            'admin_user::aplicar_roles' => ['/controller/admin_user.php', 'aplicar_roles'],
            // Global, shared-state mutation.
            'admin_orden_menu::guardar_orden' => ['/controller/admin_orden_menu.php', 'guardar_orden'],
        ];
    }

    #[Test]
    #[DataProvider('authorityMutatingMethods')]
    public function authorityMutatingMethodRequiresAdmin(string $relativePath, string $method): void
    {
        $path = dirname(__DIR__, 2) . $relativePath;
        $this->assertFileExists($path);

        $body = $this->methodBody((string) file_get_contents($path), $method);

        $this->assertStringContainsString(
            '$this->user->admin',
            $body,
            sprintf(
                '%s::%s() mutates authority and must check $this->user->admin at its entry point.',
                basename($relativePath),
                $method
            )
        );
    }
}
