<?php

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/base/fs_functions.php';

final class PluginControllerDiscoveryTest extends TestCase
{
    private array $previousPlugins = [];

    protected function setUp(): void
    {
        $this->previousPlugins = $GLOBALS['plugins'] ?? [];
        $GLOBALS['plugins'] = ['catalogo_core'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['plugins'] = $this->previousPlugins;
    }

    public function testFindModernControllerUsesNamespacedControllerForLegacyPageName(): void
    {
        $controller = \find_modern_controller('admin_almacenes');

        self::assertIsArray($controller);
        self::assertSame(
            'FSFramework\\Plugins\\catalogo_core\\Controller\\AdminAlmacenes',
            $controller['class']
        );
        self::assertStringEndsWith('/plugins/catalogo_core/Controller/AdminAlmacenes.php', $controller['file']);
    }

    public function testFindControllerKeepsLegacyWrapperPathAvailable(): void
    {
        $controllerPath = \find_controller('admin_almacenes');

        self::assertSame('plugins/catalogo_core/controller/admin_almacenes.php', $controllerPath);
    }

    public function testFindControllerUsesModernPathForModernBasename(): void
    {
        $controllerPath = \find_controller('AdminAlmacenes');

        self::assertSame('plugins/catalogo_core/Controller/AdminAlmacenes.php', $controllerPath);
    }

    public function testFindControllerByPageDataSkipsLegacyWrapperFiles(): void
    {
        $controllerPath = \fs_find_controller_by_page_data('catalogo_core', 'admin_almacenes');

        self::assertSame('plugins/catalogo_core/Controller/AdminAlmacenes.php', $controllerPath);
    }
}