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

        // Registrar modelos legacy como servicios
        self::registerLegacyModels();

        // Cargar servicios de plugins
        self::loadPluginServices();
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
                $configurator = require $servicesFile;
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

    /**
     * Resetea el contenedor (útil para tests).
     */
    public static function reset(): void
    {
        self::$container = null;
        self::$compiled = false;
    }
}
