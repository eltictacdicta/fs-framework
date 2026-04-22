<?php

namespace FSFramework\Core;

/**
 * Plugin management for FSFramework.
 */
class Plugins
{
    /**
     * @var array<string, list<string>>
     */
    private static array $publicPathPrefixes = [];

    /**
     * @var array<string, callable>
     */
    private static array $routeConfigurators = [];

    /**
     * @var array<string, bool>
     */
    private static array $automaticRouteLoadingDisabled = [];

    /**
     * Stealth home overrides: plugins can register a redirect URL to use instead
     * of the default stealth homepage when stealth mode is enabled.
     *
     * @var array<string, array{url: string, priority: int}>
     */
    private static array $stealthHomeOverrides = [];

    public static function init(): void
    {
        foreach (self::enabled() as $pluginName) {
            $initClass = '\\FSFramework\\Plugins\\' . $pluginName . '\\Init';

            if (!class_exists($initClass)) {
                continue;
            }

            $init = new $initClass();
            if (method_exists($init, 'init')) {
                $init->init();
            }
        }
    }

    public static function enabled(): array
    {
        return $GLOBALS['plugins'] ?? [];
    }

    public static function isEnabled(string $pluginName): bool
    {
        return in_array($pluginName, self::enabled(), true);
    }

    /**
     * @param list<string> $prefixes
     */
    public static function registerPublicPathPrefixes(string $pluginName, array $prefixes): void
    {
        $normalized = [];
        foreach ($prefixes as $prefix) {
            $candidate = trim((string) $prefix);
            if ($candidate === '') {
                continue;
            }

            $normalized[] = $candidate === '/' ? '/' : rtrim($candidate, '/');
        }

        self::$publicPathPrefixes[$pluginName] = array_values(array_unique($normalized));
    }

    public static function isPublicPath(string $path): bool
    {
        $normalizedPath = $path === '/' ? '/' : rtrim($path, '/');
        if ($normalizedPath === '') {
            $normalizedPath = '/';
        }

        foreach (self::enabled() as $pluginName) {
            foreach (self::$publicPathPrefixes[$pluginName] ?? [] as $prefix) {
                if ($prefix === '/') {
                    if ($normalizedPath === '/') {
                        return true;
                    }

                    continue;
                }

                if ($normalizedPath === $prefix || str_starts_with($normalizedPath, $prefix . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function registerRouteConfigurator(string $pluginName, callable $configurator): void
    {
        self::$routeConfigurators[$pluginName] = $configurator;
    }

    /**
     * @return array<string, callable>
     */
    public static function routeConfigurators(): array
    {
        $activeConfigurators = [];
        foreach (self::enabled() as $pluginName) {
            if (isset(self::$routeConfigurators[$pluginName])) {
                $activeConfigurators[$pluginName] = self::$routeConfigurators[$pluginName];
            }
        }

        return $activeConfigurators;
    }

    public static function disableAutomaticRouteLoading(string $pluginName): void
    {
        self::$automaticRouteLoadingDisabled[$pluginName] = true;
    }

    public static function shouldLoadAutomaticRoutes(string $pluginName): bool
    {
        return !isset(self::$automaticRouteLoadingDisabled[$pluginName]);
    }

    /**
     * Register a stealth home override for a plugin.
     *
     * When stealth mode is active and would normally show the public homepage,
     * the system will instead redirect to the override URL registered by the
     * highest-priority active plugin.
     *
     * @param string $pluginName The plugin registering the override
     * @param string $redirectUrl The URL to redirect to (e.g., '/oauth/login')
     * @param int $priority Higher priority overrides take precedence (default: 0)
     */
    public static function registerStealthHomeOverride(string $pluginName, string $redirectUrl, int $priority = 0): void
    {
        $url = trim($redirectUrl);
        if ($url === '') {
            return;
        }

        if (!self::isValidStealthRedirectUrl($url)) {
            return;
        }

        self::$stealthHomeOverrides[$pluginName] = [
            'url' => $url,
            'priority' => $priority,
        ];
    }

    private static function isValidStealthRedirectUrl(string $url): bool
    {
        $decodedUrl = strtolower(rawurldecode($url));
        $unsafeSchemes = ['javascript:', 'data:', 'vbscript:', 'file:', 'blob:'];
        foreach ($unsafeSchemes as $scheme) {
            if (str_starts_with($decodedUrl, $scheme)) {
                return false;
            }
        }

        if (str_starts_with($url, '//')) {
            return false;
        }

        $parsedScheme = parse_url($url, PHP_URL_SCHEME);
        $parsedHost = parse_url($url, PHP_URL_HOST);

        if ($parsedScheme !== null || $parsedHost !== null) {
            return false;
        }

        if (!str_starts_with($url, '/')) {
            return false;
        }

        return true;
    }

    /**
     * Get the stealth home override URL from the highest-priority active plugin.
     *
     * @return string|null The redirect URL, or null if no override is registered
     */
    public static function getStealthHomeOverride(): ?string
    {
        $bestUrl = null;
        $bestPriority = PHP_INT_MIN;

        foreach (self::enabled() as $pluginName) {
            if (!isset(self::$stealthHomeOverrides[$pluginName])) {
                continue;
            }

            $override = self::$stealthHomeOverrides[$pluginName];
            if ($override['priority'] > $bestPriority) {
                $bestPriority = $override['priority'];
                $bestUrl = $override['url'];
            }
        }

        return $bestUrl;
    }

    /**
     * Check if any active plugin has registered a stealth home override.
     */
    public static function hasStealthHomeOverride(): bool
    {
        return self::getStealthHomeOverride() !== null;
    }

    public static function resetRuntimeState(): void
    {
        self::$publicPathPrefixes = [];
        self::$routeConfigurators = [];
        self::$automaticRouteLoadingDisabled = [];
        self::$stealthHomeOverrides = [];
    }
}
