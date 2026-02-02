<?php
/**
 * This file is part of FacturaScripts
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

namespace FSFramework\Api\Attribute;

use Attribute;

/**
 * Atributo para ocultar un campo de la API
 *
 * Uso:
 * ```php
 * class usuario extends fs_model {
 *     #[ApiHidden]
 *     public $password_hash;
 *
 *     #[ApiHidden(reason: 'Información interna')]
 *     public $internal_data;
 * }
 * ```
 *
 * @author FacturaScripts Team
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ApiHidden
{
    /**
     * @param string|null $reason Razón por la que está oculto (para documentación)
     */
    public function __construct(
        public ?string $reason = null
    ) {}
}
