<?php

namespace FSFramework\Core;

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
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;

class Router
{
    private RouteCollection $routes;
    private string $rootFolder;
    private ?RequestContext $context = null;
    private ?UrlGenerator $urlGenerator = null;
    private static string $cacheFile = '';
    private static string $cacheSigFile = '';

    public function __construct(string $rootFolder)
    {
        $this->rootFolder = $rootFolder;
        self::$cacheFile = $rootFolder . '/tmp/routes_cache.php';
        self::$cacheSigFile = $rootFolder . '/tmp/routes_cache.sig';
        $this->routes = $this->loadRoutes();
    }

    /**
     * Huella de los ficheros fuente de rutas (config + controladores con atributos).
     * Si cambia cualquier archivo, la caché serializada se invalida (evita rutas OIDC / API obsoletas).
     */
    private function getRoutesSourceFingerprint(): string
    {
        $parts = [];
        $routesFile = $this->rootFolder . '/config/routes.php';
        if (is_file($routesFile)) {
            $parts[] = $routesFile . ':' . (int) filemtime($routesFile);
        }
        $coreDir = $this->rootFolder . '/src/Controller';
        if (is_dir($coreDir)) {
            $files = glob($coreDir . '/*.php') ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $f) {
                $parts[] = $f . ':' . (int) filemtime($f);
            }
        }
        if (isset($GLOBALS['plugins']) && is_array($GLOBALS['plugins'])) {
            foreach ($GLOBALS['plugins'] as $plugin) {
                $dir = $this->rootFolder . '/plugins/' . $plugin . '/Controller';
                if (!is_dir($dir)) {
                    continue;
                }
                $files = glob($dir . '/*.php') ?: [];
                sort($files, SORT_STRING);
                foreach ($files as $f) {
                    $parts[] = $f . ':' . (int) filemtime($f);
                }
            }
        }
        return hash('sha256', implode("\0", $parts));
    }

    private function loadRoutes(): RouteCollection
    {
        $fingerprint = $this->getRoutesSourceFingerprint();
        // Intentar cargar desde caché en producción (solo si la huella coincide con el disco)
        if ($this->shouldUseCache()
            && file_exists(self::$cacheFile)
            && file_exists(self::$cacheSigFile)) {
            $storedSig = @file_get_contents(self::$cacheSigFile);
            if ($storedSig !== false && hash_equals($fingerprint, trim($storedSig))) {
                try {
                    $cached = include_once self::$cacheFile;
                    if ($cached instanceof RouteCollection) {
                        return $cached;
                    }
                } catch (\Throwable $e) {
                    @unlink(self::$cacheFile);
                    @unlink(self::$cacheSigFile);
                    error_log("Router: Cache corrupted, regenerating - " . $e->getMessage());
                }
            } else {
                @unlink(self::$cacheFile);
                @unlink(self::$cacheSigFile);
            }
        }

        $collection = new RouteCollection();

        // 1. Cargar rutas definidas en archivos PHP (config/routes.php y plugins)
        $this->loadRoutesFromFiles($collection);

        // 2. Cargar rutas con atributos #[Route] usando reflexión (compatible con Symfony 7.x)
        $this->loadRoutesFromAttributes($collection);

        // Guardar en caché si está habilitado
        if ($this->shouldUseCache()) {
            $this->cacheRoutes($collection, $fingerprint);
        }

        return $collection;
    }

    private function loadRoutesFromFiles(RouteCollection $collection): void
    {
        // Only load core routes from config/routes.php.
        // Plugin routes MUST use #[FSRoute] attributes on controllers,
        // which are loaded exclusively for active plugins via loadRoutesFromAttributes().
        $configDir = $this->rootFolder . '/config';
        if (is_dir($configDir)) {
            $loader = new PhpFileLoader(new FileLocator($configDir));
            if (file_exists($configDir . '/routes.php')) {
                $collection->addCollection($loader->load('routes.php'));
            }
        }
    }

    /**
     * Carga rutas desde atributos #[Route] en controladores.
     * Compatible con Symfony 7.x que eliminó AnnotationDirectoryLoader.
     */
    private function loadRoutesFromAttributes(RouteCollection $collection): void
    {
        $coreControllerDir = $this->rootFolder . '/src/Controller';
        if (is_dir($coreControllerDir)) {
            $this->loadAttributeRoutesFromDirectory($collection, $coreControllerDir, 'FSFramework\\Controller\\');
        }

        $legacyControllerDir = $this->rootFolder . '/controller';
        if (is_dir($legacyControllerDir)) {
            $collection->addCollection($this->loadLegacyControllerRoutes($legacyControllerDir));
        }

        $this->loadPluginRoutes($collection);
    }

    private function loadPluginRoutes(RouteCollection $collection): void
    {
        $pluginsDir = $this->rootFolder . '/plugins';
        if (!is_dir($pluginsDir) || !isset($GLOBALS['plugins'])) {
            return;
        }

        foreach ($GLOBALS['plugins'] as $plugin) {
            $pluginControllerDir = $pluginsDir . '/' . $plugin . '/controller';
            if (is_dir($pluginControllerDir)) {
                $collection->addCollection($this->loadLegacyControllerRoutes($pluginControllerDir));
            }

            $pluginModernControllerDir = $pluginsDir . '/' . $plugin . '/Controller';
            if (!is_dir($pluginModernControllerDir)) {
                continue;
            }

            try {
                $namespace = 'FSFramework\\Plugins\\' . $plugin . '\\Controller\\';
                $this->loadAttributeRoutesFromDirectory($collection, $pluginModernControllerDir, $namespace);
            } catch (\Throwable $e) {
                error_log("Error loading plugin routes for {$plugin}: " . $e->getMessage());
            }
        }
    }

    /**
     * Carga rutas con atributos desde un directorio de controladores usando reflexión.
     * Este método es compatible con Symfony 7.x.
     */
    private function loadAttributeRoutesFromDirectory(RouteCollection $collection, string $directory, string $namespace): void
    {
        $files = glob($directory . '/*.php');

        foreach ($files as $file) {
            $className = $namespace . basename($file, '.php');

            try {
                include_once $file;
                if (!class_exists($className, false)) {
                    continue;
                }

                $this->loadAttributeRoutesFromClass($collection, $className);
            } catch (\Throwable $e) {
                error_log("Error loading routes from {$className}: " . $e->getMessage());
            }
        }
    }

    /**
     * Carga rutas desde una clase usando reflexión para leer atributos #[Route].
     */
    private function loadAttributeRoutesFromClass(RouteCollection $collection, string $className): void
    {
        $reflectionClass = new \ReflectionClass($className);
        $classConfig = $this->extractClassRouteConfig($reflectionClass);

        foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $className || str_starts_with($method->getName(), '__')) {
                continue;
            }

            $this->loadMethodRoutes($collection, $method, $classConfig, $className);
        }
    }

    private function extractClassRouteConfig(\ReflectionClass $reflectionClass): array
    {
        $config = [
            'prefix' => '',
            'namePrefix' => '',
            'defaults' => [],
            'methods' => [],
            'requirements' => [],
            'host' => '',
            'schemes' => [],
        ];

        $classAttributes = array_merge(
            $reflectionClass->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF),
            $reflectionClass->getAttributes(\FSFramework\Attribute\FSRoute::class, \ReflectionAttribute::IS_INSTANCEOF)
        );

        foreach ($classAttributes as $attribute) {
            $routeAttr = $attribute->newInstance();
            $config['prefix'] = $routeAttr->getPath() ?? $config['prefix'];
            $config['namePrefix'] = $routeAttr->getName() ?? $config['namePrefix'];
            $config['defaults'] = array_merge($config['defaults'], $routeAttr->getDefaults());
            $config['methods'] = $routeAttr->getMethods() ?: $config['methods'];
            $config['requirements'] = array_merge($config['requirements'], $routeAttr->getRequirements());
            $config['host'] = $routeAttr->getHost() ?? $config['host'];
            $config['schemes'] = $routeAttr->getSchemes() ?: $config['schemes'];
        }

        return $config;
    }

    private function loadMethodRoutes(RouteCollection $collection, \ReflectionMethod $method, array $classConfig, string $className): void
    {
        $methodAttributes = array_merge(
            $method->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF),
            $method->getAttributes(\FSFramework\Attribute\FSRoute::class, \ReflectionAttribute::IS_INSTANCEOF)
        );

        foreach ($methodAttributes as $attribute) {
            $this->addRouteFromAttribute($collection, $attribute, $classConfig, $className, $method->getName());
        }
    }

    private function addRouteFromAttribute(
        RouteCollection $collection,
        \ReflectionAttribute $attribute,
        array $classConfig,
        string $className,
        string $methodName
    ): void {
        $routeAttr = $attribute->newInstance();

        $path = $classConfig['prefix'] . ($routeAttr->getPath() ?? '');
        $routeName = $this->resolveRouteName($routeAttr, $classConfig, $className, $methodName);

        $defaults = array_merge($classConfig['defaults'], $routeAttr->getDefaults(), [
            '_controller' => $className . '::' . $methodName,
        ]);

        $route = new Route(
            $path,
            $defaults,
            array_merge($classConfig['requirements'], $routeAttr->getRequirements()),
            $routeAttr->getOptions(),
            $routeAttr->getHost() ?? $classConfig['host'] ?: '',
            $routeAttr->getSchemes() ?: $classConfig['schemes'],
            $routeAttr->getMethods() ?: $classConfig['methods'] ?: [],
            $routeAttr->getCondition() ?? ''
        );

        $collection->add($routeName, $route);
    }

    private function resolveRouteName(mixed $routeAttr, array $classConfig, string $className, string $methodName): string
    {
        $attrName = $routeAttr->getName();

        if ($classConfig['namePrefix'] && $attrName) {
            return $classConfig['namePrefix'] . $attrName;
        }

        return $attrName ?: $this->generateRouteName($className, $methodName);
    }

    /**
     * Genera un nombre de ruta único basado en la clase y método.
     */
    private function generateRouteName(string $className, string $methodName): string
    {
        $shortClass = (new \ReflectionClass($className))->getShortName();
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortClass) . '_' . $methodName);
    }

    private function loadLegacyControllerRoutes(string $directory): RouteCollection
    {
        $collection = new RouteCollection();
        $files = glob($directory . '/*.php');

        foreach ($files as $file) {
            $className = basename($file, '.php');

            // Crear ruta legacy para compatibilidad
            $route = new \Symfony\Component\Routing\Route(
                '/index.php?page=' . $className,
                [
                    '_controller' => $className,
                    'page' => $className
                ],
                [],
                [],
                '',
                [],
                ['GET', 'POST']
            );

            $collection->add('legacy_' . strtolower($className), $route);
        }

        return $collection;
    }

    public function handle(Request $request): ?Response
    {
        $this->context = new RequestContext();
        $this->context->fromRequest($request);

        $matcher = new UrlMatcher($this->routes, $this->context);

        try {
            $parameters = $matcher->match($request->getPathInfo());
            $controller = $parameters['_controller'];
            unset($parameters['_controller'], $parameters['_route']);

            return $this->dispatchController($controller, $request, $parameters);
        } catch (ResourceNotFoundException) {
            return null;
        } catch (MethodNotAllowedException) {
            return new Response('Method Not Allowed', 405);
        } catch (\Throwable $e) {
            error_log('Router Error: ' . $e->getMessage());
            return new Response('Internal Server Error', 500);
        }
    }

    private function dispatchController(mixed $controller, Request $request, array $parameters): ?Response
    {
        if (is_string($controller) && str_contains($controller, '::')) {
            [$className, $method] = explode('::', $controller, 2);
            if (class_exists($className)) {
                $instance = new $className();
                return $instance->$method($request, ...array_values($parameters));
            }
        }

        if (is_string($controller) && class_exists($controller)) {
            return $this->dispatchClassController($controller, $request, $parameters);
        }

        if (is_callable($controller)) {
            return $controller($request, ...array_values($parameters));
        }

        return null;
    }

    private function dispatchClassController(string $controller, Request $request, array $parameters): ?Response
    {
        if (is_subclass_of($controller, 'fs_controller')) {
            if (Plugins::isEnabled('legacy_support') && class_exists('FSFramework\\Plugins\\legacy_support\\LegacyUsageTracker')) {
                \FSFramework\Plugins\legacy_support\LegacyUsageTracker::incrementLegacyRoute($controller, 'legacy_controller');
            }
            return null;
        }

        $instance = new $controller();

        if (method_exists($instance, 'handle')) {
            return $instance->handle($request, ...array_values($parameters));
        }

        if (method_exists($instance, 'run')) {
            $instance->run();
            return (method_exists($instance, 'response') && $instance->response() instanceof Response)
                ? $instance->response()
                : null;
        }

        return null;
    }

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

    public function clearCache(): bool
    {
        $ok = true;
        if (file_exists(self::$cacheFile)) {
            if (!unlink(self::$cacheFile)) {
                $ok = false;
            }
        }
        if (file_exists(self::$cacheSigFile)) {
            if (!unlink(self::$cacheSigFile)) {
                $ok = false;
            }
        }
        return $ok;
    }

    private function shouldUseCache(): bool
    {
        return !defined('FS_DEBUG') || !FS_DEBUG;
    }

    private function cacheRoutes(RouteCollection $routes, string $fingerprint): void
    {
        // Verificar si hay closures que impidan la serialización
        if ($this->hasClosureControllers($routes)) {
            // No se puede cachear si hay closures
            return;
        }

        $cacheDir = dirname(self::$cacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        try {
            $serialized = serialize($routes);
            // Usar base64 para evitar problemas de escapado de caracteres especiales
            $encoded = base64_encode($serialized);
            $content = "<?php\n// Routes cache generated at " . date('Y-m-d H:i:s') . "\n";
            $content .= "return unserialize(base64_decode('" . $encoded . "'));\n";
            @file_put_contents(self::$cacheFile, $content);
            @file_put_contents(self::$cacheSigFile, $fingerprint);
        } catch (\Exception $e) {
            // Si falla la serialización (closures u otros), simplemente no cachear
            error_log("Router: Unable to cache routes - " . $e->getMessage());
        }
    }

    /**
     * Verifica si alguna ruta tiene un controlador Closure que no se puede serializar.
     */
    private function hasClosureControllers(RouteCollection $routes): bool
    {
        foreach ($routes as $route) {
            $controller = $route->getDefault('_controller');
            if ($controller instanceof \Closure) {
                return true;
            }
            // También verificar si es un array con closure
            if (is_array($controller)) {
                foreach ($controller as $item) {
                    if ($item instanceof \Closure) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
