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

namespace FSFramework\Plugins\legacy_support;

/**
 * Persiste y resume el registro de uso legacy (rutas y componentes).
 */
final class LegacyUsageTracker
{
    private const STORAGE_FILE = '/tmp/legacy_usage.json';
    private const LEGACY_STORAGE_FILE = '/tmp/legacy_telemetry.json';

    public static function incrementLegacyRoute(string $endpoint, string $routeType): void
    {
        self::increment('routes', self::normalizeKey($routeType . ':' . $endpoint));
    }

    public static function incrementLegacyComponent(string $component, string $context): void
    {
        self::increment('components', self::normalizeKey($component . ':' . $context));
    }

    public static function reset(): bool
    {
        $result = self::writeData([]);
        $legacyPath = self::getLegacyStoragePath();
        if (file_exists($legacyPath)) {
            @unlink($legacyPath);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getSummary(int $limit = 8): array
    {
        $data = self::readData();

        $routes = self::sortCounters($data['routes'] ?? []);
        $components = self::sortCounters($data['components'] ?? []);

        return [
            'updated_at' => $data['updated_at'] ?? null,
            'totals' => [
                'route_hits' => array_sum($routes),
                'component_hits' => array_sum($components),
                'unique_routes' => count($routes),
                'unique_components' => count($components),
            ],
            'top_routes' => array_slice($routes, 0, $limit, true),
            'top_components' => array_slice($components, 0, $limit, true),
        ];
    }

    private static function increment(string $section, string $key): void
    {
        $data = self::readData();
        $data['routes'] ??= [];
        $data['components'] ??= [];
        $data[$section][$key] = (int) ($data[$section][$key] ?? 0) + 1;
        $data['updated_at'] = date(DATE_ATOM);

        self::writeData($data);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readData(): array
    {
        $path = self::resolveReadableStoragePath();
        if ($path === '' || !file_exists($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function writeData(array $data): bool
    {
        $path = self::getStoragePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return false;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return false;
            }

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            fflush($fp);

            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @param array<string, int> $counters
     * @return array<string, int>
     */
    private static function sortCounters(array $counters): array
    {
        arsort($counters);
        return $counters;
    }

    private static function normalizeKey(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?: 'unknown';
    }

    private static function resolveReadableStoragePath(): string
    {
        $path = self::getStoragePath();
        if (file_exists($path)) {
            return $path;
        }

        $legacyPath = self::getLegacyStoragePath();
        return file_exists($legacyPath) ? $legacyPath : $path;
    }

    private static function getStoragePath(): string
    {
        return defined('FS_FOLDER')
            ? FS_FOLDER . self::STORAGE_FILE
            : dirname(__DIR__, 2) . self::STORAGE_FILE;
    }

    private static function getLegacyStoragePath(): string
    {
        return defined('FS_FOLDER')
            ? FS_FOLDER . self::LEGACY_STORAGE_FILE
            : dirname(__DIR__, 2) . self::LEGACY_STORAGE_FILE;
    }
}