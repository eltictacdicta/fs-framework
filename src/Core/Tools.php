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
 * Tools and Utilities Class for FSFramework.
 * Provides file system, configuration, and logging helpers.
 */
class Tools
{
    /**
     * Get the logger instance for logging messages.
     * @return object
     */
    public static function log()
    {
        return new class {
            public function error($msg, $params = [])
            {
                $message = self::interpolate($msg, $params);
                error_log('FS Error: ' . $message);
                if (class_exists('fs_core_log')) {
                    $log = new \fs_core_log('Tools');
                    $log->new_error($message);
                }
            }

            public function warning($msg, $params = [])
            {
                $message = self::interpolate($msg, $params);
                error_log('FS Warning: ' . $message);
                if (class_exists('fs_core_log')) {
                    $log = new \fs_core_log('Tools');
                    $log->new_advice($message);
                }
            }

            public function notice($msg, $params = [])
            {
                $message = self::interpolate($msg, $params);
                error_log('FS Notice: ' . $message);
                if (class_exists('fs_core_log')) {
                    $log = new \fs_core_log('Tools');
                    $log->new_message($message);
                }
            }

            private static function interpolate($msg, $params)
            {
                if (empty($params)) {
                    return $msg;
                }
                $replacements = [];
                foreach ($params as $key => $val) {
                    // Ensure value is a string for strtr
                    $replacements[$key] = is_scalar($val) || (is_object($val) && method_exists($val, '__toString')) 
                        ? (string) $val 
                        : '';
                }
                return strtr($msg, $replacements);
            }
        };
    }

    /**
     * Get configuration value.
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function config($key, $default = null)
    {
        // Map to legacy constants or DB config
        $configMap = [
            'db_type' => 'FS_DB_TYPE',
            'db_port' => 'FS_DB_PORT',
            'db_name' => 'FS_DB_NAME',
            'db_user' => 'FS_DB_USER',
            'db_pass' => 'FS_DB_PASS',
            'db_host' => 'FS_DB_HOST',
            'mysql_charset' => 'FS_MYSQL_CHARSET',
            'mysql_collate' => 'FS_MYSQL_COLLATE',
            'route' => 'FS_PATH',
        ];

        // Default values for configuration
        $defaults = [
            'db_type' => 'mysql',
            'db_port' => 3306,
            'db_host' => 'localhost',
            'mysql_charset' => 'utf8',
            'mysql_collate' => 'utf8_bin',
            'route' => '/',
        ];

        if (isset($configMap[$key])) {
            $constant = $configMap[$key];
            if (!defined($constant)) {
                return $default ?? ($defaults[$key] ?? null);
            }

            $value = constant($constant);

            // db_type must be lowercase for FS2025 compatibility
            if ($key === 'db_type') {
                return strtolower($value);
            }

            return $value;
        }

        return $default;
    }

    /**
     * Build a path from FS_FOLDER and path components.
     * @param string ...$paths Path components to join
     * @return string Full path
     */
    public static function folder(...$paths)
    {
        $fullPath = FS_FOLDER;
        foreach ($paths as $path) {
            if (!empty($path)) {
                $fullPath .= '/' . $path;
            }
        }
        return $fullPath;
    }

    /**
     * Check if folder exists, create if not.
     * @param string $path
     * @return bool
     */
    public static function folderCheckOrCreate($path)
    {
        if (!file_exists($path)) {
            return mkdir($path, 0777, true);
        }
        return is_dir($path);
    }

    /**
     * Scan a folder and return list of files/directories.
     * @param string $path
     * @param bool $recursive
     * @return array
     */
    public static function folderScan($path, $recursive = false)
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $items = scandir($path);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if ($recursive && is_dir($path . '/' . $item)) {
                $subFiles = self::folderScan($path . '/' . $item, true);
                foreach ($subFiles as $subFile) {
                    $files[] = $item . '/' . $subFile;
                }
            }

            $files[] = $item;
        }

        return $files;
    }

    /**
     * Delete a folder and all its contents recursively.
     * @param string $path
     * @return bool
     */
    public static function folderDelete($path)
    {
        if (!file_exists($path)) {
            return true;
        }

        if (!is_dir($path)) {
            return unlink($path);
        }

        foreach (scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if (!self::folderDelete($path . '/' . $item)) {
                return false;
            }
        }

        return rmdir($path);
    }

    /**
     * Copy a folder and all its contents recursively.
     * @param string $source
     * @param string $dest
     * @return bool
     */
    public static function folderCopy($source, $dest)
    {
        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($dest)) {
            if (!mkdir($dest, 0777, true)) {
                return false;
            }
        }

        $dir = opendir($source);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcPath = $source . '/' . $file;
            $destPath = $dest . '/' . $file;

            if (is_dir($srcPath)) {
                if (!self::folderCopy($srcPath, $destPath)) {
                    closedir($dir);
                    return false;
                }
            } else {
                if (!copy($srcPath, $destPath)) {
                    closedir($dir);
                    return false;
                }
            }
        }

        closedir($dir);
        return true;
    }

    /**
     * Get the current date in a specific format.
     * @param string $format
     * @return string
     */
    public static function date($format = 'Y-m-d')
    {
        return date($format);
    }

    /**
     * Get the current datetime in a specific format.
     * @param string $format
     * @return string
     */
    public static function dateTime($format = 'Y-m-d H:i:s')
    {
        return date($format);
    }

    /**
     * Format money value.
     * @param float $amount
     * @param string $decimals
     * @return string
     */
    public static function money($amount, $decimals = 2)
    {
        $nf0 = defined('FS_NF0') ? FS_NF0 : 2;
        $nf1 = defined('FS_NF1') ? FS_NF1 : ',';
        $nf2 = defined('FS_NF2') ? FS_NF2 : '.';

        return number_format($amount, $decimals ?: $nf0, $nf1, $nf2);
    }

    /**
     * Format a number.
     * @param float $number
     * @param int $decimals
     * @return string
     */
    public static function number($number, $decimals = null)
    {
        $nf0 = defined('FS_NF0') ? FS_NF0 : 2;
        $nf1 = defined('FS_NF1') ? FS_NF1 : ',';
        $nf2 = defined('FS_NF2') ? FS_NF2 : '.';

        return number_format($number, $decimals ?? $nf0, $nf1, $nf2);
    }
}
