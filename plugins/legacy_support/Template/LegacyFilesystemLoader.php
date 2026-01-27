<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FacturaScripts\Plugins\legacy_support\Template;

use Twig\Loader\FilesystemLoader;
use Twig\Source;

/**
 * Custom FilesystemLoader that:
 * 1. Translates legacy RainTPL templates (.html) to Twig syntax
 * 2. Falls back from .html to .html.twig when the .html file doesn't exist
 */
class LegacyFilesystemLoader extends FilesystemLoader
{
    private FilesystemLoader $innerLoader;

    public function __construct(FilesystemLoader $innerLoader)
    {
        $this->innerLoader = $innerLoader;
        // Initialize parent with same paths
        parent::__construct($innerLoader->getPaths());

        // Copy all namespaced paths
        foreach ($innerLoader->getNamespaces() as $namespace) {
            if ($namespace !== FilesystemLoader::MAIN_NAMESPACE) {
                foreach ($innerLoader->getPaths($namespace) as $path) {
                    $this->addPath($path, $namespace);
                }
            }
        }
    }

    /**
     * Resolve template name:
     * 1. If .html requested and missing -> try .html.twig
     * 2. If .html.twig requested and missing -> try .html (Reverse Fallback)
     */
    private function resolveTemplateName(string $name): string
    {
        // 1. HTML -> Twig Fallback
        if (str_ends_with($name, '.html') && !str_ends_with($name, '.html.twig')) {
            if (!$this->innerLoader->exists($name)) {
                $twigName = $name . '.twig';
                if ($this->innerLoader->exists($twigName)) {
                    return $twigName;
                }
            }
        }

        // 2. Twig -> HTML Fallback (Core cleanup support)
        if (str_ends_with($name, '.html.twig')) {
            if (!$this->innerLoader->exists($name)) {
                $htmlName = substr($name, 0, -5); // remove .twig
                if ($this->innerLoader->exists($htmlName)) {
                    return $htmlName;
                }

                // Fallback for root views (some environments fail innerLoader check)
                if (defined('FS_FOLDER') && file_exists(FS_FOLDER . '/view/' . $htmlName)) {
                    return $htmlName;
                }
            }
        }

        return $name;
    }

    public function getSourceContext(string $name): Source
    {
        $resolvedName = $this->resolveTemplateName($name);

        try {
            $context = $this->innerLoader->getSourceContext($resolvedName);
        } catch (\Twig\Error\LoaderError $e) {
            // Fail-safe for root views if inner loader fails
            if (str_ends_with($resolvedName, '.html') && defined('FS_FOLDER')) {
                $path = FS_FOLDER . '/view/' . $resolvedName;
                if (file_exists($path)) {
                    return new Source(
                        RainToTwig::translate(file_get_contents($path)),
                        $resolvedName,
                        $path
                    );
                }
            }
            throw $e;
        }

        // Only translate legacy .html files (RainTPL syntax)
        // Native .html.twig files are used as-is
        if (str_ends_with($resolvedName, '.html') && !str_ends_with($resolvedName, '.html.twig')) {
            return new Source(
                RainToTwig::translate($context->getCode()),
                $context->getName(),
                $context->getPath()
            );
        }

        return $context;
    }

    public function getCacheKey(string $name): string
    {
        $resolvedName = $this->resolveTemplateName($name);

        // Fail-safe for root views
        if (str_ends_with($resolvedName, '.html') && defined('FS_FOLDER')) {
            $path = FS_FOLDER . '/view/' . $resolvedName;
            if (file_exists($path)) {
                return 'legacy_root_' . $path;
            }
        }

        // Prefix cache key with 'legacy_' for translated templates
        if (str_ends_with($resolvedName, '.html') && !str_ends_with($resolvedName, '.html.twig')) {
            return 'legacy_' . $this->innerLoader->getCacheKey($resolvedName);
        }
        return $this->innerLoader->getCacheKey($resolvedName);
    }

    public function isFresh(string $name, int $time): bool
    {
        $resolvedName = $this->resolveTemplateName($name);

        // Fail-safe for root views
        if (str_ends_with($resolvedName, '.html') && defined('FS_FOLDER')) {
            $path = FS_FOLDER . '/view/' . $resolvedName;
            if (file_exists($path)) {
                return filemtime($path) <= $time;
            }
        }

        return $this->innerLoader->isFresh($resolvedName, $time);
    }

    public function exists(string $name): bool
    {
        // First check if the exact file exists
        if ($this->innerLoader->exists($name)) {
            return true;
        }

        // If it's a .html file, try .html.twig fallback
        if (str_ends_with($name, '.html') && !str_ends_with($name, '.html.twig')) {
            return $this->innerLoader->exists($name . '.twig');
        }

        // If it's a .html.twig file, try .html fallback (Core request)
        if (str_ends_with($name, '.html.twig')) {
            $htmlName = substr($name, 0, -5);
            if ($this->innerLoader->exists($htmlName)) {
                return true;
            }
            // Fail-safe for root views
            return defined('FS_FOLDER') && file_exists(FS_FOLDER . '/view/' . $htmlName);
        }

        return false;
    }
}

