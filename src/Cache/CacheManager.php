<?php
/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
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

namespace FSFramework\Cache;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Gestor unificado de caché para FSFramework.
 * 
 * Características:
 * - Usa Symfony Cache con adaptadores múltiples
 * - TTL corto por defecto (180s) ideal para sistemas de administración
 * - Soporte legacy (fs_cache, RainTPL)
 * - Limpieza de todas las cachés: aplicación, Twig, templates legacy
 * 
 * Uso:
 *   $cache = CacheManager::getInstance();
 *   
 *   // Obtener/guardar con callback (PSR-6 style)
 *   $value = $cache->get('my_key', function() {
 *       return $this->expensiveCalculation();
 *   });
 *   
 *   // Obtener valor simple
 *   $value = $cache->getItem('my_key');
 *   
 *   // Guardar valor
 *   $cache->set('my_key', $value, 60); // TTL 60 segundos
 *   
 *   // Limpiar todo
 *   $cache->clearAll();
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 */
class CacheManager
{
    private static ?CacheManager $instance = null;
    private CacheItemPoolInterface $cache;
    private ?CacheItemPoolInterface $memcachedCache = null;
    private string $cacheDir;
    private string $twigCacheDir;
    private string $legacyCacheDir;
    
    /**
     * TTL por defecto en segundos.
     * 180s (3 minutos) es ideal para sistemas de administración
     * donde los cambios deben reflejarse rápidamente.
     */
    public const DEFAULT_TTL = 180;
    
    /**
     * TTL corto para datos muy dinámicos (30 segundos).
     */
    public const SHORT_TTL = 30;
    
    /**
     * TTL medio para datos moderadamente estáticos (10 minutos).
     */
    public const MEDIUM_TTL = 600;
    
    /**
     * TTL largo para datos semi-estáticos (1 hora).
     */
    public const LONG_TTL = 3600;

    private function __construct()
    {
        $baseDir = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__, 2);
        $tmpName = defined('FS_TMP_NAME') ? FS_TMP_NAME : '';
        
        $this->cacheDir = $baseDir . '/tmp/' . $tmpName . 'symfony_cache';
        $this->twigCacheDir = $baseDir . '/tmp/twig_cache';
        $this->legacyCacheDir = $baseDir . '/tmp/' . $tmpName;
        
        $this->initializeCache();
    }

    /**
     * Obtiene la instancia singleton del CacheManager.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa los adaptadores de caché.
     * Usa Memcached si está disponible, con filesystem como fallback.
     */
    private function initializeCache(): void
    {
        $adapters = [];
        
        // ArrayAdapter como caché en memoria para la request actual
        $adapters[] = new ArrayAdapter(self::DEFAULT_TTL, false);
        
        // Intentar usar Memcached si está configurado Y realmente disponible
        if ($this->isMemcachedAvailable() && $this->tryConnectMemcached()) {
            // Memcached ya conectado en tryConnectMemcached()
            $adapters[] = $this->memcachedCache;
        }
        
        // Filesystem como caché persistente (siempre disponible)
        $prefix = defined('FS_CACHE_PREFIX') ? FS_CACHE_PREFIX : 'fs_';
        
        // Asegurar que el directorio de caché existe
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0777, true);
        }
        
        $adapters[] = new FilesystemAdapter($prefix, self::DEFAULT_TTL, $this->cacheDir);
        
        // Usar ChainAdapter para combinar todos los niveles de caché
        $this->cache = new ChainAdapter($adapters);
    }

    /**
     * Intenta conectar con Memcached de forma segura.
     * 
     * @return bool True si la conexión fue exitosa
     */
    private function tryConnectMemcached(): bool
    {
        try {
            $host = defined('FS_CACHE_HOST') ? FS_CACHE_HOST : 'localhost';
            $port = defined('FS_CACHE_PORT') ? FS_CACHE_PORT : 11211;
            
            // Primero verificar si el servidor responde con un socket test
            $socket = @fsockopen($host, $port, $errno, $errstr, 1);
            if ($socket === false) {
                // Servidor Memcached no accesible
                return false;
            }
            fclose($socket);
            
            // Ahora crear el adaptador
            $memcachedClient = MemcachedAdapter::createConnection(
                'memcached://' . $host . ':' . $port
            );
            $prefix = defined('FS_CACHE_PREFIX') ? FS_CACHE_PREFIX : 'fs_';
            $this->memcachedCache = new MemcachedAdapter(
                $memcachedClient,
                $prefix,
                self::DEFAULT_TTL
            );
            
            return true;
        } catch (\Exception $e) {
            // Memcached no disponible, continuar sin él (silencioso)
            return false;
        } catch (\Error $e) {
            // Error fatal de PHP, continuar sin Memcached
            return false;
        }
    }

    /**
     * Verifica si Memcached está disponible y configurado.
     */
    private function isMemcachedAvailable(): bool
    {
        return class_exists('Memcached') 
            && defined('FS_CACHE_HOST') 
            && defined('FS_CACHE_PORT');
    }

    /**
     * Obtiene un valor de caché con callback para regenerar si no existe.
     * 
     * @param string $key Clave de caché
     * @param callable $callback Función que genera el valor si no está en caché
     * @param int|null $ttl TTL en segundos (null = DEFAULT_TTL)
     * @return mixed El valor cacheado o generado
     */
    public function get(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? self::DEFAULT_TTL;
        
        $item = $this->cache->getItem($key);
        
        if (!$item->isHit()) {
            $value = $callback();
            $item->set($value);
            $item->expiresAfter($ttl);
            $this->cache->save($item);
            return $value;
        }
        
        return $item->get();
    }

    /**
     * Obtiene un valor de caché directamente.
     * 
     * @param string $key Clave de caché
     * @param mixed $default Valor por defecto si no existe
     * @return mixed El valor o el default
     */
    public function getItem(string $key, mixed $default = null): mixed
    {
        $item = $this->cache->getItem($key);
        return $item->isHit() ? $item->get() : $default;
    }

    /**
     * Guarda un valor en caché.
     * 
     * @param string $key Clave de caché
     * @param mixed $value Valor a guardar
     * @param int|null $ttl TTL en segundos (null = DEFAULT_TTL)
     * @return bool True si se guardó correctamente
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? self::DEFAULT_TTL;
        
        $item = $this->cache->getItem($key);
        $item->set($value);
        $item->expiresAfter($ttl);
        
        return $this->cache->save($item);
    }

    /**
     * Elimina un elemento de la caché.
     * 
     * @param string $key Clave de caché
     * @return bool True si se eliminó correctamente
     */
    public function delete(string $key): bool
    {
        return $this->cache->deleteItem($key);
    }

    /**
     * Elimina múltiples elementos de la caché.
     * 
     * @param array $keys Claves a eliminar
     * @return bool True si se eliminaron correctamente
     */
    public function deleteMultiple(array $keys): bool
    {
        return $this->cache->deleteItems($keys);
    }

    /**
     * Verifica si existe un elemento en caché.
     * 
     * @param string $key Clave de caché
     * @return bool True si existe y no ha expirado
     */
    public function has(string $key): bool
    {
        return $this->cache->getItem($key)->isHit();
    }

    /**
     * Limpia la caché de aplicación (Symfony Cache).
     * 
     * @return bool True si se limpió correctamente
     */
    public function clear(): bool
    {
        return $this->cache->clear();
    }

    /**
     * Limpia la caché de templates Twig.
     * 
     * @return bool True si se limpió correctamente
     */
    public function clearTwigCache(): bool
    {
        return $this->clearDirectory($this->twigCacheDir);
    }

    /**
     * Limpia la caché legacy de RainTPL (archivos .php compilados).
     * 
     * @return bool True si se limpió correctamente
     */
    public function clearLegacyTemplateCache(): bool
    {
        $success = true;
        
        // Limpiar archivos .php de RainTPL en tmp/FS_TMP_NAME/
        foreach ($this->scanFiles($this->legacyCacheDir, 'php') as $file) {
            $filePath = $this->legacyCacheDir . $file;
            if (file_exists($filePath) && !@unlink($filePath)) {
                $success = false;
            }
        }
        
        return $success;
    }

    /**
     * Limpia la caché legacy de php_file_cache.
     * 
     * @return bool True si se limpió correctamente
     */
    public function clearLegacyFileCache(): bool
    {
        $legacyFileCacheDir = $this->legacyCacheDir . 'cache';
        
        if (!file_exists($legacyFileCacheDir)) {
            return true;
        }
        
        return $this->clearDirectory($legacyFileCacheDir, 'php');
    }

    /**
     * Limpia TODAS las cachés del sistema.
     * Incluye: Symfony Cache, Twig, RainTPL, php_file_cache, y Memcached si está disponible.
     * 
     * @return array Resultado de cada limpieza ['symfony' => bool, 'twig' => bool, ...]
     */
    public function clearAll(): array
    {
        $results = [
            'symfony' => $this->clear(),
            'twig' => $this->clearTwigCache(),
            'legacy_templates' => $this->clearLegacyTemplateCache(),
            'legacy_file_cache' => $this->clearLegacyFileCache(),
        ];
        
        // Limpiar también fs_cache legacy si está disponible
        if (class_exists('fs_cache')) {
            try {
                $legacyCache = new \fs_cache();
                $results['legacy_memcache'] = $legacyCache->clean();
            } catch (\Exception $e) {
                $results['legacy_memcache'] = false;
            }
        }
        
        return $results;
    }

    /**
     * Limpia todas las cachés y devuelve true si todas fueron exitosas.
     * 
     * @return bool True si todas las cachés se limpiaron correctamente
     */
    public function clearAllSuccessful(): bool
    {
        $results = $this->clearAll();
        return !in_array(false, $results, true);
    }

    /**
     * Obtiene información sobre el estado de la caché.
     * 
     * @return array Información de la caché
     */
    public function getInfo(): array
    {
        $info = [
            'type' => 'Symfony Cache',
            'adapters' => ['ArrayAdapter', 'FilesystemAdapter'],
            'default_ttl' => self::DEFAULT_TTL . 's',
            'cache_dir' => $this->cacheDir,
            'twig_cache_dir' => $this->twigCacheDir,
            'memcached_available' => $this->memcachedCache !== null,
        ];
        
        if ($this->memcachedCache !== null) {
            $info['adapters'][] = 'MemcachedAdapter';
            $info['memcached_host'] = defined('FS_CACHE_HOST') ? FS_CACHE_HOST : 'N/A';
            $info['memcached_port'] = defined('FS_CACHE_PORT') ? FS_CACHE_PORT : 'N/A';
        }
        
        return $info;
    }

    /**
     * Devuelve una descripción legible del tipo de caché.
     * Compatible con el método cache_version() del sistema legacy.
     * 
     * @return string Descripción del sistema de caché
     */
    public function version(): string
    {
        if ($this->memcachedCache !== null) {
            return 'Symfony Cache + Memcached';
        }
        return 'Symfony Cache (Filesystem)';
    }

    /**
     * Elimina todos los archivos de un directorio.
     * 
     * @param string $directory Directorio a limpiar
     * @param string|null $extension Solo eliminar archivos con esta extensión
     * @return bool True si se limpió correctamente
     */
    private function clearDirectory(string $directory, ?string $extension = null): bool
    {
        if (!file_exists($directory) || !is_dir($directory)) {
            return true;
        }
        
        $success = true;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                if ($extension === null || $file->getExtension() === $extension) {
                    if (!@unlink($file->getPathname())) {
                        $success = false;
                    }
                }
            } elseif ($file->isDir() && $extension === null) {
                @rmdir($file->getPathname());
            }
        }
        
        return $success;
    }

    /**
     * Escanea archivos con una extensión específica en un directorio.
     * 
     * @param string $directory Directorio a escanear
     * @param string $extension Extensión de los archivos
     * @return array Lista de nombres de archivo
     */
    private function scanFiles(string $directory, string $extension): array
    {
        if (!file_exists($directory) || !is_dir($directory)) {
            return [];
        }
        
        $files = [];
        $len = 1 + strlen($extension);
        
        foreach (scandir($directory) as $file) {
            if (substr($file, 0 - $len) === '.' . $extension) {
                $files[] = $file;
            }
        }
        
        return $files;
    }

    /**
     * Obtiene el adaptador de caché interno (para uso avanzado).
     * 
     * @return CacheItemPoolInterface
     */
    public function getAdapter(): CacheItemPoolInterface
    {
        return $this->cache;
    }

    /**
     * Previene la clonación del singleton.
     */
    private function __clone() {}

    /**
     * Resetea la instancia (útil para tests).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
