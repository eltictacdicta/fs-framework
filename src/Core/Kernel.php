<?php

namespace FSFramework\Core;

use FSFramework\Core\Exception\KernelNotBootedException;
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
        $this->configureLegacyIncludePaths();

        $root = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__, 2);
        $this->router = new Router($root);

        // Load PHPMailer compatibility layer
        if (file_exists($root . '/extras/phpmailer_compat.php')) {
            require_once $root . '/extras/phpmailer_compat.php';
        }
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
        $proxies = $this->resolveTrustedProxies();
        if (empty($proxies)) {
            return;
        }

        $trustedHeaderSet = $this->resolveTrustedHeaderSet();
        Request::setTrustedProxies($proxies, $trustedHeaderSet);

        // Backward-compatible hook for Symfony versions exposing setTrustedHeaders().
        if (method_exists(Request::class, 'setTrustedHeaders')) {
            Request::setTrustedHeaders($trustedHeaderSet);
        }
    }

    /**
     * @return list<string>
     */
    private function resolveTrustedProxies(): array
    {
        $configured = defined('FS_TRUSTED_PROXIES') ? FS_TRUSTED_PROXIES : getenv('FS_TRUSTED_PROXIES');

        if (is_array($configured)) {
            $items = $configured;
        } elseif (is_string($configured) && $configured !== '') {
            $items = preg_split('/[\s,]+/', $configured) ?: [];
        } else {
            $items = [];
        }

        $result = [];
        foreach ($items as $item) {
            $proxy = trim((string) $item);
            if ($proxy !== '') {
                $result[] = $proxy;
            }
        }

        return array_values(array_unique($result));
    }

    private function resolveTrustedHeaderSet(): int
    {
        $default = Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PREFIX;

        $configured = defined('FS_TRUSTED_HEADERS') ? FS_TRUSTED_HEADERS : getenv('FS_TRUSTED_HEADERS');

        if (is_int($configured)) {
            return $configured;
        }

        if (!is_string($configured) || trim($configured) === '') {
            return $default;
        }

        $map = [
            'forwarded' => Request::HEADER_FORWARDED,
            'x_forwarded_for' => Request::HEADER_X_FORWARDED_FOR,
            'x_forwarded_host' => Request::HEADER_X_FORWARDED_HOST,
            'x_forwarded_proto' => Request::HEADER_X_FORWARDED_PROTO,
            'x_forwarded_port' => Request::HEADER_X_FORWARDED_PORT,
            'x_forwarded_prefix' => Request::HEADER_X_FORWARDED_PREFIX,
            'x_forwarded_aws_elb' => Request::HEADER_X_FORWARDED_AWS_ELB,
            'x_forwarded_traefik' => Request::HEADER_X_FORWARDED_TRAEFIK,
            'aws_elb' => Request::HEADER_X_FORWARDED_AWS_ELB,
            'traefik' => Request::HEADER_X_FORWARDED_TRAEFIK,
        ];

        $set = 0;
        $tokens = preg_split('/[\s,|]+/', strtolower($configured)) ?: [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (isset($map[$token])) {
                $set |= $map[$token];
            }
        }

        return $set > 0 ? $set : $default;
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
            Plugins::init();
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
     */
    public static function handleRequest(): ?Response
    {
        $kernel = self::getInstance();
        return $kernel->getRouter() ? $kernel->getRouter()->handle($kernel->getRequest()) : null;
    }

    /**
     * Helper to get the router statically for URL generation.
      *
     * Uso:
     *   Kernel::router()->generate('admin_users');
     *   Kernel::router()->generateLegacyUrl('admin_home', ['id' => 1]);
      *
     * @return Router|null
     */
    public static function router(): ?Router
    {
        return self::getInstance()->getRouter();
    }

    /**
     * Returns the current version of the framework.
     * Aligned with modern FacturaScripts year-based versioning.
     */
    public static function version(): float
    {
        return 2025.101;
    }
}
