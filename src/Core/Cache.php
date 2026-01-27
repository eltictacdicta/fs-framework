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
 * Cache Manager for FSFramework
 * Wrapper around fs_cache model.
 */
class Cache
{
    /** @var \fs_cache|null */
    private static ?\fs_cache $instance = null;

    /**
     * Get the cache instance.
     * @return \fs_cache
     */
    protected static function getInstance(): \fs_cache
    {
        if (self::$instance === null) {
            self::$instance = new \fs_cache();
        }
        return self::$instance;
    }

    /**
     * Clear all cached data.
     * @return void
     */
    public static function clear(): void
    {
        self::getInstance()->clean();
    }

    /**
     * Get a cached value.
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $value = self::getInstance()->get($key);
        return $value !== null ? $value : $default;
    }

    /**
     * Set a cached value.
     * @param string $key
     * @param mixed $value
     * @param int $ttl Time to live in seconds (fs_cache doesn't support TTL yet, but interface does)
     * @return void
     */
    public static function set(string $key, $value, int $ttl = 0): void
    {
        self::getInstance()->set($key, $value);
    }

    /**
     * Delete a cached value.
     * @param string $key
     * @return void
     */
    public static function delete(string $key): void
    {
        self::getInstance()->delete($key);
    }

    /**
     * Check if a key exists in cache.
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return self::getInstance()->get($key) !== null;
    }
}
