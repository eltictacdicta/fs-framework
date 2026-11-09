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

use FSFramework\Core\Plugins;
use FSFramework\Core\PublicAccessGate;
use FSFramework\Core\Router;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;

/**
 * The front controller's request pipeline as HttpKernel listeners:
 * public-access gate, then route matching, then the browser-probe 204.
 * A request no route claims ends in NotFoundHttpException, which
 * index.php takes as "dispatch by ?page=".
 */
final class FrontRequestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Router $router,
        private readonly ?PublicAccessGate $gate = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['onPublicAccess', 256],
                ['onRouting', 32],
                ['onBrowserProbe', 16],
            ],
            KernelEvents::EXCEPTION => ['onException', -128],
        ];
    }

    public function onPublicAccess(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->gate === null) {
            return;
        }

        $response = $this->gate->intercept($event->getRequest());
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onRouting(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->has('_controller')) {
            return;
        }

        try {
            $parameters = $this->router->match($request);
        } catch (MethodNotAllowedException) {
            $event->setResponse(new Response('Method Not Allowed', 405));
            return;
        }

        // A route to an fs_controller is not claimed: legacy pages are
        // dispatched by ?page= (Router::handle() returned null for them).
        $controller = $parameters['_controller'] ?? null;
        if (is_string($controller) && class_exists($controller) && is_a($controller, 'fs_controller', true)) {
            if (Plugins::isEnabled('legacy_support') && class_exists('FSFramework\\Plugins\\legacy_support\\LegacyUsageTracker')) {
                \FSFramework\Plugins\legacy_support\LegacyUsageTracker::incrementLegacyRoute($controller, 'legacy_controller');
            }
            return;
        }

        if ($parameters !== null) {
            $request->attributes->add($parameters);
            $request->attributes->set('_route_params', array_diff_key($parameters, ['_controller' => 1, '_route' => 1]));
        }
    }

    public function onBrowserProbe(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->has('_controller') || !Plugins::shouldRespondNoContentForBrowserProbe($request)) {
            return;
        }

        $response = new Response('', 204, ['Cache-Control' => 'public, max-age=86400']);
        $response->prepare($request);
        $event->setResponse($response);
    }

    /**
     * A failing route controller answers 500, as Router::handle() did. A
     * request without a controller is left to propagate: index.php falls
     * back to the ?page= dispatch.
     */
    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        if ($e instanceof NotFoundHttpException && !$event->getRequest()->attributes->has('_controller')) {
            return;
        }

        $details = $e->getMessage();
        $previous = $e->getPrevious();
        if ($previous instanceof \Throwable && $previous->getMessage() !== '') {
            $details .= ' | caused by: ' . $previous->getMessage();
        }
        error_log('Router Error: ' . $details);
        $event->setResponse(new Response('Internal Server Error', 500));
    }
}
