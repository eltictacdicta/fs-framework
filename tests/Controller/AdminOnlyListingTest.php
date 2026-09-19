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

require_once FS_FOLDER . '/base/fs_controller.php';
require_once FS_FOLDER . '/controller/admin_rol.php';
require_once FS_FOLDER . '/controller/admin_users.php';
require_once FS_FOLDER . '/controller/admin_user.php';

/**
 * Page double for the role/user matrices.
 */
final class ListingPageStub
{
    public bool $enabled = false;
    public bool $allow_delete = false;

    /** @var array<string, mixed> */
    public array $users = [];

    public function __construct(
        public string $name,
        public bool $admin_only
    ) {
    }
}

/**
 * `$this->user` double: `admin_users::all_pages()` calls `all()`.
 */
final class ListingUserStub
{
    /**
     * @return array<int, object>
     */
    public function all(): array
    {
        return [];
    }
}

/**
 * `$this->rol` double: `admin_rol::all_pages()` calls `get_accesses()`.
 */
final class ListingRolStub
{
    /**
     * @param array<int, object> $accesses
     */
    public function __construct(private array $accesses)
    {
    }

    /**
     * @return array<int, object>
     */
    public function get_accesses(): array
    {
        return $this->accesses;
    }
}

/**
 * `$this->suser` double: `admin_user::all_pages()` calls
 * `get_role_allowed_pages()`.
 */
final class ListingSuserStub
{
    /**
     * @param array<string, array<string, mixed>> $allowed
     */
    public function __construct(private array $allowed)
    {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_role_allowed_pages(): array
    {
        return $this->allowed;
    }
}

/**
 * PA-04: every page matrix excludes administrator-only pages for every actor.
 *
 * Instantiating a controller boots a database connection, so the tests use
 * `newInstanceWithoutConstructor()` plus reflection, with stubs for the user,
 * role and subject-user collaborators.
 */
final class AdminOnlyListingTest extends TestCase
{
    /**
     * @return array<int, ListingPageStub>
     */
    private function menuPages(): array
    {
        return [
            new ListingPageStub('admin_users', true),
            new ListingPageStub('ventas', false),
        ];
    }

    /**
     * @return array<int, object>
     */
    private function accesses(): array
    {
        return [
            (object) ['fs_page' => 'admin_users', 'allow_delete' => true],
            (object) ['fs_page' => 'ventas', 'allow_delete' => false],
        ];
    }

    private function setProperty(object $object, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($object, $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    /**
     * @param array<int, object> $pages
     * @return list<string>
     */
    private function pageNames(array $pages): array
    {
        return array_values(array_map(
            static fn (object $page): string => (string) $page->name,
            $pages
        ));
    }

    /**
     * The filter is unconditional by design, so the actor dimension is not a
     * variable here: it must hold for an administrator too. The injected menu
     * deliberately CONTAINS the admin-only page (the shape an admin's
     * get_menu() returns), so the assertion proves the filter did the work
     * rather than the fixture hiding it.
     */
    #[Test]
    public function adminRolMatrixExcludesAdminOnlyPages(): void
    {
        $controller = (new \ReflectionClass(\admin_rol::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'menu', $this->menuPages());
        $this->setProperty($controller, 'rol', new ListingRolStub($this->accesses()));

        $names = $this->pageNames($controller->all_pages());

        self::assertSame(['ventas'], $names);
        self::assertNotContains('admin_users', $names);
    }

    #[Test]
    public function adminUsersMatrixExcludesAdminOnlyPages(): void
    {
        $controller = (new \ReflectionClass(\admin_users::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'menu', $this->menuPages());
        $this->setProperty($controller, 'user', new ListingUserStub());

        $names = $this->pageNames($controller->all_pages());

        self::assertSame(['ventas'], $names);
        self::assertNotContains('admin_users', $names);
    }

    #[Test]
    public function adminUserMatrixExcludesAdminOnlyPagesEvenWithAStaleGrant(): void
    {
        $controller = (new \ReflectionClass(\admin_user::class))->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'menu', $this->menuPages());
        $this->setProperty($controller, 'suser', new ListingSuserStub([
            'admin_users' => ['allow_delete' => true],
            'ventas' => ['allow_delete' => false],
        ]));

        $names = $this->pageNames($controller->all_pages());

        self::assertSame(['ventas'], $names);
        self::assertNotContains('admin_users', $names);
    }
}
