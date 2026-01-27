<?php

namespace FacturaScripts\Core\Template;

/**
 * Bridge for FacturaScripts\Core\Template\InitClass
 */
abstract class InitClass
{
    /**
     * Code to load every time FacturaScripts starts.
     */
    abstract public function init(): void;

    /**
     * Code that is executed when uninstalling a plugin.
     */
    abstract public function uninstall(): void;

    /**
     * Code to load every time the plugin is enabled or updated.
     */
    abstract public function update(): void;

    /**
     * @param mixed $extension
     *
     * @return bool
     */
    protected function loadExtension($extension): bool
    {
        // Extension loading is complex and depends on Dinamic architecture.
        // For now, we provide a stub for compilation compatibility.
        return false;
    }
}
