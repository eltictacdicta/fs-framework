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
 * Minimal page double carrying the properties `compose_menu()` and the
 * default-page fallthrough read.
 */
final class MenuPageStub
{
    public function __construct(
        public string $name,
        public bool $admin_only,
        public bool $show_on_menu = true
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
     * FS_DEMO was removed. This test guards that a non-admin with no demo flag
     * keeps only its granted ordinary pages — the demo bypass must never return.
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

    /**
     * PA-06 propagation: a non-admin whose STORED default page is admin-only
     * must not land there.
     *
     * select_default_page() redirects only when `have_access_to($homePage)` is
     * true, so the filtered menu makes it fall through to the first visible
     * page. This models that decision without invoking header()/exit().
     */
    #[Test]
    public function adminOnlyDefaultPageFallsThroughForANonAdmin(): void
    {
        $user = (new \ReflectionClass(fs_user::class))->newInstanceWithoutConstructor();
        $menuProp = new \ReflectionProperty(fs_user::class, 'menu');
        $menuProp->setAccessible(true);
        $menuProp->setValue($user, fs_user::compose_menu(
            array_values($this->pages()),
            $this->allowedWithStaleGrant(),
            false
        ));

        // The stored default page is admin-only and the stale grant "allows" it.
        self::assertFalse(
            $user->have_access_to('admin_users'),
            'select_default_page() must not honour an admin-only default page.'
        );

        $fallback = null;
        foreach ($user->get_menu() as $page) {
            if ($page->show_on_menu) {
                $fallback = $page->name;
                break;
            }
        }

        self::assertSame('ventas', $fallback, 'The first visible ordinary page wins the fallthrough.');
    }

    /**
     * The propagation is only real while select_default_page() keeps gating on
     * the access check; pin it so a future edit cannot bypass the filter.
     */
    #[Test]
    public function defaultPageSelectionConsumesTheAccessGate(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/base/fs_controller.php');
        $body = $this->methodBody($source, 'select_default_page');

        self::assertStringContainsString('have_access_to', $body);
    }

    /**
     * Regression guard: FS_DEMO was fully removed from the codebase. This test
     * ensures the constant never reappears in authority methods. Comments are
     * stripped so documentation prose does not trigger a false positive.
     */
    #[Test]
    public function userAuthorityNeverReadsFsDemo(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/model/core/fs_user.php');

        foreach (['get_menu', 'compose_menu', 'allow_delete_on'] as $method) {
            $code = self::withoutComments($this->methodBody($source, $method));

            self::assertStringNotContainsString(
                'FS_DEMO',
                $code,
                $method . '() must not read FS_DEMO: it granted authority unconditionally.'
            );
        }
    }

    /**
     * Drops comments and docblocks so a test can assert on executable code
     * without tripping over the prose that explains it.
     */
    private static function withoutComments(string $php): string
    {
        // The fragment has no `<?php` tag, so it must be added or the whole
        // body tokenizes as inline HTML and comments are never recognised.
        $code = '';

        foreach (token_get_all('<?php ' . $php) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];
                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
