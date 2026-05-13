<?php

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/base/fs_functions.php';
require_once dirname(__DIR__, 2) . '/base/fs_controller.php';
require_once dirname(__DIR__, 2) . '/controller/admin_home.php';

final class AdminHomePageDiscoveryTest extends TestCase
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

    public function testAllPagesSkipsLowercaseWrapperWhenScanningModernControllers(): void
    {
        self::assertFalse(class_exists('FSFramework\\Plugins\\catalogo_core\\Controller\\admin_almacenes', false));

        $controller = (new \ReflectionClass('admin_home'))->newInstanceWithoutConstructor();
        $controller->plugin_manager = new class {
            public function enabled(): array
            {
                return ['catalogo_core'];
            }
        };
        $controller->page = new class {
            public function all(): array
            {
                return [];
            }
        };

        $method = new \ReflectionMethod('admin_home', 'all_pages');
        $method->setAccessible(true);
        $pages = $method->invoke($controller);

        $pageNames = array_map(static fn ($page) => $page->name, $pages);

        self::assertContains('admin_almacenes', $pageNames);
        self::assertSame(1, count(array_filter($pageNames, static fn ($name) => $name === 'admin_almacenes')));
        self::assertFalse(class_exists('FSFramework\\Plugins\\catalogo_core\\Controller\\admin_almacenes', false));
    }
}