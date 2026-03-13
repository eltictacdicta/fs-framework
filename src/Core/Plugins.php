<?php

namespace FSFramework\Core;

/**
 * Plugin management for FSFramework.
 */
class Plugins
{
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
}
