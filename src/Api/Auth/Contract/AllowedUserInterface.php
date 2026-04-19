<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2013-2025 Carlos Garcia Gomez <neorazorx@gmail.com>
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

namespace FSFramework\Api\Auth\Contract;

/**
 * Interface para gestionar los usuarios permitidos para acceder a la API
 *
 * @author FacturaScripts Team
 */
interface AllowedUserInterface
{
    /**
     * Verifica si un usuario tiene permiso para acceder a la API
     */
    public function isUserAllowed(string $nick_usuario): bool;

    /**
     * Actualiza el último acceso de un usuario
     */
    public function updateLastAccess(string $nick_usuario): bool;
}
