<?php

namespace FSFramework;

use FSFramework\Plugin\LegacyPluginAutoloader;
use FSFramework\Plugin\PluginAutoloader;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private const CONFIG_EXTS = '.{php,xml,yaml,yml}';

    /**
     * Boot the kernel and set up plugin autoloaders
     */
    public function boot(): void
    {
        parent::boot();
        
        // Register the plugin autoloaders
        PluginAutoloader::register();
        LegacyPluginAutoloader::register();
    }

    public function registerBundles(): iterable
    {
        $contents = require $this->getProjectDir().'/config/bundles.php';
        foreach ($contents as $class => $envs) {
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                yield new $class();
            }
        }
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->addResource(new FileResource($this->getProjectDir().'/config/bundles.php'));
        $container->setParameter('container.dumper.inline_class_loader', \PHP_VERSION_ID < 70400 || $this->debug);
        $container->setParameter('container.dumper.inline_factories', true);

        $confDir = $this->getProjectDir().'/config';

        $loader->load($confDir.'/{packages}/*'.self::CONFIG_EXTS, 'glob');
        $loader->load($confDir.'/{packages}/'.$this->environment.'/*'.self::CONFIG_EXTS, 'glob');
        $loader->load($confDir.'/{services}'.self::CONFIG_EXTS, 'glob');
        $loader->load($confDir.'/{services}_'.$this->environment.self::CONFIG_EXTS, 'glob');

        // Cargar servicios de plugins
        $this->loadPluginServices($container);
    }

    /**
     * Carga los servicios de los plugins activos
     */
    private function loadPluginServices(ContainerBuilder $container): void
    {
        // Verificar si existe la variable global de plugins
        if (!isset($GLOBALS['plugins']) || !is_array($GLOBALS['plugins'])) {
            return;
        }

        // Recorrer los plugins activos
        foreach ($GLOBALS['plugins'] as $pluginName) {
            $pluginDir = $this->getProjectDir().'/plugins/'.$pluginName;

            // Registrar controladores del plugin
            $namespace = 'FSFramework\\Plugin\\'.ucfirst($pluginName).'\\Controller';
            $container->registerForAutoconfiguration($namespace.'\\')->addTag('controller.service_arguments');

            // Buscar controladores en el directorio Controller
            if (is_dir($pluginDir.'/Controller')) {
                foreach (scandir($pluginDir.'/Controller') as $file) {
                    if ($file === '.' || $file === '..' || substr($file, -4) !== '.php') {
                        continue;
                    }

                    $className = substr($file, 0, -4);
                    $fullClassName = $namespace.'\\' . $className;

                    // Registrar el controlador como servicio
                    $container->register($fullClassName)
                        ->setAutoconfigured(true)
                        ->setAutowired(true)
                        ->addTag('controller.service_arguments');
                }
            }
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $confDir = $this->getProjectDir().'/config';

        $routes->import($confDir.'/{routes}/'.$this->environment.'/*'.self::CONFIG_EXTS, 'glob');
        $routes->import($confDir.'/{routes}/*'.self::CONFIG_EXTS, 'glob');
        $routes->import($confDir.'/{routes}'.self::CONFIG_EXTS, 'glob');

        // Cargar rutas de plugins
        $this->loadPluginRoutes($routes);
    }

    /**
     * Carga las rutas de los plugins activos
     */
    private function loadPluginRoutes(RoutingConfigurator $routes): void
    {
        // Verificar si existe la variable global de plugins
        if (!isset($GLOBALS['plugins']) || !is_array($GLOBALS['plugins'])) {
            return;
        }

        // Recorrer los plugins activos
        foreach ($GLOBALS['plugins'] as $pluginName) {
            $pluginDir = $this->getProjectDir().'/plugins/'.$pluginName;

            // Importar controladores del plugin si existen
            if (is_dir($pluginDir.'/Controller')) {
                try {
                    $routes->import($pluginDir.'/Controller/', 'attribute');
                } catch (\Exception $e) {
                    // Si falla con attribute, intentamos con annotation
                    try {
                        $routes->import($pluginDir.'/Controller/', 'annotation');
                    } catch (\Exception $e) {
                        // Si ambos fallan, mostramos el error
                        error_log('Error al cargar las rutas del plugin ' . $pluginName . ': ' . $e->getMessage());
                    }
                }
            }
        }
    }
}
