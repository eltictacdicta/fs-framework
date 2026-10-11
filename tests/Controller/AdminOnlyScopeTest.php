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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/model/fs_page.php';
require_once FS_FOLDER . '/base/fs_controller.php';
require_once FS_FOLDER . '/base/fs_list_controller.php';
require_once FS_FOLDER . '/controller/admin_users.php';
require_once FS_FOLDER . '/controller/admin_user.php';
require_once FS_FOLDER . '/controller/admin_rol.php';
require_once FS_FOLDER . '/controller/admin_info.php';
require_once FS_FOLDER . '/controller/admin_email.php';
require_once FS_FOLDER . '/controller/admin_system_branding.php';
require_once FS_FOLDER . '/controller/admin_stealth.php';
require_once FS_FOLDER . '/controller/admin_orden_menu.php';
require_once FS_FOLDER . '/controller/admin_agentes.php';
require_once FS_FOLDER . '/controller/admin_home.php';

/**
 * PA-07: exactly the nine scoped core pages declare `#[AdminOnly]`; `admin_home`
 * stays accessible.
 */
final class AdminOnlyScopeTest extends TestCase
{
    private const ATTRIBUTE_MARKER = '#[\FSFramework\Attribute\AdminOnly]';

    /**
     * @return array<string, array{string}>
     */
    public static function scopedControllerClasses(): array
    {
        return [
            'admin_users' => ['admin_users'],
            'admin_user' => ['admin_user'],
            'admin_rol' => ['admin_rol'],
            'admin_info' => ['admin_info'],
            'admin_email' => ['admin_email'],
            'admin_system_branding' => ['admin_system_branding'],
            'admin_stealth' => ['admin_stealth'],
            'admin_orden_menu' => ['admin_orden_menu'],
            'admin_agentes' => ['admin_agentes'],
        ];
    }

    /**
     * @return list<string>
     */
    private static function scopedPageNames(): array
    {
        return [
            'admin_users',
            'admin_user',
            'admin_rol',
            'admin_info',
            'admin_email',
            'admin_system_branding',
            'admin_stealth',
            'admin_orden_menu',
            'admin_agentes',
        ];
    }

    #[Test]
    #[DataProvider('scopedControllerClasses')]
    public function scopedControllersResolveAdminOnly(string $className): void
    {
        self::assertTrue(class_exists($className), $className . ' must be loadable.');
        self::assertTrue(
            \fs_page::is_admin_only_class($className),
            $className . ' must resolve as admin-only from its attribute.'
        );
    }

    #[Test]
    public function adminHomeIsNotAdminOnly(): void
    {
        self::assertTrue(class_exists('admin_home'));
        self::assertFalse(
            \fs_page::is_admin_only_class('admin_home'),
            'admin_home is the default landing page and must stay accessible.'
        );
    }

    #[Test]
    public function attributeIsDeclaredOnExactlyTheScopedFiles(): void
    {
        $flagged = [];
        foreach (glob(FS_FOLDER . '/controller/admin_*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);
            if (str_contains($source, self::ATTRIBUTE_MARKER)) {
                $flagged[] = basename($path, '.php');
            }
        }

        $expected = self::scopedPageNames();
        sort($flagged);
        sort($expected);

        self::assertSame($expected, $flagged);
    }

    #[Test]
    public function adminHomeSourceDoesNotDeclareTheAttribute(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/controller/admin_home.php');

        self::assertStringNotContainsString(self::ATTRIBUTE_MARKER, $source);
    }
}
