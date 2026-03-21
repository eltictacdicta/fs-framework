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

namespace FSFramework\Plugins\legacy_support\Template;

use FSFramework\Plugins\legacy_support\LegacyUsageTracker;
use Twig\Loader\FilesystemLoader;
use Twig\Source;

/**
 * Custom FilesystemLoader that:
 * 1. Translates legacy RainTPL templates (.html) to Twig syntax
 * 2. Falls back from .html to .html.twig when the .html file doesn't exist
 * 3. Maps legacy template aliases to current templates (e.g., footer2 -> footer)
 *
 * @deprecated Será retirado en v3.0. Migrar plantillas legacy .html a .html.twig nativas.
 */
class LegacyFilesystemLoader extends FilesystemLoader
{
    private FilesystemLoader $innerLoader;

    /**
     * Map of legacy template names to their current equivalents.
     * This provides backward compatibility when templates are renamed or consolidated.
     * Format: 'legacy_name' => 'current_name' (without extension)
     */
    private const EXT_HTML = '.html';
    private const EXT_TWIG = '.html.twig';
    private const VIEW_PATH = '/view/';

    private const TEMPLATE_ALIASES = [
        'footer2' => 'footer',
        'footer2.html' => 'footer.html.twig',
    ];

    public function __construct(FilesystemLoader $innerLoader)
    {
        LegacyUsageTracker::incrementLegacyComponent('legacy.template_loader', '__construct');
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
     * Check if the template name has a legacy alias and return the mapped name.
     * 
     * @param string $name Original template name
     * @return string Mapped template name or original if no alias exists
     */
    private function resolveTemplateAlias(string $name): string
    {
        // Direct match in aliases
        if (isset(self::TEMPLATE_ALIASES[$name])) {
            return self::TEMPLATE_ALIASES[$name];
        }

        // Check without extension for .html files
        if (str_ends_with($name, self::EXT_HTML) && !str_ends_with($name, self::EXT_TWIG)) {
            $baseName = substr($name, 0, -5); // Remove .html
            if (isset(self::TEMPLATE_ALIASES[$baseName])) {
                return self::TEMPLATE_ALIASES[$baseName] . self::EXT_TWIG;
            }
        }

        return $name;
    }

    /**
     * Resolve template name:
     * 0. Apply legacy template aliases first
     * 1. If .html requested and missing -> try .html.twig
     * 2. If .html.twig requested and missing -> try .html (Reverse Fallback)
     */
    private function resolveTemplateName(string $name): string
    {
        $name = $this->resolveTemplateAlias($name);

        if ($this->isLegacyHtml($name)) {
            return $this->resolveHtmlToTwig($name);
        }

        if (str_ends_with($name, self::EXT_TWIG)) {
            return $this->resolveTwigToHtml($name);
        }

        return $name;
    }

    private function isLegacyHtml(string $name): bool
    {
        return str_ends_with($name, self::EXT_HTML) && !str_ends_with($name, self::EXT_TWIG);
    }

    private function resolveHtmlToTwig(string $name): string
    {
        if ($this->innerLoader->exists($name)) {
            return $name;
        }

        $twigName = $name . '.twig';
        return $this->innerLoader->exists($twigName) ? $twigName : $name;
    }

    private function resolveTwigToHtml(string $name): string
    {
        if ($this->innerLoader->exists($name)) {
            return $name;
        }

        $htmlName = substr($name, 0, -5);

        if ($this->innerLoader->exists($htmlName)) {
            return $htmlName;
        }

        if (defined('FS_FOLDER') && file_exists(FS_FOLDER . self::VIEW_PATH . $htmlName)) {
            return $htmlName;
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
            if (str_ends_with($resolvedName, self::EXT_HTML) && defined('FS_FOLDER')) {
                $path = FS_FOLDER . self::VIEW_PATH . $resolvedName;
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
        if (str_ends_with($resolvedName, self::EXT_HTML) && !str_ends_with($resolvedName, self::EXT_TWIG)) {
            LegacyUsageTracker::incrementLegacyComponent('legacy.template_translation', $resolvedName);
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
        if (str_ends_with($resolvedName, self::EXT_HTML) && defined('FS_FOLDER')) {
            $path = FS_FOLDER . self::VIEW_PATH . $resolvedName;
            if (file_exists($path)) {
                return 'legacy_root_' . $path;
            }
        }

        // Prefix cache key with 'legacy_' for translated templates
        if (str_ends_with($resolvedName, self::EXT_HTML) && !str_ends_with($resolvedName, self::EXT_TWIG)) {
            LegacyUsageTracker::incrementLegacyComponent('legacy.template_translation', $resolvedName);
            return 'legacy_' . $this->innerLoader->getCacheKey($resolvedName);
        }
        return $this->innerLoader->getCacheKey($resolvedName);
    }

    public function isFresh(string $name, int $time): bool
    {
        $resolvedName = $this->resolveTemplateName($name);

        // Fail-safe for root views
        if (str_ends_with($resolvedName, self::EXT_HTML) && defined('FS_FOLDER')) {
            $path = FS_FOLDER . self::VIEW_PATH . $resolvedName;
            if (file_exists($path)) {
                return filemtime($path) <= $time;
            }
        }

        return $this->innerLoader->isFresh($resolvedName, $time);
    }

    public function exists(string $name): bool
    {
        // Apply legacy template aliases first
        $resolvedName = $this->resolveTemplateAlias($name);

        // First check if the exact file exists
        if ($this->innerLoader->exists($resolvedName)) {
            return true;
        }

        // If it's a .html file, try .html.twig fallback
        if (str_ends_with($resolvedName, self::EXT_HTML) && !str_ends_with($resolvedName, self::EXT_TWIG)) {
            LegacyUsageTracker::incrementLegacyComponent('legacy.template_translation', $resolvedName);
            return $this->innerLoader->exists($resolvedName . '.twig');
        }

        // If it's a .html.twig file, try .html fallback (Core request)
        if (str_ends_with($resolvedName, self::EXT_TWIG)) {
            $htmlName = substr($resolvedName, 0, -5);
            if ($this->innerLoader->exists($htmlName)) {
                return true;
            }
            // Fail-safe for root views
            return defined('FS_FOLDER') && file_exists(FS_FOLDER . self::VIEW_PATH . $htmlName);
        }

        return false;
    }
}

