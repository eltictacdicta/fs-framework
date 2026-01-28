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
            try {
                $cached = include self::$cacheFile;
                if ($cached instanceof RouteCollection) {
                    return $cached;
                }
            } catch (\Throwable $e) {
                // Caché corrupto, eliminar y regenerar
                @unlink(self::$cacheFile);
                error_log("Router: Cache corrupted, regenerating - " . $e->getMessage());
            }
        }

        $collection = new RouteCollection();

        // 1. Cargar rutas definidas en archivos PHP (config/routes.php y plugins)
        $this->loadRoutesFromFiles($collection);

        // 2. Cargar rutas con atributos #[Route] usando reflexión (compatible con Symfony 7.x)
        $this->loadRoutesFromAttributes($collection);

        // Guardar en caché si está habilitado
        if ($this->shouldUseCache()) {
            $this->cacheRoutes($collection);
        }

        return $collection;
    }

    private function loadRoutesFromFiles(RouteCollection $collection): void
    {
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
    }

    /**
     * Carga rutas desde atributos #[Route] en controladores.
     * Compatible con Symfony 7.x que eliminó AnnotationDirectoryLoader.
     */
    private function loadRoutesFromAttributes(RouteCollection $collection): void
    {
        // Cargar controladores del núcleo (src/Controller)
        $coreControllerDir = $this->rootFolder . '/src/Controller';
        if (is_dir($coreControllerDir)) {
            $this->loadAttributeRoutesFromDirectory($collection, $coreControllerDir, 'FSFramework\\Controller\\');
        }

        // Cargar controladores legacy (controller/)
        $legacyControllerDir = $this->rootFolder . '/controller';
        if (is_dir($legacyControllerDir)) {
            $legacyRoutes = $this->loadLegacyControllerRoutes($legacyControllerDir);
            $collection->addCollection($legacyRoutes);
        }

        // Cargar controladores de plugins
        $pluginsDir = $this->rootFolder . '/plugins';
        if (is_dir($pluginsDir) && isset($GLOBALS['plugins'])) {
            foreach ($GLOBALS['plugins'] as $plugin) {
                $pluginControllerDir = $pluginsDir . '/' . $plugin . '/controller';
                if (is_dir($pluginControllerDir)) {
                    $pluginRoutes = $this->loadLegacyControllerRoutes($pluginControllerDir);
                    $collection->addCollection($pluginRoutes);
                }

                $pluginModernControllerDir = $pluginsDir . '/' . $plugin . '/Controller';
                if (is_dir($pluginModernControllerDir)) {
                    try {
                        $namespace = 'FacturaScripts\\Plugins\\' . $plugin . '\\Controller\\';
                        $this->loadAttributeRoutesFromDirectory($collection, $pluginModernControllerDir, $namespace);
                    } catch (\Exception $e) {
                        error_log("Error loading plugin routes: " . $e->getMessage());
                    }
                }
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
            
            if (!class_exists($className)) {
                // Intentar incluir el archivo si la clase no está cargada (include_once para evitar errores fatales)
                include_once $file;
                if (!class_exists($className)) {
                    continue;
                }
            }

            try {
                $this->loadAttributeRoutesFromClass($collection, $className);
            } catch (\Exception $e) {
                error_log("Error loading routes from class {$className}: " . $e->getMessage());
            }
        }
    }

    /**
     * Carga rutas desde una clase usando reflexión para leer atributos #[Route].
     */
    private function loadAttributeRoutesFromClass(RouteCollection $collection, string $className): void
    {
        $reflectionClass = new \ReflectionClass($className);
        
        // Obtener prefijo de ruta a nivel de clase (si existe)
        $classPrefix = '';
        $classNamePrefix = '';
        $classDefaults = [];
        $classMethods = [];
        $classRequirements = [];
        $classHost = '';
        $classSchemes = [];
        
        // Buscar atributos Route y FSRoute a nivel de clase
        $classAttributes = array_merge(
            $reflectionClass->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF),
            $reflectionClass->getAttributes(\FSFramework\Attribute\FSRoute::class, \ReflectionAttribute::IS_INSTANCEOF)
        );
        
        foreach ($classAttributes as $attribute) {
            $routeAttr = $attribute->newInstance();
            $classPrefix = $routeAttr->getPath() ?? $classPrefix;
            $classNamePrefix = $routeAttr->getName() ?? $classNamePrefix;
            $classDefaults = array_merge($classDefaults, $routeAttr->getDefaults());
            $classMethods = $routeAttr->getMethods() ?: $classMethods;
            $classRequirements = array_merge($classRequirements, $routeAttr->getRequirements());
            $classHost = $routeAttr->getHost() ?? $classHost;
            $classSchemes = $routeAttr->getSchemes() ?: $classSchemes;
        }

        // Procesar métodos de la clase
        foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Ignorar métodos heredados de clases padre o métodos mágicos
            if ($method->class !== $className || str_starts_with($method->getName(), '__')) {
                continue;
            }
            
            // Buscar atributos Route y FSRoute en los métodos
            $methodAttributes = array_merge(
                $method->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF),
                $method->getAttributes(\FSFramework\Attribute\FSRoute::class, \ReflectionAttribute::IS_INSTANCEOF)
            );

            foreach ($methodAttributes as $attribute) {
                $routeAttr = $attribute->newInstance();
                
                // Construir path completo (prefijo de clase + path del método)
                $methodPath = $routeAttr->getPath() ?? '';
                $path = $classPrefix . $methodPath;
                
                // Construir nombre de ruta (prefijo de clase + nombre del método)
                $methodName = $routeAttr->getName();
                if ($classNamePrefix && $methodName) {
                    $routeName = $classNamePrefix . $methodName;
                } elseif ($methodName) {
                    $routeName = $methodName;
                } else {
                    $routeName = $this->generateRouteName($className, $method->getName());
                }
                
                $defaults = array_merge($classDefaults, $routeAttr->getDefaults(), [
                    '_controller' => $className . '::' . $method->getName()
                ]);
                
                $requirements = array_merge($classRequirements, $routeAttr->getRequirements());
                $options = $routeAttr->getOptions();
                $host = $routeAttr->getHost() ?? $classHost;
                $schemes = $routeAttr->getSchemes() ?: $classSchemes;
                $methods = $routeAttr->getMethods() ?: $classMethods ?: [];
                $condition = $routeAttr->getCondition() ?? '';

                $route = new Route(
                    $path,
                    $defaults,
                    $requirements,
                    $options,
                    $host ?: '',
                    $schemes,
                    $methods,
                    $condition
                );

                $collection->add($routeName, $route);
            }
        }
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
            $pathInfo = $request->getPathInfo();
            $parameters = $matcher->match($pathInfo);
            $controller = $parameters['_controller'];
            $routeName = $parameters['_route'];
            unset($parameters['_controller'], $parameters['_route']);

            // Manejo de controladores legacy
            if (is_string($controller) && class_exists($controller)) {
                // Si es un controlador legacy (hereda de fs_controller)
                if (is_subclass_of($controller, 'fs_controller')) {
                    return null; // Dejar que el index.php legacy lo maneje
                }

                // Controlador moderno
                $instance = new $controller();
                if (method_exists($instance, 'handle')) {
                    return $instance->handle($request, ...array_values($parameters));
                } elseif (method_exists($instance, 'run')) {
                    $instance->run();
                    if (method_exists($instance, 'response') && $instance->response() instanceof Response) {
                        return $instance->response();
                    }
                    return null;
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
        if (file_exists(self::$cacheFile)) {
            return unlink(self::$cacheFile);
        }
        return true;
    }

    private function shouldUseCache(): bool
    {
        return !defined('FS_DEBUG') || !FS_DEBUG;
    }

    private function cacheRoutes(RouteCollection $routes): void
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
