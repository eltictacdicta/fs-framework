<?php

namespace FSFramework\Core;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Loader\PhpFileLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;

class Router
{
    private RouteCollection $routes;
    private string $rootFolder;

    public function __construct(string $rootFolder)
    {
        $this->rootFolder = $rootFolder;
        $this->routes = $this->loadRoutes();
    }

    private function loadRoutes(): RouteCollection
    {
        $collection = new RouteCollection();

        // Cargar rutas del núcleo
        $configDir = $this->rootFolder . '/config';
        if (is_dir($configDir)) {
            $loader = new PhpFileLoader(new FileLocator($configDir));
            if (file_exists($configDir . '/routes.php')) {
                $collection->addCollection($loader->load('routes.php'));
            }
        }

        // Cargar rutas de plugins
        $pluginsDir = $this->rootFolder . '/plugins';
        if (is_dir($pluginsDir)) {
            $dirs = scandir($pluginsDir);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..')
                    continue;

                $pluginRouteFile = $pluginsDir . '/' . $dir . '/config/routes.php';
                if (file_exists($pluginRouteFile)) {
                    $loader = new PhpFileLoader(new FileLocator(dirname($pluginRouteFile)));
                    $collection->addCollection($loader->load('routes.php'));
                }
            }
        }

        return $collection;
    }

    public function handle(Request $request): ?Response
    {
        $context = new RequestContext();
        $context->fromRequest($request);

        $matcher = new UrlMatcher($this->routes, $context);

        try {
            $pathInfo = $request->getPathInfo();
            $parameters = $matcher->match($pathInfo);
            $controller = $parameters['_controller'];
            unset($parameters['_controller'], $parameters['_route']);

            if (is_array($controller)) {
                $class = $controller[0];
                $method = $controller[1];

                if (class_exists($class)) {
                    $instance = new $class();
                    return $instance->$method($request, ...array_values($parameters));
                }
            }

            if (is_callable($controller)) {
                return $controller($request, ...array_values($parameters));
            }

        } catch (ResourceNotFoundException $e) {
            return null;
        } catch (MethodNotAllowedException $e) {
            return new Response('Method Not Allowed', 405);
        } catch (\Throwable $e) {
            error_log('Router Error: ' . $e->getMessage());
            return new Response('Internal Server Error', 500);
        }

        return null;
    }

    public function getRoutes(): RouteCollection
    {
        return $this->routes;
    }
}
