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

use FSFramework\Cache\CacheManager;

/**
 * Cache estático para FSFramework
 * 
 * Facade estática sobre CacheManager (Symfony Cache).
 * Proporciona una API simple y estática para operaciones de caché comunes.
 * 
 * Uso:
 *   Cache::set('key', $value, 300);
 *   $value = Cache::get('key', 'default');
 *   Cache::delete('key');
 *   Cache::clear();
 * 
 * Para operaciones avanzadas, usar CacheManager directamente:
 *   $cache = CacheManager::getInstance();
 *   $value = $cache->get('key', fn() => expensiveOperation());
 * 
 * @see \FSFramework\Cache\CacheManager
 */
class Cache
{
    /**
     * Obtiene un valor de caché.
     * 
     * @param string $key Clave
     * @param mixed $default Valor por defecto
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return CacheManager::getInstance()->getItem($key, $default);
    }

    /**
     * Guarda un valor en caché.
     * 
     * @param string $key Clave
     * @param mixed $value Valor
     * @param int $ttl TTL en segundos (0 = default)
     * @return bool
     */
    public static function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $ttl = $ttl > 0 ? $ttl : null;
        return CacheManager::getInstance()->set($key, $value, $ttl);
    }

    /**
     * Elimina un valor de caché.
     * 
     * @param string $key Clave
     * @return bool
     */
    public static function delete(string $key): bool
    {
        return CacheManager::getInstance()->delete($key);
    }

    /**
     * Verifica si existe una clave en caché.
     * 
     * @param string $key Clave
     * @return bool
     */
    public static function has(string $key): bool
    {
        return CacheManager::getInstance()->has($key);
    }

    /**
     * Limpia toda la caché.
     * 
     * @return bool
     */
    public static function clear(): bool
    {
        return CacheManager::getInstance()->clear();
    }

    /**
     * Limpia todas las cachés del sistema.
     * 
     * @return array Resultados por tipo de caché
     */
    public static function clearAll(): array
    {
        return CacheManager::getInstance()->clearAll();
    }

    /**
     * Obtiene un valor con callback para regenerar si no existe.
     * 
     * @param string $key Clave
     * @param callable $callback Función que genera el valor
     * @param int|null $ttl TTL en segundos
     * @return mixed
     */
    public static function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        return CacheManager::getInstance()->get($key, $callback, $ttl);
    }

    /**
     * Obtiene información del sistema de caché.
     * 
     * @return array
     */
    public static function info(): array
    {
        return CacheManager::getInstance()->getInfo();
    }
}
