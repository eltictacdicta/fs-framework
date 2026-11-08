<?php

namespace FSFramework\Core;

use FSFramework\Core\Exception\KernelNotBootedException;
use FSFramework\Security\TrustedProxyConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Kernel
{
    private static ?Kernel $instance = null;
    private Request $request;
    private ?Router $router = null;

    private function __construct()
    {
        $this->configureTrustedProxies();
        $this->request = Request::createFromGlobals();
    }

    private function initializeRouter(): void
    {
        $root = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__, 2);
        $this->router = new Router($root);
    }

    /**
     * Configure trusted proxies and forwarded headers for Symfony Request.
     *
     * Expected values:
     * - FS_TRUSTED_PROXIES: comma/space separated list (IPs/CIDRs, REMOTE_ADDR, PRIVATE_SUBNETS)
     * - FS_TRUSTED_HEADERS: comma/space separated list of header keys or aliases
     */
    private function configureTrustedProxies(): void
    {
        TrustedProxyConfigurator::configure();
    }

    public static function boot(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            Plugins::init();
            self::$instance->initializeRouter();
        }
        return self::$instance;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new KernelNotBootedException();
        }
        return self::$instance;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getRouter(): ?Router
    {
        return $this->router;
    }

    /**
     * Helper to get the request statically for legacy code access
     */
    public static function request(): Request
    {
        return self::getInstance()->getRequest();
    }

    /**
     * Helper to handle the current request through the router
     * @deprecated Será retirado en v3.0. Migrar a Kernel::router()?->handle(Kernel::request()).
     */
    public static function handleRequest(): ?Response
    {
        if (Plugins::isEnabled('legacy_support') && class_exists('FSFramework\\Plugins\\legacy_support\\LegacyCompatibility')) {
            \FSFramework\Plugins\legacy_support\LegacyCompatibility::reportDeprecatedComponent(
                'legacy.kernel',
                'handleRequest',
                'Kernel::router()?->handle(Kernel::request())'
            );
        }

        $kernel = self::getInstance();
        return $kernel->getRouter() ? $kernel->getRouter()->handle($kernel->getRequest()) : null;
    }

    /**
     * Helper to get the router statically for URL generation.
      *
     * Uso:
     *   Kernel::router()->generate('admin_users');
      *
     * @return Router|null
     */
    public static function router(): ?Router
    {
        return self::getInstance()->getRouter();
    }

    /**
     * Nivel de compatibilidad FS2025 (versionado por año de FacturaScripts).
     *
     * NO es la versión de FSFramework, que vive en el fichero VERSION (lo lee
     * fs_plugin_manager). Este valor es el que se compara con el min_version
     * de los plugins FS2025, así que no debe alinearse con VERSION.
     */
    public static function version(): float
    {
        return 2025.101;
    }
}
