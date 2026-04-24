<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2018 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/**
 * Description of fs_file_manager
 *
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class fs_file_manager
{
    private static ?Filesystem $filesystem = null;

    private static function getFilesystem(): Filesystem
    {
        if (self::$filesystem === null) {
            self::$filesystem = new Filesystem();
        }
        return self::$filesystem;
    }

    /**
     * Check and copy .htaccess files
     */
    public static function check_htaccess()
    {
        if (!file_exists(FS_FOLDER . '/.htaccess')) {
            $txt = file_get_contents(FS_FOLDER . '/htaccess-sample');
            file_put_contents(FS_FOLDER . '/.htaccess', $txt);
        }

        /// ahora comprobamos el de tmp/XXXXX/private_keys
        if (file_exists(FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'private_keys') && !file_exists(FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'private_keys/.htaccess')) {
            file_put_contents(FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'private_keys/.htaccess', 'Deny from all');
        }
    }

    /**
     * Clear all RainTPL cache files.
     */
    public static function clear_raintpl_cache()
    {
        $baseDir = FS_FOLDER . '/tmp/' . FS_TMP_NAME;
        foreach (self::scan_files(FS_FOLDER . '/tmp/' . FS_TMP_NAME, 'php') as $file_name) {
            static::safe_unlink($baseDir . $file_name, $baseDir);
        }
    }

    /**
     * Clear Twig template cache files.
     */
    public static function clear_twig_cache()
    {
        $twigCacheDir = FS_FOLDER . '/tmp/twig_cache';
        if (file_exists($twigCacheDir) && is_dir($twigCacheDir)) {
            self::del_tree($twigCacheDir);
            @mkdir($twigCacheDir, 0755, true);
        }
    }

    /**
     * Clear all template caches (RainTPL + Twig).
     */
    public static function clear_all_template_cache()
    {
        self::clear_raintpl_cache();
        self::clear_twig_cache();
    }

    /**
     * Recursive delete directory.
     *
     * @param string $folder
     *
     * @return bool
     */
    public static function del_tree($folder)
    {
        $folder = static::normalize_path($folder);
        if (!static::is_removal_target_safe($folder)) {
            return false;
        }

        if (!file_exists($folder)) {
            return true;
        }

        $files = is_dir($folder) ? static::scan_folder($folder) : [];
        foreach ($files as $file) {
            $path = static::normalize_path($folder . DIRECTORY_SEPARATOR . $file);
            if (!static::is_path_within_base($path, $folder)) {
                continue;
            }

            is_dir($path) ? static::del_tree($path) : static::safe_unlink($path, $folder);
        }

        return is_dir($folder) ? rmdir($folder) : unlink($folder);
    }

    /**
     * Returns an array with all not writable folders.
     *
     * @return array
     */
    public static function not_writable_folders()
    {
        $notwritable = [];
        foreach (static::scan_folder(FS_FOLDER, true) as $folder) {
            if (is_dir($folder) && !is_writable($folder)) {
                $notwritable[] = $folder;
            }
        }

        return $notwritable;
    }

    /**
     * Copy all files and folders from $src to $dst
     *
     * @param string $src
     * @param string $dst
     * @param string|null $allowedBaseDir If set, validates dst is within this directory
     * 
     * @return bool
     */
    public static function recurse_copy($src, $dst, ?string $allowedBaseDir = null)
    {
        if ($allowedBaseDir !== null) {
            $realBase = realpath($allowedBaseDir);
            if ($realBase === false) {
                return false;
            }

            $realBase = static::normalize_absolute_path($realBase);
            $resolvedDst = static::resolve_path_for_base_check($dst);
            $dstParent = $resolvedDst !== null ? static::normalize_absolute_path(dirname($resolvedDst)) : null;
            $safeDstForLog = $resolvedDst ?? static::normalize_path($dst);

            if ($realBase === null || $dstParent === null || !static::is_base_path_allowed($dstParent, $realBase)) {
                error_log("SECURITY: Attempted copy outside allowed base: $safeDstForLog");
                return false;
            }
        }

        $folder = opendir($src);
        if ($folder === false) {
            return false;
        }

        if (!file_exists($dst) && !@mkdir($dst, 0755)) {
            closedir($folder);
            return false;
        }

        while (false !== ($file = readdir($folder))) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcPath = $src . DIRECTORY_SEPARATOR . $file;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $file;

            if (!static::is_symlink_safe($srcPath, $src)) {
                continue;
            }

            if (is_dir($srcPath)) {
                static::recurse_copy($srcPath, $dstPath, $allowedBaseDir);
            } else {
                copy($srcPath, $dstPath);
            }
        }

        closedir($folder);
        return true;
    }

    /**
     * 
     * @param string $folder
     * @param string $extension
     *
     * @return array
     */
    public static function scan_files($folder, $extension)
    {
        $files = [];
        $len = 1 + strlen($extension);
        foreach (self::scan_folder($folder) as $file_name) {
            if (substr($file_name, 0 - $len) === '.' . $extension) {
                $files[] = $file_name;
            }
        }

        return $files;
    }

    /**
     * Returns an array with files and folders inside given $folder
     *
     * @param string $folder
     * @param bool   $recursive
     * @param array  $exclude
     *
     * @return array
     */
    public static function scan_folder($folder, $recursive = false, $exclude = ['.', '..', '.DS_Store', '.well-known'])
    {
        $scan = scandir($folder, SCANDIR_SORT_ASCENDING);
        if (!is_array($scan)) {
            return [];
        }

        $rootFolder = array_diff($scan, $exclude);
        natcasesort($rootFolder);
        if (!$recursive) {
            return $rootFolder;
        }

        $result = [];
        foreach ($rootFolder as $item) {
            $newItem = $folder . DIRECTORY_SEPARATOR . $item;
            if (is_file($newItem)) {
                $result[] = $item;
                continue;
            }
            $result[] = $item;
            foreach (static::scan_folder($newItem, true) as $item2) {
                $result[] = $item . DIRECTORY_SEPARATOR . $item2;
            }
        }

        return $result;
    }

    /**
     * Extracts a ZIP file safely, preventing directory traversal attacks (Zip Slip).
     *
     * @param string $zip_path Absolute path to the ZIP file.
     * @param string $destination Absolute path to the destination directory.
     * @return bool True on success, False on failure or security violation.
     */
    public static function extract_zip_safe($zip_path, $destination)
    {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== TRUE) {
            return false;
        }

        // Security check: Scan for malicious paths before extraction
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // Check for directory traversal attempts or absolute paths
            if (strpos($filename, '../') !== false || strpos($filename, '..\\') !== false || strpos($filename, '/') === 0) {
                if (class_exists('fs_core_log')) {
                    $log = new fs_core_log();
                    $log->new_error("ALERTA DE SEGURIDAD: Archivo malicioso detectado en ZIP: " . $filename);
                }
                $zip->close();
                return false;
            }
        }

        $res = $zip->extractTo($destination);
        $zip->close();
        return $res;
    }

    /**
     * Secure recursive delete using Symfony Filesystem.
     * Provides atomic operations and better error handling.
     *
     * @param string $folder Directory to remove
     * @return bool
     */
    public static function del_tree_safe(string $folder): bool
    {
        $folder = static::normalize_path($folder);
        if (!static::is_removal_target_safe($folder)) {
            return false;
        }

        if (!file_exists($folder)) {
            return true;
        }

        try {
            self::getFilesystem()->remove($folder);
            return true;
        } catch (IOExceptionInterface $e) {
            error_log("fs_file_manager: Error removing directory: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Secure directory mirroring using Symfony Filesystem.
     * Validates destination is within allowed base directory.
     *
     * @param string $src Source directory
     * @param string $dst Destination directory
     * @param string $allowedBase Base directory that dst must be within
     * @return bool
     */
    public static function mirror_safe(string $src, string $dst, string $allowedBase): bool
    {
        $realBase = realpath($allowedBase);
        if ($realBase === false) {
            error_log("fs_file_manager: Invalid allowed base directory: $allowedBase");
            return false;
        }

        $realBase = static::normalize_absolute_path($realBase);
        $resolvedDst = static::resolve_path_for_base_check($dst);
        $dstParent = $resolvedDst !== null ? static::normalize_absolute_path(dirname($resolvedDst)) : null;
        if (
            $realBase === null
            || $resolvedDst === null
            || $dstParent === null
            || !static::is_base_path_allowed($resolvedDst, $realBase)
        ) {
            error_log("SECURITY: Attempted mirror outside allowed base: " . ($resolvedDst ?? static::normalize_path($dst)));
            return false;
        }

        if (!static::is_symlink_safe($src, dirname($src))) {
            error_log("SECURITY: Source contains unsafe symlink: $src");
            return false;
        }

        try {
            self::getFilesystem()->mirror($src, $dst, null, [
                'override' => true,
                'delete' => false,
            ]);
            return true;
        } catch (IOExceptionInterface $e) {
            error_log("fs_file_manager: Error mirroring directory: " . $e->getMessage());
            return false;
        }
    }

    private static function normalize_path($path)
    {
        if (!is_scalar($path)) {
            return '';
        }

        $path = str_replace('\\', '/', (string) $path);
        $path = preg_replace('#/+#', '/', $path);
        return rtrim($path, '/');
    }

    private static function normalize_absolute_path($path)
    {
        $path = static::normalize_path($path);
        if ($path === '') {
            return null;
        }

        $prefix = '';
        if ($path[0] === '/') {
            $prefix = '/';
            $path = ltrim($path, '/');
        } elseif (preg_match('#^[A-Za-z]:/#', $path) === 1) {
            $prefix = substr($path, 0, 2) . '/';
            $path = substr($path, 3);
        } else {
            return null;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if (empty($segments)) {
                    return null;
                }

                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        if (empty($segments)) {
            return rtrim($prefix, '/');
        }

        return $prefix . implode('/', $segments);
    }

    private static function resolve_path_for_base_check($path)
    {
        $candidate = static::normalize_absolute_path($path);
        if ($candidate === null) {
            return null;
        }

        $suffix = [];
        $probe = $candidate;

        while (true) {
            $resolved = realpath($probe);
            if ($resolved !== false) {
                $resolved = static::normalize_absolute_path($resolved);
                if ($resolved === null) {
                    return null;
                }

                if (empty($suffix)) {
                    return $resolved;
                }

                return static::normalize_absolute_path($resolved . '/' . implode('/', array_reverse($suffix)));
            }

            $parent = dirname($probe);
            if ($parent === $probe) {
                return null;
            }

            $suffix[] = basename($probe);
            $probe = $parent;
        }
    }

    private static function is_base_path_allowed($path, $base)
    {
        $path = static::normalize_absolute_path($path);
        $base = static::normalize_absolute_path($base);
        if ($path === null || $base === null) {
            return false;
        }

        return strpos($path . '/', $base . '/') === 0 || $path === $base;
    }

    private static function is_path_within_base($path, $base)
    {
        $path = static::normalize_path($path);
        $base = rtrim(static::normalize_path($base), '/') . '/';
        return $path !== '' && strpos($path . '/', $base) === 0;
    }

    private static function is_removal_target_safe($path)
    {
        return $path !== '' && $path !== '/' && !preg_match('#^[A-Za-z]:$#', $path);
    }

    /**
     * Check if a path is a symlink pointing outside its base directory.
     * Prevents symlink escape attacks.
     *
     * @param string $path Path to check
     * @param string $base Base directory the path should be within
     * @return bool True if safe (not a symlink or points within base), false otherwise
     */
    private static function is_symlink_safe($path, $base): bool
    {
        if (!is_link($path)) {
            return true;
        }

        $realPath = realpath($path);
        $realBase = realpath($base);

        if ($realPath === false || $realBase === false) {
            return false;
        }

        return strpos($realPath . '/', $realBase . '/') === 0;
    }

    private static function safe_unlink($path, $base)
    {
        if (!static::is_path_within_base($path, $base) || !is_file($path)) {
            return false;
        }

        if (!static::is_symlink_safe($path, $base)) {
            error_log("SECURITY: Attempted to unlink unsafe symlink: $path");
            return false;
        }

        return @unlink($path);
    }
}
