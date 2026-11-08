<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
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

use FSFramework\Core\Router;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Las páginas legacy las despacha index.php; el router no debe registrar rutas
 * `/index.php?page=X`, que nunca coinciden porque getPathInfo() no incluye la
 * query string.
 */
final class RouterLegacyRoutesTest extends TestCase
{
    public function testRouterDoesNotRegisterUnmatchableLegacyPageRoutes(): void
    {
        $previousPlugins = $GLOBALS['plugins'] ?? null;
        $GLOBALS['plugins'] = [];

        try {
            $router = (new \ReflectionClass(Router::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(Router::class, 'rootFolder'))->setValue($router, dirname(__DIR__, 2));

            $collection = new RouteCollection();
            (new \ReflectionMethod(Router::class, 'loadRoutesFromAttributes'))->invoke($router, $collection);
        } finally {
            $GLOBALS['plugins'] = $previousPlugins;
        }

        // controller/admin_home.php existe: antes generaba la ruta muerta legacy_admin_home.
        $this->assertFileExists(dirname(__DIR__, 2) . '/controller/admin_home.php');
        $this->assertNull($collection->get('legacy_admin_home'));

        foreach ($collection as $name => $route) {
            $this->assertStringNotContainsString('?', $route->getPath(), "Route {$name} can never match");
            $this->assertStringStartsNotWith('legacy_', (string) $name);
        }
    }
}
