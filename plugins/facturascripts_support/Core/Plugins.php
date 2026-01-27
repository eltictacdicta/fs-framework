<?php

namespace FacturaScripts\Core;

/**
 * Bridge for FacturaScripts\Core\Plugins
 */
class Plugins
{
    public static function init(): void
    {
        foreach (self::enabled() as $pluginName) {
            $initClass = "\\FacturaScripts\\Plugins\\" . $pluginName . "\\Init";
            if (class_exists($initClass)) {
                $init = new $initClass();
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
        return in_array($pluginName, self::enabled());
    }
}
