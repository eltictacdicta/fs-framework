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

namespace FSFramework\Database;

use RuntimeException;

/**
 * Factoría de conexiones Doctrine DBAL para una migración gradual.
 */
class DbalConnectionFactory
{
    /**
     * Crea una conexión DBAL a partir de la configuración legacy.
     *
     * @return object
     */
    public function createConnection()
    {
        require_once FS_FOLDER . '/base/fs_dbal.php';

        if (!\fs_dbal::is_available()) {
            throw new RuntimeException(\fs_dbal::unavailable_reason());
        }

        return \Doctrine\DBAL\DriverManager::getConnection(\fs_dbal::connection_params());
    }

    /**
     * Devuelve si DBAL está disponible para crear conexiones.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        require_once FS_FOLDER . '/base/fs_dbal.php';
        return \fs_dbal::is_available();
    }

    /**
     * Devuelve los parámetros efectivos calculados desde la config legacy.
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        require_once FS_FOLDER . '/base/fs_dbal.php';
        return \fs_dbal::connection_params();
    }
}
