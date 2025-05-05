<?php

namespace FSFramework\Service;

/**
 * Class SystemIntegrityChecker
 *
 * This service performs various system integrity checks to ensure the framework
 * is properly configured and all requirements are met.
 */
class SystemIntegrityChecker
{
    /**
     * Run all integrity checks and return the results
     *
     * @return array Array with all check results
     */
    public function runAllChecks(): array
    {
        $results = [
            'php' => $this->checkPhpVersion(),
            'extensions' => $this->checkPhpExtensions(),
            'directories' => $this->checkDirectories(),
            'files' => $this->checkFiles(),
            'permissions' => $this->checkPermissions(),
            'symfony' => $this->checkSymfonyComponents(),
            'database' => $this->checkDatabaseConfiguration(),
            'autoloader' => $this->checkAutoloader(),
            'kernel' => $this->checkKernel(),
            'environment' => $this->checkEnvironment(),
        ];

        // Calculate overall status
        $results['overall_status'] = $this->calculateOverallStatus($results);

        return $results;
    }

    /**
     * Check PHP version
     *
     * @return array Check result
     */
    public function checkPhpVersion(): array
    {
        $required = '8.1.0';
        $current = PHP_VERSION;
        $status = version_compare($current, $required, '>=');

        return [
            'name' => 'Versión de PHP',
            'required' => $required,
            'current' => $current,
            'status' => $status,
            'message' => $status
                ? 'La versión de PHP es compatible'
                : 'FS-Framework necesita PHP 8.1 o superior'
        ];
    }

    /**
     * Check required PHP extensions
     *
     * @return array Check result
     */
    public function checkPhpExtensions(): array
    {
        $required_extensions = [
            'pdo' => 'PDO (Database)',
            'pdo_mysql' => 'PDO MySQL',
            'json' => 'JSON',
            'mbstring' => 'Multibyte String',
            'xml' => 'XML',
            'zip' => 'ZIP',
            'openssl' => 'OpenSSL'
        ];

        $results = [];
        $overall_status = true;

        foreach ($required_extensions as $ext => $name) {
            $status = extension_loaded($ext);
            $results[$ext] = [
                'name' => $name,
                'status' => $status,
                'message' => $status
                    ? "Extensión $name cargada correctamente"
                    : "Extensión $name no está disponible"
            ];

            if (!$status) {
                $overall_status = false;
            }
        }

        return [
            'name' => 'Extensiones de PHP',
            'details' => $results,
            'status' => $overall_status,
            'message' => $overall_status
                ? 'Todas las extensiones requeridas están disponibles'
                : 'Faltan algunas extensiones requeridas'
        ];
    }

    /**
     * Check required directories
     *
     * @return array Check result
     */
    public function checkDirectories(): array
    {
        // Directorios que deben existir antes de la instalación
        $required_directories = [
            'src' => 'Directorio de código fuente'
        ];

        // Directorios que se crearán durante o después de la instalación
        $optional_directories = [
            'templates' => 'Directorio de plantillas',
            'plugins' => 'Directorio de plugins',
            'var/cache' => 'Directorio de caché',
            'var/log' => 'Directorio de logs',
            'tmp' => 'Directorio temporal'
        ];

        $results = [];
        $overall_status = true;

        // Verificar directorios requeridos
        foreach ($required_directories as $dir => $name) {
            $status = is_dir(__DIR__ . '/../../' . $dir);
            $results[$dir] = [
                'name' => $name,
                'status' => $status,
                'message' => $status
                    ? "$name existe"
                    : "$name no existe (requerido)"
            ];

            if (!$status) {
                $overall_status = false;
            }
        }

        // Verificar directorios opcionales
        foreach ($optional_directories as $dir => $name) {
            $exists = is_dir(__DIR__ . '/../../' . $dir);
            $results[$dir] = [
                'name' => $name,
                'status' => true, // Siempre true porque son opcionales
                'message' => $exists
                    ? "$name existe"
                    : "$name se creará durante la instalación"
            ];
        }

        return [
            'name' => 'Directorios requeridos',
            'details' => $results,
            'status' => $overall_status,
            'message' => $overall_status
                ? 'Todos los directorios requeridos existen'
                : 'Faltan algunos directorios requeridos'
        ];
    }

    /**
     * Check required files
     *
     * @return array Check result
     */
    public function checkFiles(): array
    {
        // Archivos que deben existir antes de la instalación
        $required_files = [
            'src/Kernel.php' => 'Kernel del framework',
            'src/Plugin/PluginAutoloader.php' => 'Cargador de plugins'
        ];

        // Archivos que se crearán durante o después de la instalación
        $optional_files = [
            'config/packages/twig.yaml' => 'Configuración de Twig',
            'templates/base.html.twig' => 'Plantilla base'
        ];

        $results = [];
        $overall_status = true;

        // Verificar archivos requeridos
        foreach ($required_files as $file => $name) {
            $status = file_exists(__DIR__ . '/../../' . $file);
            $results[$file] = [
                'name' => $name,
                'status' => $status,
                'message' => $status
                    ? "$name existe"
                    : "$name no existe (requerido)"
            ];

            if (!$status) {
                $overall_status = false;
            }
        }

        // Verificar archivos opcionales
        foreach ($optional_files as $file => $name) {
            $exists = file_exists(__DIR__ . '/../../' . $file);
            $results[$file] = [
                'name' => $name,
                'status' => true, // Siempre true porque son opcionales
                'message' => $exists
                    ? "$name existe"
                    : "$name se creará durante la instalación"
            ];
        }

        return [
            'name' => 'Archivos requeridos',
            'details' => $results,
            'status' => $overall_status,
            'message' => $overall_status
                ? 'Todos los archivos requeridos existen'
                : 'Faltan algunos archivos requeridos'
        ];
    }

    /**
     * Check directory permissions
     *
     * @return array Check result
     */
    public function checkPermissions(): array
    {
        // El directorio raíz siempre debe tener permisos de escritura
        $critical_directories = [
            '.' => 'Directorio raíz'
        ];

        // Directorios que se crearán durante la instalación y necesitarán permisos
        $installation_directories = [
            'var/cache' => 'Directorio de caché',
            'var/log' => 'Directorio de logs',
            'plugins' => 'Directorio de plugins',
            'tmp' => 'Directorio temporal'
        ];

        $results = [];
        $overall_status = true;

        // Verificar directorios críticos
        foreach ($critical_directories as $dir => $name) {
            $fullPath = __DIR__ . '/../../' . $dir;
            if (is_dir($fullPath)) {
                $status = is_writable($fullPath);
                $results[$dir] = [
                    'name' => $name,
                    'status' => $status,
                    'message' => $status
                        ? "$name tiene permisos de escritura"
                        : "$name no tiene permisos de escritura (requerido)"
                ];

                if (!$status) {
                    $overall_status = false;
                }
            } else {
                $results[$dir] = [
                    'name' => $name,
                    'status' => false,
                    'message' => "$name no existe (requerido)"
                ];
                $overall_status = false;
            }
        }

        // Verificar directorios de instalación
        foreach ($installation_directories as $dir => $name) {
            $fullPath = __DIR__ . '/../../' . $dir;
            if (is_dir($fullPath)) {
                $status = is_writable($fullPath);
                $results[$dir] = [
                    'name' => $name,
                    'status' => $status,
                    'message' => $status
                        ? "$name tiene permisos de escritura"
                        : "$name no tiene permisos de escritura"
                ];

                // No afecta el estado general si no existe todavía
                if (!$status) {
                    $overall_status = false;
                }
            } else {
                // Si no existe, no es un error porque se creará durante la instalación
                $results[$dir] = [
                    'name' => $name,
                    'status' => true,
                    'message' => "$name se creará durante la instalación"
                ];
            }
        }

        return [
            'name' => 'Permisos de directorios',
            'details' => $results,
            'status' => $overall_status,
            'message' => $overall_status
                ? 'Todos los directorios tienen permisos de escritura'
                : 'Algunos directorios no tienen permisos de escritura'
        ];
    }

    /**
     * Check Symfony components
     *
     * @return array Check result
     */
    public function checkSymfonyComponents(): array
    {
        $components = [
            'Symfony\Component\HttpFoundation\Request' => 'HttpFoundation',
            'Symfony\Component\ErrorHandler\Debug' => 'ErrorHandler',
            'Symfony\Component\HttpKernel\Kernel' => 'HttpKernel',
            'Symfony\Component\Routing\Router' => 'Routing',
            'Symfony\Component\DependencyInjection\ContainerBuilder' => 'DependencyInjection',
            'Symfony\Component\Config\FileLocator' => 'Config',
            'Twig\Environment' => 'Twig'
        ];

        $results = [];
        $overall_status = true;

        foreach ($components as $class => $name) {
            $status = class_exists($class);
            $results[$name] = [
                'name' => $name,
                'status' => $status,
                'message' => $status
                    ? "Componente $name disponible"
                    : "Componente $name no disponible"
            ];

            if (!$status) {
                $overall_status = false;
            }
        }

        return [
            'name' => 'Componentes de Symfony',
            'details' => $results,
            'status' => $overall_status,
            'message' => $overall_status
                ? 'Todos los componentes de Symfony están disponibles'
                : 'Faltan algunos componentes de Symfony'
        ];
    }

    /**
     * Check database configuration
     *
     * @return array Check result
     */
    public function checkDatabaseConfiguration(): array
    {
        // Skip if config.php doesn't exist yet
        if (!file_exists(__DIR__ . '/../../config.php')) {
            return [
                'name' => 'Configuración de base de datos',
                'status' => true,
                'message' => 'El archivo config.php se creará durante la instalación'
            ];
        }

        // Include config file
        include_once __DIR__ . '/../../config.php';

        // Check if database constants are defined
        $constants = [
            'FS_DB_TYPE' => 'Tipo de base de datos',
            'FS_DB_HOST' => 'Servidor de base de datos',
            'FS_DB_PORT' => 'Puerto de base de datos',
            'FS_DB_NAME' => 'Nombre de base de datos',
            'FS_DB_USER' => 'Usuario de base de datos'
        ];

        $results = [];
        $overall_status = true;

        foreach ($constants as $const => $name) {
            $status = defined($const);
            $results[$const] = [
                'name' => $name,
                'status' => $status,
                'message' => $status
                    ? "$name configurado correctamente"
                    : "$name no está configurado"
            ];

            if (!$status) {
                $overall_status = false;
            }
        }

        // Test database connection if all constants are defined
        if ($overall_status) {
            try {
                $dsn = strtolower(FS_DB_TYPE) === 'mysql'
                    ? "mysql:host=" . FS_DB_HOST . ";port=" . FS_DB_PORT . ";dbname=" . FS_DB_NAME
                    : "pgsql:host=" . FS_DB_HOST . ";port=" . FS_DB_PORT . ";dbname=" . FS_DB_NAME;

                $pdo = new \PDO($dsn, FS_DB_USER, FS_DB_PASS);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                $results['connection'] = [
                    'name' => 'Conexión a la base de datos',
                    'status' => true,
                    'message' => 'Conexión a la base de datos exitosa'
                ];
            } catch (\PDOException $e) {
                $results['connection'] = [
                    'name' => 'Conexión a la base de datos',
                    'status' => false,
                    'message' => 'Error de conexión: ' . $e->getMessage()
                ];
                $overall_status = false;
            }
        }

        return [
            'name' => 'Configuración de base de datos',
            'details' => $results,
            'status' => $overall_status,
            'message' => $overall_status
                ? 'La configuración de base de datos es correcta'
                : 'Hay problemas con la configuración de base de datos'
        ];
    }

    /**
     * Check autoloader
     *
     * @return array Check result
     */
    public function checkAutoloader(): array
    {
        $autoloaderExists = file_exists(__DIR__ . '/../../vendor/autoload.php');
        $composerJsonExists = file_exists(__DIR__ . '/../../composer.json');
        $composerLockExists = file_exists(__DIR__ . '/../../composer.lock');

        $details = [];

        // Verificar autoloader
        $details['autoloader'] = [
            'name' => 'Archivo autoload.php',
            'status' => $autoloaderExists,
            'message' => $autoloaderExists
                ? 'El autoloader está disponible'
                : 'El autoloader no está disponible. Ejecute "composer install"'
        ];

        // Verificar composer.json
        $details['composer_json'] = [
            'name' => 'Archivo composer.json',
            'status' => $composerJsonExists,
            'message' => $composerJsonExists
                ? 'El archivo composer.json existe'
                : 'El archivo composer.json no existe'
        ];

        // Verificar composer.lock
        $details['composer_lock'] = [
            'name' => 'Archivo composer.lock',
            'status' => $composerLockExists || !$composerJsonExists, // No es un error si no existe composer.json
            'message' => $composerLockExists
                ? 'El archivo composer.lock existe'
                : ($composerJsonExists
                    ? 'El archivo composer.lock no existe. Ejecute "composer install"'
                    : 'El archivo composer.lock no existe (no es necesario sin composer.json)')
        ];

        // Determinar el estado general
        $status = $autoloaderExists || !$composerJsonExists; // Si no hay composer.json, no es un error que no haya autoloader

        return [
            'name' => 'Composer y Autoloader',
            'details' => $details,
            'status' => $status,
            'message' => $status
                ? 'El sistema de autoloading está correctamente configurado'
                : 'Hay problemas con el sistema de autoloading. Ejecute "composer install"'
        ];
    }

    /**
     * Check kernel
     *
     * @return array Check result
     */
    public function checkKernel(): array
    {
        $fsClasses = [
            'FSFramework\Kernel' => 'Kernel del framework',
            'FSFramework\Plugin\PluginAutoloader' => 'Cargador de plugins'
        ];

        $results = [];
        $overall_status = true;

        foreach ($fsClasses as $class => $name) {
            $status = class_exists($class);
            $results[$class] = [
                'name' => $name,
                'status' => $status,
                'message' => $status
                    ? "$name está disponible"
                    : "$name no está disponible"
            ];

            if (!$status) {
                $overall_status = false;
            }
        }

        return [
            'name' => 'Clases del framework',
            'details' => $results,
            'status' => $overall_status,
            'message' => $overall_status
                ? 'Todas las clases del framework están disponibles'
                : 'Faltan algunas clases del framework'
        ];
    }

    /**
     * Check environment
     *
     * @return array Check result
     */
    public function checkEnvironment(): array
    {
        // Verificar si existe el archivo .env
        $envFileExists = file_exists(__DIR__ . '/../../.env');

        // Preparar los detalles de las variables de entorno
        $details = [];

        // Si el archivo .env existe, intentar leer las variables
        if ($envFileExists) {
            // Intentar obtener variables de entorno
            $appEnv = getenv('APP_ENV');
            $appDebug = getenv('APP_DEBUG');

            // Añadir APP_ENV a los detalles
            $details['app_env'] = [
                'name' => 'APP_ENV',
                'status' => !empty($appEnv),
                'message' => !empty($appEnv)
                    ? "Valor: " . $appEnv
                    : "No definido (se usará 'prod' por defecto)"
            ];

            // Añadir APP_DEBUG a los detalles
            $details['app_debug'] = [
                'name' => 'APP_DEBUG',
                'status' => !empty($appDebug),
                'message' => !empty($appDebug)
                    ? "Valor: " . $appDebug
                    : "No definido (se usará '0' por defecto)"
            ];
        } else {
            // Si no existe el archivo .env, mostrar mensaje informativo
            $details['env_file'] = [
                'name' => 'Archivo .env',
                'status' => true, // No es un error, es informativo
                'message' => "No existe (se creará durante la instalación)"
            ];
        }

        return [
            'name' => 'Variables de entorno',
            'details' => $details,
            'status' => true, // Siempre true porque no es crítico para la instalación
            'message' => $envFileExists
                ? 'El archivo .env existe'
                : 'El archivo .env se creará durante la instalación'
        ];
    }

    /**
     * Calculate overall status based on all check results
     *
     * @param array $results All check results
     * @return bool Overall status
     */
    private function calculateOverallStatus(array $results): bool
    {
        foreach ($results as $key => $result) {
            if ($key !== 'overall_status' && isset($result['status']) && $result['status'] === false) {
                return false;
            }
        }

        return true;
    }
}
