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
            // Default landing page: every logged-in user reaches this one.
            'admin_home::exec_actions' => ['/controller/admin_home.php', 'exec_actions'],
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

    /**
     * `admin_home` is the default landing page, so it is reachable by every
     * logged-in user. `exec_actions()` used to check the admin flag *after*
     * the `updated` branch, letting a normal user delete the `updates` var,
     * flip `check_db_types` and flush the cache with a plain GET.
     *
     * Presence of the guard is not enough: it has to come first.
     */
    #[Test]
    public function adminHomeActionsAreGuardedBeforeAnyMutation(): void
    {
        $path = dirname(__DIR__, 2) . '/controller/admin_home.php';
        $this->assertFileExists($path);

        $body = $this->methodBody((string) file_get_contents($path), 'exec_actions');

        $guard = strpos($body, '$this->user->admin');
        $updatedBranch = strpos($body, "filter_input(INPUT_GET, 'updated')");
        $mutation = strpos($body, 'activar_comprobacion_columnas');

        $this->assertNotFalse($guard, 'exec_actions() must check $this->user->admin.');
        $this->assertNotFalse($updatedBranch, 'The `updated` branch must still exist.');
        $this->assertNotFalse($mutation, 'The `updated` branch must still mutate state.');

        $this->assertLessThan(
            $updatedBranch,
            $guard,
            'The admin guard must run before the `updated` branch.'
        );
        $this->assertLessThan(
            $mutation,
            $guard,
            'The admin guard must run before activar_comprobacion_columnas().'
        );
    }

    /**
     * `check_ca_bundle_update()` writes base/cacert.pem and performs an
     * outbound request. It must not be reachable from a non-admin session.
     */
    #[Test]
    public function adminHomeCaBundleUpdateIsAdminOnly(): void
    {
        $path = dirname(__DIR__, 2) . '/controller/admin_home.php';
        $this->assertFileExists($path);

        $body = $this->methodBody((string) file_get_contents($path), 'private_core');

        $guard = strpos($body, '$this->user->admin');
        $call = strpos($body, 'check_ca_bundle_update()');

        $this->assertNotFalse($guard, 'private_core() must gate the CA bundle update on admin.');
        $this->assertNotFalse($call, 'private_core() must still call check_ca_bundle_update().');

        $this->assertLessThan(
            $call,
            $guard,
            'The admin guard must run before check_ca_bundle_update().'
        );
    }
}
