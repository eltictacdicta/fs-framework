<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com> (lead developer of Facturascript)
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

/**
 * Capa legacy de caché (retrocompatible) unificada sobre Symfony CacheManager.
 *
 * Mantiene la API histórica de fs_cache para evitar romper código legacy,
 * pero internamente usa exclusivamente la caché moderna del sistema.
 *
 * @author Carlos García Gómez <neorazorx@gmail.com>
 */
class fs_cache
{
    /**
     * @var \FSFramework\Cache\CacheManager|null
     */
    private static $cache_manager = null;

    /**
     * @var bool
     */
    private static $connected = false;

    /**
     * @var bool
     */
    private static $error = false;

    /**
     * @var string
     */
    private static $error_msg = '';

    public function __construct()
    {
        if (self::$cache_manager !== null) {
            return;
        }

        try {
            self::$cache_manager = \FSFramework\Cache\CacheManager::getInstance();
            $info = self::$cache_manager->getInfo();
            self::$connected = !empty($info['memcached_available']);
            self::$error = false;
            self::$error_msg = '';
        } catch (\Throwable $e) {
            self::$cache_manager = null;
            self::$connected = false;
            self::$error = true;
            self::$error_msg = 'No se pudo inicializar Symfony CacheManager: ' . $e->getMessage();
        }
    }

    public function error()
    {
        return self::$error;
    }

    public function error_msg()
    {
        return self::$error_msg;
    }

    public function close()
    {
        // No aplica al backend Symfony/PSR-6. Se mantiene por compatibilidad.
        return true;
    }

    public function set($key, $object, $expire = 5400)
    {
        if (self::$cache_manager === null) {
            return false;
        }

        return self::$cache_manager->set((string) $key, $object, (int) $expire);
    }

    public function get($key)
    {
        if (self::$cache_manager === null) {
            return null;
        }

        return self::$cache_manager->getItem((string) $key);
    }

    /**
     * Devuelve un array almacenado en cache
     * @param string $key
     * @return array
     */
    public function get_array($key)
    {
        $value = $this->get($key);
        return is_array($value) ? $value : [];
    }

    /**
     * Devuelve un array almacenado en cache, tal y como get_[], pero con la direfencia
     * de que si no se encuentra en cache, se pone $error a true.
     * @param string $key
     * @param boolean $error
     * @return array
     */
    public function get_array2($key, &$error)
    {
        $value = $this->get($key);
        if (is_array($value)) {
            $error = false;
            return $value;
        }

        $error = true;
        return [];
    }

    public function delete($key)
    {
        if (self::$cache_manager === null) {
            return false;
        }

        return self::$cache_manager->delete((string) $key);
    }

    public function delete_multi($keys)
    {
        if (self::$cache_manager === null) {
            return false;
        }

        $normalized = [];
        foreach ((array) $keys as $value) {
            $normalized[] = (string) $value;
        }

        return self::$cache_manager->deleteMultiple($normalized);
    }

    public function clean()
    {
        if (self::$cache_manager === null) {
            return false;
        }

        return self::$cache_manager->clear();
    }

    public function version()
    {
        if (self::$cache_manager === null) {
            return 'Symfony Cache (unavailable)';
        }

        return self::$cache_manager->version();
    }

    public function connected()
    {
        // Semántica legacy: "connected" se refiere a backend de red tipo memcache.
        return self::$connected;
    }
}
