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

    public static function resetRuntimeState(): void
    {
        self::$publicPathPrefixes = [];
        self::$routeConfigurators = [];
        self::$automaticRouteLoadingDisabled = [];
    }
}
