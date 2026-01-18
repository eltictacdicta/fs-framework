<?php

namespace FSFramework\Core;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Kernel
{
    private static ?Kernel $instance = null;
    private Request $request;
    private ?Router $router = null;

    private function __construct()
    {
        $this->request = Request::createFromGlobals();
        $this->configureLegacyIncludePaths();

        $root = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__, 2);
        $this->router = new Router($root);

        // Load PHPMailer compatibility layer
        if (file_exists($root . '/extras/phpmailer_compat.php')) {
            require_once $root . '/extras/phpmailer_compat.php';
        }
    }

    /**
     * Configures include paths for legacy compatibility.
     * specifically for plugins like facturacion_base that expect 'extras/' to be in the path.
     */
    private function configureLegacyIncludePaths(): void
    {
        $root = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__, 2);
        $legacyPath = $root . '/plugins/facturacion_base';

        if (is_dir($legacyPath)) {
            set_include_path(get_include_path() . PATH_SEPARATOR . $legacyPath);
        }
    }

    public static function boot(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Kernel not booted. Call Kernel::boot() first.');
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
     */
    public static function handleRequest(): ?Response
    {
        $kernel = self::getInstance();
        return $kernel->getRouter() ? $kernel->getRouter()->handle($kernel->getRequest()) : null;
    }
}
