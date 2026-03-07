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
 * Utilidades de compatibilidad para una migración gradual a Doctrine DBAL.
 *
 * - No sustituye todavía a fs_db2 ni a los motores legacy.
 * - Centraliza la detección de feature flags y parámetros comunes.
 * - Permite que el primer slice de migración sea compatible con producción.
 */
class fs_dbal
{
    public const BACKEND_LEGACY = 'legacy';
    public const BACKEND_DBAL = 'doctrine_dbal';

    /**
     * Devuelve el backend solicitado por configuración.
     *
     * Se aceptan dos formas de configuración:
     * - FS_DB_BACKEND = 'legacy' | 'doctrine_dbal'
     * - FS_DB_USE_DBAL = true|false
     *
     * @return string
     */
    public static function requested_backend(): string
    {
        if (defined('FS_DB_BACKEND')) {
            $backend = strtolower(trim((string) FS_DB_BACKEND));
            if (in_array($backend, [self::BACKEND_LEGACY, self::BACKEND_DBAL], true)) {
                return $backend;
            }
        }

        if (defined('FS_DB_USE_DBAL') && FS_DB_USE_DBAL) {
            return self::BACKEND_DBAL;
        }

        return self::BACKEND_LEGACY;
    }

    /**
     * Indica si Doctrine DBAL ha sido solicitado por configuración.
     *
     * @return bool
     */
    public static function is_requested(): bool
    {
        return self::requested_backend() === self::BACKEND_DBAL;
    }

    /**
     * Indica si Doctrine DBAL está disponible en runtime.
     *
     * @return bool
     */
    public static function is_available(): bool
    {
        return class_exists('\Doctrine\DBAL\DriverManager');
    }

    /**
     * Devuelve si DBAL puede activarse realmente.
     *
     * @return bool
     */
    public static function is_enabled(): bool
    {
        return self::is_requested() && self::is_available();
    }

    /**
     * Devuelve el backend efectivo, teniendo en cuenta disponibilidad.
     *
     * @return string
     */
    public static function effective_backend(): string
    {
        return self::is_enabled() ? self::BACKEND_DBAL : self::BACKEND_LEGACY;
    }

    /**
     * Devuelve el tipo de motor normalizado.
     *
     * @param string|null $dbType
     * @return string
     */
    public static function database_type(?string $dbType = null): string
    {
        $dbType = strtoupper(trim((string) ($dbType ?? (defined('FS_DB_TYPE') ? FS_DB_TYPE : 'MYSQL'))));

        if (in_array($dbType, ['PGSQL', 'POSTGRES', 'POSTGRESQL'], true)) {
            return 'POSTGRESQL';
        }

        if (in_array($dbType, ['MARIADB', 'MYSQL'], true)) {
            return 'MYSQL';
        }

        return $dbType !== '' ? $dbType : 'MYSQL';
    }

    /**
     * Devuelve el puerto configurado o el predeterminado por motor.
     *
     * @param string|null $dbType
     * @return string
     */
    public static function database_port(?string $dbType = null): string
    {
        if (defined('FS_DB_PORT') && FS_DB_PORT !== '') {
            return (string) FS_DB_PORT;
        }

        return self::database_type($dbType) === 'POSTGRESQL' ? '5432' : '3306';
    }

    /**
     * Devuelve el nombre de driver DBAL recomendado para el motor actual.
     *
     * @param string|null $dbType
     * @return string
     */
    public static function driver_name(?string $dbType = null): string
    {
        return self::database_type($dbType) === 'POSTGRESQL' ? 'pdo_pgsql' : 'pdo_mysql';
    }

    /**
     * Devuelve parámetros comunes para crear una conexión DBAL.
     *
     * @return array<string, mixed>
     */
    public static function connection_params(): array
    {
        $driver = self::driver_name();

        $params = [
            'driver' => $driver,
            'host' => defined('FS_DB_HOST') ? FS_DB_HOST : 'localhost',
            'port' => (int) self::database_port(),
            'dbname' => defined('FS_DB_NAME') ? FS_DB_NAME : 'facturascripts',
            'user' => defined('FS_DB_USER') ? FS_DB_USER : 'root',
            'password' => defined('FS_DB_PASS') ? FS_DB_PASS : '',
        ];

        if (in_array($driver, ['pgsql', 'pdo_pgsql'], true)) {
            $params['charset'] = 'UTF8';
        } else {
            $params['charset'] = 'utf8mb4';
        }

        return $params;
    }

    /**
     * Devuelve una razón legible si DBAL no puede activarse.
     *
     * @return string
     */
    public static function unavailable_reason(): string
    {
        if (!self::is_requested()) {
            return 'Doctrine DBAL no está solicitado por configuración.';
        }

        if (!self::is_available()) {
            return 'La dependencia doctrine/dbal no está instalada o no está cargada por Composer.';
        }

        return '';
    }
}
