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

namespace Tests\Base;

use FSFramework\model\fs_user;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Controller\Concerns\ExtractsMethodBody;

require_once FS_FOLDER . '/model/core/fs_user.php';

/**
 * Minimal page double carrying the two properties `compose_menu()` reads.
 */
final class MenuPageStub
{
    public function __construct(
        public string $name,
        public bool $admin_only
    ) {
    }
}

/**
 * PA-06 / PA-09 / PA-10: access enforcement at the menu source.
 *
 * `compose_menu()` is the pure function extracted from `get_menu()` so the
 * filtering rule can be verified without a database or a session.
 */
final class FsUserComposeMenuTest extends TestCase
{
    use ExtractsMethodBody;

    /**
     * @return array<string, MenuPageStub>
     */
    private function pages(): array
    {
        return [
            'admin_users' => new MenuPageStub('admin_users', true),
            'ventas' => new MenuPageStub('ventas', false),
            'compras' => new MenuPageStub('compras', false),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function allowedWithStaleGrant(): array
    {
        return [
            'admin_users' => ['allow_delete' => true],
            'ventas' => ['allow_delete' => false],
        ];
    }

    #[Test]
    public function nonAdminMenuDropsAdminOnlyPagesEvenWithAStaleGrant(): void
    {
        $menu = fs_user::compose_menu(array_values($this->pages()), $this->allowedWithStaleGrant(), false);

        $names = array_map(static fn (MenuPageStub $page): string => $page->name, $menu);

        self::assertSame(['ventas'], $names);
        self::assertNotContains('admin_users', $names, 'A stale grant must not expose an admin-only page.');
    }

    #[Test]
    public function nonAdminMenuKeepsOnlyGrantedOrdinaryPages(): void
    {
        $menu = fs_user::compose_menu(array_values($this->pages()), [], false);

        self::assertSame([], $menu, 'Without grants a non-admin has no pages.');
    }

    #[Test]
    public function adminKeepsTheFullList(): void
    {
        $menu = fs_user::compose_menu(array_values($this->pages()), [], true);

        $names = array_map(static fn (MenuPageStub $page): string => $page->name, $menu);

        self::assertSame(['admin_users', 'ventas', 'compras'], $names);
    }

    /**
     * FS_DEMO must never widen authority. It used to return the full page list
     * (including admin_users and admin_rol) and skip roles entirely, so a demo
     * deployment on real data exposed the permission system. Only $admin does.
     */
    #[Test]
    public function demoModeDoesNotWidenAuthority(): void
    {
        $menu = fs_user::compose_menu(array_values($this->pages()), $this->allowedWithStaleGrant(), false);

        $names = array_map(static fn (MenuPageStub $page): string => $page->name, $menu);

        self::assertSame(['ventas'], $names, 'A non-admin in demo mode keeps only its granted ordinary pages.');
        self::assertNotContains('admin_users', $names, 'Demo mode must not expose admin-only pages.');
    }

    #[Test]
    public function getMenuDelegatesToComposeMenu(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/model/core/fs_user.php');
        $body = $this->methodBody($source, 'get_menu');

        self::assertStringContainsString('compose_menu', $body);
        self::assertStringContainsString('get_role_allowed_pages', $body);
    }

    #[Test]
    public function accessGateConsumesTheFilteredMenu(): void
    {
        $user = (new \ReflectionClass(fs_user::class))->newInstanceWithoutConstructor();
        $menuProp = new \ReflectionProperty(fs_user::class, 'menu');
        $menuProp->setAccessible(true);
        $menuProp->setValue($user, fs_user::compose_menu(
            array_values($this->pages()),
            $this->allowedWithStaleGrant(),
            false
        ));

        self::assertFalse($user->have_access_to('admin_users'), 'A stale grant must not grant access.');
        self::assertTrue($user->have_access_to('ventas'));
    }
}
