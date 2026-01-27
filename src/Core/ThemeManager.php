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

namespace FSFramework\Core;

/**
 * Theme Manager
 * Manages theme activation and discovery for FSFramework.
 */
class ThemeManager
{
    private const THEMES_DIR = 'themes';
    private const CONFIG_KEY = 'default_theme';
    private const DEFAULT_THEME = 'AdminLTE';

    private static ?ThemeManager $instance = null;
    private ?string $activeTheme = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the currently active theme name.
     */
    public function getActiveTheme(): string
    {
        if ($this->activeTheme === null) {
            $this->activeTheme = $this->loadActiveTheme();
        }
        return $this->activeTheme;
    }

    /**
     * Get list of available themes.
     * 
     * @return array Array of theme info arrays with name, version, description
     */
    public function getAvailableThemes(): array
    {
        $themes = [];
        $themesPath = FS_FOLDER . '/' . self::THEMES_DIR;

        if (!is_dir($themesPath)) {
            return $themes;
        }

        foreach (scandir($themesPath) as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $themeIni = $themesPath . '/' . $dir . '/theme.ini';
            if (is_file($themeIni)) {
                $info = parse_ini_file($themeIni);
                if ($info) {
                    $themes[$dir] = [
                        'name' => $info['name'] ?? $dir,
                        'version' => $info['version'] ?? '1.0',
                        'description' => $info['description'] ?? '',
                        'author' => $info['author'] ?? '',
                        'active' => ($dir === $this->getActiveTheme()),
                        'path' => $themesPath . '/' . $dir,
                    ];
                }
            }
        }

        return $themes;
    }

    /**
     * Activate a theme.
     * 
     * @param string $themeName Theme directory name
     * @return bool Success
     */
    public function activateTheme(string $themeName): bool
    {
        $themePath = FS_FOLDER . '/' . self::THEMES_DIR . '/' . $themeName;

        if (!is_dir($themePath) || !is_file($themePath . '/theme.ini')) {
            return false;
        }

        // Save to fs_vars table using fs_var model
        $fsVar = new \fs_var();
        if ($fsVar->simple_save(self::CONFIG_KEY, $themeName)) {
            $this->activeTheme = $themeName;
            // Clear Twig cache
            $this->clearTwigCache();
            return true;
        }

        return false;
    }

    /**
     * Get the view path for the active theme.
     */
    public function getThemeViewPath(): ?string
    {
        $theme = $this->getActiveTheme();
        $path = FS_FOLDER . '/' . self::THEMES_DIR . '/' . $theme . '/view';

        return is_dir($path) ? $path : null;
    }

    /**
     * Get the assets path for the active theme.
     */
    public function getThemeAssetsPath(): string
    {
        $theme = $this->getActiveTheme();
        return self::THEMES_DIR . '/' . $theme;
    }

    /**
     * Load active theme from database.
     */
    private function loadActiveTheme(): string
    {
        try {
            $fsVar = new \fs_var();
            $themeName = $fsVar->simple_get(self::CONFIG_KEY);
            if ($themeName !== false && $themeName !== '') {
                // Verify theme exists
                $themePath = FS_FOLDER . '/' . self::THEMES_DIR . '/' . $themeName;
                if (is_dir($themePath)) {
                    return $themeName;
                }
            }
        } catch (\Throwable $e) {
            // Fall back to default
        }

        return self::DEFAULT_THEME;
    }

    /**
     * Clear Twig template cache.
     */
    private function clearTwigCache(): void
    {
        $cacheDir = FS_FOLDER . '/tmp/twig_cache';
        if (is_dir($cacheDir)) {
            $this->deleteDirectory($cacheDir);
        }
    }

    /**
     * Recursively delete a directory.
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
