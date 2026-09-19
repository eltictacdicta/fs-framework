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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

require_once FS_FOLDER . '/base/fs_core_log.php';
require_once FS_FOLDER . '/base/fs_functions.php';
require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/model/fs_page.php';
require_once FS_FOLDER . '/model/core/fs_user.php';
require_once FS_FOLDER . '/base/fs_controller.php';

/**
 * The declaration in code is the security boundary, not the database row.
 *
 * `fs_pages.admin_only` exists so the role listing can hide admin-only pages
 * without instantiating every controller. It is an index, not the gate: if the
 * migration never ran, the row would still read `false` and a database-only
 * check would let a non-admin straight into `admin_users`.
 *
 * These tests pin the gate that makes that impossible: the concrete controller
 * is already instantiated at access time, so its `#[AdminOnly]` attribute is
 * consulted directly and wins over the persisted row.
 */
#[\FSFramework\Attribute\AdminOnly]
final class AdminOnlyGateFixtureController extends \fs_controller
{
    public function __construct()
    {
        // Intentionally skip the heavy parent constructor (no DB).
    }

    public function evaluate(): bool
    {
        $method = new ReflectionMethod(\fs_controller::class, 'isAccessAllowed');
        $method->setAccessible(true);

        return (bool) $method->invoke($this);
    }

    public function withUser(object $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function withPage(string $name): self
    {
        $this->page = new \fs_page([
            'name' => $name,
            'title' => $name,
            'folder' => 'admin',
            'show_on_menu' => true,
            'important' => false,
            'orden' => 100,
        ]);

        return $this;
    }
}

/**
 * Same shape, without the attribute: the control case.
 */
final class OrdinaryGateFixtureController extends \fs_controller
{
    public function __construct()
    {
        // Intentionally skip the heavy parent constructor (no DB).
    }

    public function evaluate(): bool
    {
        $method = new ReflectionMethod(\fs_controller::class, 'isAccessAllowed');
        $method->setAccessible(true);

        return (bool) $method->invoke($this);
    }

    public function withUser(object $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function withPage(string $name): self
    {
        $this->page = new \fs_page([
            'name' => $name,
            'title' => $name,
            'folder' => 'admin',
            'show_on_menu' => true,
            'important' => false,
            'orden' => 100,
        ]);

        return $this;
    }
}

final class AdminOnlyAccessGateTest extends TestCase
{
    /**
     * A non-admin is denied an admin-only page even though the user object
     * grants it through the menu (the stale-grant shape) — and even though no
     * fs_pages row was consulted at all.
     */
    #[Test]
    public function nonAdminIsDeniedAnAdminOnlyPageEvenWhenTheMenuGrantsIt(): void
    {
        $user = $this->user(admin: false, grants: ['admin_users' => true]);
        $controller = (new AdminOnlyGateFixtureController())->withUser($user)->withPage('admin_users');

        self::assertFalse(
            $controller->evaluate(),
            'The #[AdminOnly] declaration must deny a non-admin regardless of the persisted row.'
        );
    }

    #[Test]
    public function adminIsAllowedAnAdminOnlyPage(): void
    {
        $user = $this->user(admin: true, grants: []);
        $controller = (new AdminOnlyGateFixtureController())->withUser($user)->withPage('admin_users');

        self::assertTrue($controller->evaluate());
    }

    /**
     * The control case: an ordinary page keeps behaving exactly as before, so
     * the attribute check does not over-block.
     */
    #[Test]
    public function ordinaryPageFallsThroughToTheMenu(): void
    {
        $granted = $this->user(admin: false, grants: ['ventas' => true]);
        $denied = $this->user(admin: false, grants: []);

        self::assertTrue(
            (new OrdinaryGateFixtureController())->withUser($granted)->withPage('ventas')->evaluate()
        );
        self::assertFalse(
            (new OrdinaryGateFixtureController())->withUser($denied)->withPage('ventas')->evaluate()
        );
    }

    /**
     * The gate must read the CONCRETE controller class, not the requested page
     * name: a subclass that inherits an admin-only declaration stays protected.
     */
    #[Test]
    public function theGateReadsTheConcreteControllerClass(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/base/fs_controller.php');

        self::assertStringContainsString(
            'is_admin_only_class(\\get_class($this))',
            $source,
            'The legacy gate must resolve the attribute from the concrete controller.'
        );
    }

    /**
     * Minimal fs_user double: the gate only reads `admin` and `have_access_to()`.
     *
     * @param array<string, bool> $grants page name => access
     */
    private function user(bool $admin, array $grants): object
    {
        return new class ($admin, $grants) {
            /** @param array<string, bool> $grants */
            public function __construct(
                public bool $admin,
                private array $grants
            ) {
            }

            public function have_access_to(string $pageName): bool
            {
                return $this->grants[$pageName] ?? false;
            }
        };
    }
}
