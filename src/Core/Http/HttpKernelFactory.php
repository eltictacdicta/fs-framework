<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FSFramework\Core\Http;

use FSFramework\Core\PublicAccessGate;
use FSFramework\Core\Router;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\HttpKernel;

final class HttpKernelFactory
{
    /**
     * Plugins may listen to kernel.* events on the same dispatcher.
     */
    public static function create(EventDispatcherInterface $dispatcher, Router $router, ?PublicAccessGate $gate): HttpKernel
    {
        $dispatcher->addSubscriber(new FrontRequestSubscriber($router, $gate));

        return new HttpKernel($dispatcher, new ControllerResolver(), new RequestStack(), new ArgumentResolver());
    }
}
