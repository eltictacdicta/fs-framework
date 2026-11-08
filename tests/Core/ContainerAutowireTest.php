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

use FSFramework\Cache\CacheManager;
use FSFramework\DependencyInjection\Container;
use PHPUnit\Framework\TestCase;

final class AutowireLeaf
{
}

final class AutowireMiddle
{
    public function __construct(public readonly AutowireLeaf $leaf)
    {
    }
}

final class AutowireRoot
{
    public function __construct(
        public readonly AutowireMiddle $middle,
        public readonly CacheManager $cache,
        public readonly int $limit = 10,
    ) {
    }
}

interface AutowireUnboundInterface
{
}

final class AutowireOptionalDependency
{
    public function __construct(public readonly ?AutowireLeaf $leaf = null)
    {
    }
}

final class AutowireUnresolvable
{
    public function __construct(public readonly AutowireUnboundInterface $dependency)
    {
    }
}

final class AutowirePluginService
{
    public function __construct(public readonly AutowireLeaf $leaf)
    {
    }
}

/**
 * El contenedor no se compila, así que el autowiring documentado
 * (`Container::get(Clase::class)` y `setAutowired(true)` en services.php de
 * plugins) debe resolver los argumentos del constructor por sí mismo.
 */
final class ContainerAutowireTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    public function testGetByClassResolvesConstructorDependenciesRecursively(): void
    {
        $root = Container::get(AutowireRoot::class);

        $this->assertInstanceOf(AutowireRoot::class, $root);
        $this->assertInstanceOf(AutowireLeaf::class, $root->middle->leaf);
        $this->assertSame(10, $root->limit);
    }

    public function testCoreServicesAreInjectedAsTheSharedInstance(): void
    {
        $root = Container::get(AutowireRoot::class);

        $this->assertSame(CacheManager::getInstance(), $root->cache);
        $this->assertSame(Container::cache(), Container::get(CacheManager::class));
    }

    public function testAutowiredServicesAreShared(): void
    {
        $this->assertSame(Container::get(AutowireMiddle::class), Container::get(AutowireMiddle::class));
        $this->assertSame(
            Container::get(AutowireMiddle::class)->leaf,
            Container::get(AutowireLeaf::class)
        );
    }

    public function testPluginDefinitionMarkedAutowiredGetsItsArguments(): void
    {
        Container::getContainer()
            ->register('plugin.service', AutowirePluginService::class)
            ->setPublic(true)
            ->setAutowired(true);

        $service = Container::get('plugin.service');

        $this->assertInstanceOf(AutowireLeaf::class, $service->leaf);
    }

    public function testOptionalParameterIsNotAutoRegistered(): void
    {
        $service = Container::get(AutowireOptionalDependency::class);

        $this->assertNull($service->leaf);
    }

    public function testUnresolvableServiceKeepsFailingWithTheClearMessageOnRetry(): void
    {
        foreach ([1, 2] as $attempt) {
            try {
                Container::get(AutowireUnresolvable::class);
                $this->fail("Attempt {$attempt} should not resolve the service.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('parameter $dependency', $e->getMessage(), "Attempt {$attempt}");
            }
        }
    }

    public function testUnresolvableRequiredParameterFailsWithAClearMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('parameter $dependency');

        Container::get(AutowireUnresolvable::class);
    }
}
