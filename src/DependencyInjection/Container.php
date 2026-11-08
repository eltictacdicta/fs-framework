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
use FSFramework\Security\EncryptionService;
use FSFramework\Security\PasswordHasherService;
use FSFramework\Security\SessionManager;
use FSFramework\Cache\CacheManager;

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
     * Core service definitions that plugins must not be able to shadow.
     *
     * Symfony's `ContainerBuilder::register()` overwrites unconditionally, so
     * the definitions registered before `loadPluginServices()` are captured here
     * and re-asserted right after the plugins have had their say (CI-17, D9).
     *
     * @var array<string, \Symfony\Component\DependencyInjection\Definition>
     */
    private static array $reservedDefinitions = [];

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

        // Encryption Service (Sodium)
        $container->register('encryption', EncryptionService::class)
            ->setPublic(true);

        // Cache Manager
        $container->register('cache', CacheManager::class)
            ->setFactory([CacheManager::class, 'getInstance'])
            ->setPublic(true);

        // Session Manager
        $container->register('session', SessionManager::class)
            ->setFactory([SessionManager::class, 'getInstance'])
            ->setPublic(true);

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

        // Alias por clase: el autowiring de un parámetro tipado recibe la
        // instancia compartida en vez de construir una nueva.
        $classAliases = [
            FSEventDispatcher::class => 'event_dispatcher',
            CsrfManager::class => 'csrf_manager',
            PasswordHasherService::class => 'password_hasher',
            EncryptionService::class => 'encryption',
            CacheManager::class => 'cache',
            SessionManager::class => 'session',
            \Symfony\Component\HttpFoundation\Request::class => 'request',
            \FSFramework\Core\Router::class => 'router',
            \fs_db2::class => 'db',
        ];
        foreach ($classAliases as $class => $serviceId) {
            $container->setAlias($class, $serviceId)->setPublic(true);
        }

        // Registrar modelos legacy como servicios
        self::registerLegacyModels();

        // Registrar servicios core de content-i18n (reservados ante plugins)
        self::registerContentI18nServices();

        // Cargar servicios de plugins
        self::loadPluginServices();

        // Re-afirmar los ids core reservados tras la carga de plugins
        self::restoreReservedServices();
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
     * Registra los servicios core de content-i18n con ids namespaced.
     *
     * Los `Definition` resultantes se memorizan en `$reservedDefinitions` para
     * poder re-afirmarlos despues de que los plugins registren sus servicios
     * (CI-17, D9).
     */
    private static function registerContentI18nServices(): void
    {
        self::$reservedDefinitions['core.language_registry'] = self::$container
            ->register('core.language_registry', \FSFramework\Translation\LanguageRegistry::class)
            ->setPublic(true);

        self::$reservedDefinitions['core.content_translator'] = self::$container
            ->register('core.content_translator', \FSFramework\Translation\ContentTranslator::class)
            ->setPublic(true)
            ->setArguments([new Reference('core.language_registry')]);
    }

    /**
     * Re-afirma los ids core reservados sobre cualquier colision registrada por
     * un plugin. Solo toca los ids `core.` reservados; los ids propios de los
     * plugins quedan intactos (CI-17, D9).
     */
    private static function restoreReservedServices(): void
    {
        foreach (self::$reservedDefinitions as $id => $definition) {
            self::$container->setDefinition($id, $definition);
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

        // Si es un nombre de clase sin registrar, registrarlo como autowired
        if (!$container->has($id) && class_exists($id)) {
            $container->register($id, $id)
                ->setPublic(true)
                ->setAutowired(true);
        }

        if ($container->has($id)) {
            self::autowire($id);
            return $container->get($id);
        }

        throw new \InvalidArgumentException("Service '{$id}' not found in container.");
    }

    /**
     * Ids cuyas dependencias ya se resolvieron (o se están resolviendo).
     *
     * @var array<string, true>
     */
    private static array $autowired = [];

    /**
     * Resuelve los argumentos del constructor de una definición autowired.
     *
     * El contenedor no se compila (los plugins registran servicios en tiempo de
     * ejecución), así que el AutowirePass de Symfony nunca corre y
     * `setAutowired(true)` por sí solo instancia la clase sin argumentos. Aquí
     * se rellenan con `Reference` los parámetros tipados con una clase o
     * interfaz; las dependencias aún no registradas se registran como
     * autowired, de forma recursiva. Las definiciones con argumentos
     * explícitos o con factory se respetan tal cual.
     */
    private static function autowire(string $id): void
    {
        $container = self::getContainer();
        while ($container->hasAlias($id)) {
            $id = (string) $container->getAlias($id);
        }
        if (self::$compiled || isset(self::$autowired[$id]) || !$container->hasDefinition($id)) {
            return;
        }
        // La marca se pone antes de recursar (corta ciclos), pero se retira si la
        // resolución falla: si quedara puesta, el siguiente get() instanciaría la
        // clase sin argumentos y ocultaría el error claro tras un ArgumentCountError.
        self::$autowired[$id] = true;
        try {
            self::resolveConstructorArguments($id, $container->getDefinition($id));
        } catch (\Throwable $e) {
            unset(self::$autowired[$id]);
            throw $e;
        }
    }

    private static function resolveConstructorArguments(string $id, \Symfony\Component\DependencyInjection\Definition $definition): void
    {
        $class = $definition->getClass() ?? $id;
        if (!$definition->isAutowired()
            || $definition->getFactory() !== null
            || $definition->getArguments() !== []
            || !class_exists($class)
        ) {
            return;
        }

        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return;
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $dependency = self::resolveDependencyId($parameter);
            if ($dependency === null) {
                if ($parameter->isOptional()) {
                    break; // el resto de parámetros toma su valor por defecto
                }
                if ($parameter->allowsNull()) {
                    $arguments[] = null;
                    continue;
                }
                throw new \InvalidArgumentException(sprintf(
                    'Cannot autowire service "%s": parameter $%s of %s::__construct() has no resolvable class type.',
                    $id,
                    $parameter->getName(),
                    $class
                ));
            }

            self::autowire($dependency);
            $arguments[] = new Reference($dependency);
        }

        $definition->setArguments($arguments);
    }

    /**
     * Devuelve el id de servicio que satisface un parámetro, registrándolo como
     * autowired si es una clase concreta aún desconocida. NULL si no se puede.
     */
    private static function resolveDependencyId(\ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();
        if (in_array(strtolower($name), ['self', 'static'], true)) {
            $name = $parameter->getDeclaringClass()?->getName() ?? $name;
        }

        $container = self::getContainer();
        if ($container->has($name)) {
            return $name;
        }

        // Un parámetro opcional solo se cablea con servicios ya existentes.
        if (!$parameter->isOptional() && class_exists($name) && (new \ReflectionClass($name))->isInstantiable()) {
            $container->register($name, $name)
                ->setPublic(true)
                ->setAutowired(true);
            return $name;
        }

        return null;
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

    public static function encryption(): EncryptionService
    {
        return self::get('encryption');
    }

    public static function cache(): CacheManager
    {
        return self::get('cache');
    }

    public static function session(): SessionManager
    {
        return self::get('session');
    }

    /**
     * Resetea el contenedor (útil para tests).
     */
    public static function reset(): void
    {
        self::$container = null;
        self::$compiled = false;
        self::$reservedDefinitions = [];
        self::$autowired = [];
    }
}
