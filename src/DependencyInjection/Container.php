<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
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

namespace FSFramework\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use FSFramework\Event\FSEventDispatcher;
use FSFramework\Security\CsrfManager;
use FSFramework\Security\PasswordHasherService;
use FSFramework\Security\SessionManager;
use FSFramework\Cache\CacheManager;
use FSFramework\Api\ApiKernel;
use FSFramework\Api\Router\ApiRouter;
use FSFramework\Api\Router\EndpointRegistry;
use FSFramework\Api\Resource\ModelResourceRegistry;
use FSFramework\Api\Resource\ResourceTransformer;
use FSFramework\Api\Auth\Contract\ApiAuthInterface;
use FSFramework\Api\Auth\Contract\AllowedUserInterface;
use FSFramework\Api\Auth\Contract\ApiLogInterface;
use FSFramework\Api\Middleware\AuthMiddleware;
use FSFramework\Api\Middleware\CorsMiddleware;
use FSFramework\Api\Middleware\RateLimitMiddleware;

/**
 * Contenedor de servicios para FSFramework.
 * 
 * Proporciona un Service Locator compatible con código legacy
 * y soporte para inyección de dependencias en código moderno.
 * 
 * Uso (Service Locator - compatible con legacy):
 *   $db = Container::get('db');
 *   $empresa = Container::get(Empresa::class);
 * 
 * Uso (Inyección en controladores modernos):
 *   class MiController {
 *       public function __construct(
 *           private readonly EmpresaRepository $empresas
 *       ) {}
 *   }
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class Container
{
    private static ?ContainerBuilder $container = null;
    private static bool $compiled = false;

    /**
     * Obtiene el contenedor de servicios.
     * Lo inicializa si no existe.
     */
    public static function getContainer(): ContainerBuilder
    {
        if (self::$container === null) {
            self::$container = new ContainerBuilder();
            self::registerCoreServices();
        }
        return self::$container;
    }

    /**
     * Registra los servicios core del framework.
     */
    private static function registerCoreServices(): void
    {
        $container = self::$container;

        // Event Dispatcher
        $container->register('event_dispatcher', FSEventDispatcher::class)
            ->setFactory([FSEventDispatcher::class, 'getInstance'])
            ->setPublic(true);

        // CSRF Manager
        $container->register('csrf_manager', CsrfManager::class)
            ->setPublic(true);

        // Password Hasher
        $container->register('password_hasher', PasswordHasherService::class)
            ->setPublic(true);

        // Cache Manager
        $container->register('cache', CacheManager::class)
            ->setFactory([CacheManager::class, 'getInstance'])
            ->setPublic(true);

        // Session Manager
        $container->register('session', SessionManager::class)
            ->setFactory([SessionManager::class, 'getInstance'])
            ->setPublic(true);

        // Prepared DB (con statement caching)
        $container->register('prepared_db', \fs_prepared_db::class)
            ->setPublic(true)
            ->setShared(true);

        // Request (desde Kernel)
        $container->register('request', \Symfony\Component\HttpFoundation\Request::class)
            ->setFactory([\FSFramework\Core\Kernel::class, 'request'])
            ->setPublic(true);

        // Router
        $container->register('router', \FSFramework\Core\Router::class)
            ->setFactory([\FSFramework\Core\Kernel::class, 'router'])
            ->setPublic(true);

        // Database connection (lazy - se crea cuando se necesita)
        $container->register('db', \fs_db2::class)
            ->setPublic(true)
            ->setLazy(true);

        // API Services
        self::registerApiServices();

        // Registrar modelos legacy como servicios
        self::registerLegacyModels();

        // Cargar servicios de plugins
        self::loadPluginServices();
    }

    /**
     * Registra los servicios de la API REST.
     * 
     * NOTA: Los servicios de autenticación (api.auth_model, api.allowed_user_model, api.log_model)
     * son interfaces que deben ser implementadas por un plugin (ej: api_base).
     * El núcleo solo proporciona la infraestructura base.
     */
    private static function registerApiServices(): void
    {
        $container = self::$container;

        // Endpoint Registry (singleton)
        $container->register('api.endpoint_registry', EndpointRegistry::class)
            ->setFactory([EndpointRegistry::class, 'getInstance'])
            ->setPublic(true);

        // Model Resource Registry (singleton)
        $container->register('api.model_registry', ModelResourceRegistry::class)
            ->setFactory([ModelResourceRegistry::class, 'getInstance'])
            ->setPublic(true);

        // Resource Transformer
        $container->register('api.transformer', ResourceTransformer::class)
            ->setPublic(true);

        // Auth Middleware (sin modelos por defecto - deben ser inyectados por plugins)
        $container->register('api.auth_middleware', AuthMiddleware::class)
            ->setPublic(true);

        // CORS Middleware
        $container->register('api.cors_middleware', CorsMiddleware::class)
            ->setPublic(true);

        // Rate Limit Middleware
        $container->register('api.rate_limit_middleware', RateLimitMiddleware::class)
            ->setPublic(true);

        // API Router
        $container->register('api.router', ApiRouter::class)
            ->addArgument(new Reference('api.endpoint_registry'))
            ->addArgument(new Reference('api.auth_middleware'))
            ->addArgument(new Reference('api.cors_middleware'))
            ->setPublic(true);

        // API Kernel (singleton)
        $container->register('api.kernel', ApiKernel::class)
            ->setFactory([ApiKernel::class, 'getInstance'])
            ->setPublic(true);

        // Alias de interfaces para que plugins puedan registrar implementaciones
        // Uso: Container::set(ApiAuthInterface::class, MiImplementacion::class)
        $container->setAlias(ApiAuthInterface::class, 'api.auth_model')->setPublic(true);
        $container->setAlias(AllowedUserInterface::class, 'api.allowed_user_model')->setPublic(true);
        $container->setAlias(ApiLogInterface::class, 'api.log_model')->setPublic(true);
    }

    /**
     * Registra una implementación de autenticación API.
     * Debe ser llamado por el plugin que implementa la autenticación.
     * 
     * @param string $authClass Clase que implementa ApiAuthInterface
     * @param string $allowedUserClass Clase que implementa AllowedUserInterface
     * @param string|null $logClass Clase que implementa ApiLogInterface (opcional)
     */
    public static function registerApiAuth(string $authClass, string $allowedUserClass, ?string $logClass = null): void
    {
        $container = self::getContainer();

        // Registrar implementación de ApiAuth
        $container->register('api.auth_model', $authClass)
            ->setPublic(true);

        // Registrar implementación de AllowedUser
        $container->register('api.allowed_user_model', $allowedUserClass)
            ->setPublic(true);

        // Registrar implementación de ApiLog si se proporciona
        if ($logClass !== null) {
            $container->register('api.log_model', $logClass)
                ->setPublic(true);
        }

        // Inyectar los modelos en el AuthMiddleware
        if ($container->has('api.auth_middleware')) {
            /** @var AuthMiddleware $authMiddleware */
            $authMiddleware = $container->get('api.auth_middleware');
            $authMiddleware->setAuthModel($container->get('api.auth_model'));
            $authMiddleware->setAllowedUserModel($container->get('api.allowed_user_model'));
        }

        // Inyectar el logger en el ApiKernel si está disponible
        if ($logClass !== null && $container->has('api.kernel')) {
            /** @var ApiKernel $kernel */
            $kernel = $container->get('api.kernel');
            $kernel->setApiLogger($container->get('api.log_model'));
        }
    }

    /**
     * Registra modelos legacy comunes como servicios.
     * Permite obtenerlos via Container::get(Empresa::class)
     */
    private static function registerLegacyModels(): void
    {
        $legacyModels = [
            'empresa',
            'fs_user',
            'fs_page',
            'fs_access',
            'fs_extension',
            'fs_var',
            'fs_log',
        ];

        foreach ($legacyModels as $modelName) {
            if (class_exists($modelName)) {
                self::$container->register($modelName, $modelName)
                    ->setPublic(true);
            }
        }
    }

    /**
     * Carga servicios definidos en plugins.
     * Los plugins pueden definir services.php en su directorio config/
     */
    private static function loadPluginServices(): void
    {
        $plugins = $GLOBALS['plugins'] ?? [];
        $root = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__, 2);

        foreach ($plugins as $plugin) {
            $servicesFile = $root . '/plugins/' . $plugin . '/config/services.php';
            if (file_exists($servicesFile)) {
                $configurator = require_once $servicesFile;
                if (is_callable($configurator)) {
                    $configurator(self::$container);
                }
            }
        }
    }

    /**
     * Compila el contenedor (optimización para producción).
     * Debe llamarse después de registrar todos los servicios.
     */
    public static function compile(): void
    {
        if (!self::$compiled) {
            self::getContainer()->compile();
            self::$compiled = true;
        }
    }

    /**
     * Obtiene un servicio del contenedor.
     * 
     * @param string $id ID del servicio o nombre de clase
     * @return mixed El servicio solicitado
     * @throws \Exception Si el servicio no existe
     */
    public static function get(string $id): mixed
    {
        $container = self::getContainer();

        // Intentar obtener por ID directo
        if ($container->has($id)) {
            return $container->get($id);
        }

        // Si es un nombre de clase, intentar auto-wire
        if (class_exists($id)) {
            // Registrar y obtener dinámicamente
            if (!$container->has($id)) {
                $container->register($id, $id)
                    ->setPublic(true)
                    ->setAutowired(true);
            }
            return $container->get($id);
        }

        throw new \InvalidArgumentException("Service '{$id}' not found in container.");
    }

    /**
     * Verifica si un servicio existe.
     */
    public static function has(string $id): bool
    {
        return self::getContainer()->has($id) || class_exists($id);
    }

    /**
     * Registra un servicio manualmente.
     * 
     * @param string $id ID del servicio
     * @param string|object $service Clase o instancia del servicio
     * @return \Symfony\Component\DependencyInjection\Definition
     */
    public static function set(string $id, string|object $service): \Symfony\Component\DependencyInjection\Definition
    {
        $container = self::getContainer();

        if (is_object($service)) {
            $container->set($id, $service);
            return $container->register($id, get_class($service))->setPublic(true);
        }

        return $container->register($id, $service)->setPublic(true);
    }

    /**
     * Registra un servicio como singleton.
     */
    public static function singleton(string $id, string $class): \Symfony\Component\DependencyInjection\Definition
    {
        return self::getContainer()
            ->register($id, $class)
            ->setPublic(true)
            ->setShared(true);
    }

    /**
     * Registra un servicio con factory.
     */
    public static function factory(string $id, callable|array $factory): \Symfony\Component\DependencyInjection\Definition
    {
        return self::getContainer()
            ->register($id)
            ->setFactory($factory)
            ->setPublic(true);
    }

    /**
     * Atajos para servicios comunes.
     */
    public static function db(): \fs_db2
    {
        return self::get('db');
    }

    public static function request(): \Symfony\Component\HttpFoundation\Request
    {
        return self::get('request');
    }

    public static function events(): FSEventDispatcher
    {
        return self::get('event_dispatcher');
    }

    public static function csrf(): CsrfManager
    {
        return self::get('csrf_manager');
    }

    public static function passwordHasher(): PasswordHasherService
    {
        return self::get('password_hasher');
    }

    public static function cache(): CacheManager
    {
        return self::get('cache');
    }

    public static function session(): SessionManager
    {
        return self::get('session');
    }

    public static function preparedDb(): \fs_prepared_db
    {
        return self::get('prepared_db');
    }

    // API Services shortcuts

    public static function apiKernel(): ApiKernel
    {
        return self::get('api.kernel');
    }

    public static function apiRouter(): ApiRouter
    {
        return self::get('api.router');
    }

    public static function endpointRegistry(): EndpointRegistry
    {
        return self::get('api.endpoint_registry');
    }

    public static function modelRegistry(): ModelResourceRegistry
    {
        return self::get('api.model_registry');
    }

    public static function apiAuthMiddleware(): AuthMiddleware
    {
        return self::get('api.auth_middleware');
    }

    /**
     * Resetea el contenedor (útil para tests).
     */
    public static function reset(): void
    {
        self::$container = null;
        self::$compiled = false;
    }
}
