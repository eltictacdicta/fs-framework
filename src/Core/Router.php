<?php

namespace FSFramework\Core;

use FSFramework\Attribute\FSRoute;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Loader\PhpFileLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

class Router
{
    private RouteCollection $routes;
    private string $rootFolder;
    private ?RequestContext $context = null;
    private ?UrlGenerator $urlGenerator = null;
    private static string $cacheFile = '';

    public function __construct(string $rootFolder)
    {
        $this->rootFolder = $rootFolder;
        self::$cacheFile = $rootFolder . '/tmp/routes_cache.php';
        $this->routes = $this->loadRoutes();
    }

    private function loadRoutes(): RouteCollection
    {
        // Intentar cargar desde caché en producción
        if ($this->shouldUseCache() && file_exists(self::$cacheFile)) {
            $cached = include self::$cacheFile;
            if ($cached instanceof RouteCollection) {
                return $cached;
            }
        }

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
                if ($dir === '.' || $dir === '..') {
                    continue;
                }

                $pluginRouteFile = $pluginsDir . '/' . $dir . '/config/routes.php';
                if (file_exists($pluginRouteFile)) {
                    $loader = new PhpFileLoader(new FileLocator(dirname($pluginRouteFile)));
                    $collection->addCollection($loader->load('routes.php'));
                }
            }
        }

        // Escanear atributos #[FSRoute] en controladores
        $collection->addCollection($this->loadRoutesFromAttributes());

        // Guardar en caché si está habilitado
        if ($this->shouldUseCache()) {
            $this->cacheRoutes($collection);
        }

        return $collection;
    }

    /**
     * Escanea controladores buscando atributos #[FSRoute]
     */
    private function loadRoutesFromAttributes(): RouteCollection
    {
        $collection = new RouteCollection();

        // Escanear controladores del núcleo
        $controllerDir = $this->rootFolder . '/controller';
        if (is_dir($controllerDir)) {
            $this->scanControllerDirectory($controllerDir, $collection);
        }

        // Escanear controladores de plugins activos
        $pluginsDir = $this->rootFolder . '/plugins';
        if (is_dir($pluginsDir) && isset($GLOBALS['plugins'])) {
            foreach ($GLOBALS['plugins'] as $plugin) {
                $pluginControllerDir = $pluginsDir . '/' . $plugin . '/controller';
                if (is_dir($pluginControllerDir)) {
                    $this->scanControllerDirectory($pluginControllerDir, $collection);
                }
            }
        }

        return $collection;
    }

    /**
     * Escanea un directorio de controladores buscando atributos FSRoute
     * Usa parsing de tokens para evitar cargar archivos antes de tiempo.
     */
    private function scanControllerDirectory(string $directory, RouteCollection $collection): void
    {
        $files = glob($directory . '/*.php');
        foreach ($files as $file) {
            try {
                $route = $this->parseRouteAttributeFromFile($file);
                if ($route !== null) {
                    $className = basename($file, '.php');
                    $routeName = $route['name'] ?? 'fs_' . $className;

                    $symfonyRoute = new Route(
                        $route['path'],
                        array_merge($route['defaults'] ?? [], ['_controller' => $className]),
                        $route['requirements'] ?? [],
                        [],
                        '',
                        [],
                        $route['methods'] ?? ['GET']
                    );

                    $collection->add($routeName, $symfonyRoute);
                }
            } catch (\Throwable $e) {
                error_log("Error scanning controller file {$file}: " . $e->getMessage());
            }
        }
    }

    /**
     * Parsea un archivo PHP buscando el atributo #[FSRoute] sin cargarlo.
     * Esto evita el error de clases padre no definidas.
     * 
     * @return array|null Array con path, methods, name, defaults, requirements o null si no tiene FSRoute
     */
    private function parseRouteAttributeFromFile(string $file): ?array
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        // Buscar patrón #[FSRoute(...)]
        // Soporta: #[FSRoute('/path')] o #[\FSFramework\Attribute\FSRoute('/path', ...)]
        $pattern = '/#\[(?:\\\\?FSFramework\\\\Attribute\\\\)?FSRoute\s*\(\s*([\'"])([^\'"]+)\1(?:\s*,\s*(.+?))?\s*\)\s*\]/s';

        if (!preg_match($pattern, $content, $matches)) {
            return null;
        }

        $route = [
            'path' => $matches[2],
            'methods' => ['GET'],
            'name' => null,
            'defaults' => [],
            'requirements' => []
        ];

        // Parsear parámetros adicionales si existen
        if (isset($matches[3]) && !empty($matches[3])) {
            $paramsStr = $matches[3];

            // Buscar methods: ['GET', 'POST']
            if (preg_match('/methods\s*:\s*\[([^\]]+)\]/', $paramsStr, $methodMatch)) {
                $methods = [];
                preg_match_all('/[\'"]([A-Z]+)[\'"]/', $methodMatch[1], $methodMatches);
                if (!empty($methodMatches[1])) {
                    $methods = $methodMatches[1];
                }
                $route['methods'] = $methods;
            }

            // Buscar name: 'route_name'
            if (preg_match('/name\s*:\s*[\'"]([^\'"]+)[\'"]/', $paramsStr, $nameMatch)) {
                $route['name'] = $nameMatch[1];
            }
        }

        return $route;
    }


    public function handle(Request $request): ?Response
    {
        $this->context = new RequestContext();
        $this->context->fromRequest($request);

        $matcher = new UrlMatcher($this->routes, $this->context);

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

            // Soporte para controladores FSFramework con atributo #[FSRoute]
            if (is_string($controller) && class_exists($controller)) {
                // Verificar si es un controlador legacy de FSFramework
                if (is_subclass_of($controller, 'fs_controller')) {
                    // Los controladores legacy usan el flujo normal de FSFramework
                    return null;
                }
                $instance = new $controller();
                if (method_exists($instance, 'handle')) {
                    return $instance->handle($request, ...array_values($parameters));
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

    /**
     * Genera una URL para una ruta con nombre
     *
     * @param string $routeName Nombre de la ruta
     * @param array $parameters Parámetros para la ruta
     * @param int $referenceType Tipo de referencia URL (absoluta, relativa, etc.)
     * @return string
     * @throws RouteNotFoundException
     */
    public function generate(string $routeName, array $parameters = [], int $referenceType = UrlGenerator::ABSOLUTE_PATH): string
    {
        if ($this->context === null) {
            $this->context = new RequestContext();
            $this->context->fromRequest(Kernel::request());
        }

        if ($this->urlGenerator === null) {
            $this->urlGenerator = new UrlGenerator($this->routes, $this->context);
        }

        return $this->urlGenerator->generate($routeName, $parameters, $referenceType);
    }

    /**
     * Genera una URL para una página legacy del sistema
     *
     * @param string $pageName Nombre de la página
     * @param array $params Parámetros adicionales
     * @return string
     */
    public function generateLegacyUrl(string $pageName, array $params = []): string
    {
        $url = 'index.php?page=' . urlencode($pageName);
        foreach ($params as $key => $value) {
            $url .= '&' . urlencode($key) . '=' . urlencode((string) $value);
        }
        return $url;
    }

    public function getRoutes(): RouteCollection
    {
        return $this->routes;
    }

    /**
     * Limpia la caché de rutas
     */
    public function clearCache(): bool
    {
        if (file_exists(self::$cacheFile)) {
            return unlink(self::$cacheFile);
        }
        return true;
    }

    /**
     * Determina si se debe usar caché de rutas
     */
    private function shouldUseCache(): bool
    {
        // Solo usar caché si FS_DEBUG no está definido o es false
        return !defined('FS_DEBUG') || !FS_DEBUG;
    }

    /**
     * Guarda las rutas en caché
     */
    private function cacheRoutes(RouteCollection $routes): void
    {
        $cacheDir = dirname(self::$cacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        // Nota: RouteCollection no es directamente serializable de forma completa,
        // pero podemos guardar una versión simplificada para debugging
        $content = "<?php\n// Routes cache generated at " . date('Y-m-d H:i:s') . "\n";
        $content .= "// This is a placeholder - full caching requires Symfony Cache component with compiled matchers\n";
        $content .= "return null;\n";

        @file_put_contents(self::$cacheFile, $content);
    }
}
