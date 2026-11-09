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

use FSFramework\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;

/**
 * Turns the matched `_controller` into a callable. Controller classes come
 * from the container, so their constructor dependencies are autowired.
 *
 * - "Class::method"      → that method
 * - class with handle()  → handle()
 * - class with run()     → run(), then its response() or an empty response
 * - no route matched     → the ?page= dispatch (PageDispatcher), as a PageResponse
 */
final class ControllerResolver implements ControllerResolverInterface
{
    public function getController(Request $request): callable|false
    {
        if (!$request->attributes->has('_controller')) {
            return static fn (Request $request): PageResponse => new PageResponse(
                static fn () => (new PageDispatcher())->dispatch($request)
            );
        }

        $controller = $request->attributes->get('_controller');

        if (is_string($controller) && str_contains($controller, '::')) {
            [$class, $method] = explode('::', $controller, 2);
            if (class_exists($class)) {
                return [Container::get($class), $method];
            }
            return false;
        }

        if (is_string($controller) && class_exists($controller)) {
            if (is_a($controller, 'fs_controller', true)) {
                return false;
            }

            $instance = Container::get($controller);
            if (method_exists($instance, 'handle')) {
                return [$instance, 'handle'];
            }
            if (method_exists($instance, 'run')) {
                return static function () use ($instance): Response {
                    $instance->run();
                    $response = method_exists($instance, 'response') ? $instance->response() : null;

                    return $response instanceof Response ? $response : new Response('');
                };
            }
            return false;
        }

        return is_callable($controller) ? $controller : false;
    }
}
