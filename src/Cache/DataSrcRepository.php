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

/**
 * Repositorio cacheado de datos maestros (DataSrc).
 *
 * Patrón inspirado en FacturaScripts 2025 Core/DataSrc/ para almacenar
 * en memoria datos de referencia consultados frecuentemente (empresas,
 * divisas, países, usuarios, etc.) usando CacheManager como respaldo.
 *
 * Las subclases implementan loadAll() para cargar los datos desde la
 * base de datos. La primera llamada a all() los carga y cachea; llamadas
 * posteriores devuelven la copia en memoria. clear() invalida la caché.
 *
 * Ejemplo de uso:
 *
 *   class Empresas extends DataSrcRepository
 *   {
 *       protected static string $dataSrcKey = 'empresas';
 *
 *       protected static function loadAll(): array
 *       {
 *           return (new \empresa())->all();
 *       }
 *   }
 *
 *   $empresas = Empresas::all();
 *   Empresas::clear();
 */
abstract class DataSrcRepository
{
    protected static string $dataSrcKey = '';
    protected static ?array $list = null;
    protected static int $defaultTtl = 600; // 10 minutos por defecto

    /**
     * Devuelve todos los registros cacheados.
     */
    public static function all(): array
    {
        if (static::$list === null) {
            static::$list = static::loadFromCache();
        }

        return static::$list ?? [];
    }

    /**
     * Invalida la caché en memoria y en disco.
     */
    public static function clear(): void
    {
        static::$list = null;

        try {
            $cache = CacheManager::getInstance();
            $cache->delete(static::cacheKey());
        } catch (\Throwable) {
        }
    }

    /**
     * Busca un registro por un campo concreto.
     */
    public static function findBy(string $field, mixed $value): ?array
    {
        foreach (static::all() as $item) {
            if (($item[$field] ?? ($item->{$field} ?? null)) == $value) {
                return is_array($item) ? $item : (array) $item;
            }
        }

        return null;
    }

    /**
     * Devuelve los registros como array para CodeModel (selects).
     */
    public static function codeModel(string $codfield, string $descfield, bool $addEmpty = true): array
    {
        $codes = [];

        foreach (static::all() as $item) {
            $cod = is_array($item) ? ($item[$codfield] ?? '') : ($item->{$codfield} ?? '');
            $desc = is_array($item) ? ($item[$descfield] ?? '') : ($item->{$descfield} ?? '');
            $codes[$cod] = $desc;
        }

        if ($addEmpty) {
            return array_merge(['' => '------'], $codes);
        }

        return $codes;
    }

    /**
     * Carga los datos desde caché o BD.
     */
    protected static function loadFromCache(): array
    {
        try {
            $cache = CacheManager::getInstance();
            $data = $cache->get(static::cacheKey(), function () {
                return static::loadAll();
            }, static::$defaultTtl);

            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return static::loadAll();
        }
    }

    /**
     * Las subclases implementan este método para cargar datos desde BD.
     */
    abstract protected static function loadAll(): array;

    private static function cacheKey(): string
    {
        $key = static::$dataSrcKey;

        if ($key === '') {
            $key = strtolower((new \ReflectionClass(static::class))->getShortName());
        }

        return 'datasrc_' . $key;
    }
}
