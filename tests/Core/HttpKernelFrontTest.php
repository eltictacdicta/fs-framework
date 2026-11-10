<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace Tests\Core;

use FSFramework\Core\Http\HttpKernelFactory;
use FSFramework\Core\Http\PageResponse;
use FSFramework\Core\Router;
use FSFramework\DependencyInjection\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class HttpKernelFrontFixtureController
{
    public function show(Request $request, string $graph): Response
    {
        return new Response($request->getMethod() . ':' . $graph);
    }

    public function noArgs(): Response
    {
        return new Response('ok');
    }

    public function boom(): Response
    {
        throw new \RuntimeException('boom');
    }
}

final class HttpKernelFrontTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
    }

    public function testRouteParametersAreResolvedByName(): void
    {
        $response = $this->kernel()->handle(Request::create('/graphs/notes'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('GET:notes', $response->getContent());
    }

    public function testAControllerWithoutArgumentsStillRuns(): void
    {
        self::assertSame('ok', $this->kernel()->handle(Request::create('/ping'))->getContent());
    }

    public function testNoMatchingRouteIsServedByThePageDispatch(): void
    {
        $response = $this->kernel()->handle(Request::create('/index.php?page=admin_home'));

        self::assertInstanceOf(PageResponse::class, $response);
    }

    public function testAPageResponseSendsNoHeadersOfItsOwn(): void
    {
        $ran = false;
        $response = new PageResponse(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertSame($response, $response->sendHeaders());
        $response->sendContent();
        self::assertTrue($ran);
    }

    public function testAnUnresolvableRouteControllerAnswers500(): void
    {
        $previous = ini_set('error_log', '/dev/null');
        try {
            $response = $this->kernel(['/gone' => 'Missing\\Controller::action'])->handle(Request::create('/gone'));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        self::assertSame(500, $response->getStatusCode());
    }

    public function testAWrongMethodAnswers405(): void
    {
        $response = $this->kernel()->handle(Request::create('/graphs/notes', 'POST'));

        self::assertSame(405, $response->getStatusCode());
    }

    public function testAFailingControllerAnswers500(): void
    {
        $previous = ini_set('error_log', '/dev/null');
        try {
            $response = $this->kernel()->handle(Request::create('/boom'));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', $response->getContent());
    }

    public function testALegacyControllerRouteFallsThroughToThePageDispatch(): void
    {
        require_once FS_FOLDER . '/base/fs_controller.php';

        $response = $this->kernel(['/legacy' => \fs_controller::class])->handle(Request::create('/legacy'));

        self::assertInstanceOf(PageResponse::class, $response);
    }

    /**
     * @param array<string, string> $extra path => _controller
     */
    private function kernel(array $extra = []): HttpKernel
    {
        $fixture = HttpKernelFrontFixtureController::class;
        $routes = new RouteCollection();
        $routes->add('show', new Route('/graphs/{graph}', ['_controller' => $fixture . '::show'], methods: ['GET']));
        $routes->add('ping', new Route('/ping', ['_controller' => $fixture . '::noArgs']));
        $routes->add('boom', new Route('/boom', ['_controller' => $fixture . '::boom']));
        foreach ($extra as $path => $controller) {
            $routes->add('extra' . $path, new Route($path, ['_controller' => $controller]));
        }

        $router = (new \ReflectionClass(Router::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Router::class, 'routes'))->setValue($router, $routes);

        return HttpKernelFactory::create(new EventDispatcher(), $router, null);
    }
}
